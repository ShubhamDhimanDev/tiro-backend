<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Covers App\Http\Controllers\Admin\Reporting\ReportingController — gated
 * `reporting.view` (verified against RolesAndPermissionsSeeder: super_admin/
 * ecommerce/operations/fleet hold it, customer_support does not). All
 * aggregation logic itself belongs to `ReportingService` and is covered by
 * `ReportingServiceTest.php` — this file only exercises the controller's own
 * concerns: route/permission wiring, the `{dashboard}` allow-list, and the
 * shared date-range/zone-filter input it hands to the service.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reportingViewer(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('ecommerce'); // reporting.view

    return $user;
}

test('each of the 5 dashboards renders for a reporting.view user', function (string $dashboard) {
    $viewer = reportingViewer();

    $response = $this->actingAs($viewer)->get(route('admin.reporting.show', $dashboard));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('reporting/show')
        ->where('dashboard', $dashboard)
        ->has('data')
        ->has('serviceZones'));
})->with(['sales', 'bookings', 'conversion', 'cancellation', 'product-performance']);

test('an unrecognised dashboard name 404s', function () {
    $viewer = reportingViewer();

    $this->actingAs($viewer)->get(route('admin.reporting.show', 'not-a-real-dashboard'))->assertNotFound();
});

test('a user without reporting.view is forbidden', function () {
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->assignRole('customer_support'); // no `reporting` entry in its module-tier row

    $this->actingAs($outsider)->get(route('admin.reporting.show', 'sales'))->assertForbidden();
});

test('the date-range/zone filters are echoed back and default to a trailing 30-day range', function () {
    $viewer = reportingViewer();

    $response = $this->actingAs($viewer)->get(route('admin.reporting.show', 'sales'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('reporting/show')
        ->has('filters.from')
        ->has('filters.to')
        ->where('filters.service_zone_id', null));
});

test('an explicit from/to/service_zone_id query string is passed through', function () {
    $viewer = reportingViewer();

    $response = $this->actingAs($viewer)->get(route('admin.reporting.show', [
        'dashboard' => 'sales',
        'from' => '2026-01-01',
        'to' => '2026-01-31',
    ]));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.from', '2026-01-01')
        ->where('filters.to', '2026-01-31'));
});
