<?php

namespace App\Http\Requests\Admin\Locations;

use App\Enums\ServiceZoneType;
use App\Enums\Status;
use App\Models\ServiceZone;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see ServiceZone}.
 *
 * `origin_lat`/`origin_lng`/`radius_km` are only app-level-required when
 * `type` is {@see ServiceZoneType::Radius} (the DB columns are nullable
 * because the requirement is conditional, not absent) — for
 * {@see ServiceZoneType::SuburbList} zones they stay optional, capturable
 * only as a display centroid for a future map view.
 *
 * `operating_hours` is validated per weekday against the locked
 * `{"mon": {"open": "HH:mm", "close": "HH:mm"}, ..., "sun": null}` shape —
 * see docs/architecture/01-data-model.md.
 */
class ServiceZoneRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('locations.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isRadius = $this->input('type') === ServiceZoneType::Radius->value;
        $originRequirement = $isRadius ? 'required' : 'nullable';

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'state_id' => ['required', Rule::exists('states', 'id')],
            'type' => ['required', Rule::enum(ServiceZoneType::class)],
            'origin_lat' => [$originRequirement, 'numeric', 'between:-90,90'],
            'origin_lng' => [$originRequirement, 'numeric', 'between:-180,180'],
            'radius_km' => [$originRequirement, 'numeric', 'min:0.1', 'max:9999.99'],
            'operating_hours' => ['required', 'array:mon,tue,wed,thu,fri,sat,sun'],
            'priority' => ['required', 'integer', 'min:0', 'max:32767'],
            'status' => ['required', Rule::enum(Status::class)],
        ];

        foreach (self::DAYS as $day) {
            // `nullable` short-circuits the rest when the day is `null`
            // (closed). When present, `required_array_keys` forces both
            // `open` and `close` together — no half-set day. The two nested
            // field rules then only run when the key actually exists in the
            // payload (Laravel skips non-implicit rules on absent fields),
            // so they don't need their own `nullable`/`required_with`.
            $rules["operating_hours.{$day}"] = ['nullable', 'array:open,close', 'required_array_keys:open,close'];
            $rules["operating_hours.{$day}.open"] = ['date_format:H:i'];
            $rules["operating_hours.{$day}.close"] = ['date_format:H:i', 'after:operating_hours.'.$day.'.open'];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'origin_lat.required' => 'Origin latitude is required for radius-type zones.',
            'origin_lng.required' => 'Origin longitude is required for radius-type zones.',
            'radius_km.required' => 'A radius (km) is required for radius-type zones.',
            '*.close.after' => 'Closing time must be after opening time.',
        ];
    }
}
