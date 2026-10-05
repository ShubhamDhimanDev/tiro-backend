<?php

namespace App\Http\Requests\Admin\Bookings;

use App\Enums\Status;
use App\Models\TechnicianShift;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see TechnicianShift} — the capacity
 * source of truth the booking engine reads (see
 * docs/architecture/04-booking-capacity-engine.md). `shift_end` after
 * `shift_start` is enforced here (app-level, surfaced as a normal validation
 * error) as well as by the migration's MySQL `CHECK` constraint (a backstop
 * for direct DB writes, not the primary UX) — the admin form additionally
 * repeats this check client-side, see
 * `resources/js/pages/bookings/shifts/index.tsx`.
 */
class TechnicianShiftRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('bookings.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var TechnicianShift|null $shift */
        $shift = $this->route('technicianShift');

        return [
            'technician_id' => ['required', Rule::exists('technicians', 'id')],
            'van_id' => ['required', Rule::exists('vans', 'id')],
            'service_zone_id' => ['required', Rule::exists('service_zones', 'id')],
            'date' => ['required', 'date'],
            'shift_start' => ['required', 'date_format:H:i'],
            'shift_end' => ['required', 'date_format:H:i', 'after:shift_start'],
            'status' => ['required', Rule::enum(Status::class)],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shift_end.after' => 'Shift end must be after shift start.',
        ];
    }

    /**
     * Get the "after" validation callables for the instance.
     *
     * Backstops the `(technician_id, date, shift_start)` unique index with a
     * friendlier message than the raw DB integrity-constraint error a
     * double-submit would otherwise surface as, and additionally rejects any
     * genuine time-range overlap against another shift row for the same
     * technician/date — not just an exact `shift_start` duplicate. Split
     * shifts that touch but don't overlap (one ending exactly when the next
     * starts) remain allowed: the overlap test below is strict (`<`/`>`,
     * not `<=`/`>=`).
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var TechnicianShift|null $shift */
                $shift = $this->route('technicianShift');

                $technicianId = $this->input('technician_id');
                $date = $this->input('date');
                $shiftStart = $this->input('shift_start');
                $shiftEnd = $this->input('shift_end');

                $exactDuplicate = TechnicianShift::query()
                    ->where('technician_id', $technicianId)
                    ->where('date', $date)
                    ->where('shift_start', $shiftStart)
                    ->when($shift, fn ($q) => $q->whereKeyNot($shift->id))
                    ->exists();

                if ($exactDuplicate) {
                    $validator->errors()->add(
                        'shift_start',
                        'This technician already has a shift starting at this time on this date.',
                    );

                    return;
                }

                // Standard interval-overlap test: new.shift_start <
                // existing.shift_end AND new.shift_end > existing.shift_start.
                // Strict comparisons mean back-to-back shifts (one ending
                // exactly when the next starts) are not flagged.
                $overlaps = TechnicianShift::query()
                    ->where('technician_id', $technicianId)
                    ->where('date', $date)
                    ->where('shift_start', '<', $shiftEnd)
                    ->where('shift_end', '>', $shiftStart)
                    ->when($shift, fn ($q) => $q->whereKeyNot($shift->id))
                    ->exists();

                if ($overlaps) {
                    $validator->errors()->add(
                        'shift_start',
                        'This technician already has an overlapping shift on this date.',
                    );
                }
            },
        ];
    }
}
