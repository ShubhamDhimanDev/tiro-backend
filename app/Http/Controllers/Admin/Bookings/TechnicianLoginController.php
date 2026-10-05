<?php

namespace App\Http\Controllers\Admin\Bookings;

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Bookings\CreateTechnicianLoginRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\RoleAssignmentService;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Provisions the explicit "create login" step for a {@see Technician} whose
 * `user_id` is still null — see
 * docs/architecture/07-admin-auth-permissions.md §5. Mirrors
 * {@see UserController::store()} exactly (random
 * unusable password + Fortify's reset-link-as-invite, role assigned via
 * {@see RoleAssignmentService} so the change gets one atomic `AuditLog` row)
 * rather than a second, divergent provisioning path — the only difference is
 * the role is fixed to `technician` and the resulting `User` is linked back
 * onto the `Technician` row.
 */
class TechnicianLoginController extends Controller
{
    public function __construct(
        private readonly RoleAssignmentService $roleAssignmentService,
    ) {}

    /**
     * Create a login for a technician that doesn't have one yet. A
     * technician who already has `user_id` set is a 409 — this endpoint
     * never overwrites or duplicates an existing login.
     */
    public function store(CreateTechnicianLoginRequest $request, Technician $technician): RedirectResponse
    {
        if ($technician->user_id !== null) {
            abort(409, __('This technician already has a login.'));
        }

        $user = User::create([
            'name' => $technician->name,
            'email' => $request->validated('email'),
            'password' => Str::password(40),
        ]);

        $this->roleAssignmentService->syncRoles($user, ['technician'], AdminGuard::user($request));

        $technician->update(['user_id' => $user->id]);

        Password::sendResetLink(['email' => $user->email]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Login created and invite email sent.')]);

        return back();
    }
}
