<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ServiceZone;
use App\Models\TechnicianShift;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sets one start/end time on every shift from today onwards and the same
 * open/close hours on every service zone, all seven days. Customers are only
 * offered the fixed start times in `bookings.slot_start_times`, and a start is
 * offered only when the shift and the zone's hours have room for the whole job
 * from it, so the day must run past the last start (09:00-21:00 gives the
 * 09:00, 12:00, 15:00 and 18:00 starts three hours each).
 *
 * Shift status is not touched, so a day an admin switched off stays off. Past
 * shifts are left as they were.
 */
#[Signature('bookings:set-shift-hours {start=09:00 : Shift start and zone opening time (HH:MM)} {end=21:00 : Shift end and zone closing time (HH:MM)} {--dry-run : Show what would change without changing anything} {--force : Skip the confirmation prompt}')]
#[Description('Set the start/end time of every shift from today on, and every zone\'s opening hours on all 7 days')]
class SetShiftHoursCommand extends Command
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $start = (string) $this->argument('start');
        $end = (string) $this->argument('end');

        foreach ([$start, $end] as $time) {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                $this->error("\"{$time}\" is not a 24h HH:MM time.");

                return self::FAILURE;
            }
        }

        if ($end <= $start) {
            $this->error('The end time must be after the start time.');

            return self::FAILURE;
        }

        $today = Carbon::today()->toDateString();
        $shifts = TechnicianShift::query()->where('date', '>=', $today);
        $shiftCount = (clone $shifts)->count();
        $zones = ServiceZone::query()->orderBy('id')->get();

        $this->info(sprintf('Set %d shift(s) from %s onwards to %s-%s, and %d zone(s) to open %s-%s every day.', $shiftCount, $today, $start, $end, $zones->count(), $start, $end));

        $slotStarts = config('bookings.slot_start_times', []);
        $this->line('Customer slot start times (bookings.slot_start_times): '.($slotStarts === [] ? 'every 15 minutes' : implode(', ', $slotStarts)));

        $outside = array_filter($slotStarts, fn (string $slot): bool => $slot < $start || $slot >= $end);

        if ($outside !== []) {
            $this->warn('These start times fall outside '.$start.'-'.$end.' and will never be offered: '.implode(', ', $outside));
        }

        if ($this->option('dry-run')) {
            $this->comment('[dry-run] Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply this change?')) {
            $this->comment('Cancelled, nothing was changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($shifts, $zones, $start, $end, $today, $shiftCount): void {
            $shifts->update(['shift_start' => $start.':00', 'shift_end' => $end.':00', 'updated_at' => Carbon::now()]);

            $hours = array_fill_keys(self::DAY_KEYS, ['open' => $start, 'close' => $end]);

            foreach ($zones as $zone) {
                $zone->update(['operating_hours' => $hours]);
            }

            AuditLog::create([
                'auditable_type' => TechnicianShift::class,
                'auditable_id' => 0,
                'action' => 'bookings.bulk_set_shift_hours',
                'actor_id' => null,
                'before' => null,
                'after' => ['start' => $start, 'end' => $end, 'from_date' => $today, 'shifts' => $shiftCount, 'zones' => $zones->pluck('id')->all()],
            ]);
        });

        $this->info('Done.');

        return self::SUCCESS;
    }
}
