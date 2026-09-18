<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Defense in depth for docs/architecture/06-open-decisions.md item 14: even
 * with `OtpCodeMail` now `ShouldBeEncrypted`, nothing should linger forever
 * in `failed_jobs`. 48 hours is enough time to notice and manually retry a
 * genuinely failed OTP delivery in this local-dev/early-stage app, while
 * still bounding how long any (encrypted) auth-adjacent payload sits at
 * rest.
 */
Schedule::command('queue:prune-failed', ['--hours' => 48])->daily();
