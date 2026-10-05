<?php

namespace App\Http\Requests\Admin\Bookings;

use App\Enums\Status;
use App\Models\Van;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see Van} — see
 * docs/architecture/01-data-model.md's "Fleet & capacity" section.
 */
class VanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('bookings.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Van|null $van */
        $van = $this->route('van');

        return [
            'rego' => ['required', 'string', 'max:20', Rule::unique('vans', 'rego')->ignore($van)],
            'name' => ['required', 'string', 'max:255'],
            'home_stock_location_id' => ['required', Rule::exists('stock_locations', 'id')],
            'has_alignment_equipment' => ['required', 'boolean'],
            // Admin-editable per van, not a global constant — see
            // docs/architecture/01-data-model.md.
            'max_jobs_per_day' => ['required', 'integer', 'between:1,255'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }
}
