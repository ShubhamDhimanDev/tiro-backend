<?php

namespace App\Rules;

use App\Support\ContentTokens;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects customer-facing copy that still contains an unresolved
 * `[UPPER_CASE]` template token — see {@see ContentTokens}.
 */
class NoPlaceholderTokens implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $found = ContentTokens::find($value);

        if ($found !== []) {
            $fail('The :attribute still contains placeholder text ('.implode(', ', $found).'). Replace it with the real value.');
        }
    }
}
