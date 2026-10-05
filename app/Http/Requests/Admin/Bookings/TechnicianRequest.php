<?php

namespace App\Http\Requests\Admin\Bookings;

use App\Enums\Status;
use App\Enums\TechnicianEmploymentType;
use App\Models\Technician;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see Technician}'s own roster fields.
 * Login provisioning (`user_id`) is deliberately not writable here — see
 * {@see CreateTechnicianLoginRequest} and
 * docs/architecture/07-admin-auth-permissions.md §5's "explicit step" note.
 */
class TechnicianRequest extends FormRequest
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
        // `user_id` is deliberately absent from this rule set — Laravel's
        // FormRequest::validated() only ever returns keys covered by
        // rules(), so even if a future form submitted `user_id`, it could
        // never leak through to the controller's `Technician::create()`/
        // `update()` calls via this request.
        return [
            'name' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', Rule::enum(TechnicianEmploymentType::class)],
            'certifications' => ['nullable', 'array'],
            'certifications.*' => ['string', 'max:100'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }
}
