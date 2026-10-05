<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    /**
     * Create (or refresh) the initial super admin from `business.super_admin`
     * config (SUPER_ADMIN_* env vars). Does nothing when email or password is
     * unset. Requires the `super_admin` role, so it runs after
     * RolesAndPermissionsSeeder.
     */
    public function run(): void
    {
        $email = config('business.super_admin.email');
        $password = config('business.super_admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn('SuperAdminSeeder skipped: set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD.');

            return;
        }

        $this->callOnce(RolesAndPermissionsSeeder::class);

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => config('business.super_admin.name'),
            'password' => $password,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $user->syncRoles('super_admin');
    }
}
