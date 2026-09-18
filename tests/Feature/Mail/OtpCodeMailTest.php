<?php

use App\Mail\OtpCodeMail;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Covers item 3 of the Phase 0 auth security review: `AuthController::
 * requestPasswordReset()` now always creates the challenge and queues this
 * mail regardless of whether the email belongs to an account — the
 * "should this actually get delivered" decision lives here instead, so it
 * runs in the queue worker rather than affecting the request's response
 * time.
 */
it('delivers a password-reset otp only when the recipient is an activated customer', function () {
    Customer::factory()->activated()->create(['email' => 'jane@example.com']);

    $toActivatedCustomer = (new OtpCodeMail('123456', 'password_reset'))->to('jane@example.com');
    $toUnknownEmail = (new OtpCodeMail('123456', 'password_reset'))->to('ghost@example.com');

    expect($toActivatedCustomer->shouldDeliver())->toBeTrue();
    expect($toUnknownEmail->shouldDeliver())->toBeFalse();
});

it('does not deliver a password-reset otp to an unactivated (guest-checkout) customer email', function () {
    Customer::factory()->create(['email' => 'unactivated@example.com']); // no password, no email_verified_at

    $mail = (new OtpCodeMail('123456', 'password_reset'))->to('unactivated@example.com');

    expect($mail->shouldDeliver())->toBeFalse();
});

it('does not gate delivery of registration or login otps on customer state', function () {
    $registration = (new OtpCodeMail('123456', 'registration'))->to('ghost@example.com');
    $login = (new OtpCodeMail('123456', 'login'))->to('ghost@example.com');

    expect($registration->shouldDeliver())->toBeTrue();
    expect($login->shouldDeliver())->toBeTrue();
});

/**
 * Item 14 of docs/architecture/06-open-decisions.md: `OtpCodeMail`
 * implements `ShouldBeEncrypted` so the plaintext OTP code never sits
 * readable in the `jobs` table (the default queue driver is `database`).
 * This asserts the raw `payload` column actually reflects that — not just
 * that the interface is declared.
 */
it('does not persist the plaintext otp code in the jobs table payload when queued', function () {
    config(['queue.default' => 'database']);

    Mail::to('jane@example.com')->queue(new OtpCodeMail('123456', 'registration'));

    $job = DB::table('jobs')->first();

    expect($job)->not->toBeNull();
    expect($job->payload)->not->toContain('123456');
    // An unencrypted payload would carry the Mailable's class name in the
    // serialized command; an encrypted payload is an opaque ciphertext blob.
    expect($job->payload)->not->toContain(OtpCodeMail::class);
});
