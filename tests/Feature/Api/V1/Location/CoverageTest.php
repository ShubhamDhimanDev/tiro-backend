<?php

use App\Enums\ContentPageType;
use App\Enums\PageStatus;
use App\Enums\Status;
use App\Models\ContentPage;
use App\Models\ServiceZone;
use App\Models\State;
use App\Models\Suburb;

/**
 * `GET /api/v1/locations` and `GET /api/v1/locations/{state}/{city}` — the
 * public state > city > suburb tree, built from existing zone/suburb data.
 */
function coverageFixture(): array
{
    $vic = State::factory()->create(['code' => 'VIC', 'name' => 'Victoria']);
    $nsw = State::factory()->create(['code' => 'NSW', 'name' => 'New South Wales']);

    $melbourne = ServiceZone::factory()->radius(-37.8136, 144.9631, 25)->create([
        'name' => 'Melbourne Metro', 'state_id' => $vic->id, 'city_name' => 'Melbourne', 'city_slug' => 'melbourne', 'priority' => 10,
    ]);
    $inCbd = Suburb::factory()->create(['name' => 'Richmond', 'state_id' => $vic->id, 'postcode' => '3121', 'lat' => -37.8230, 'lng' => 144.9986]);
    $inCbd2 = Suburb::factory()->create(['name' => 'Melbourne', 'state_id' => $vic->id, 'postcode' => '3000', 'lat' => -37.8136, 'lng' => 144.9631]);
    $farAway = Suburb::factory()->create(['name' => 'Ballarat', 'state_id' => $vic->id, 'postcode' => '3350', 'lat' => -37.5622, 'lng' => 143.8503]);

    $sydney = ServiceZone::factory()->suburbList()->create([
        'name' => 'Sydney Inner West', 'state_id' => $nsw->id, 'city_name' => 'Sydney', 'city_slug' => 'sydney',
    ]);
    $newtown = Suburb::factory()->create(['name' => 'Newtown', 'state_id' => $nsw->id, 'postcode' => '2042']);
    Suburb::factory()->create(['name' => 'Bondi', 'state_id' => $nsw->id, 'postcode' => '2026', 'lat' => -33.8908, 'lng' => 151.2743]);
    $sydney->suburbs()->attach($newtown);

    return compact('vic', 'nsw', 'melbourne', 'sydney', 'inCbd', 'inCbd2', 'farAway', 'newtown');
}

it('returns the state > city > suburbs tree with counts, slugs, postcodes and zone ids', function () {
    ['melbourne' => $melbourne, 'sydney' => $sydney] = coverageFixture();

    $response = $this->getJson('/api/v1/locations');

    $response->assertOk()->assertJsonStructure(['data' => ['*' => [
        'code', 'name', 'slug', 'city_count', 'suburb_count',
        'cities' => ['*' => ['name', 'slug', 'service_zone_id', 'service_zone_ids', 'suburb_count', 'suburbs' => ['*' => ['name', 'slug', 'postcode', 'service_zone_id']]]],
    ]]]);

    $states = collect($response->json('data'))->keyBy('slug');
    expect($states->keys()->sort()->values()->all())->toBe(['nsw', 'vic']);

    $vic = $states['vic'];
    expect($vic['city_count'])->toBe(1)->and($vic['suburb_count'])->toBe(2);
    $city = $vic['cities'][0];
    expect($city['name'])->toBe('Melbourne')
        ->and($city['slug'])->toBe('melbourne')
        ->and($city['service_zone_id'])->toBe($melbourne->id)
        ->and($city['suburb_count'])->toBe(2)
        ->and(collect($city['suburbs'])->pluck('name')->all())->toBe(['Melbourne', 'Richmond'])
        ->and($city['suburbs'][1])->toBe(['name' => 'Richmond', 'slug' => 'richmond', 'postcode' => '3121', 'service_zone_id' => $melbourne->id]);

    $sydneyCity = $states['nsw']['cities'][0];
    expect($sydneyCity['service_zone_id'])->toBe($sydney->id)
        ->and(collect($sydneyCity['suburbs'])->pluck('name')->all())->toBe(['Newtown']);
});

it('omits suburbs outside every served zone, zones without a city, inactive zones and inactive states', function () {
    coverageFixture();

    $qld = State::factory()->create(['code' => 'QLD', 'name' => 'Queensland']);
    ServiceZone::factory()->create(['name' => 'No city zone', 'state_id' => $qld->id]);
    $sa = State::factory()->create(['code' => 'SA', 'name' => 'South Australia']);
    ServiceZone::factory()->create(['name' => 'Adelaide', 'state_id' => $sa->id, 'city_name' => 'Adelaide', 'city_slug' => 'adelaide', 'status' => Status::Inactive]);
    $wa = State::factory()->create(['code' => 'WA', 'name' => 'Western Australia', 'status' => Status::Inactive]);
    ServiceZone::factory()->create(['name' => 'Perth', 'state_id' => $wa->id, 'city_name' => 'Perth', 'city_slug' => 'perth']);

    $response = $this->getJson('/api/v1/locations');

    expect(collect($response->json('data'))->pluck('slug')->sort()->values()->all())->toBe(['nsw', 'vic']);
    $allSuburbs = collect($response->json('data'))->flatMap(fn ($s) => $s['cities'])->flatMap(fn ($c) => $c['suburbs'])->pluck('name');
    expect($allSuburbs->all())->not->toContain('Ballarat')->and($allSuburbs->all())->not->toContain('Bondi');
});

it('lists only states switched on in the admin panel, and follows the toggle', function () {
    ['nsw' => $nsw] = coverageFixture();
    $slugs = fn () => collect($this->getJson('/api/v1/locations')->json('data'))->pluck('slug')->sort()->values()->all();

    expect($slugs())->toBe(['nsw', 'vic']);

    $nsw->update(['is_active' => false]);
    expect($slugs())->toBe(['vic']);

    $this->getJson('/api/v1/locations/nsw/sydney')->assertNotFound();

    $nsw->update(['is_active' => true]);
    expect($slugs())->toBe(['nsw', 'vic']);
});

it('merges several zones of one city and assigns a suburb to the same zone the serviceability check does', function () {
    ['vic' => $vic, 'melbourne' => $melbourne] = coverageFixture();

    // Smaller, higher-priority express zone at the same origin: wins CBD suburbs.
    $express = ServiceZone::factory()->radius(-37.8136, 144.9631, 5)->create([
        'name' => 'Melbourne CBD Express', 'state_id' => $vic->id, 'city_name' => 'Melbourne', 'city_slug' => 'melbourne', 'priority' => 20,
    ]);

    $city = collect($this->getJson('/api/v1/locations')->json('data'))->firstWhere('slug', 'vic')['cities'][0];

    expect($city['service_zone_ids'])->toBe([$express->id, $melbourne->id])
        ->and($city['service_zone_id'])->toBe($express->id);

    foreach ($city['suburbs'] as $suburb) {
        $resolved = $this->postJson('/api/v1/serviceability', ['postcode' => $suburb['postcode']])->json('service_zone_id');
        expect($suburb['service_zone_id'])->toBe($resolved);
    }
});

it('gives same-named suburbs in one city distinct slugs', function () {
    ['vic' => $vic] = coverageFixture();
    Suburb::factory()->create(['name' => 'Richmond', 'state_id' => $vic->id, 'postcode' => '3122', 'lat' => -37.82, 'lng' => 145.0]);

    $slugs = collect($this->getJson('/api/v1/locations')->json('data'))->firstWhere('slug', 'vic')['cities'][0]['suburbs'];

    expect(collect($slugs)->pluck('slug')->all())->toContain('richmond-3121')->toContain('richmond-3122');
});

it('returns an empty list when nothing is served', function () {
    $this->getJson('/api/v1/locations')->assertOk()->assertExactJson(['data' => []]);
});

it('returns a city with its suburbs, coverage notes and null content when there is no location page', function () {
    ['melbourne' => $melbourne] = coverageFixture();

    $response = $this->getJson('/api/v1/locations/vic/melbourne');

    $response->assertOk();
    expect($response->json('data.state'))->toBe(['code' => 'VIC', 'name' => 'Victoria', 'slug' => 'vic'])
        ->and($response->json('data.city.name'))->toBe('Melbourne')
        ->and($response->json('data.city.service_zone_id'))->toBe($melbourne->id)
        ->and($response->json('data.city.suburb_count'))->toBe(2)
        ->and($response->json('data.suburbs'))->toHaveCount(2)
        ->and($response->json('data.coverage.zones.0.name'))->toBe('Melbourne Metro')
        ->and($response->json('data.coverage.zones.0.type'))->toBe('radius')
        ->and($response->json('data.coverage.zones.0.radius_km'))->toEqual(25)
        ->and($response->json('data.coverage.notes.0'))->toContain('up to 25 km')
        ->and($response->json('data.coverage.notes'))->toContain('Closed on Sun.')
        ->and($response->json('data.content'))->toBeNull();
});

it('accepts an upper-case state code and 404s on unknown or unserved state/city pairs', function () {
    coverageFixture();

    $this->getJson('/api/v1/locations/VIC/melbourne')->assertOk();
    $this->getJson('/api/v1/locations/vic/sydney')->assertNotFound();
    $this->getJson('/api/v1/locations/xx/melbourne')->assertNotFound();
    $this->getJson('/api/v1/locations/vic/atlantis')->assertNotFound();
});

it('includes the published location_page CMS content linked to the city zone, ignoring drafts', function () {
    ['melbourne' => $melbourne, 'sydney' => $sydney] = coverageFixture();

    ContentPage::factory()->published()->ofType(ContentPageType::LocationPage)->create([
        'title' => 'Mobile tyres in Melbourne', 'slug' => 'melbourne-mobile-tyres', 'body' => '<p>We come to you.</p>',
        'service_zone_id' => $melbourne->id,
    ]);
    ContentPage::factory()->ofType(ContentPageType::LocationPage)->create([
        'title' => 'Draft Sydney', 'slug' => 'sydney', 'status' => PageStatus::Draft, 'service_zone_id' => $sydney->id,
    ]);

    $content = $this->getJson('/api/v1/locations/vic/melbourne')->json('data.content');

    expect($content['title'])->toBe('Mobile tyres in Melbourne')
        ->and($content['body'])->toBe('<p>We come to you.</p>')
        ->and($content)->toHaveKeys(['slug', 'excerpt', 'featured_image_path', 'meta_title', 'meta_description', 'updated_at']);
    expect($this->getJson('/api/v1/locations/nsw/sydney')->json('data.content'))->toBeNull();
});

it('finds location page content by city slug when it is not linked to a zone', function () {
    coverageFixture();
    ContentPage::factory()->published()->ofType(ContentPageType::LocationPage)->create(['title' => 'Melbourne page', 'slug' => 'melbourne', 'service_zone_id' => null]);

    expect($this->getJson('/api/v1/locations/vic/melbourne')->json('data.content.title'))->toBe('Melbourne page');
});
