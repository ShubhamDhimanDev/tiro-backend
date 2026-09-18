<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffUserRequest;
use App\Models\User;
use App\Services\RoleAssignmentService;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(
        private readonly RoleAssignmentService $roleAssignmentService,
    ) {}

    /**
     * Display everyone with panel access today, alongside the invite form
     * (see `resources/js/pages/users/index.tsx`). There is no separate
     * "customer" concern here — `User` is exclusively the staff/panel model
     * (customers live on their own `Customer` model), so every row is
     * relevant to this screen.
     */
    public function index(): Response
    {
        $staff = User::query()
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values()->all(),
            ]);

        return Inertia::render('users/index', [
            'staff' => $staff,
        ]);
    }

    /**
     * Provision a new staff user: create the row with an unusable random
     * password, assign a single role, then send a "set your password" reset
     * link (Fortify's existing `resetPasswords()` feature, reused as an
     * invite flow — no separate invite mechanism).
     */
    public function store(StoreStaffUserRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Str::password(40),
        ]);

        $this->roleAssignmentService->syncRoles(
            $user,
            [$request->validated('role')],
            AdminGuard::user($request),
        );

        Password::sendResetLink(['email' => $user->email]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Staff user invited.')]);

        return back();
    }
}
