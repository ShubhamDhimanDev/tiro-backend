<?php

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

test('the dashboard lives at /admin/dashboard and the old /dashboard URL redirects to it', function () {
    expect(route('dashboard', absolute: false))->toBe('/admin/dashboard');

    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get('/dashboard')
        ->assertRedirect('/admin/dashboard');
});

test('a user with no module permissions gets an empty dashboard', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('sales', null)
            ->where('orders', null)
            ->where('bookings', null)
            ->where('inventory', null));
});

test('a super admin sees every dashboard block populated from live data', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withTwoFactor()->create();
    $admin->assignRole('super_admin');

    Customer::factory()->count(2)->create();
    Order::factory()->create(['status' => OrderStatus::PendingPayment, 'placed_at' => now()]);

    $this->actingAs($admin)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('customers.total', fn ($total) => $total >= 2)
            ->where('orders.awaiting_payment', 1)
            ->has('sales.daily', 14)
            ->has('bookings.today')
            ->has('inventory.low_stock')
            ->has('catalogue')
            ->has('promotions')
            ->has('reviews'));
});

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
