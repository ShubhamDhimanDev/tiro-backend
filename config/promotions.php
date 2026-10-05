<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Price-Guarantee Claim Redemption Window
    |--------------------------------------------------------------------------
    |
    | How many days a pre-purchase (order_id null) approved
    | PriceGuaranteeClaim stays usable (PriceGuaranteeClaim.expires_at, set
    | at approval time) before the customer must place their order — see
    | docs/architecture/05-promotions-pricing.md's "Price-guarantee claim
    | workflow" section: "recommend 30 days, a config value not a hard-coded
    | constant."
    |
    */

    'price_guarantee_redemption_days' => (int) env('PRICE_GUARANTEE_REDEMPTION_DAYS', 30),

];
