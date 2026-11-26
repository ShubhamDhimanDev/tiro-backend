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

    /*
    |--------------------------------------------------------------------------
    | Same-day cutoff
    |--------------------------------------------------------------------------
    |
    | A fitting can be booked for today only if the booking is made before
    | `cutoff` (24h "HH:MM", in `timezone`); after it the earliest bookable day
    | is tomorrow and the storefront shows a "call us" prompt for same-day help.
    | `min_lead_minutes` drops same-day slots that start too soon after "now".
    | See App\Services\Bookings\SameDayCutoffPolicy.
    |
    */

    'timezone' => env('BOOKINGS_TIMEZONE', 'Australia/Melbourne'),

    /*
    |--------------------------------------------------------------------------
    | Fixed slot start times
    |--------------------------------------------------------------------------
    |
    | The only start times customers are offered (24h "HH:MM", comma-separated
    | in the env var). A start is offered on a day when a technician's shift
    | (and the zone's operating hours) has room for the whole job from that
    | time, so the shift must run past the last start by the job length - e.g.
    | 09:00-21:00 for an 18:00 slot. Empty means the old behaviour: every
    | 15 minutes the shift can fit. See App\Services\Bookings\SlotComputationService.
    |
    */

    'slot_start_times' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('BOOKINGS_SLOT_START_TIMES', '09:00,12:00,15:00,18:00')),
    ))),

    'same_day' => [
        'cutoff' => env('BOOKINGS_SAME_DAY_CUTOFF', '09:00'),
        'min_lead_minutes' => (int) env('BOOKINGS_SAME_DAY_MIN_LEAD_MINUTES', 60),
    ],

];
