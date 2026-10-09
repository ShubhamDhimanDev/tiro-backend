<?php

test('the root sends guests to the login page via the dashboard', function () {
    $this->get(route('home'))->assertRedirect('/admin/dashboard');

    $this->followingRedirects()->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/login'));
});

test('the root takes a signed-in, verified user to the admin dashboard', function () {
    $this->actingAs(App\Models\User::factory()->create());

    $this->followingRedirects()->get(route('home'))->assertOk()->assertInertia(fn ($page) => $page->component('dashboard'));
});
