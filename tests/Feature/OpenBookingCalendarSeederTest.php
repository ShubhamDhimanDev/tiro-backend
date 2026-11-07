<?php

use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use Database\Seeders\LocationSeeder;
use Database\Seeders\OpenBookingCalendarSeeder;
use Illuminate\Support\Carbon;

it('opens 10:00-18:00 shifts for every future day with one van and one technician per zone', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(OpenBookingCalendarSeeder::class);

    $zoneCount = ServiceZone::query()->where('status', Status::Active)->count();

    expect(Technician::query()->count())->toBe($zoneCount)
        ->and(Van::query()->count())->toBe($zoneCount)
        ->and(TechnicianShift::query()->count())->toBe($zoneCount * OpenBookingCalendarSeeder::HORIZON_DAYS);

    $zone = ServiceZone::query()->firstOrFail();
    $sunday = Carbon::today()->next(Carbon::SUNDAY)->toDateString();

    $shifts = TechnicianShift::query()->where('service_zone_id', $zone->id)->whereDate('date', $sunday)->get();

    expect($shifts)->toHaveCount(1)
        ->and($shifts->first()->shift_start)->toBe('10:00:00')
        ->and($shifts->first()->shift_end)->toBe('18:00:00')
        ->and($zone->refresh()->operating_hours['sun'])->toBe(['open' => '10:00', 'close' => '18:00']);
});

it('is idempotent and preserves admin edits to existing shifts', function () {
    $this->seed(LocationSeeder::class);
    $this->seed(OpenBookingCalendarSeeder::class);

    $shift = TechnicianShift::query()->firstOrFail();
    $shift->update(['status' => Status::Inactive]);
    $total = TechnicianShift::query()->count();

    $this->seed(OpenBookingCalendarSeeder::class);

    expect(TechnicianShift::query()->count())->toBe($total)
        ->and(Van::query()->count())->toBe(ServiceZone::query()->count())
        ->and($shift->refresh()->status)->toBe(Status::Inactive);
});

it('does nothing when there are no service zones', function () {
    $this->seed(OpenBookingCalendarSeeder::class);

    expect(TechnicianShift::query()->count())->toBe(0);
});
