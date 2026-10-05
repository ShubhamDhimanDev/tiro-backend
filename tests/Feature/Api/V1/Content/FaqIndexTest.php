<?php

use App\Enums\ContentPageType;
use App\Models\ContentPage;
use App\Models\Faq;

/**
 * `GET /api/v1/content/faqs` — see the Phase 6 task brief's "Public read
 * API" section.
 */
it('returns every published global faq when no filters are given', function () {
    $global = Faq::factory()->published()->create();
    Faq::factory()->create(); // draft, excluded
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create();
    Faq::factory()->published()->create(['content_page_id' => $page->id]); // page-scoped, excluded

    $response = $this->getJson('/api/v1/content/faqs');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.id', $global->id);
});

it('filters by category regardless of page scope', function () {
    $globalPdp = Faq::factory()->published()->create(['category' => 'pdp']);
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create();
    $scopedPdp = Faq::factory()->published()->create(['category' => 'pdp', 'content_page_id' => $page->id]);
    Faq::factory()->published()->create(['category' => 'checkout']);

    $response = $this->getJson('/api/v1/content/faqs?category=pdp');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$globalPdp->id, $scopedPdp->id])->sort()->values()->all());
});

it('filters by content_page_id', function () {
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create();
    $scoped = Faq::factory()->published()->create(['content_page_id' => $page->id]);
    Faq::factory()->published()->create(); // global, excluded

    $response = $this->getJson("/api/v1/content/faqs?content_page_id={$page->id}");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.id', $scoped->id);
});

it('combines category and content_page_id with AND', function () {
    $page = ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create();
    $match = Faq::factory()->published()->create(['category' => 'pdp', 'content_page_id' => $page->id]);
    Faq::factory()->published()->create(['category' => 'checkout', 'content_page_id' => $page->id]);
    Faq::factory()->published()->create(['category' => 'pdp']);

    $response = $this->getJson("/api/v1/content/faqs?category=pdp&content_page_id={$page->id}");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.id', $match->id);
});

it('excludes non-published faqs from every filter combination', function () {
    Faq::factory()->create(['category' => 'pdp']); // draft

    $response = $this->getJson('/api/v1/content/faqs?category=pdp');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('returns 200 with an empty array for an unmatched category, not a 404', function () {
    $response = $this->getJson('/api/v1/content/faqs?category=does-not-exist');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('returns 200 with an empty array for a content_page_id with no faqs, not a 404', function () {
    $response = $this->getJson('/api/v1/content/faqs?content_page_id=999999');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});
