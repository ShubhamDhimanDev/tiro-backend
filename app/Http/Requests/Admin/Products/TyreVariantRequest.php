<?php

namespace App\Http\Requests\Admin\Products;

use App\Enums\Status;
use App\Enums\TyreSidewall;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see TyreVariant}.
 * `tyre_model_id` is only accepted on create (via the `{tyreModel}` route
 * segment) — a variant never moves to a different model from this form.
 *
 * `base_price` is stored in whole cents (matches the `unsignedInteger`
 * column) — the admin UI is responsible for the dollars<->cents conversion
 * before submitting, this request validates the wire value as-is.
 */
class TyreVariantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('products.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var TyreVariant|null $variant */
        $variant = $this->route('tyreVariant');

        /** @var TyreModel $tyreModel */
        $tyreModel = $this->route('tyreModel') ?? $variant?->tyreModel;

        return [
            'sku' => [
                'required', 'string', 'max:255',
                Rule::unique('tyre_variants', 'sku')->ignore($variant),
            ],
            // Required once the variant exists (edit form); optional on
            // create — a blank value there lets TyreVariant::booted()'s
            // `creating` hook auto-generate it from the model + size.
            'slug' => [
                $variant ? 'required' : 'nullable', 'string', 'max:255', 'alpha_dash',
                Rule::unique('tyre_variants', 'slug')->ignore($variant),
            ],
            'width' => ['required', 'integer', 'between:1,999'],
            'profile' => ['required', 'integer', 'between:1,999'],
            'rim_diameter' => ['required', 'integer', 'between:1,999'],
            'load_index' => ['required', 'string', 'max:10'],
            'speed_rating' => ['required', 'string', 'max:5'],
            'sidewall' => ['required', Rule::enum(TyreSidewall::class)],
            'ean' => ['nullable', 'string', 'max:32'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'base_price' => ['required', 'integer', 'min:0'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }

    /**
     * Get the "after" validation callables for the instance.
     *
     * The compound spec-uniqueness constraint (`tyre_variants_spec_unique`)
     * needs several `where()`s chained onto one `unique` check, which
     * doesn't fit `rules()`'s single-rule-per-key shape cleanly — done here
     * instead so the DB-level collision is caught with a friendly field
     * error rather than a raw `QueryException` from the unique index.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var TyreVariant|null $variant */
                $variant = $this->route('tyreVariant');

                /** @var TyreModel|null $tyreModel */
                $tyreModel = $this->route('tyreModel');
                $routeTyreModelId = $tyreModel?->id;
                $tyreModelId = $routeTyreModelId ?? $variant?->tyre_model_id;

                $exists = TyreVariant::query()
                    ->where('tyre_model_id', $tyreModelId)
                    ->where('width', $this->integer('width'))
                    ->where('profile', $this->integer('profile'))
                    ->where('rim_diameter', $this->integer('rim_diameter'))
                    ->where('load_index', $this->string('load_index'))
                    ->where('speed_rating', $this->string('speed_rating'))
                    ->when($variant, fn ($query) => $query->whereKeyNot($variant->id))
                    ->exists();

                if ($exists) {
                    $validator->errors()->add(
                        'width',
                        'A variant with this exact width/profile/rim/load-index/speed-rating already exists for this model.',
                    );
                }
            },
        ];
    }
}
