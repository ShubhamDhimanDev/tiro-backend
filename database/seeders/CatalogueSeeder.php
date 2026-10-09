<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Enums\TyreConstruction;
use App\Enums\TyreSidewall;
use App\Enums\TyreType;
use App\Models\Brand;
use App\Models\InventoryItem;
use App\Models\PopularSize;
use App\Models\StockLocation;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * TEST FIXTURE: not run by {@see DatabaseSeeder} (that imports the real
 * catalogue via {@see CatalogImportSeeder}); the feature tests seed this
 * directly for a small, deterministic catalogue.
 *
 * Deliberately small, dev/test-only catalogue seed data — enough brand/
 * type/size variety to exercise search later, not a production catalogue
 * import. Covers every {@see TyreCategory} and {@see TyreType} at least
 * once, a non-Standard {@see TyreSidewall} (Commercial), and staggered
 * `released_at` dates so "latest releases" sorting has something real to
 * sort. Depends on {@see LocationSeeder} having already created the
 * `stock_locations` rows this seeder stocks.
 */
class CatalogueSeeder extends Seeder
{
    /**
     * Seed the catalogue domain.
     */
    public function run(): void
    {
        $stockLocations = StockLocation::query()->get();

        $catalogue = [
            [
                'brand' => ['name' => 'Bridgestone', 'country_of_origin' => 'Japan', 'tier' => 'premium'],
                'models' => [
                    [
                        'name' => 'Turanza T005', 'category' => TyreCategory::Car, 'tyre_type' => TyreType::Highway,
                        'run_flat' => false, 'released_at_months_ago' => 6,
                        'variants' => [
                            ['width' => 205, 'profile' => 55, 'rim_diameter' => 16, 'load_index' => '91', 'speed_rating' => 'V', 'base_price' => 18900],
                            ['width' => 225, 'profile' => 45, 'rim_diameter' => 17, 'load_index' => '94', 'speed_rating' => 'W', 'base_price' => 22900],
                        ],
                    ],
                    [
                        'name' => 'Dueler A/T 001', 'category' => TyreCategory::Suv, 'tyre_type' => TyreType::AllTerrain,
                        'run_flat' => false, 'released_at_months_ago' => 18,
                        'variants' => [
                            ['width' => 235, 'profile' => 60, 'rim_diameter' => 18, 'load_index' => '107', 'speed_rating' => 'T', 'base_price' => 26900],
                            ['width' => 265, 'profile' => 65, 'rim_diameter' => 17, 'load_index' => '112', 'speed_rating' => 'T', 'base_price' => 29900],
                        ],
                    ],
                ],
            ],
            [
                'brand' => ['name' => 'Michelin', 'country_of_origin' => 'France', 'tier' => 'premium'],
                'models' => [
                    [
                        'name' => 'Primacy 4', 'category' => TyreCategory::Car, 'tyre_type' => TyreType::Highway,
                        'run_flat' => false, 'released_at_months_ago' => 3,
                        'variants' => [
                            ['width' => 195, 'profile' => 65, 'rim_diameter' => 15, 'load_index' => '91', 'speed_rating' => 'H', 'base_price' => 17900],
                            ['width' => 215, 'profile' => 55, 'rim_diameter' => 17, 'load_index' => '94', 'speed_rating' => 'V', 'base_price' => 23900],
                        ],
                    ],
                    [
                        'name' => 'Pilot Sport 4', 'category' => TyreCategory::Car, 'tyre_type' => TyreType::Performance,
                        'run_flat' => false, 'released_at_months_ago' => 24,
                        'variants' => [
                            ['width' => 225, 'profile' => 40, 'rim_diameter' => 18, 'load_index' => '92', 'speed_rating' => 'Y', 'base_price' => 27900],
                            ['width' => 245, 'profile' => 35, 'rim_diameter' => 19, 'load_index' => '93', 'speed_rating' => 'Y', 'base_price' => 32900],
                        ],
                    ],
                ],
            ],
            [
                'brand' => ['name' => 'Goodyear', 'country_of_origin' => 'USA', 'tier' => 'premium'],
                'models' => [
                    [
                        'name' => 'Wrangler Territory', 'category' => TyreCategory::FourByFour, 'tyre_type' => TyreType::AllTerrain,
                        'run_flat' => false, 'released_at_months_ago' => 9,
                        'variants' => [
                            ['width' => 265, 'profile' => 70, 'rim_diameter' => 17, 'load_index' => '115', 'speed_rating' => 'S', 'base_price' => 31900],
                            ['width' => 245, 'profile' => 70, 'rim_diameter' => 16, 'load_index' => '111', 'speed_rating' => 'T', 'base_price' => 28900],
                        ],
                    ],
                    [
                        'name' => 'EfficientGrip Performance', 'category' => TyreCategory::Car, 'tyre_type' => TyreType::Eco,
                        'run_flat' => false, 'released_at_months_ago' => 15,
                        'variants' => [
                            ['width' => 195, 'profile' => 55, 'rim_diameter' => 16, 'load_index' => '87', 'speed_rating' => 'V', 'base_price' => 16900],
                            ['width' => 205, 'profile' => 60, 'rim_diameter' => 16, 'load_index' => '92', 'speed_rating' => 'H', 'base_price' => 17900],
                        ],
                    ],
                ],
            ],
            // A 4th brand, added specifically to give LightTruck/MudTerrain
            // (otherwise unrepresented in this catalogue) and a non-Standard
            // sidewall marking real products to search/filter against.
            [
                'brand' => ['name' => 'Kumho', 'country_of_origin' => 'South Korea', 'tier' => 'mid'],
                'models' => [
                    [
                        'name' => 'Road Venture MT51', 'category' => TyreCategory::LightTruck, 'tyre_type' => TyreType::MudTerrain,
                        'run_flat' => false, 'released_at_months_ago' => 1,
                        'variants' => [
                            ['width' => 265, 'profile' => 70, 'rim_diameter' => 17, 'load_index' => '121', 'speed_rating' => 'Q', 'base_price' => 34900, 'sidewall' => TyreSidewall::Commercial],
                            ['width' => 285, 'profile' => 75, 'rim_diameter' => 16, 'load_index' => '126', 'speed_rating' => 'Q', 'base_price' => 36900, 'sidewall' => TyreSidewall::Commercial],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($catalogue as $brandData) {
            $brand = Brand::query()->create([
                'name' => $brandData['brand']['name'],
                'slug' => Str::slug($brandData['brand']['name']),
                'logo_path' => null,
                'country_of_origin' => $brandData['brand']['country_of_origin'],
                'tier' => $brandData['brand']['tier'] ?? null,
                'status' => Status::Active,
            ]);

            foreach ($brandData['models'] as $modelData) {
                $tyreModel = TyreModel::query()->create([
                    'brand_id' => $brand->id,
                    'name' => $modelData['name'],
                    'slug' => Str::slug($brand->name.' '.$modelData['name']),
                    'category' => $modelData['category'],
                    'tyre_type' => $modelData['tyre_type'],
                    'construction' => TyreConstruction::Radial,
                    'run_flat' => $modelData['run_flat'],
                    'description' => "The {$brand->name} {$modelData['name']} is a ".str_replace('_', ' ', $modelData['tyre_type']->value).' tyre for '.str_replace('_', ' ', $modelData['category']->value).' vehicles, supplied and fitted at your door.',
                    'warranty_text' => 'Manufacturer warranty applies. See warranty terms for full conditions.',
                    'warranty_km' => 80000,
                    'service_inclusions' => ['Fitting', 'Computer balancing', 'New valves', 'Old tyre disposal (where applicable)'],
                    'released_at' => now()->subMonths($modelData['released_at_months_ago']),
                    'images' => [],
                    'status' => Status::Active,
                ]);

                foreach ($modelData['variants'] as $variantData) {
                    // DatabaseSeeder runs under WithoutModelEvents, which
                    // suppresses TyreVariant's `creating` hook — generate
                    // the slug explicitly here via the model's own
                    // collision-handling algorithm rather than duplicating
                    // it or leaving `slug` unset.
                    $unsavedVariant = new TyreVariant([
                        'tyre_model_id' => $tyreModel->id,
                        'width' => $variantData['width'],
                        'profile' => $variantData['profile'],
                        'rim_diameter' => $variantData['rim_diameter'],
                        'load_index' => $variantData['load_index'],
                        'speed_rating' => $variantData['speed_rating'],
                    ]);
                    $unsavedVariant->setRelation('tyreModel', $tyreModel);

                    $variant = TyreVariant::query()->create([
                        'tyre_model_id' => $tyreModel->id,
                        'sku' => Str::upper(Str::slug($tyreModel->slug, '')).'-'.$variantData['width'].$variantData['profile'].$variantData['rim_diameter'],
                        'slug' => TyreVariant::generateUniqueSlug($unsavedVariant),
                        'width' => $variantData['width'],
                        'profile' => $variantData['profile'],
                        'rim_diameter' => $variantData['rim_diameter'],
                        'load_index' => $variantData['load_index'],
                        'speed_rating' => $variantData['speed_rating'],
                        'sidewall' => $variantData['sidewall'] ?? TyreSidewall::Standard,
                        'ean' => self::ean("93{$tyreModel->id}{$variantData['width']}{$variantData['profile']}{$variantData['rim_diameter']}"),
                        'weight_kg' => round(7 + ($variantData['width'] - 185) * 0.065 + ($variantData['rim_diameter'] - 14) * 0.9, 1),
                        'base_price' => $variantData['base_price'],
                        'status' => Status::Active,
                    ]);

                    foreach ($stockLocations as $stockLocation) {
                        InventoryItem::query()->create([
                            'tyre_variant_id' => $variant->id,
                            'stock_location_id' => $stockLocation->id,
                            'qty_on_hand' => fake()->numberBetween(5, 30),
                            'qty_reserved' => 0,
                            'reorder_point' => 3,
                        ]);
                    }
                }
            }
        }

        // Popular sizes deliberately match sizes actually seeded above, so
        // a later "popular size" deep link resolves to real products.
        collect([
            ['width' => 205, 'profile' => 55, 'rim_diameter' => 16, 'sort_order' => 1],
            ['width' => 225, 'profile' => 45, 'rim_diameter' => 17, 'sort_order' => 2],
            ['width' => 235, 'profile' => 60, 'rim_diameter' => 18, 'sort_order' => 3],
        ])->each(fn (array $attributes) => PopularSize::query()->create([
            ...$attributes,
            'status' => Status::Active,
        ]));

        $this->command->info('Catalogue seeded: '.Brand::query()->count().' brands, '.
            TyreModel::query()->count().' models, '.TyreVariant::query()->count().' variants, '.
            InventoryItem::query()->count().' inventory rows, '.PopularSize::query()->count().' popular sizes.');
    }

    /**
     * A stable, valid EAN-13 derived from a numeric seed (zero-padded to 12
     * digits, then the standard check digit appended).
     */
    private static function ean(string $seed): string
    {
        $digits = str_pad(substr(preg_replace('/\D/', '', $seed), 0, 12), 12, '0', STR_PAD_LEFT);
        $sum = 0;

        foreach (str_split($digits) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 1 : 3);
        }

        return $digits.((10 - $sum % 10) % 10);
    }
}
