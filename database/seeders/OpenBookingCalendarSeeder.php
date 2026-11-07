<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Enums\TechnicianEmploymentType;
use App\Models\ServiceZone;
use App\Models\Technician;
use App\Models\TechnicianShift;
use App\Models\Van;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Opens the booking calendar on every future date, 10:00 - 18:00, seven days
 * a week, with exactly one van and one technician per active service zone.
 *
 * Capacity is `TechnicianShift` rows (docs/architecture/04-booking-capacity-
 * engine.md), so "every future date" is materialised as a rolling window of
 * {@see self::HORIZON_DAYS} days starting tomorrow. Re-run the seeder
 * (`php artisan db:seed --class=OpenBookingCalendarSeeder`) to extend the
 * window; it is idempotent.
 *
 * It also rewrites each zone's `operating_hours` to 10:00-18:00 on all seven
 * days, because the slot engine intersects shifts with the zone's operating
 * window - without that, Sundays would stay closed and Saturdays would still
 * end earlier. A technician cannot be on two zones at the same time, so each
 * zone gets its own van + technician (capacity of 1 per zone).
 *
 * Shift rows that already exist (same technician/date/start) are left alone,
 * so an admin's edits or deactivated days survive a re-run. Other
 * technicians' shifts (e.g. DemoCatalogSeeder's) are untouched and add to
 * capacity if present.
 *
 * Must run after {@see LocationSeeder} (zones + depot).
 */
class OpenBookingCalendarSeeder extends Seeder
{
    /** How many days ahead of today the calendar is opened. */
    public const HORIZON_DAYS = 365;

    public const SHIFT_START = '10:00';

    public const SHIFT_END = '18:00';

    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function run(): void
    {
        $zones = ServiceZone::query()->where('status', Status::Active)->with('stockLocations')->get();

        if ($zones->isEmpty()) {
            $this->command?->warn('No active service zones found - run LocationSeeder first.');

            return;
        }

        $openHours = array_fill_keys(self::DAY_KEYS, ['open' => self::SHIFT_START, 'close' => self::SHIFT_END]);
        $shiftsCreated = 0;

        foreach ($zones as $zone) {
            $depot = $zone->stockLocations->first();

            if ($depot === null) {
                $this->command?->warn("Zone \"{$zone->name}\" has no stock location - skipped.");

                continue;
            }

            $zone->update(['operating_hours' => $openHours]);

            $technician = Technician::query()->firstOrCreate(
                ['name' => "Open Calendar Technician - {$zone->name}"],
                ['employment_type' => TechnicianEmploymentType::Employee, 'certifications' => [], 'status' => Status::Active],
            );

            $van = Van::query()->firstOrCreate(
                ['rego' => 'OPEN'.str_pad((string) $zone->id, 3, '0', STR_PAD_LEFT)],
                [
                    'name' => "{$zone->name} Van",
                    'home_stock_location_id' => $depot->id,
                    'has_alignment_equipment' => true,
                    'max_jobs_per_day' => 8,
                    'status' => Status::Active,
                ],
            );

            $shiftsCreated += $this->openShifts($zone, $technician, $van);
        }

        $this->command?->info("Booking calendar opened for {$zones->count()} zone(s), ".self::SHIFT_START.'-'.self::SHIFT_END
            .' for the next '.self::HORIZON_DAYS." days ({$shiftsCreated} new shifts).");
    }

    private function openShifts(ServiceZone $zone, Technician $technician, Van $van): int
    {
        $now = now();
        $rows = [];

        for ($day = 1; $day <= self::HORIZON_DAYS; $day++) {
            $rows[] = [
                'technician_id' => $technician->id,
                'van_id' => $van->id,
                'service_zone_id' => $zone->id,
                'date' => Carbon::today()->addDays($day)->toDateString(),
                'shift_start' => self::SHIFT_START.':00',
                'shift_end' => self::SHIFT_END.':00',
                'status' => Status::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $created = 0;

        foreach (array_chunk($rows, 200) as $chunk) {
            $created += TechnicianShift::query()->insertOrIgnore($chunk);
        }

        return $created;
    }
}
