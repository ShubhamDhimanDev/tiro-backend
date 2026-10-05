<?php

use App\Enums\ContentPageType;
use App\Models\ContentPage;

/**
 * `GET /api/v1/content/pages` — see the Phase 6 task brief's "Public read
 * API" section.
 */
it('requires the type query param', function () {
    $response = $this->getJson('/api/v1/content/pages');

    $response->assertUnprocessable()->assertJsonValidationErrors('type');
});

it('rejects an unrecognized type value', function () {
    $response = $this->getJson('/api/v1/content/pages?type=not-a-real-type');

    $response->assertUnprocessable()->assertJsonValidationErrors('type');
});

it('returns only published blog_post rows in the summary shape', function () {
    $published = ContentPage::factory()->ofType(ContentPageType::BlogPost)->published()->create();
    ContentPage::factory()->ofType(ContentPageType::BlogPost)->create(); // draft
    ContentPage::factory()->ofType(ContentPageType::BlogPost)->scheduled()->create(); // future-scheduled
    ContentPage::factory()->ofType(ContentPageType::BlogPost)->archived()->create();
    ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create(); // different type

    $response = $this->getJson('/api/v1/content/pages?type=blog_post');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.id', $published->id);
    $response->assertJsonMissingPath('data.0.body');
});

it('filters by category within the given type', function () {
    $matching = ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create(['category' => 'maintenance-tips']);
    ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create(['category' => 'buying-guides']);

    $response = $this->getJson('/api/v1/content/pages?type=guide&category=maintenance-tips');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    $response->assertJsonPath('data.0.id', $matching->id);
});

it('returns an empty page for a category with no matches, not a 404', function () {
    ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create(['category' => 'buying-guides']);

    $response = $this->getJson('/api/v1/content/pages?type=guide&category=does-not-exist');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(0);
});

it('paginates the listing', function () {
    ContentPage::factory()->ofType(ContentPageType::Page)->published()->count(3)->create();

    $response = $this->getJson('/api/v1/content/pages?type=page&per_page=2');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    $response->assertJsonPath('meta.total', 3);
});
