<?php

namespace App\Http\Requests\Api\Catalogue;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/v1/tyres/price-ladders` query params: `ids` is a CSV of 1 to 24
 * tyre variant ids; `zone` is optional.
 */
class TyrePriceLaddersRequest extends FormRequest
{
    public const MAX_IDS = 24;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'string', 'max:300', function (string $attribute, mixed $value, Closure $fail): void {
                $ids = explode(',', (string) $value);

                if (count($ids) > self::MAX_IDS || array_filter($ids, fn (string $id): bool => ! ctype_digit($id)) !== []) {
                    $fail('The ids must be a comma-separated list of at most '.self::MAX_IDS.' numeric ids.');
                }
            }],
            'zone' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<int>
     */
    public function variantIds(): array
    {
        return array_values(array_unique(array_map('intval', explode(',', (string) $this->validated('ids')))));
    }
}
