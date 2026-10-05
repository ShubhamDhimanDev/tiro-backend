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

    // Initial staff login created by SuperAdminSeeder. Skipped unless both
    // email and password are set, so no credentials live in the repo.
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],
];
