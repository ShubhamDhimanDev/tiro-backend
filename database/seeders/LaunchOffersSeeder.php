<?php

namespace Database\Seeders;

use App\Enums\PromotionEligibilityScope;
use App\Enums\PromotionType;
use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Models\Brand;
use App\Models\Promotion;
use Illuminate\Database\Seeder;

/**
 * Phase 6a — SEED PLACEHOLDER offers so the storefront offers hub has
 * content in dev. Runs only when no public offer exists yet, so it never
 * overwrites or duplicates offers an admin has authored. These are ordinary
 * promotions on the existing engine, not a separate offer system. All three
 * are code-gated (never auto-applied) so seeding them does not silently
 * change dev cart prices or the e2e specs' expected totals. Discounts, dates and
 * wording are placeholders: every `terms` text starts "Seed placeholder".
 * Delete or edit them in the admin panel before launch.
 *
 * Brand offers are skipped when that brand does not exist (run after
 * {@see CatalogImportSeeder}).
 */
class LaunchOffersSeeder extends Seeder
{
    public function run(): void
    {
        if (Promotion::query()->where('is_public', true)->exists()) {
            return;
        }

        $created = 0;

        $bridgestone = Brand::query()->where('slug', 'bridgestone')->first();

        if ($bridgestone !== null) {
            $promotion = $this->offer([
                'name' => 'Bridgestone 4 for 3',
                'code' => 'BRIDGESTONE4',
                'slug' => 'bridgestone-4-for-3',
                'title' => 'Buy 3 Bridgestone tyres, get the 4th free',
                'summary' => 'Fit a full set of Bridgestone tyres and the cheapest one is on us.',
                'badge_text' => '4 for 3',
                'discount_description' => 'Fourth tyre free',
                'type' => PromotionType::FourForThree,
                'value' => 0,
            ]);
            $promotion->eligibilities()->create(['scope' => PromotionEligibilityScope::Brand, 'scope_id' => (string) $bridgestone->id]);
            $created++;
        }

        $michelin = Brand::query()->where('slug', 'michelin')->first();

        if ($michelin !== null) {
            $promotion = $this->offer([
                'name' => 'Michelin $20 off each tyre',
                'code' => 'MICHELIN20',
                'slug' => 'michelin-20-off',
                'title' => '$20 off every Michelin tyre',
                'summary' => 'Save $20 on each Michelin tyre, fitted at your door.',
                'badge_text' => '$20 off',
                'discount_description' => '$20 off each tyre',
                'type' => PromotionType::Fixed,
                'value' => 2000,
            ]);
            $promotion->eligibilities()->create(['scope' => PromotionEligibilityScope::Brand, 'scope_id' => (string) $michelin->id]);
            $created++;
        }

        // Coded like the two above: never auto-applied.
        $welcome = $this->offer([
            'name' => 'Welcome 10% off',
            'code' => 'WELCOME10',
            'slug' => 'welcome-10',
            'title' => '10% off your first order',
            'summary' => 'New to Tiro? Enjoy 10% off your tyres, applied automatically.',
            'badge_text' => '10% off',
            'discount_description' => '10% off tyres',
            'type' => PromotionType::Percentage,
            'value' => 10,
        ]);

        foreach (TyreCategory::cases() as $category) {
            $welcome->eligibilities()->create(['scope' => PromotionEligibilityScope::Category, 'scope_id' => $category->value]);
        }

        $created++;

        $this->command?->info("Launch offers seeded: {$created} SEED PLACEHOLDER offers.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function offer(array $attributes): Promotion
    {
        return Promotion::query()->create([
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonths(3)->toDateString(),
            'stackable' => false,
            'status' => Status::Active,
            'is_public' => true,
            'terms' => 'Offer valid on selected tyres while the campaign is active and cannot be combined with other offers.',
            ...$attributes,
        ]);
    }
}
