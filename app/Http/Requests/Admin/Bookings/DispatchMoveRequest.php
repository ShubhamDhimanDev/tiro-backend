<?php

namespace App\Http\Requests\Admin\Bookings;

use App\Http\Controllers\Admin\Bookings\DispatchBoardController;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dispatch-board "move" (reschedule and/or reassign technician) — see
 * {@see DispatchBoardController::move()}.
 * The booking's zone and cart contents are not editable from here; only
 * when/who. `technician_id` is optional — omitted, the same first-fit
 * assignment the customer-facing flow uses picks the technician; provided,
 * it's an explicit reassignment to that technician (rejected if they're not
 * actually free for the requested slot).
 */
class DispatchMoveRequest extends FormRequest
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
        return [
            'scheduled_date' => ['required', 'date'],
            'slot_start' => ['required', 'date_format:H:i'],
            'technician_id' => ['nullable', Rule::exists('technicians', 'id')],
        ];
    }
}
