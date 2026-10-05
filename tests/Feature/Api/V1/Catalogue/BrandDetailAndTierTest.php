<?php

use App\Enums\BrandTier;
use App\Enums\ContentPageType;
use App\Enums\Status;
use App\Models\Brand;
use App\Models\ContentPage;
use App\Models\ServiceZone;
use App\Models\TyreModel;
use App\Models\TyreVariant;

/**
 * Phase 6a catalogue metadata: `GET /api/v1/brands/{slug}`, brand `tier` on
 * brand/model/variant payloads, the result `total`, and `updated_at` on
 * content resources.
 */
it('shows one active brand with tier and active model count', function () {
    $brand = Brand::factory()->tier(BrandTier::Premium)->create(['name' => 'Bridgestone', 'slug' => 'bridgestone']);
    TyreModel::factory()->count(2)->create(['brand_id' => $brand->id]);
    TyreModel::factory()->create(['brand_id' => $brand->id, 'status' => Status::Inactive]);

    $response = $this->getJson('/api/v1/brands/bridgestone');

    $response->assertOk()->assertJsonStructure(['data' => ['id', 'name', 'slug', 'logo_path', 'country_of_origin', 'tier', 'tyre_model_count']]);
    expect($response->json('data.slug'))->toBe('bridgestone')
        ->and($response->json('data.tier'))->toBe('premium')
        ->and($response->json('data.tyre_model_count'))->toBe(2);
});

it('returns a null tier for an unclassified brand and 404 for unknown or inactive brands', function () {
    Brand::factory()->create(['slug' => 'no-tier']);
    Brand::factory()->create(['slug' => 'retired', 'status' => Status::Inactive]);

    expect($this->getJson('/api/v1/brands/no-tier')->assertOk()->json('data.tier'))->toBeNull();
    $this->getJson('/api/v1/brands/retired')->assertNotFound();
    $this->getJson('/api/v1/brands/nope')->assertNotFound();
});

it('does not confuse the brand detail route with the listing', function () {
    Brand::factory()->create(['slug' => 'kumho']);

    expect($this->getJson('/api/v1/brands')->json('data'))->toHaveCount(1);
});

it('includes tier on the brand list', function () {
    Brand::factory()->tier(BrandTier::Budget)->create();

    expect($this->getJson('/api/v1/brands')->json('data.0.tier'))->toBe('budget');
});

it('includes the brand tier on each tyre search result, top level and on the model', function () {
    $premium = Brand::factory()->tier(BrandTier::Premium)->create();
    $unranked = Brand::factory()->create();
    $a = TyreVariant::factory()->create(['tyre_model_id' => TyreModel::factory()->create(['brand_id' => $premium->id])]);
    $b = TyreVariant::factory()->create(['tyre_model_id' => TyreModel::factory()->create(['brand_id' => $unranked->id])]);

    $data = collect($this->getJson('/api/v1/tyres?per_page=100')->assertOk()->json('data'))->keyBy('id');

    expect($data[$a->id]['tier'])->toBe('premium')
        ->and($data[$a->id]['tyre_model']['tier'])->toBe('premium')
        ->and($data[$a->id]['tyre_model']['brand']['tier'])->toBe('premium')
        ->and($data[$b->id]['tier'])->toBeNull()
        ->and($data[$b->id]['tyre_model']['tier'])->toBeNull();
});

it('lets the tier travel with the zone-priced search results too', function () {
    $zone = ServiceZone::factory()->create();
    $brand = Brand::factory()->tier(BrandTier::Mid)->create();
    TyreVariant::factory()->create(['tyre_model_id' => TyreModel::factory()->create(['brand_id' => $brand->id])]);

    expect($this->getJson('/api/v1/tyres?zone='.$zone->id)->json('data.0.tier'))->toBe('mid');
});

it('reports the total number of matching tyres in meta.total, independent of the page size', function () {
    TyreVariant::factory()->count(5)->create(['width' => 205, 'profile' => 55, 'rim_diameter' => 16]);
    TyreVariant::factory()->create(['width' => 195, 'profile' => 65, 'rim_diameter' => 15]);

    $response = $this->getJson('/api/v1/tyres?width=205&profile=55&rim_diameter=16&per_page=2');

    expect($response->json('meta.total'))->toBe(5)
        ->and($response->json('data'))->toHaveCount(2)
        ->and($this->getJson('/api/v1/tyres?brand=no-such-brand')->json('meta.total'))->toBe(0);
});

it('reports front and rear totals for a staggered search', function () {
    TyreVariant::factory()->count(3)->create(['width' => 245, 'profile' => 40, 'rim_diameter' => 18]);
    TyreVariant::factory()->count(2)->create(['width' => 275, 'profile' => 35, 'rim_diameter' => 19]);

    $response = $this->getJson('/api/v1/tyres?'.http_build_query([
        'staggered' => 1, 'front_width' => 245, 'front_profile' => 40, 'front_rim_diameter' => 18,
        'rear_width' => 275, 'rear_profile' => 35, 'rear_rim_diameter' => 19,
    ]));

    expect($response->json('data.front.meta.total'))->toBe(3)->and($response->json('data.rear.meta.total'))->toBe(2);
});

it('exposes updated_at on content page summaries and details', function () {
    $page = ContentPage::factory()->ofType(ContentPageType::BlogPost)->published()->create(['slug' => 'hello']);
    $page->forceFill(['updated_at' => '2026-08-15 10:30:00'])->saveQuietly();

    $listed = $this->getJson('/api/v1/content/pages?type=blog_post')->json('data.0.updated_at');
    $detail = $this->getJson('/api/v1/content/pages/blog_post/hello')->json('data.updated_at');

    expect($listed)->toStartWith('2026-08-15T10:30:00')->and($detail)->toStartWith('2026-08-15T10:30:00');
});

it('exposes updated_at on guides too', function () {
    ContentPage::factory()->ofType(ContentPageType::Guide)->published()->create(['slug' => 'guide-1']);

    expect($this->getJson('/api/v1/content/pages/guide/guide-1')->json('data'))->toHaveKey('updated_at');
});
