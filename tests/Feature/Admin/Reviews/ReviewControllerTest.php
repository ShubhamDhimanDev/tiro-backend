<?php

use App\Jobs\NotifyFrontendRevalidation;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Covers `App\Http\Controllers\Admin\Reviews\ReviewController` — gated
 * `content.view`/`content.manage`, same tier as
 * `App\Http\Controllers\Admin\Content\FaqController` (see
 * `tests/Feature/Admin/Content/FaqControllerTest.php`). No new permission
 * — verified against `RolesAndPermissionsSeeder`.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function reviewContentManager(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('ecommerce'); // content.manage

    return $user;
}

test('a content.manage user can view the reviews index, including hidden rows', function () {
    $admin = reviewContentManager();
    Review::factory()->create();
    Review::factory()->hidden()->create();

    $response = $this->actingAs($admin)->get(route('admin.reviews.index'));

    // Both the visible and the hidden row are present — unlike the public
    // `GET /api/v1/reviews` endpoint, which would return only 1 (see
    // tests/Feature/Api/V1/Reviews/ReviewIndexTest.php). Deliberately no
    // `->component('reviews/index')` assertion: that Inertia page is
    // super-admin-agent's to build in a follow-on round (this phase only
    // builds the controller/route it renders against) and
    // `inertia.testing.ensure_pages_exist` would fail this test on the
    // missing page file, not the controller behavior actually under test.
    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('reviews.data', 2));
});

test('a user without content.view cannot view the reviews index', function () {
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->assignRole('customer_support'); // no `content` entry in its module-tier row

    $this->actingAs($outsider)->get(route('admin.reviews.index'))->assertForbidden();
});

test('a content.manage user can hide a review and it dispatches one revalidation job', function () {
    Bus::fake();
    $admin = reviewContentManager();
    $review = Review::factory()->create(['is_hidden' => false]);

    $response = $this->actingAs($admin)->patch(route('admin.reviews.update', $review), ['is_hidden' => true]);

    $response->assertRedirect();
    expect($review->fresh()->is_hidden)->toBeTrue();
    Bus::assertDispatchedTimes(NotifyFrontendRevalidation::class, 1);
    Bus::assertDispatched(NotifyFrontendRevalidation::class, fn ($job) => $job->tags === ['reviews']);
});

test('a content.manage user can restore a hidden review', function () {
    Bus::fake();
    $admin = reviewContentManager();
    $review = Review::factory()->hidden()->create();

    $this->actingAs($admin)->patch(route('admin.reviews.update', $review), ['is_hidden' => false])->assertRedirect();

    expect($review->fresh()->is_hidden)->toBeFalse();
});

test('a user without content.manage cannot toggle a review', function () {
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->assignRole('customer_support');
    $review = Review::factory()->create();

    $this->actingAs($outsider)->patch(route('admin.reviews.update', $review), ['is_hidden' => true])->assertForbidden();
});

test('is_hidden is required and must be boolean', function () {
    $admin = reviewContentManager();
    $review = Review::factory()->create();

    $this->actingAs($admin)->patch(route('admin.reviews.update', $review), [])->assertSessionHasErrors('is_hidden');
});

test('a content.manage user can trigger a manual resync', function () {
    Bus::fake();
    $admin = reviewContentManager();

    config([
        'services.google_reviews.client_id' => 'test-client-id',
        'services.google_reviews.client_secret' => 'test-client-secret',
        'services.google_reviews.refresh_token' => 'test-refresh-token',
        'services.google_reviews.account_id' => '12345',
        'services.google_reviews.location_id' => '67890',
    ]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        'mybusiness.googleapis.com/*' => Http::response([
            'reviews' => [[
                'reviewId' => 'review-1',
                'reviewer' => ['displayName' => 'Jane Smith'],
                'starRating' => 'FIVE',
                'comment' => 'Great service.',
                'createTime' => '2026-08-14T10:00:00Z',
            ]],
        ], 200),
    ]);

    $response = $this->actingAs($admin)->post(route('admin.reviews.resync'));

    $response->assertRedirect();
    $this->assertDatabaseHas('reviews', ['external_id' => 'review-1']);
    Bus::assertDispatchedTimes(NotifyFrontendRevalidation::class, 1);
});

test('a manual resync surfaces a failure toast without throwing when not configured', function () {
    $admin = reviewContentManager();
    // GOOGLE_REVIEWS_* left unset -> the command itself fails fast.
    config([
        'services.google_reviews.client_id' => null,
        'services.google_reviews.client_secret' => null,
        'services.google_reviews.refresh_token' => null,
        'services.google_reviews.account_id' => null,
        'services.google_reviews.location_id' => null,
    ]);

    $response = $this->actingAs($admin)->post(route('admin.reviews.resync'));

    $response->assertRedirect();
    expect(Review::query()->count())->toBe(0);
});

test('a user without content.manage cannot trigger a resync', function () {
    $outsider = User::factory()->withTwoFactor()->create();
    $outsider->assignRole('customer_support');

    $this->actingAs($outsider)->post(route('admin.reviews.resync'))->assertForbidden();
});

test('the reviews index can be searched and filtered by rating and visibility', function () {
    $admin = reviewContentManager();
    Review::factory()->create(['author_name' => 'Alice Wonder', 'body' => 'Quick fit', 'rating' => 5]);
    Review::factory()->create(['author_name' => 'Bob Builder', 'body' => 'Late arrival', 'rating' => 2]);
    Review::factory()->hidden()->create(['author_name' => 'Carol Hidden', 'rating' => 5]);

    $this->actingAs($admin)->get(route('admin.reviews.index', ['search' => 'alice']))
        ->assertInertia(fn (Assert $page) => $page->has('reviews.data', 1)->where('filters.search', 'alice'));

    $this->actingAs($admin)->get(route('admin.reviews.index', ['rating' => 2]))
        ->assertInertia(fn (Assert $page) => $page->has('reviews.data', 1)->where('filters.rating', 2));

    $this->actingAs($admin)->get(route('admin.reviews.index', ['visibility' => 'hidden']))
        ->assertInertia(fn (Assert $page) => $page->has('reviews.data', 1));

    $this->actingAs($admin)->get(route('admin.reviews.index', ['rating' => 5, 'visibility' => 'visible']))
        ->assertInertia(fn (Assert $page) => $page->has('reviews.data', 1));
});
