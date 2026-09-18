<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Enums\VehicleFitmentConfidence;
use App\Enums\VehicleFitmentPosition;
use App\Enums\VehicleFitmentSource;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Deliberately small, dev/test-only seed data for the manual vehicle
 * picker — NOT a production fitment-table import (that's
 * `php artisan fitment:import`, see docs/architecture/01-data-model.md).
 * Enough vehicles/fitments to exercise the make -> model -> year -> fitment
 * cascade manually, including one non-staggered/staggered pair sharing a
 * make+model+year (Corolla sedan vs hatch) and one genuinely staggered
 * vehicle (Commodore), same posture as `LocationSeeder`/`CatalogueSeeder`.
 */
class VehicleSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the vehicle & fitment domain.
     */
    public function run(): void
    {
        // DatabaseSeeder runs under WithoutModelEvents, which suppresses
        // Vehicle's `creating` hook — generate the slug explicitly here via
        // the model's own collision-handling algorithm, same as
        // CatalogueSeeder does for TyreVariant.
        $makeVehicle = function (array $attributes): Vehicle {
            $unsaved = new Vehicle($attributes);
            $attributes['slug'] = Vehicle::generateUniqueSlug($unsaved);
            $attributes['status'] = Status::Active;

            return Vehicle::query()->create($attributes);
        };

        $corollaSedan = $makeVehicle([
            'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'sedan',
            'year_from' => 2019, 'year_to' => 2023,
        ]);
        VehicleFitment::query()->create([
            'vehicle_id' => $corollaSedan->id, 'position' => VehicleFitmentPosition::All,
            'width' => 205, 'profile' => 55, 'rim_diameter' => 16, 'load_index' => '91', 'speed_rating' => 'V',
            'is_staggered' => false, 'source' => VehicleFitmentSource::Manual,
            'confidence' => VehicleFitmentConfidence::Confirmed, 'status' => Status::Active,
        ]);

        $corollaHatch = $makeVehicle([
            'make' => 'Toyota', 'model' => 'Corolla', 'series' => 'Ascent Sport', 'body_type' => 'hatch',
            'year_from' => 2019, 'year_to' => 2023,
        ]);
        VehicleFitment::query()->create([
            'vehicle_id' => $corollaHatch->id, 'position' => VehicleFitmentPosition::All,
            'width' => 205, 'profile' => 55, 'rim_diameter' => 16, 'load_index' => '91', 'speed_rating' => 'V',
            'is_staggered' => false, 'source' => VehicleFitmentSource::Manual,
            'confidence' => VehicleFitmentConfidence::Confirmed, 'status' => Status::Active,
        ]);

        $cx5 = $makeVehicle([
            'make' => 'Mazda', 'model' => 'CX-5', 'series' => 'Maxx', 'body_type' => 'suv',
            'year_from' => 2017, 'year_to' => 2021,
        ]);
        VehicleFitment::query()->create([
            'vehicle_id' => $cx5->id, 'position' => VehicleFitmentPosition::All,
            'width' => 225, 'profile' => 55, 'rim_diameter' => 19, 'load_index' => '99', 'speed_rating' => 'H',
            'is_staggered' => false, 'source' => VehicleFitmentSource::Manual,
            'confidence' => VehicleFitmentConfidence::Confirmed, 'status' => Status::Active,
        ]);

        // Genuinely staggered fitment — classic RWD Commodore front/rear split.
        $commodore = $makeVehicle([
            'make' => 'Holden', 'model' => 'Commodore', 'series' => 'VF SS-V', 'body_type' => 'sedan',
            'year_from' => 2013, 'year_to' => 2017,
        ]);
        VehicleFitment::query()->create([
            'vehicle_id' => $commodore->id, 'position' => VehicleFitmentPosition::Front,
            'width' => 245, 'profile' => 45, 'rim_diameter' => 18, 'load_index' => '96', 'speed_rating' => 'W',
            'is_staggered' => true, 'source' => VehicleFitmentSource::Manual,
            'confidence' => VehicleFitmentConfidence::Confirmed, 'status' => Status::Active,
        ]);
        VehicleFitment::query()->create([
            'vehicle_id' => $commodore->id, 'position' => VehicleFitmentPosition::Rear,
            'width' => 245, 'profile' => 40, 'rim_diameter' => 19, 'load_index' => '98', 'speed_rating' => 'W',
            'is_staggered' => true, 'source' => VehicleFitmentSource::Manual,
            'confidence' => VehicleFitmentConfidence::Confirmed, 'status' => Status::Active,
        ]);

        $ranger = $makeVehicle([
            'make' => 'Ford', 'model' => 'Ranger', 'series' => 'XLT', 'body_type' => 'ute',
            'year_from' => 2018, 'year_to' => 2022,
        ]);
        VehicleFitment::query()->create([
            'vehicle_id' => $ranger->id, 'position' => VehicleFitmentPosition::All,
            'width' => 265, 'profile' => 60, 'rim_diameter' => 18, 'load_index' => '110', 'speed_rating' => 'S',
            'is_staggered' => false, 'source' => VehicleFitmentSource::Manual,
            'confidence' => VehicleFitmentConfidence::Confirmed, 'status' => Status::Active,
        ]);

        $this->command->info('Vehicles seeded: '.Vehicle::query()->count().' vehicles, '.
            VehicleFitment::query()->count().' fitment rows.');
    }
}
