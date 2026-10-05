<?php

namespace App\Http\Requests\Concerns;

use App\Enums\BrandTier;
use App\Enums\TyreCategory;
use App\Enums\TyreType;
use App\Services\Catalogue\TyreSearchFilters;
use Closure;

/**
 * Validation rules for the non-size tyre filters shared by `GET /tyres` and
 * `GET /tyres/facets` (see {@see TyreSearchFilters}). List filters accept a
 * CSV so several values can be selected at once.
 */
trait TyreFilterRules
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function tyreFilterRules(): array
    {
        return [
            'brand' => ['sometimes', 'string', 'max:300', $this->csvOfPattern('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')],
            'pattern' => ['sometimes', 'string', 'max:600', $this->csvOfPattern('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')],
            'tyre_type' => ['sometimes', 'string', $this->csvOfValues(array_column(TyreType::cases(), 'value'))],
            'category' => ['sometimes', 'string', $this->csvOfValues(array_column(TyreCategory::cases(), 'value'))],
            'tier' => ['sometimes', 'string', $this->csvOfValues(array_column(BrandTier::cases(), 'value'))],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function tyreExtraFilterRules(): array
    {
        return [
            'min_load' => ['sometimes', 'nullable', 'integer', 'between:60,130'],
            'min_speed' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', TyreSearchFilters::SPEED_ORDER)],
            'runflat' => ['sometimes', 'nullable', 'string', 'in:yes,no,1,0,true,false'],
            'price_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'price_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
            'car_make' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function csvOfValues(array $allowed): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            foreach (TyreSearchFilters::csv($value) as $item) {
                if (! in_array($item, $allowed, true)) {
                    $fail("The selected {$attribute} is invalid.");

                    return;
                }
            }
        };
    }

    private function csvOfPattern(string $pattern): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pattern): void {
            foreach (TyreSearchFilters::csv($value) as $item) {
                if (preg_match($pattern, $item) !== 1) {
                    $fail("The {$attribute} format is invalid.");

                    return;
                }
            }
        };
    }
}
