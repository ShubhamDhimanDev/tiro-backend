<?php

namespace App\Support;

/**
 * Detects unresolved template placeholders (`[MAX_BOOKING_WINDOW_DAYS]`,
 * `[PRIVACY_EMAIL]`, `[ABN PLACEHOLDER]`, `[STATE/TERRITORY]`...) in
 * customer-visible copy so they can never be saved through the admin panel
 * or shipped by a seeder.
 */
final class ContentTokens
{
    /**
     * A bracketed run of two or more characters that is entirely upper case
     * (letters, digits, `_`, space, `/`) — never matches normal bracketed
     * prose or markdown links like `[Read more](/x)`.
     */
    public const PATTERN = '/\[(?=[A-Z0-9_ \/]*[A-Z])[A-Z][A-Z0-9_ \/]*[A-Z0-9]\]/';

    /**
     * @return list<string>
     */
    public static function find(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param  iterable<string|null>  $texts
     *
     * @throws \RuntimeException when any text still contains a placeholder token.
     */
    public static function assertNone(iterable $texts, string $context): void
    {
        foreach ($texts as $text) {
            $found = self::find($text);

            if ($found !== []) {
                throw new \RuntimeException('Unresolved placeholder token(s) '.implode(', ', $found)." in {$context}.");
            }
        }
    }
}
