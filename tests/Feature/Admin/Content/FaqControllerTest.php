<?php

use App\Enums\PageStatus;
use App\Models\AuditLog;
use App\Models\ContentPage;
use App\Models\Faq;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Covers App\Http\Controllers\Admin\Content\FaqController — both global
 * (`content_page_id = null`) and page-scoped `Faq` rows share this one
 * controller, gated `content.view`/`content.manage` (same tier as
 * ContentPageControllerTest). `AuditLog` writes are handled by
 * `FaqObserver` (see FaqAuditLogObserverTest.php) — asserted here only to
 * confirm no double-log.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function faqManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('ecommerce'); // content.manage

    return $user;
}

function validFaqPayload(array $overrides = []): array
{
    return array_merge([
        'question' => 'Do you fit run-flats?',
        'answer' => 'Yes, most models.',
        'category' => null,
        'content_page_id' => null,
        'sort_order' => 0,
        'status' => PageStatus::Published->value,
    ], $overrides);
}

test('a content.manage user can view the faqs index', function () {
    $admin = faqManager();
    Faq::factory()->create();

    $response = $this->actingAs($admin)->get(route('admin.content.faqs.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('content/faqs/index'));
});

test('a content.manage user can create a global faq and it is audit-logged exactly once', function () {
    $admin = faqManager();

    $response = $this->actingAs($admin)->post(route('admin.content.faqs.store'), validFaqPayload());

    $response->assertRedirect();
    $this->assertDatabaseHas('faqs', ['question' => 'Do you fit run-flats?', 'content_page_id' => null]);
    expect(AuditLog::query()->where('action', 'faqs.created')->where('auditable_type', Faq::class)->count())->toBe(1);
});

test('a faq can be scoped to a specific content page', function () {
    $admin = faqManager();
    $page = ContentPage::factory()->create();

    $response = $this->actingAs($admin)->post(route('admin.content.faqs.store'), validFaqPayload([
        'content_page_id' => $page->id,
    ]));

    $response->assertRedirect();
    $this->assertDatabaseHas('faqs', ['content_page_id' => $page->id]);
});

test('deleting a content page cascades to its own scoped faqs', function () {
    $admin = faqManager();
    $page = ContentPage::factory()->create();
    $faq = Faq::factory()->create(['content_page_id' => $page->id]);

    $this->actingAs($admin)->delete(route('admin.content.pages.destroy', $page))->assertRedirect();

    $this->assertModelMissing($faq);
});

test('an unresolvable content_page_id is rejected', function () {
    $admin = faqManager();

    $response = $this->actingAs($admin)->post(route('admin.content.faqs.store'), validFaqPayload([
        'content_page_id' => 999999,
    ]));

    $response->assertSessionHasErrors('content_page_id');
});

test('a user without content.manage cannot create a faq', function () {
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->assignRole('customer_support'); // no `content` entry in its module-tier row

    $this->actingAs($outsider)->post(route('admin.content.faqs.store'), validFaqPayload())->assertForbidden();
});
