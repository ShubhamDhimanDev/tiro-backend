<?php

use App\Enums\Status;
use App\Models\ServiceZone;
use App\Models\TechnicianShift;
use Illuminate\Support\Carbon;

it('sets every shift from today on and every zone to the given hours, leaving past shifts and status alone', function () {
    $zone = ServiceZone::factory()->create();
    $past = TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'date' => Carbon::yesterday()->toDateString(),
        'shift_start' => '10:00:00',
        'shift_end' => '18:00:00',
    ]);
    $future = TechnicianShift::factory()->create([
        'service_zone_id' => $zone->id,
        'date' => Carbon::tomorrow()->toDateString(),
        'shift_start' => '10:00:00',
        'shift_end' => '18:00:00',
        'status' => Status::Inactive,
    ]);

    $this->artisan('bookings:set-shift-hours', ['--force' => true])->assertSuccessful();

    $future->refresh();
    expect($future->shift_start)->toBe('09:00:00')
        ->and($future->shift_end)->toBe('21:00:00')
        ->and($future->status)->toBe(Status::Inactive)
        ->and($past->refresh()->shift_start)->toBe('10:00:00')
        ->and($zone->refresh()->operating_hours['sun'])->toBe(['open' => '09:00', 'close' => '21:00']);
});

it('changes nothing on a dry run or with an invalid time', function () {
    $shift = TechnicianShift::factory()->create(['date' => Carbon::tomorrow()->toDateString(), 'shift_start' => '10:00:00', 'shift_end' => '18:00:00']);

    $this->artisan('bookings:set-shift-hours', ['--dry-run' => true])->assertSuccessful();
    $this->artisan('bookings:set-shift-hours', ['start' => '21:00', 'end' => '09:00', '--force' => true])->assertFailed();
    $this->artisan('bookings:set-shift-hours', ['start' => '9am', '--force' => true])->assertFailed();

    expect($shift->refresh()->shift_start)->toBe('10:00:00');
});
