<?php

namespace App\Http\Requests\Admin\Locations;

use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the bulk suburb spreadsheet upload. Content checks (required
 * columns, per-row values) happen in the import service so they can be
 * reported row by row.
 */
class SuburbImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('locations.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:10240'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }
}
