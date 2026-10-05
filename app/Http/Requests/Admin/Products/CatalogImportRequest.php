<?php

namespace App\Http\Requests\Admin\Products;

use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the bulk product-catalog (Brand/TyreModel/TyreVariant) CSV/JSON
 * upload — mirrors `App\Http\Requests\Admin\Vehicles\VehicleFitmentImportRequest`.
 * `extensions` (not `mimes`) is used deliberately: a plain-text CSV has no
 * single canonical MIME type across browsers/OSes, so extension-based
 * checking is the more reliable gate here.
 */
class CatalogImportRequest extends FormRequest
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
        return [
            'file' => ['required', 'file', 'extensions:csv,txt,json', 'max:10240'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }
}
