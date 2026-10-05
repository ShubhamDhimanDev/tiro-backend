<?php

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\Hash;

it('creates a verified super admin from config', function () {
    config()->set('business.super_admin', ['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'secret-pass-1']);

    $this->seed(SuperAdminSeeder::class);

    $user = User::query()->where('email', 'boss@example.com')->sole();

    expect($user->name)->toBe('Boss')
        ->and($user->hasRole('super_admin'))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secret-pass-1', $user->password))->toBeTrue();
});

it('is idempotent', function () {
    config()->set('business.super_admin', ['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'secret-pass-1']);

    $this->seed(SuperAdminSeeder::class);
    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->where('email', 'boss@example.com')->count())->toBe(1);
});

it('does nothing when credentials are not configured', function () {
    config()->set('business.super_admin', ['name' => 'Boss', 'email' => null, 'password' => null]);

    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->count())->toBe(0);
});
