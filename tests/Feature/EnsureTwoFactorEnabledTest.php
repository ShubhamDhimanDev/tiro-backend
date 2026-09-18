<?php

use App\Models\User;

test('a user with neither confirmed 2FA nor a passkey is redirected to the security settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));
});

test('a user with confirmed TOTP two-factor can reach the dashboard', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('a user with a registered passkey but no confirmed TOTP can reach the dashboard', function () {
    $user = User::factory()->create();
    $user->passkeys()->create([
        'name' => 'Work laptop',
        'credential_id' => 'test-credential-id',
        'credential' => ['id' => 'test-credential-id'],
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('the security settings page itself stays reachable even without 2FA satisfied, to avoid a redirect loop', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();
});

test('guests are unaffected by the two-factor gate', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
