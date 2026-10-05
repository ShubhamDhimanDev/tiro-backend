<?php

use App\Enums\PromotionType;
use App\Enums\Status;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\PromotionEligibility;
use App\Models\ServiceZone;

/**
 * `GET /api/v1/offers` and `GET /api/v1/offers/{slug}` — public offers on top
 * of the existing promotions engine.
 */
it('lists only active, in-window, public offers with a slug', function () {
    $visible = Promotion::factory()->publicOffer('spring-sale')->create(['name' => 'Spring sale']);
    Promotion::factory()->publicOffer('private')->create(['is_public' => false]);
    Promotion::factory()->publicOffer('draft')->create(['status' => Status::Draft]);
    Promotion::factory()->publicOffer('expired')->create(['starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->subDay()->toDateString()]);
    Promotion::factory()->publicOffer('future')->create(['starts_at' => now()->addDay()->toDateString(), 'ends_at' => now()->addMonth()->toDateString()]);
    Promotion::factory()->publicOffer('used-up')->create(['usage_limit' => 5, 'usage_count' => 5]);
    Promotion::factory()->create(['name' => 'Internal auto promo']);

    $response = $this->getJson('/api/v1/offers');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('slug')->all())->toBe(['spring-sale']);
    expect($response->json('data.0.id'))->toBe($visible->id);
});

it('returns the documented offer fields, deriving badge/description from the promotion when not authored', function () {
    $brand = Brand::factory()->create(['name' => 'Bridgestone', 'slug' => 'bridgestone']);
    $promotion = Promotion::factory()->publicOffer('bridgestone-4-for-3')->fourForThree()->create([
        'name' => 'Bridgestone 4 for 3',
        'terms' => 'Terms apply.',
        'image_path' => 'offers/bridgestone.jpg',
    ]);
    PromotionEligibility::factory()->forBrand($brand->id)->create(['promotion_id' => $promotion->id]);

    $response = $this->getJson('/api/v1/offers');

    $response->assertOk()->assertJsonStructure(['data' => ['*' => [
        'id', 'slug', 'title', 'summary', 'brand' => ['name', 'slug', 'logo_path'], 'discount_description', 'badge_text',
        'code', 'starts_at', 'ends_at', 'terms', 'image_path', 'shop_filters', 'zone_ids',
    ]]]);

    $offer = $response->json('data.0');
    expect($offer['badge_text'])->toBe('4 for 3')
        ->and($offer['discount_description'])->toBe('Buy 3, get the 4th free')
        ->and($offer['brand']['slug'])->toBe('bridgestone')
        ->and($offer['shop_filters'])->toBe(['brand' => 'bridgestone'])
        ->and($offer['zone_ids'])->toBe([])
        ->and($offer['terms'])->toBe('Terms apply.')
        ->and($offer['ends_at'])->toBe($promotion->ends_at->toDateString());
});

it('prefers authored badge text and description and reports category filters', function () {
    $promotion = Promotion::factory()->publicOffer('suv-deal')->create([
        'type' => PromotionType::Fixed,
        'value' => 2500,
        'badge_text' => 'SUV special',
        'discount_description' => '$25 off each SUV tyre',
    ]);
    PromotionEligibility::factory()->forCategory('suv')->create(['promotion_id' => $promotion->id]);

    $offer = $this->getJson('/api/v1/offers/suv-deal')->assertOk()->json('data');

    expect($offer['badge_text'])->toBe('SUV special')
        ->and($offer['discount_description'])->toBe('$25 off each SUV tyre')
        ->and($offer['shop_filters'])->toBe(['category' => 'suv'])
        ->and($offer['brand'])->toBeNull();
});

it('lists the zones an offer is restricted to', function () {
    $promotion = Promotion::factory()->publicOffer('zone-deal')->create();
    $zone = ServiceZone::factory()->create();
    PromotionEligibility::factory()->forCategory('car')->forZone($zone->id)->create(['promotion_id' => $promotion->id]);

    expect($this->getJson('/api/v1/offers/zone-deal')->json('data.zone_ids'))->toBe([$zone->id]);
});

it('derives fixed and percentage wording', function () {
    Promotion::factory()->publicOffer('fixed')->fixed(2000)->create();
    Promotion::factory()->publicOffer('pct')->create(['value' => 15]);

    $bySlug = collect($this->getJson('/api/v1/offers')->json('data'))->keyBy('slug');

    expect($bySlug['fixed']['badge_text'])->toBe('$20 off')
        ->and($bySlug['fixed']['discount_description'])->toBe('$20 off each tyre')
        ->and($bySlug['pct']['badge_text'])->toBe('15% off');
});

it('never leaks internal commercial fields', function () {
    Promotion::factory()->publicOffer('x')->create(['usage_limit' => 100, 'stock_limit' => 50]);

    $offer = $this->getJson('/api/v1/offers')->json('data.0');

    expect($offer)->not->toHaveKeys(['usage_limit', 'usage_count', 'stock_limit', 'status', 'stackable', 'type', 'value']);
});

it('exposes the code for public offers that have one', function () {
    Promotion::factory()->publicOffer('coded')->withCode('save10')->create();

    expect($this->getJson('/api/v1/offers/coded')->json('data.code'))->toBe('SAVE10');
});

it('shows one offer by slug and 404s for unknown, private, or expired slugs', function () {
    Promotion::factory()->publicOffer('live')->create();
    Promotion::factory()->publicOffer('hidden')->create(['is_public' => false]);
    Promotion::factory()->publicOffer('old')->create(['starts_at' => now()->subMonth()->toDateString(), 'ends_at' => now()->subDay()->toDateString()]);

    $this->getJson('/api/v1/offers/live')->assertOk()->assertJsonPath('data.slug', 'live');
    $this->getJson('/api/v1/offers/hidden')->assertNotFound();
    $this->getJson('/api/v1/offers/old')->assertNotFound();
    $this->getJson('/api/v1/offers/nope')->assertNotFound();
});

it('is public (no auth) and returns an empty list when nothing is live', function () {
    $this->getJson('/api/v1/offers')->assertOk()->assertExactJson(['data' => []]);
});
