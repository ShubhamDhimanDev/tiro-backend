<?php

namespace App\Http\Requests\Admin\Products;

use App\Enums\BrandTier;
use App\Enums\Status;
use App\Models\Brand;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see Brand}. Used by both
 * `store` (no route-bound brand) and `update` (`brand` route parameter
 * present, excluded from its own `slug` uniqueness check).
 */
class BrandRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Defense in depth alongside the `permission:products.manage` route
     * middleware.
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
        $brand = $this->route('brand');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'alpha_dash',
                Rule::unique('brands', 'slug')->ignore($brand),
            ],
            'logo_path' => ['nullable', 'string', 'max:2048'],
            'country_of_origin' => ['nullable', 'string', 'max:255'],
            'tier' => ['nullable', Rule::enum(BrandTier::class)],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }
}
