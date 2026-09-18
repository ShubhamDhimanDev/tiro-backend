<?php

namespace App\Http\Requests\Admin\Locations;

use App\Enums\Status;
use App\Models\State;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see State}.
 *
 * `is_active` is deliberately NOT validated/settable here — it's the actual
 * lever for turning on a new state's geography and is handled by its own
 * dedicated `toggle-active` endpoint/confirmation flow instead of being one
 * more field silently saved alongside a name/code typo fix.
 */
class StateRequest extends FormRequest
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
        $state = $this->route('state');

        return [
            'code' => [
                'required', 'string', 'max:3', 'alpha',
                Rule::unique('states', 'code')->ignore($state),
            ],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }
}
