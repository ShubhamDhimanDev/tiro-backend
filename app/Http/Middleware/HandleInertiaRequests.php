<?php

namespace App\Http\Middleware;

use App\Support\Auth\AdminGuard;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = AdminGuard::optionalUser($request);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email']),
                'roles' => $user?->getRoleNames(),
                'permissions' => $user?->getAllPermissions()->pluck('name'),
            ],
            // Drives the dismissible "enable 2FA" announcement bar; 2FA is
            // encouraged, not enforced, so this never blocks navigation.
            'twoFactorSetupRequired' => $user !== null && ! $user->hasSatisfiedTwoFactor(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
