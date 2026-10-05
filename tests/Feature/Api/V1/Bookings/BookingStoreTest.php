<?php

use App\Enums\BookingStatus;
use App\Enums\TyreCategory;
use App\Jobs\ReleaseExpiredBookingHold;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Models\Van;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * `POST /api/v1/bookings` — see docs/architecture/02-api-contract.md's
 * "Booking & capacity endpoints" section and
 * docs/architecture/04-booking-capacity-engine.md's "Reservation pattern"
 * section.
 *
 * `Queue::fake()` throughout: the delayed `ReleaseExpiredBookingHold` job is
 * dispatched with `->delay($booking->hold_expires_at)`, which the `sync`
 * queue driver this test suite runs under (see phpunit.xml) ignores,
 * executing the job immediately instead of after the hold's 15-minute TTL —
 * that would immediately flip a just-created booking back to `expired`
 * before this test could assert its `pending_hold` state. Faking the queue
 * isolates "did the controller dispatch the right job" from "does the job's
 * own handler work", which is covered separately in
 * ReleaseExpiredBookingHoldTest.
 */
beforeEach(function () {
    seedDurationRules();
    Queue::fake();
});

it('creates a guest booking hold and issues a manage_token exactly once', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = bookableFixture();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone));

    $response->assertCreated();
    $response->assertJsonStructure(['data' => ['id', 'status', 'scheduled_date', 'slot_start', 'slot_end', 'duration_minutes', 'hold_expires_at', 'manage_token_issued', 'manage_token']]);
    expect($response->json('data.status'))->toBe('pending_hold');
    expect($response->json('data.slot_start'))->toBe('09:00');
    expect($response->json('data.manage_token_issued'))->toBeTrue();

    $booking = Booking::query()->findOrFail($response->json('data.id'));
    expect($booking->technician_id)->toBe($technician->id);
    expect($booking->van_id)->toBe($van->id);
    expect($booking->customer_id)->toBeNull();
    expect($booking->manage_token_hash)->not->toBeNull();
    expect($booking->manageTokenMatches($response->json('data.manage_token')))->toBeTrue();
});

/**
 * Security review follow-up (Phase 3 booking mechanism, fix 1): the
 * transient manage-token cache entry used to serve an `Idempotency-Key`
 * replay round-tripped the raw guest `manage_token` through the cache
 * store in plaintext. `Booking::cacheManageToken()` now encrypts it via
 * `Crypt::encryptString()` before writing — same shape as `OtpCodeMailTest`
 * asserting the raw OTP never appears in the queued job payload.
 */
it('caches the guest manage_token encrypted at rest, never in plaintext, for the Idempotency-Key replay window', function () {
    ['zone' => $zone] = bookableFixture();
    $key = (string) Str::uuid();

    $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', storeBookingPayload($zone));

    $response->assertCreated();
    $manageToken = $response->json('data.manage_token');
    $booking = Booking::query()->findOrFail($response->json('data.id'));

    $cachedRaw = Cache::get(Booking::manageTokenCacheKey($booking->id));

    expect($cachedRaw)->toBeString();
    expect($cachedRaw)->not->toBe($manageToken);
    expect($cachedRaw)->not->toContain($manageToken);
    // Still round-trips back to the original plaintext through the
    // decrypting accessor a repeated Idempotency-Key request reads via.
    expect(Booking::cachedManageToken($booking->id))->toBe($manageToken);
});

it('omits manage_token for an authenticated customer booking', function () {
    ['zone' => $zone] = bookableFixture();
    $customer = Customer::factory()->activated()->create();

    $response = $this->withHeader('Authorization', 'Bearer '.$customer->createToken('storefront')->plainTextToken)
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone));

    $response->assertCreated();
    expect($response->json('data'))->not->toHaveKey('manage_token');
    expect($response->json('data.manage_token_issued'))->toBeFalse();

    $booking = Booking::query()->findOrFail($response->json('data.id'));
    expect($booking->customer_id)->toBe($customer->id);
    expect($booking->manage_token_hash)->toBeNull();
});

it('replays the identical response for a repeated Idempotency-Key instead of creating a second booking', function () {
    ['zone' => $zone] = bookableFixture();
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', storeBookingPayload($zone));
    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', storeBookingPayload($zone));

    $first->assertCreated();
    $second->assertCreated();
    expect($second->json('data'))->toBe($first->json('data'));
    expect(Booking::query()->count())->toBe(1);
});

/**
 * docs/architecture/02-api-contract.md's "One-time secrets under idempotent
 * replay" convention: the dedup guarantee (never create a second row) is
 * unbounded, but the *secret-replay* guarantee (return the literal
 * `manage_token` again) is bounded to the same 15-minute window as the
 * booking hold ({@see Booking::manageTokenCacheTtl()}). Past that window,
 * a replay is still a clean success, not an error — it just can no longer
 * surface the plaintext, and says so explicitly via `manage_token_issued`
 * rather than silently omitting the field (which would be indistinguishable
 * from an authenticated booking that never had one at all).
 */
it('replays a guest booking past its 15 minute secret-replay window with manage_token_issued true but manage_token null', function () {
    ['zone' => $zone] = bookableFixture();
    $key = (string) Str::uuid();

    $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', storeBookingPayload($zone));
    $first->assertCreated();

    $this->travel(16)->minutes();

    $second = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', storeBookingPayload($zone));

    $second->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect($second->json('data.manage_token_issued'))->toBeTrue();
    expect($second->json('data'))->not->toHaveKey('manage_token');
    expect(Booking::query()->count())->toBe(1);
});

/**
 * Security review follow-up (Phase 3 booking mechanism, fix 2): a genuine
 * race, not just the sequential-replay case the test above covers. Two
 * truly concurrent requests carrying the identical `Idempotency-Key`, each
 * routed to a *different* eligible technician, can both pass the pre-lock
 * null-checks before either has committed — whoever's `Booking::create()`
 * insert loses that race must hit the DB's unique-constraint violation and
 * recover by returning the winner's row, not surface a raw 500.
 *
 * Reproducing the literal race (two real, overlapping DB connections both
 * attempting the conflicting `INSERT`) was tried first and empirically
 * ruled out for this specific test, not assumed away: this connection's own
 * `lockForUpdate()` pre-check (the one immediately before `Booking::
 * create()`) takes a real lock, and — a genuine InnoDB quirk, also
 * confirmed empirically, not just documented — `ROLLBACK TO SAVEPOINT`
 * does *not* release row/gap locks taken since that savepoint; only a full
 * transaction end does. A second connection's conflicting `INSERT` then
 * blocks for the rest of this whole test (which runs inside
 * RefreshDatabase's single wrapping transaction), which is exactly what
 * should happen for two genuinely concurrent OS-level requests (the loser
 * simply waits, then either finds the winner's row or fails cleanly) — but
 * inside one single-threaded PHPUnit process there's no second thread to
 * let that wait resolve; the test just deadlocks against itself instead.
 *
 * So this test decouples the two things that would happen atomically under
 * real concurrency: (1) this request's own `Booking::create()` fails with
 * the exact exception type MySQL raises for a duplicate `idempotency_key`
 * (`UniqueConstraintViolationException`, constructed directly rather than
 * provoked, since provoking it for real is what deadlocks per the above),
 * and (2) the competing booking becomes committed and visible before this
 * request's catch handler re-queries for it — inserted on this *same*
 * connection (a transaction's own locks never block its own later
 * statements, so this doesn't hit the issue above) once this request's own
 * transaction has actually rolled back (`TransactionRolledBack`, fired
 * post-rollback — see `Connection::rollBack()`), landing it as an ordinary
 * statement in RefreshDatabase's outer transaction rather than inside the
 * savepoint that just unwound.
 */
it('closes the true concurrent-insert race for a shared Idempotency-Key by returning the committed winner instead of a raw 500', function () {
    ['zone' => $zone, 'technician' => $technicianA, 'van' => $vanA] = bookableFixture();

    $technicianB = Technician::factory()->create();
    $vanB = Van::factory()->create();

    TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technicianB->id,
        'van_id' => $vanB->id,
        'date' => bookingMonday(),
        'shift_start' => '09:00:00',
        'shift_end' => '17:00:00',
    ]);

    $key = (string) Str::uuid();

    // `SlotComputationService::eligibleTechniciansForSlot()` sorts
    // candidates ascending by technician_id, so `BookingController::
    // store()`'s loop tries the lower id first, on this (real) request.
    // The simulated concurrent winner is routed to the *other* technician
    // — matching the security review's "routed to different technician
    // candidates", not the same one this request already holds a lock for.
    $winnerTechnicianId = max($technicianA->id, $technicianB->id);
    $winnerVanId = $winnerTechnicianId === $technicianB->id ? $vanB->id : $vanA->id;

    $committedWinner = false;

    Event::listen(TransactionRolledBack::class, function () use (&$committedWinner, $key, $zone, $winnerTechnicianId, $winnerVanId): void {
        if ($committedWinner) {
            return;
        }

        $committedWinner = true;

        DB::table('bookings')->insert([
            'service_zone_id' => $zone->id,
            'scheduled_date' => bookingMonday(),
            'slot_start' => '09:00:00',
            'slot_end' => '09:15:00',
            'technician_id' => $winnerTechnicianId,
            'van_id' => $winnerVanId,
            'status' => BookingStatus::PendingHold->value,
            'duration_minutes' => 15,
            'idempotency_key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    Booking::creating(function (Booking $model) use ($key): void {
        if ($model->idempotency_key !== $key) {
            return;
        }

        $previous = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$key}' for key 'bookings.idempotency_key'");
        $previous->errorInfo = ['23000', 1062, "Duplicate entry '{$key}' for key 'bookings.idempotency_key'"];

        throw new UniqueConstraintViolationException('mysql', 'insert into `bookings` (`idempotency_key`, ...) values (?, ...)', [], $previous);
    });

    $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', storeBookingPayload($zone));

    $response->assertCreated();
    expect(Booking::query()->count())->toBe(1);

    $winner = Booking::query()->sole();
    expect($winner->idempotency_key)->toBe($key);
    expect($winner->technician_id)->toBe($winnerTechnicianId);
    expect($response->json('data.id'))->toBe($winner->id);
    expect($response->json('data.status'))->toBe('pending_hold');
});

it('sets a 15 minute hold TTL and dispatches the delayed release job for a new booking', function () {
    ['zone' => $zone] = bookableFixture();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone));

    $response->assertCreated();

    $booking = Booking::query()->findOrFail($response->json('data.id'));
    $minutesUntilExpiry = now()->diffInMinutes($booking->hold_expires_at);
    expect($minutesUntilExpiry)->toBeLessThanOrEqual(15)->toBeGreaterThan(14);

    Queue::assertPushed(ReleaseExpiredBookingHold::class, fn ($job) => $job->bookingId === $booking->id);
});

it('404s for an unresolvable service_zone_id', function () {
    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload(ServiceZone::factory()->make(['id' => 999999])));

    $response->assertStatus(404);
});

it('returns the documented 409 (not a validation error) when no technician has room for the slot', function () {
    ['zone' => $zone, 'technician' => $technician, 'van' => $van] = bookableFixture();

    // Occupy the requested slot (plus travel buffer) for the only
    // technician covering this zone/date, so no candidate remains eligible
    // by the time the request is evaluated.
    Booking::factory()->create([
        'service_zone_id' => $zone->id,
        'technician_id' => $technician->id,
        'van_id' => $van->id,
        'scheduled_date' => bookingMonday(),
        'slot_start' => '09:00:00',
        'slot_end' => '09:55:00',
        'status' => BookingStatus::Confirmed,
    ]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone));

    $response->assertStatus(409);
    expect($response->json())->not->toHaveKey('errors');
    expect($response->json('message'))->toBe('This slot is no longer available, please choose another.');
});

it('stores the submitted addons and derives duration server-side, ignoring any client-sent duration', function () {
    // `locking_nuts` deliberately, not `alignment` — `alignment` also
    // narrows technician eligibility to alignment-equipped vans (a separate
    // concern, covered in SlotComputationServiceTest), which would couple
    // this test to van equipment setup it isn't about.
    ['zone' => $zone] = bookableFixture();

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
            'addons' => ['locking_nuts'],
            'duration_minutes' => 999999,
        ]));

    $response->assertCreated();
    // base(15) + locking_nuts(5), the client-sent duration_minutes is ignored entirely.
    expect($response->json('data.duration_minutes'))->toBe(20);

    $booking = Booking::query()->findOrFail($response->json('data.id'));
    expect($booking->addons)->toBe(['locking_nuts']);
});

it('persists booking line items for submitted cart items', function () {
    ['zone' => $zone] = bookableFixture();
    $model = TyreModel::factory()->create(['category' => TyreCategory::Car, 'run_flat' => false]);
    $variant = TyreVariant::factory()->create(['tyre_model_id' => $model->id]);

    $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/bookings', storeBookingPayload($zone, [
            'items' => [['tyre_variant_id' => $variant->id, 'quantity' => 2, 'position' => 'all']],
        ]));

    $response->assertCreated();

    $booking = Booking::query()->findOrFail($response->json('data.id'));
    expect($booking->lineItems)->toHaveCount(1);
    expect($booking->lineItems->first()->tyre_variant_id)->toBe($variant->id);
    expect($booking->lineItems->first()->quantity)->toBe(2);
});
