<?php

namespace App\Jobs;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fires close to exactly on time via `->delay($booking->hold_expires_at)`,
 * dispatched at hold-creation time — one half of the "defense in depth" pair
 * described in docs/architecture/04-booking-capacity-engine.md's
 * "Reservation pattern" section; the `bookings:release-expired-holds`
 * every-minute sweep is the other half, for when this job never fires
 * (worker restart, queue driver hiccup). Both call
 * {@see Booking::releaseHold()} so the actual release logic lives in one
 * place. Requires a running queue worker (devops-agent's responsibility).
 */
class ReleaseExpiredBookingHold implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $bookingId) {}

    public function handle(): void
    {
        Booking::query()->find($this->bookingId)?->releaseHold();
    }
}
