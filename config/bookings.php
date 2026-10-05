<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Technician "view own" window
    |--------------------------------------------------------------------------
    |
    | How many days ahead (inclusive of today) a Technician's scoped
    | `bookings.view-own` permission lets them see their own bookings — see
    | App\Policies\BookingPolicy::view() and
    | docs/architecture/07-admin-auth-permissions.md §3.1 footnote 2. Not a
    | security boundary (it's a UI/roster-planning window, not access
    | control on *whose* bookings are visible), so it's a plain admin-tunable
    | config value, not hardcoded — ops can widen/narrow it without a
    | deploy.
    |
    */

    'technician_view_window_days' => env('BOOKINGS_TECHNICIAN_VIEW_WINDOW_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Flexible booking discount
    |--------------------------------------------------------------------------
    |
    | A customer may accept "any window in the day" in exchange for a fixed
    | discount (integer AUD cents, GST-inclusive). The server still assigns a
    | concrete, capacity-checked slot — see
    | App\Services\Bookings\FlexibleBookingPolicy. There is no admin
    | settings table in this app yet, so this is env/config-driven; set
    | BOOKINGS_FLEXIBLE_DISCOUNT_CENTS=0 or BOOKINGS_FLEXIBLE_ENABLED=false
    | to switch the option off.
    |
    */

    'flexible' => [
        'enabled' => (bool) env('BOOKINGS_FLEXIBLE_ENABLED', true),
        'discount_cents' => (int) env('BOOKINGS_FLEXIBLE_DISCOUNT_CENTS', 1000),
    ],

];
