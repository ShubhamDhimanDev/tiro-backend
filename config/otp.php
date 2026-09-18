<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OTP Code HMAC Key
    |--------------------------------------------------------------------------
    |
    | Dedicated signing key for hashing customer email-OTP codes (see
    | App\Models\EmailOtpChallenge::hashCode()). Deliberately kept separate
    | from APP_KEY — APP_KEY is shared with sessions, cookies, and signed
    | URLs, so reusing it here would give a single-purpose OTP hash a much
    | larger blast radius than it needs. Generate a random value per
    | environment; see .env.example.
    |
    */

    'hmac_key' => env('OTP_HMAC_KEY'),

];
