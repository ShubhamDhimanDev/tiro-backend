<?php

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\User;
use App\Services\AuditLogFilters;
use App\Services\AuditLogQueryService;
use Spatie\Permission\Models\Role;

/**
 * `App\Services\AuditLogQueryService` — the reusable query/filter layer
 * behind `GET /admin/audit-log` (super-admin-agent's controller/route/UI,
 * per the Phase 6 task brief; this test exercises the service directly).
 */
beforeEach(function () {
    $this->service = new AuditLogQueryService;
});

it('filters by a specific actor id', function () {
    $actor = User::factory()->create();
    $matching = AuditLog::factory()->create(['actor_id' => $actor->id]);
    AuditLog::factory()->create(); // different actor

    $result = $this->service->query(new AuditLogFilters(actorId: $actor->id))->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($matching->id);
});

it('filters to system-actor rows (actor_id IS NULL) via the "system" sentinel', function () {
    $system = AuditLog::factory()->create(['actor_id' => null]);
    AuditLog::factory()->create(); // has a real actor

    $result = $this->service->query(new AuditLogFilters(actorId: 'system'))->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($system->id);
});

it('filters by auditable_type', function () {
    $bookingLog = AuditLog::factory()->create(['auditable_type' => Booking::class, 'auditable_id' => 1]);
    AuditLog::factory()->create(['auditable_type' => Order::class, 'auditable_id' => 1]);

    $result = $this->service->query(new AuditLogFilters(auditableType: Booking::class))->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($bookingLog->id);
});

it('filters by action as a contains-match, not an exact match', function () {
    $matching = AuditLog::factory()->create(['action' => 'bookings.cancelled']);
    AuditLog::factory()->create(['action' => 'orders.refunded']);

    $result = $this->service->query(new AuditLogFilters(action: 'cancel'))->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($matching->id);
});

it('filters by a created_at date range', function () {
    $inRange = AuditLog::factory()->create(['created_at' => now()->subDays(2)]);
    AuditLog::factory()->create(['created_at' => now()->subMonth()]); // before range
    AuditLog::factory()->create(['created_at' => now()->addMonth()]); // after range

    $result = $this->service->query(new AuditLogFilters(from: now()->subWeek(), to: now()))->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($inRange->id);
});

it('combines every filter with AND', function () {
    $actor = User::factory()->create();
    $matching = AuditLog::factory()->create([
        'actor_id' => $actor->id,
        'auditable_type' => Promotion::class,
        'auditable_id' => 1,
        'action' => 'promotions.updated',
        'created_at' => now()->subDay(),
    ]);
    // Same actor, wrong action.
    AuditLog::factory()->create([
        'actor_id' => $actor->id,
        'auditable_type' => Promotion::class,
        'auditable_id' => 2,
        'action' => 'promotions.created',
        'created_at' => now()->subDay(),
    ]);

    $result = $this->service->query(new AuditLogFilters(
        actorId: $actor->id,
        auditableType: Promotion::class,
        action: 'updated',
        from: now()->subWeek(),
        to: now(),
    ))->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($matching->id);
});

it('orders results newest first', function () {
    $older = AuditLog::factory()->create(['created_at' => now()->subDays(2)]);
    $newer = AuditLog::factory()->create(['created_at' => now()->subDay()]);

    $result = $this->service->query(new AuditLogFilters)->get();

    expect($result->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

it('paginates results', function () {
    AuditLog::factory()->count(3)->create();

    $result = $this->service->paginate(new AuditLogFilters, perPage: 2);

    expect($result->total())->toBe(3);
    expect($result->items())->toHaveCount(2);
});

it('includes Role::class in the auditable_type dropdown alongside User::class', function () {
    expect(AuditLogQueryService::AUDITABLE_TYPES)->toContain(User::class, Role::class);
});

/**
 * Security-agent regression, 2026-09-23 (Phase 6 review): a query-string
 * actor id arrives as a *string* even when numeric (`?actor=5`). Before this
 * fix, `AuditLogFilters` passed `$actorId` through verbatim and
 * `AuditLogQueryService::query()`'s `is_int()`/`'system'` checks both missed
 * a numeric-string value — silently producing an unfiltered "everyone"
 * result instead of filtering to that actor. Must normalize to `int`, not
 * silently drop the filter.
 */
it('normalizes a numeric-string actor id to int so the actor filter actually applies', function () {
    $actor = User::factory()->create();
    $matching = AuditLog::factory()->create(['actor_id' => $actor->id]);
    AuditLog::factory()->create(); // different actor

    $filters = new AuditLogFilters(actorId: (string) $actor->id);

    expect($filters->actorId)->toBe($actor->id)->toBeInt();

    $result = $this->service->query($filters)->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($matching->id);
});

/**
 * Security-agent regression, 2026-09-23 (Phase 6 review): an
 * invalid/unrecognized actor filter value must fail closed (reject
 * construction) rather than ever silently degrade to an unfiltered
 * "everyone" result on this permission-gated cross-admin audit trail.
 */
it('rejects a non-numeric, non-"system" actor id instead of silently ignoring the filter', function () {
    expect(fn () => new AuditLogFilters(actorId: 'not-a-real-actor'))
        ->toThrow(InvalidArgumentException::class);
});
