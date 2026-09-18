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
    }
}
