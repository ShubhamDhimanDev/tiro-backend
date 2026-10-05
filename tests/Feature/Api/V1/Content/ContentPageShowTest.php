<?php

use App\Enums\ContentPageType;
use App\Models\ContentPage;
use App\Models\Promotion;
use App\Models\ServiceZone;
use App\Models\State;

/**
 * `GET /api/v1/content/pages/{type}/{slug}` — see the Phase 6 task brief's
 * "Public read API" section.
 */
it('returns the full detail shape for a published page', function () {
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create([
        'slug' => 'how-to-check-tyre-pressure',
    ]);

    $response = $this->getJson('/api/v1/content/pages/guide/how-to-check-tyre-pressure');

    $response->assertOk()->assertJsonStructure([
        'data' => [
            'id', 'type', 'title', 'slug', 'excerpt', 'body', 'featured_image_path',
            'meta_title', 'meta_description', 'og_image_path', 'category', 'published_at',
        ],
    ]);
    $response->assertJsonPath('data.id', $page->id);
    $response->assertJsonPath('data.body', $page->body);
});

it('includes a minimal nested service_zone summary when linked', function () {
    $state = State::factory()->create();
    $zone = ServiceZone::factory()->for($state)->create(['name' => 'Inner West Sydney']);
    $page = ContentPage::factory()->ofType(ContentPageType::LocationPage)->published()->create([
        'service_zone_id' => $zone->id,
    ]);

    $response = $this->getJson("/api/v1/content/pages/location_page/{$page->slug}");

    $response->assertOk();
    $response->assertJsonPath('data.service_zone.id', $zone->id);
    $response->assertJsonPath('data.service_zone.name', 'Inner West Sydney');
    $response->assertJsonMissingPath('data.promotion');
});

it('includes a minimal nested promotion summary when linked', function () {
    $promotion = Promotion::factory()->create(['name' => '4 for 3 — Select Bridgestone']);
    $page = ContentPage::factory()->ofType(ContentPageType::PromoLanding)->published()->create([
        'promotion_id' => $promotion->id,
    ]);

    $response = $this->getJson("/api/v1/content/pages/promo_landing/{$page->slug}");

    $response->assertOk();
    $response->assertJsonPath('data.promotion.id', $promotion->id);
    $response->assertJsonPath('data.promotion.name', '4 for 3 — Select Bridgestone');
});

it('returns 404 for a slug that does not exist for that type', function () {
    $response = $this->getJson('/api/v1/content/pages/guide/does-not-exist');

    $response->assertNotFound();
});

it('returns 404 for a draft page regardless of caller', function () {
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->create(); // draft by default

    $response = $this->getJson("/api/v1/content/pages/guide/{$page->slug}");

    $response->assertNotFound();
});

it('returns 404 for a published page scheduled in the future', function () {
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->scheduled()->create();

    $response = $this->getJson("/api/v1/content/pages/guide/{$page->slug}");

    $response->assertNotFound();
});

it('returns 404 for an archived page', function () {
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->archived()->create();

    $response = $this->getJson("/api/v1/content/pages/guide/{$page->slug}");

    $response->assertNotFound();
});

it('returns 404 when the slug exists but under a different type', function () {
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create(['slug' => 'shared-slug']);

    $response = $this->getJson('/api/v1/content/pages/blog_post/shared-slug');

    $response->assertNotFound();
    expect(ContentPage::query()->where('slug', 'shared-slug')->count())->toBe(1);
});
