<?php

namespace App\Support;

/**
 * Turns the launch copy's bracketed template tokens into real wording.
 *
 * Values come from `config/business.php`. When the owner hasn't supplied a
 * value yet, the surrounding phrase is rewritten so it reads naturally
 * without it — customers never see a literal `[TOKEN]` (or a "draft" note).
 * Used by `LaunchContentSeeder` and the data migration that cleans rows
 * seeded before this existed.
 */
final class ContentPlaceholderResolver
{
    public static function resolve(string $html): string
    {
        $abn = self::config('abn');
        $supportEmail = self::config('support_email');
        $privacyEmail = self::config('privacy_email') ?? $supportEmail;
        $phone = self::config('support_phone');
        $hours = self::config('support_hours') ?? '8am to 5pm';
        $state = self::config('governing_state') ?? 'Victoria';

        // The trailing "operational draft" legal footer line.
        $html = preg_replace(
            '~<p><em>Tiro Mobile Tyres\s*[—-]\s*ABN \[ABN PLACEHOLDER\]\..*?</em></p>~su',
            $abn !== null ? '<p><em>Tiro Mobile Tyres — ABN '.e($abn).'.</em></p>' : '',
            $html,
        ) ?? $html;

        $contactVia = $supportEmail !== null && $phone !== null
            ? 'at '.e($supportEmail).' or '.e($phone)
            : ($supportEmail !== null ? 'at '.e($supportEmail) : ($phone !== null ? 'on '.e($phone) : 'through our Contact page'));

        $contactBlock = '';
        if ($supportEmail !== null) {
            $contactBlock .= '<strong>Email:</strong> '.e($supportEmail);
        }
        if ($phone !== null) {
            $contactBlock .= ($contactBlock !== '' ? '<br>' : '').'<strong>Phone:</strong> '.e($phone).' (Mon–Fri, '.e($hours).')';
        }
        $contactBlock = $contactBlock !== ''
            ? $contactBlock
            : 'Send us a message using the contact form on this page and we will reply by email.';

        $pairs = [
            '<strong>Email:</strong> [SUPPORT_EMAIL]<br><strong>Phone:</strong> [SUPPORT_PHONE] (Mon–Fri, [SUPPORT_HOURS])' => $contactBlock,
            'at [PRIVACY_EMAIL]' => $privacyEmail !== null ? 'at '.e($privacyEmail) : 'through our Contact page',
            'at [SUPPORT_EMAIL] or [SUPPORT_PHONE]' => $contactVia,
            'up to [CANCELLATION_NOTICE_HOURS] hours before your appointment at no charge' => 'before your appointment, subject to the cancellation policy shown when you book',
            ' for [WORKMANSHIP_WARRANTY_PERIOD] from the date of fitting' => ' from the date of fitting',
            'up to [MAX_BOOKING_WINDOW_DAYS] days ahead' => 'well ahead of time — the dates available to you are shown when you book',
            '[STATE/TERRITORY]' => $state,
        ];

        return strtr($html, $pairs);
    }

    private static function config(string $key): ?string
    {
        $value = config('business.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
