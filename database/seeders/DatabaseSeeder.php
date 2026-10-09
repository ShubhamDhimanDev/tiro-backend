<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(SuperAdminSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(LocationSeeder::class);

        // The real catalogue, imported from the client's WooCommerce export.
        // No demo brands or products: CatalogueSeeder is a test fixture only.
        $this->call(CatalogImportSeeder::class);
        $this->call(VehicleSeeder::class);
        $this->call(DurationRuleSeeder::class);
        $this->call(CancellationPolicySeeder::class);
        $this->call(LaunchContentSeeder::class);

        // Phase 6a placeholders (launch cities, public offers) — see each
        // seeder's docblock. Offers need the catalogue's brands.
        $this->call(LaunchCitiesSeeder::class);
        $this->call(LaunchOffersSeeder::class);
    }
}
