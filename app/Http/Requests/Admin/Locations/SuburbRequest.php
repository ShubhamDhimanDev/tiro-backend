<?php

namespace App\Http\Requests\Admin\Locations;

use App\Models\Suburb;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see Suburb}.
 *
 * `lat`/`lng` are optional but must be given together — a radius-type zone
 * match computes distance from a suburb's centroid, so a suburb without
 * coordinates is skipped by radius resolution until they are filled in.
 */
class SuburbRequest extends FormRequest
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
        $suburb = $this->route('suburb');

        return [
            'name' => ['required', 'string', 'max:255'],
            'state_id' => ['required', Rule::exists('states', 'id')],
            'postcode' => [
                'required', 'string', 'size:4', 'regex:/^\d{4}$/',
                Rule::unique('suburbs')
                    ->where('state_id', $this->input('state_id'))
                    ->where('name', $this->input('name'))
                    ->ignore($suburb),
            ],
            'lat' => ['nullable', 'required_with:lng', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'required_with:lat', 'numeric', 'between:-180,180'],
        ];
    }
}
