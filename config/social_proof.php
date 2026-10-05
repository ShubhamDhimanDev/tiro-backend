<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kill Switch
    |--------------------------------------------------------------------------
    |
    | When false, `GET /api/v1/social-proof/recent-orders` returns an empty
    | list without querying. Keep false until a settable consent mechanism
    | exists (docs/architecture/10-r2-integration-decisions.md, R2-4).
    |
    */

    'enabled' => (bool) env('SOCIAL_PROOF_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Minimum Qualifying Orders
    |--------------------------------------------------------------------------
    |
    | `GET /api/v1/social-proof/recent-orders` returns an empty list unless at
    | least this many distinct customers with qualifying orders exist, so a quiet week never
    | exposes a single identifiable customer. See
    | docs/architecture/02-api-contract.md's "Social proof: recent orders (R2)".
    |
    */

    'min_orders' => (int) env('SOCIAL_PROOF_MIN_ORDERS', 5),

];
