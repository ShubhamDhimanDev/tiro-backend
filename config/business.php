<?php

/*
|--------------------------------------------------------------------------
| Business details used in customer-facing copy
|--------------------------------------------------------------------------
|
| Values the seeded legal/contact pages interpolate. Leave a value blank and
| the seeders fall back to wording that needs no value (never a literal
| `[PLACEHOLDER]`). The owner supplies the real values per environment.
|
*/

return [
    'abn' => env('BUSINESS_ABN'),
    'support_email' => env('BUSINESS_SUPPORT_EMAIL'),
    'privacy_email' => env('BUSINESS_PRIVACY_EMAIL'),
    'support_phone' => env('BUSINESS_SUPPORT_PHONE'),
    'support_hours' => env('BUSINESS_SUPPORT_HOURS', '8am to 5pm'),
    'governing_state' => env('BUSINESS_GOVERNING_STATE', 'Victoria'),
];
