<?php

use App\Models\Booking;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * docs/architecture/04-booking-capacity-engine.md's "Reservation pattern" ->
 * "Race prevention at creation time" — the `Cache::lock()` genuinely
 * preventing two different customers from winning the *same*
 * technician/slot. Distinct from BookingStoreTest's Idempotency-Key
 * replay/race coverage (one shared key, routed to two *different*
 * technicians): here it's two different Idempotency-Keys contending for the
 * *same* technician/slot, proving the lock itself — not idempotency
 * dedup — is what prevents the double-book.
 *
 * A literal two-connection race isn't reproducible inside a single
 * PHPUnit process (see BookingStoreTest's own note on why that deadlocks
 * against RefreshDatabase's wrapping transaction for the DB-row race). But
 * `Cache::lock()` doesn't need a second DB connection to prove genuine
 * mutual exclusion — its state lives in the cache store (this suite's
 * CACHE_STORE=array, per phpunit.xml — the array store's locks are real
 * `Illuminate\Cache\ArrayLock` entries with ownership/expiry semantics, not
 * a stub), not inside the DB transaction. Acquiring the exact lock key
 * `BookingController::lockKey()` builds — *before* firing a real HTTP
 * request through the exact same controller code path — is a faithful
 * reproduction of "another request already won this slot's lock", using the
 * real primitive under real contention rather than mocking it.
 */
beforeEach(function () {
    seedDurationRules();
    Queue::fake();
});

function lockConcurrencyMonday(): string
{
    return CarbonImmutable::parse('next monday')->toDateString();
}

it('rejects a competing Idempotency-Key for the same technician/slot while the Cache::lock is genuinely held elsewhere, without creating a booking', function () {
    $zone = ServiceZone::factory()->create();
    $technician = Technician::factory()->create();
    $van = Van::factory()->create();

    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'date' => lockConcurrencyMonday(),
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    // Simulates a concurrent request that has already entered
    // BookingController::store()'s critical section for this exact
    // technician/slot and not yet released it.
    $contendingLock = Cache::lock("booking-slot:{$technician->id}:".lockConcurrencyMonday().':09:00', 10);
    expect($contendingLock->get())->toBeTrue();

    $payload = [
        'service_zone_id' => $zone->id,
        'scheduled_date' => lockConcurrencyMonday(),
        'slot_start' => '09:00',
    ];

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', $payload);

    // Only one technician covers this zone/slot, so a contended lock leaves
    // no fallback candidate — the documented 409, not a silent double-book.
    $response->assertStatus(409);
    expect($response->json('message'))->toBe('This slot is no longer available, please choose another.');
    expect(Booking::query()->count())->toBe(0);

    $contendingLock->release();

    // Once the contending lock is released, the exact same slot is
    // bookable again under a fresh Idempotency-Key — proving the earlier
    // 409 was genuine lock contention, not a permanently broken slot.
    $second = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', $payload);

    $second->assertCreated();
    expect(Booking::query()->count())->toBe(1);
    expect(Booking::query()->sole()->technician_id)->toBe($technician->id);
});

it('lets a second technician win the same slot while the first technicians lock is held, proving contention only blocks the specific contended candidate', function () {
    $zone = ServiceZone::factory()->create();

    $technicianA = Technician::factory()->create();
    $vanA = Van::factory()->create();
    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technicianA->id,
        'van_id' => $vanA->id,
        'date' => lockConcurrencyMonday(),
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    $technicianB = Technician::factory()->create();
    $vanB = Van::factory()->create();
    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technicianB->id,
        'van_id' => $vanB->id,
        'date' => lockConcurrencyMonday(),
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    $lowerTechnicianId = min($technicianA->id, $technicianB->id);
    $higherTechnicianId = max($technicianA->id, $technicianB->id);

    // BookingController::eligibleTechniciansForSlot() sorts candidates
    // ascending by technician_id, so store() tries the lower id's lock
    // first. Contending for that one forces the real request to fall
    // through to the other, still-free technician rather than being
    // rejected outright.
    $contendingLock = Cache::lock("booking-slot:{$lowerTechnicianId}:".lockConcurrencyMonday().':09:00', 10);
    expect($contendingLock->get())->toBeTrue();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', [
            'service_zone_id' => $zone->id,
            'scheduled_date' => lockConcurrencyMonday(),
            'slot_start' => '09:00',
        ]);

    $response->assertCreated();
    $winner = Booking::query()->sole();
    expect($winner->technician_id)->toBe($higherTechnicianId);

    $contendingLock->release();
});
