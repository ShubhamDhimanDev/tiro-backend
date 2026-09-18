<?php

namespace App\Http\Requests\Admin\Products;

use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Enums\TyreConstruction;
use App\Enums\TyreType;
use App\Models\TyreModel;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see TyreModel}. `brand_id` is
 * only accepted on create (via the `{brand}` route segment, not the request
 * body) — updates never move a model to a different brand from this form.
 */
class TyreModelRequest extends FormRequest
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
        $tyreModel = $this->route('tyreModel');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('tyre_models', 'slug')->ignore($tyreModel),
            ],
            'category' => ['required', Rule::enum(TyreCategory::class)],
            'tyre_type' => ['required', Rule::enum(TyreType::class)],
            'construction' => ['required', Rule::enum(TyreConstruction::class)],
            'run_flat' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
            'warranty_text' => ['nullable', 'string'],
            'warranty_km' => ['nullable', 'integer', 'min:0'],
            'service_inclusions' => ['nullable', 'array'],
            'service_inclusions.*' => ['string', 'max:255'],
            'released_at' => ['nullable', 'date'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:2048'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }
}
