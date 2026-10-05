<?php

namespace App\Http\Requests\Admin\Bookings;

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use App\Models\CancellationPolicy;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see CancellationPolicy}. `fee_amount`
 * (cents) is required when `fee_type = flat`, `fee_percent` when
 * `fee_type = percent` — conditional-required, same pattern as
 * `ServiceZoneRequest`'s radius-only fields.
 */
class CancellationPolicyRequest extends FormRequest
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
        $isFlat = $this->input('fee_type') === CancellationFeeType::Flat->value;

        return [
            'service_zone_id' => ['nullable', Rule::exists('service_zones', 'id')],
            'notice_hours' => ['required', 'integer', 'min:0', 'max:65535'],
            'fee_type' => ['required', Rule::enum(CancellationFeeType::class)],
            'fee_amount' => [$isFlat ? 'required' : 'nullable', 'integer', 'min:0'],
            'fee_percent' => [$isFlat ? 'nullable' : 'required', 'integer', 'between:0,100'],
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
            'fee_amount.required' => 'A fee amount is required for flat fees.',
            'fee_percent.required' => 'A fee percentage is required for percent fees.',
        ];
    }

    /**
     * Get the "after" validation callables for the instance.
     *
     * Backstops `CancellationPolicy::forZone()`'s "first active row wins"
     * lookup (docs/architecture/01-data-model.md), which is only
     * deterministic if at most one *active* row exists per
     * `service_zone_id` (including the null/global key) at a time — the DB
     * has no unique index enforcing this (a nullable FK column can't express
     * "unique among active rows" as a plain constraint), so it's an
     * app-level invariant.
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

                if ($this->input('status') !== Status::Active->value) {
                    return;
                }

                /** @var CancellationPolicy|null $policy */
                $policy = $this->route('cancellationPolicy');

                $zoneId = $this->input('service_zone_id');

                $query = CancellationPolicy::query()
                    ->where('status', Status::Active)
                    ->when($policy, fn ($q) => $q->whereKeyNot($policy->id));

                $zoneId === null || $zoneId === ''
                    ? $query->whereNull('service_zone_id')
                    : $query->where('service_zone_id', $zoneId);

                if ($query->exists()) {
                    $validator->errors()->add(
                        'service_zone_id',
                        $zoneId === null || $zoneId === ''
                            ? 'An active global default policy already exists. Deactivate it before activating another.'
                            : 'This zone already has an active cancellation policy. Deactivate it before activating another.',
                    );
                }
            },
        ];
    }
}
