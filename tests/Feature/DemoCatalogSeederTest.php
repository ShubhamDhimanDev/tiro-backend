<?php

use App\Enums\Status;
use App\Models\Brand;
use App\Models\Promotion;
use App\Models\Review;
use App\Models\ServiceZone;
use App\Models\TechnicianShift;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\LocationSeeder;
use Illuminate\Support\Facades\DB;

/**
 * `DemoCatalogSeeder`: dev-only, idempotent, hides junk without deleting.
 */
it('seeds a tiered catalogue idempotently and hides junk instead of deleting it', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(CatalogueSeeder::class);

    $junkBrand = Brand::factory()->create(['name' => 'E2E Promo Brand 1']);
    $junkModel = TyreModel::factory()->create(['brand_id' => $junkBrand->id]);
    $junkZone = ServiceZone::factory()->create(['name' => 'E2E Promo Zone 1']);
    $junkReview = Review::factory()->create(['author_name' => 'E2E Full Review 1', 'is_hidden' => false]);

    $this->seed(DemoCatalogSeeder::class);

    $counts = fn (): array => [
        Brand::query()->count(), TyreModel::query()->count(), TyreVariant::query()->count(),
        Promotion::query()->count(), Review::query()->count(), TechnicianShift::query()->count(), DB::table('inventory_items')->count(),
    ];
    $afterFirst = $counts();

    $this->seed(DemoCatalogSeeder::class);

    expect($counts())->toBe($afterFirst);

    // Junk is hidden, not deleted.
    expect($junkBrand->fresh()->status)->toBe(Status::Inactive)
        ->and($junkModel->fresh())->not->toBeNull()
        ->and($junkZone->fresh()->status)->toBe(Status::Inactive)
        ->and($junkReview->fresh()->is_hidden)->toBeTrue();

    // 12 sizes x 3 tiers x >= 3 patterns.
    $rows = DB::table('tyre_variants as v')
        ->join('tyre_models as m', 'm.id', '=', 'v.tyre_model_id')
        ->join('brands as b', 'b.id', '=', 'm.brand_id')
        ->where('v.status', 'active')->where('m.status', 'active')->where('b.status', 'active')
        ->whereIn(DB::raw("CONCAT(v.width, '/', v.profile, 'R', v.rim_diameter)"), ['205/55R16', '205/65R18', '225/45R17'])
        ->selectRaw("CONCAT(v.width, '/', v.profile, 'R', v.rim_diameter) as size, b.tier, COUNT(DISTINCT m.id) as patterns")
        ->groupBy('size', 'b.tier')->get();

    expect($rows)->toHaveCount(9)->and($rows->min('patterns'))->toBeGreaterThanOrEqual(3);

    // Public offers incl. an auto-applied 4 for 3, and slots for tomorrow-onwards.
    expect(Promotion::query()->where('is_public', true)->whereNull('code')->where('type', 'four_for_three')->exists())->toBeTrue()
        ->and(Promotion::query()->where('code', 'WELCOME10')->exists())->toBeTrue()
        ->and(TechnicianShift::query()->where('date', '>', now()->toDateString())->exists())->toBeTrue();

    // Public endpoints serve the demo data.
    $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(5, 'data');
    $this->getJson('/api/v1/brands')->assertOk();
    expect($this->getJson('/api/v1/tyres?width=205&profile=55&rim_diameter=16&per_page=100')->json('meta.total'))->toBeGreaterThanOrEqual(9);
}, 300);

it('refuses to run in production', function () {
    $this->seed(LocationSeeder::class);
    $this->app['env'] = 'production';

    $brand = Brand::factory()->create(['name' => 'E2E Production Brand']);
    expect(fn () => (new DemoCatalogSeeder)->run())->toThrow(RuntimeException::class, 'refuses to run in production');

    expect($brand->fresh()->status)->toBe(Status::Active)
        ->and(TyreVariant::query()->count())->toBe(0);
});
