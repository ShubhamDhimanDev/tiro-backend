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

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // CatalogueSeeder stocks inventory at the StockLocations LocationSeeder
        // creates, so LocationSeeder must run first.
        $this->call(LocationSeeder::class);
        $this->call(CatalogueSeeder::class);
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
