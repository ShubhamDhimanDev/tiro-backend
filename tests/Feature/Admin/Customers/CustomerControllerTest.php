<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryStatus;
use App\Models\Address;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * `GET /admin/customers` (search/list), `GET /admin/customers/{customer}`
 * (detail: profile, saved vehicles/addresses, recent order history, recent
 * notifications) — see `App\Http\Controllers\Admin\Customers\CustomerController`'s
 * docblock. Read-only module this phase, gated entirely on `customers.view`
 * (`customers.manage` implies it — see
 * `RolesAndPermissionsSeeder::permissionNamesForTier()`), so every test below
 * exercises that single permission boundary.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function customersViewOnlyUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('operations'); // customers.view only

    return $user;
}

function customersManageUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('customer_support'); // customers.manage (implies view)

    return $user;
}

function noCustomersPermissionUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('fleet'); // no `customers` module entry at all

    return $user;
}

describe('index', function () {
    it('lists customers for a user holding customers.view', function () {
        Customer::factory()->create(['name' => 'Jane Citizen']);

        $response = $this->actingAs(customersViewOnlyUser())->get(route('admin.customers.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('customers/index'));
    });

    it('lists customers for a user holding customers.manage', function () {
        Customer::factory()->create();

        $response = $this->actingAs(customersManageUser())->get(route('admin.customers.index'));

        $response->assertOk();
    });

    it('denies access to a user holding no customers permission', function () {
        $response = $this->actingAs(noCustomersPermissionUser())->get(route('admin.customers.index'));

        $response->assertForbidden();
    });

    it('searches by name, email, and mobile', function () {
        $byName = Customer::factory()->create(['name' => 'Jane Citizen', 'email' => 'jane-unique@example.com']);
        $byEmail = Customer::factory()->create(['email' => 'search-target@example.com']);
        $byMobile = Customer::factory()->create(['mobile' => '+61491234567']);
        Customer::factory()->create(['name' => 'Someone Else', 'email' => 'other@example.com', 'mobile' => '+61499999999']);

        $nameResponse = $this->actingAs(customersViewOnlyUser())->get(route('admin.customers.index', ['search' => 'Jane Citizen']));
        $nameResponse->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('customers.data.0.id', $byName->id));

        $emailResponse = $this->actingAs(customersViewOnlyUser())->get(route('admin.customers.index', ['search' => 'search-target@example.com']));
        $emailResponse->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('customers.data.0.id', $byEmail->id));

        $mobileResponse = $this->actingAs(customersViewOnlyUser())->get(route('admin.customers.index', ['search' => '+61491234567']));
        $mobileResponse->assertInertia(fn ($page) => $page->where('customers.total', 1)->where('customers.data.0.id', $byMobile->id));
    });
});

describe('show', function () {
    it('shows customer detail with profile, saved vehicles, addresses, order history and notifications', function () {
        $customer = Customer::factory()->create();
        $vehicle = CustomerVehicle::factory()->create(['customer_id' => $customer->id, 'saved_fitment' => ['all' => ['width' => 225, 'profile' => 45, 'rim_diameter' => 17]]]);
        $address = Address::factory()->create(['customer_id' => $customer->id]);
        $order = Order::factory()->confirmed()->create(['customer_id' => $customer->id]);
        $notification = NotificationLog::factory()->create([
            'notifiable_type' => Customer::class,
            'notifiable_id' => $customer->id,
            'type' => 'booking.confirmed',
            'channel' => NotificationChannel::Mail,
            'status' => NotificationDeliveryStatus::Sent,
        ]);

        $response = $this->actingAs(customersViewOnlyUser())->get(route('admin.customers.show', $customer));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('customers/show')
            ->where('customer.id', $customer->id)
            ->has('vehicles', 1)
            ->where('vehicles.0.id', $vehicle->id)
            ->has('addresses', 1)
            ->where('addresses.0.id', $address->id)
            ->has('orders', 1)
            ->where('orders.0.id', $order->id)
            ->where('ordersCount', 1)
            ->has('notifications', 1)
            ->where('notifications.0.id', $notification->id)
        );
    });

    it('shows a failed notification with its error_message', function () {
        $customer = Customer::factory()->create();
        NotificationLog::factory()->create([
            'notifiable_type' => Customer::class,
            'notifiable_id' => $customer->id,
            'status' => NotificationDeliveryStatus::Failed,
            'error_message' => 'Provider rejected recipient',
        ]);

        $response = $this->actingAs(customersViewOnlyUser())->get(route('admin.customers.show', $customer));

        $response->assertInertia(fn ($page) => $page
            ->where('notifications.0.status', 'failed')
            ->where('notifications.0.error_message', 'Provider rejected recipient')
        );
    });

    it('scopes saved vehicles, addresses, orders and notifications to this customer only', function () {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        CustomerVehicle::factory()->create(['customer_id' => $other->id]);
        Address::factory()->create(['customer_id' => $other->id]);
        Order::factory()->confirmed()->create(['customer_id' => $other->id]);
        NotificationLog::factory()->create(['notifiable_type' => Customer::class, 'notifiable_id' => $other->id]);

        $response = $this->actingAs(customersViewOnlyUser())->get(route('admin.customers.show', $customer));

        $response->assertInertia(fn ($page) => $page
            ->has('vehicles', 0)
            ->has('addresses', 0)
            ->has('orders', 0)
            ->where('ordersCount', 0)
            ->has('notifications', 0)
        );
    });

    it('denies access to a user holding no customers permission', function () {
        $customer = Customer::factory()->create();

        $response = $this->actingAs(noCustomersPermissionUser())->get(route('admin.customers.show', $customer));

        $response->assertForbidden();
    });
});
