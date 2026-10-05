<?php

use App\Enums\BookingStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\User;
use App\Models\Van;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Covers App\Http\Controllers\Admin\Bookings\DispatchBoardController.
 * `move()`/`cancel()` reuse the exact same SlotComputationService +
 * Cache::lock() pattern as the customer-facing BookingController (see
 * BookingLockConcurrencyTest.php) and each write one AuditLog row so Phase 6
 * reporting can distinguish a staff-initiated dispatch action from a genuine
 * customer reschedule/cancel — both the DB write and the audit row are
 * asserted below, not just the redirect.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function dispatchBoardMonday(): string
{
    return CarbonImmutable::parse('next monday')->toDateString();
}

function bookingsManageUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('operations');

    return $user;
}

function bookingsViewOwnUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('technician');

    return $user;
}

describe('index', function () {
    it('denies the dispatch board to a user with neither bookings.manage nor bookings.view-own (403)', function () {
        $user = User::factory()->withTwoFactor()->create();
        $user->assignRole('ecommerce');

        $this->actingAs($user)->get(route('admin.dispatch.index'))->assertForbidden();
    });

    it('renders the full technician list and isScopedToOwn=false for a bookings.manage user', function () {
        $admin = bookingsManageUser();
        Technician::factory()->create();
        Technician::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.dispatch.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('bookings/dispatch/index')
            ->where('isScopedToOwn', false)
            ->has('technicians', 2)
        );
    });

    it('scopes a bookings.view-own technician to only their own technician row, with isScopedToOwn=true', function () {
        $user = bookingsViewOwnUser();
        $technician = Technician::factory()->create(['user_id' => $user->id]);
        Technician::factory()->create();

        $response = $this->actingAs($user)->get(route('admin.dispatch.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('isScopedToOwn', true)
            ->has('technicians', 1)
            ->where('technicians.0.id', $technician->id)
        );
    });

    it('clamps a scoped technicians out-of-window date request back into the view window', function () {
        $user = bookingsViewOwnUser();
        Technician::factory()->create(['user_id' => $user->id]);

        $farFuture = CarbonImmutable::now()->addDays(400)->toDateString();
        $windowEnd = CarbonImmutable::now()->startOfDay()->addDays(14)->toDateString();

        $response = $this->actingAs($user)->get(route('admin.dispatch.index', ['date' => $farFuture]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('filters.date', $windowEnd));
    });
});

describe('availability', function () {
    it('returns eligible technician/van candidates for the requested slot', function () {
        $admin = bookingsManageUser();
        $zone = ServiceZone::factory()->create();

        $technicianA = Technician::factory()->create();
        $vanA = Van::factory()->create();
        TechnicianShift::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technicianA->id,
            'van_id' => $vanA->id,
            'date' => dispatchBoardMonday(),
            'shift_start' => '08:00:00',
            'shift_end' => '18:00:00',
        ]);

        $technicianB = Technician::factory()->create();
        $vanB = Van::factory()->create();
        TechnicianShift::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technicianB->id,
            'van_id' => $vanB->id,
            'date' => dispatchBoardMonday(),
            'shift_start' => '08:00:00',
            'shift_end' => '18:00:00',
        ]);

        $booking = Booking::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technicianA->id,
            'van_id' => $vanA->id,
            'scheduled_date' => dispatchBoardMonday(),
            'slot_start' => '09:00:00',
            'slot_end' => '09:45:00',
            'status' => BookingStatus::PendingHold,
            'duration_minutes' => 45,
        ]);

        $response = $this->actingAs($admin)->getJson(
            route('admin.dispatch.bookings.availability', $booking).'?'.http_build_query([
                'scheduled_date' => dispatchBoardMonday(),
                'slot_start' => '11:00',
            ])
        );

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('technician_id')->sort()->values()->all();
        expect($ids)->toBe(collect([$technicianA->id, $technicianB->id])->sort()->values()->all());
    });

    it('denies availability to a bookings.view-own-only user (403)', function () {
        $user = bookingsViewOwnUser();
        $booking = Booking::factory()->create();

        $response = $this->actingAs($user)->getJson(
            route('admin.dispatch.bookings.availability', $booking).'?'.http_build_query([
                'scheduled_date' => dispatchBoardMonday(),
                'slot_start' => '09:00',
            ])
        );

        $response->assertForbidden();
    });
});

describe('move', function () {
    it('reassigns a booking to a new technician/slot and writes a bookings.moved AuditLog row with before/after payloads', function () {
        $admin = bookingsManageUser();
        $zone = ServiceZone::factory()->create();

        $technicianA = Technician::factory()->create();
        $vanA = Van::factory()->create();
        TechnicianShift::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technicianA->id,
            'van_id' => $vanA->id,
            'date' => dispatchBoardMonday(),
            'shift_start' => '08:00:00',
            'shift_end' => '18:00:00',
        ]);

        $technicianB = Technician::factory()->create();
        $vanB = Van::factory()->create();
        TechnicianShift::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technicianB->id,
            'van_id' => $vanB->id,
            'date' => dispatchBoardMonday(),
            'shift_start' => '08:00:00',
            'shift_end' => '18:00:00',
        ]);

        $booking = Booking::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technicianA->id,
            'van_id' => $vanA->id,
            'scheduled_date' => dispatchBoardMonday(),
            'slot_start' => '09:00:00',
            'slot_end' => '09:45:00',
            'status' => BookingStatus::PendingHold,
            'duration_minutes' => 45,
        ]);

        $response = $this->actingAs($admin)->patch(route('admin.dispatch.bookings.move', $booking), [
            'scheduled_date' => dispatchBoardMonday(),
            'slot_start' => '11:00',
            'technician_id' => $technicianB->id,
        ]);

        $response->assertStatus(302);
        $response->assertInertiaFlash('toast.type', 'success');
        $response->assertInertiaFlash('toast.message', 'Booking moved.');

        $booking->refresh();
        expect($booking->status)->toBe(BookingStatus::PendingHold)
            ->and($booking->technician_id)->toBe($technicianB->id)
            ->and($booking->van_id)->toBe($vanB->id)
            ->and($booking->slot_start)->toBe('11:00:00')
            ->and($booking->slot_end)->toBe('11:45:00');

        $log = AuditLog::query()
            ->where('auditable_type', Booking::class)
            ->where('auditable_id', $booking->id)
            ->where('action', 'bookings.moved')
            ->sole();

        expect($log->actor_id)->toBe($admin->id)
            ->and($log->before)->toMatchArray([
                'scheduled_date' => dispatchBoardMonday(),
                'slot_start' => '09:00:00',
                'slot_end' => '09:45:00',
                'technician_id' => $technicianA->id,
                'van_id' => $vanA->id,
            ])
            ->and($log->after)->toMatchArray([
                'scheduled_date' => dispatchBoardMonday(),
                'slot_start' => '11:00',
                'slot_end' => '11:45',
                'technician_id' => $technicianB->id,
                'van_id' => $vanB->id,
            ]);
    });

    it('flashes an error toast without moving the booking when the target slot is genuinely taken, rather than throwing', function () {
        $admin = bookingsManageUser();
        $zone = ServiceZone::factory()->create();
        $technician = Technician::factory()->create();
        $van = Van::factory()->create();

        TechnicianShift::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technician->id,
            'van_id' => $van->id,
            'date' => dispatchBoardMonday(),
            'shift_start' => '08:00:00',
            'shift_end' => '18:00:00',
        ]);

        // The only technician covering this zone/day is already booked
        // 11:00-11:45 by someone else, so the requested slot has no free
        // candidate at all.
        Booking::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technician->id,
            'van_id' => $van->id,
            'scheduled_date' => dispatchBoardMonday(),
            'slot_start' => '11:00:00',
            'slot_end' => '11:45:00',
            'status' => BookingStatus::Confirmed,
        ]);

        $booking = Booking::factory()->create([
            'service_zone_id' => $zone->id,
            'technician_id' => $technician->id,
            'van_id' => $van->id,
            'scheduled_date' => dispatchBoardMonday(),
            'slot_start' => '09:00:00',
            'slot_end' => '09:45:00',
            'status' => BookingStatus::PendingHold,
            'duration_minutes' => 45,
        ]);

        $response = $this->actingAs($admin)->patch(route('admin.dispatch.bookings.move', $booking), [
            'scheduled_date' => dispatchBoardMonday(),
            'slot_start' => '11:00',
        ]);

        // A normal redirect with an error toast, not a 409 — this is the
        // "doesn't throw" behavior the brief specifically asked to verify.
        $response->assertStatus(302);
        $response->assertInertiaFlash('toast.type', 'error');
        $response->assertInertiaFlash('toast.message', 'This slot is no longer available, please choose another.');

        $booking->refresh();
        expect($booking->technician_id)->toBe($technician->id)
            ->and($booking->slot_start)->toBe('09:00:00');

        expect(AuditLog::query()->where('action', 'bookings.moved')->count())->toBe(0);
    });

    it('refuses to move a booking whose status is not movable, with a toast rather than an error', function () {
        $admin = bookingsManageUser();
        $booking = Booking::factory()->create(['status' => BookingStatus::Cancelled]);

        $response = $this->actingAs($admin)->patch(route('admin.dispatch.bookings.move', $booking), [
            'scheduled_date' => dispatchBoardMonday(),
            'slot_start' => '09:00',
        ]);

        $response->assertStatus(302);
        $response->assertInertiaFlash('toast.type', 'error');
        $response->assertInertiaFlash('toast.message', 'This booking can no longer be moved.');

        expect(AuditLog::query()->where('action', 'bookings.moved')->count())->toBe(0);
    });

    it('denies move to a bookings.view-own-only user (403)', function () {
        $user = bookingsViewOwnUser();
        $booking = Booking::factory()->create(['status' => BookingStatus::PendingHold]);

        $response = $this->actingAs($user)->patch(route('admin.dispatch.bookings.move', $booking), [
            'scheduled_date' => dispatchBoardMonday(),
            'slot_start' => '09:00',
        ]);

        $response->assertForbidden();
        expect($booking->refresh()->status)->toBe(BookingStatus::PendingHold);
        expect(AuditLog::query()->where('action', 'bookings.moved')->count())->toBe(0);
    });
});

describe('cancel', function () {
    it('cancels a movable booking and writes a bookings.cancelled AuditLog row with before/after payloads', function () {
        $admin = bookingsManageUser();
        $booking = Booking::factory()->confirmed()->create();

        $response = $this->actingAs($admin)->post(route('admin.dispatch.bookings.cancel', $booking));

        $response->assertStatus(302);
        $response->assertInertiaFlash('toast.type', 'success');
        $response->assertInertiaFlash('toast.message', 'Booking cancelled.');

        $booking->refresh();
        expect($booking->status)->toBe(BookingStatus::Cancelled)
            ->and($booking->hold_expires_at)->toBeNull();

        $log = AuditLog::query()
            ->where('auditable_type', Booking::class)
            ->where('auditable_id', $booking->id)
            ->where('action', 'bookings.cancelled')
            ->sole();

        expect($log->actor_id)->toBe($admin->id)
            ->and($log->before)->toBe(['status' => 'confirmed'])
            ->and($log->after)->toBe(['status' => 'cancelled']);
    });

    it('refuses to cancel a booking that is already cancelled, with a toast rather than an error', function () {
        $admin = bookingsManageUser();
        $booking = Booking::factory()->create(['status' => BookingStatus::Cancelled]);

        $response = $this->actingAs($admin)->post(route('admin.dispatch.bookings.cancel', $booking));

        $response->assertStatus(302);
        $response->assertInertiaFlash('toast.type', 'error');
        $response->assertInertiaFlash('toast.message', 'This booking can no longer be cancelled.');

        expect(AuditLog::query()->where('action', 'bookings.cancelled')->count())->toBe(0);
    });

    it('denies cancel to a bookings.view-own-only user (403)', function () {
        $user = bookingsViewOwnUser();
        $booking = Booking::factory()->confirmed()->create();

        $response = $this->actingAs($user)->post(route('admin.dispatch.bookings.cancel', $booking));

        $response->assertForbidden();
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
        expect(AuditLog::query()->where('action', 'bookings.cancelled')->count())->toBe(0);
    });
});
