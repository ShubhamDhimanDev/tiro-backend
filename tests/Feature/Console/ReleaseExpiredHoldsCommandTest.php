<?php

use App\Enums\BookingStatus;
use App\Models\Booking;

/**
 * `php artisan bookings:release-expired-holds` — the every-minute safety-net
 * sweep for docs/architecture/04-booking-capacity-engine.md's hold-with-TTL
 * mechanism. Iterates `config('holds.models')`, currently `[Booking::class]`
 * — see config/holds.php.
 */
it('releases every expired pending_hold booking', function () {
    $expiredA = Booking::factory()->create(['status' => BookingStatus::PendingHold, 'hold_expires_at' => now()->subMinutes(5)]);
    $expiredB = Booking::factory()->create(['status' => BookingStatus::PendingHold, 'hold_expires_at' => now()->subSecond()]);

    $this->artisan('bookings:release-expired-holds')->assertExitCode(0);

    expect($expiredA->fresh()->status)->toBe(BookingStatus::Expired);
    expect($expiredB->fresh()->status)->toBe(BookingStatus::Expired);
});

it('leaves a not-yet-expired hold and a non-hold booking untouched', function () {
    $notYetExpired = Booking::factory()->create(['status' => BookingStatus::PendingHold, 'hold_expires_at' => now()->addMinutes(10)]);
    $confirmed = Booking::factory()->confirmed()->create();

    $this->artisan('bookings:release-expired-holds')->assertExitCode(0);

    expect($notYetExpired->fresh()->status)->toBe(BookingStatus::PendingHold);
    expect($confirmed->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('is idempotent — running it again with nothing new to release does nothing', function () {
    Booking::factory()->create(['status' => BookingStatus::PendingHold, 'hold_expires_at' => now()->subMinute()]);

    $this->artisan('bookings:release-expired-holds')->assertExitCode(0);
    $this->artisan('bookings:release-expired-holds')->assertExitCode(0);

    expect(Booking::query()->where('status', BookingStatus::Expired)->count())->toBe(1);
});
