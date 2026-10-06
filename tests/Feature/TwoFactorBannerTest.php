<?php

use App\Models\User;

test('a user without 2FA or a passkey is not redirected and is flagged for the setup banner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('twoFactorSetupRequired', true));
});

test('a user with confirmed TOTP two-factor is not flagged', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('twoFactorSetupRequired', false));
});

test('a user with a registered passkey but no confirmed TOTP is not flagged', function () {
    $user = User::factory()->create();
    $user->passkeys()->create([
        'name' => 'Work laptop',
        'credential_id' => 'test-credential-id',
        'credential' => ['id' => 'test-credential-id'],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('twoFactorSetupRequired', false));
});

test('guests are still sent to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
