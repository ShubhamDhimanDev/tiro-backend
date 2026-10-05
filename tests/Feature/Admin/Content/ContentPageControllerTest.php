<?php

use App\Enums\ContentPageType;
use App\Enums\PageStatus;
use App\Models\AuditLog;
use App\Models\ContentPage;
use App\Models\ServiceZone;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Covers App\Http\Controllers\Admin\Content\ContentPageController — gated
 * `content.view` (index) / `content.manage` (store/update/destroy), verified
 * against RolesAndPermissionsSeeder (super_admin and ecommerce hold
 * `content.manage`, every other role holds none). `AuditLog` writes are
 * handled by `ContentPageObserver` (see ContentPageAuditLogObserverTest.php
 * for that coverage) — asserted here only to confirm the controller doesn't
 * ALSO log (no double-log), not to re-test the observer itself.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function contentManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('ecommerce'); // content.manage

    return $user;
}

function validContentPagePayload(array $overrides = []): array
{
    return array_merge([
        'type' => ContentPageType::Page->value,
        'title' => 'About us',
        'slug' => 'about-us',
        'excerpt' => null,
        'body' => '<p>Body copy.</p>',
        'featured_image_path' => null,
        'meta_title' => null,
        'meta_description' => null,
        'og_image_path' => null,
        'category' => null,
        'status' => PageStatus::Draft->value,
        'published_at' => null,
        'service_zone_id' => null,
        'promotion_id' => null,
        'sort_order' => 0,
    ], $overrides);
}

test('a content.manage user can view the pages index', function () {
    $admin = contentManager();
    ContentPage::factory()->create();

    $response = $this->actingAs($admin)->get(route('admin.content.pages.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('content/pages/index'));
});

test('a content.manage user can create a content page and it is audit-logged exactly once', function () {
    $admin = contentManager();

    $response = $this->actingAs($admin)->post(route('admin.content.pages.store'), validContentPagePayload());

    $response->assertRedirect();
    $this->assertDatabaseHas('content_pages', ['slug' => 'about-us', 'type' => ContentPageType::Page->value]);
    expect(AuditLog::query()->where('action', 'content_pages.created')->where('auditable_type', ContentPage::class)->count())->toBe(1);
});

test('the same slug is rejected within one type but allowed across two different types', function () {
    $admin = contentManager();
    ContentPage::factory()->create(['type' => ContentPageType::Page, 'slug' => 'welcome']);

    $sameType = $this->actingAs($admin)->post(route('admin.content.pages.store'), validContentPagePayload(['slug' => 'welcome']));
    $sameType->assertSessionHasErrors('slug');

    $differentType = $this->actingAs($admin)->post(route('admin.content.pages.store'), validContentPagePayload([
        'type' => ContentPageType::Guide->value,
        'slug' => 'welcome',
    ]));
    $differentType->assertSessionDoesntHaveErrors('slug');
});

test('a content.manage user can update a content page and it is audit-logged', function () {
    $admin = contentManager();
    $page = ContentPage::factory()->create(['title' => 'Old title']);

    $response = $this->actingAs($admin)->put(route('admin.content.pages.update', $page), validContentPagePayload([
        'slug' => $page->slug,
        'title' => 'New title',
    ]));

    $response->assertRedirect();
    expect($page->refresh()->title)->toBe('New title');
    expect(AuditLog::query()->where('action', 'content_pages.updated')->where('auditable_type', ContentPage::class)->count())->toBe(1);
});

test('a content.manage user can delete a content page and it is audit-logged', function () {
    $admin = contentManager();
    $page = ContentPage::factory()->create();

    $response = $this->actingAs($admin)->delete(route('admin.content.pages.destroy', $page));

    $response->assertRedirect();
    $this->assertModelMissing($page);
    expect(AuditLog::query()->where('action', 'content_pages.deleted')->where('auditable_type', ContentPage::class)->count())->toBe(1);
});

test('a user without any content permission is forbidden from the index and from creating', function () {
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->assignRole('operations'); // no `content` entry in its module-tier row

    $this->actingAs($outsider)->get(route('admin.content.pages.index'))->assertForbidden();
    $this->actingAs($outsider)->post(route('admin.content.pages.store'), validContentPagePayload())->assertForbidden();
});

test('service_zone_id accepts a valid linked service zone for a location page', function () {
    $admin = contentManager();
    $zone = ServiceZone::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.content.pages.store'), validContentPagePayload([
        'type' => ContentPageType::LocationPage->value,
        'slug' => 'sydney-cbd',
        'service_zone_id' => $zone->id,
    ]));

    $response->assertRedirect();
    $this->assertDatabaseHas('content_pages', ['slug' => 'sydney-cbd', 'service_zone_id' => $zone->id]);
});

test('an unresolvable service_zone_id is rejected', function () {
    $admin = contentManager();

    $response = $this->actingAs($admin)->post(route('admin.content.pages.store'), validContentPagePayload([
        'type' => ContentPageType::LocationPage->value,
        'slug' => 'unresolvable-zone',
        'service_zone_id' => 999999,
    ]));

    $response->assertSessionHasErrors('service_zone_id');
});
