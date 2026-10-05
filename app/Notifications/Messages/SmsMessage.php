<?php

namespace App\Notifications\Messages;

/**
 * The value a Notification's `toSms()` returns — mirrors Laravel's own
 * `toMail()`/`Illuminate\Mail\Mailables\Content`-ish convention (a small,
 * dedicated message value object per channel) rather than a bare string, so
 * a future field (e.g. a media URL for MMS) has somewhere to go without
 * changing every `toSms()` signature.
 */
final class SmsMessage
{
    public function __construct(public readonly string $content) {}
}
