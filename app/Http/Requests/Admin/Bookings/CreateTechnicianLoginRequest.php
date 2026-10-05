<?php

namespace App\Http\Requests\Admin\Bookings;

use App\Models\Technician;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Provisions a `User` (Fortify admin-panel login) for a {@see Technician}
 * that doesn't have one yet — see
 * docs/architecture/07-admin-auth-permissions.md §5. Deliberately gated on
 * `roles-users.manage` (Super Admin only), not `bookings.manage` — this
 * creates a staff account and assigns a role, which is squarely the "Roles &
 * Users" module's concern per the permission matrix
 * (docs/architecture/07-admin-auth-permissions.md §3), even though it's
 * reached from the Technician roster screen. Flagged back to project-manager
 * as an open question: whether Operations/Fleet (who otherwise manage the
 * Technician roster) should be able to self-serve this without a Super
 * Admin, in which case this authorization boundary would need revisiting.
 */
class CreateTechnicianLoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('roles-users.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ];
    }
}
