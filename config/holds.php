<?php

use App\Models\Booking;

return [

    /*
    |--------------------------------------------------------------------------
    | Holdable Models
    |--------------------------------------------------------------------------
    |
    | Every Eloquent model implementing App\Contracts\HasHold that the
    | `bookings:release-expired-holds` scheduled sweep should also cover —
    | see docs/architecture/04-booking-capacity-engine.md's "Reservation
    | pattern (hold-with-TTL)" section. Adding a future holdable model (e.g.
    | a promo-stock or inventory-reservation hold) is a one-line addition
    | here plus the model implementing the contract, not a second sweep
    | command.
    |
    */

    'models' => [
        Booking::class,
    ],

];
