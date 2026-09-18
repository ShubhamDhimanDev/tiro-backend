<?php

namespace App\Http\Requests\Admin\Vehicles;

use App\Enums\Status;
use App\Enums\VehicleFitmentConfidence;
use App\Enums\VehicleFitmentPosition;
use App\Http\Requests\Admin\Products\TyreVariantRequest;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use App\Services\Vehicles\FitmentSetValidator;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see Vehicle} plus its full nested
 * {@see VehicleFitment} row set, submitted together as one
 * `fitments` array (the `is_staggered` toggle switches its shape between one
 * `position=all` row and two `position=front`/`rear` rows — see
 * docs/architecture/01-data-model.md's "Vehicles & fitment" section).
 *
 * `source` is deliberately absent from `rules()` — it is never
 * admin-selectable from this form. Any row created/edited here is `manual`
 * by definition; the controller hardcodes it, not this request.
 */
class VehicleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('vehicles.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Vehicle|null $vehicle */
        $vehicle = $this->route('vehicle');

        return [
            'make' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'series' => ['nullable', 'string', 'max:100'],
            'body_type' => ['nullable', 'string', 'max:50'],
            'year_from' => ['required', 'integer', 'between:1900,2100'],
            'year_to' => ['required', 'integer', 'between:1900,2100', 'gte:year_from'],
            // Required once the vehicle exists (edit form); optional on
            // create — a blank value there lets Vehicle::booted()'s
            // `creating` hook auto-generate it, same convention as
            // TyreVariant's slug.
            'slug' => [
                $vehicle ? 'required' : 'nullable', 'string', 'max:255', 'alpha_dash',
                Rule::unique('vehicles', 'slug')->ignore($vehicle),
            ],
            'status' => ['required', Rule::enum(Status::class)],
            'is_staggered' => ['required', 'boolean'],
            'fitments' => ['required', 'array', 'min:1', 'max:2'],
            'fitments.*.position' => ['required', Rule::enum(VehicleFitmentPosition::class)],
            'fitments.*.width' => ['required', 'integer', 'between:1,999'],
            'fitments.*.profile' => ['required', 'integer', 'between:1,999'],
            'fitments.*.rim_diameter' => ['required', 'integer', 'between:1,999'],
            'fitments.*.load_index' => ['nullable', 'string', 'max:10'],
            'fitments.*.speed_rating' => ['nullable', 'string', 'max:5'],
            'fitments.*.confidence' => ['required', Rule::enum(VehicleFitmentConfidence::class)],
            'fitments.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Get the "after" validation callables for the instance.
     *
     * Two checks that don't fit `rules()`'s single-rule-per-key shape:
     * the composite make/model/series/body_type/year_from/year_to
     * de-dupe check (mirrors {@see TyreVariantRequest::after()}'s
     * pattern, and is the app-level backstop the data model doc calls for
     * since the DB unique index treats NULL `series`/`body_type` as
     * distinct-per-row), and the `is_staggered`/`position` agreement check
     * — delegated entirely to {@see FitmentSetValidator}, not
     * reimplemented here, per that service's own docblock.
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

                /** @var Vehicle|null $vehicle */
                $vehicle = $this->route('vehicle');

                $query = Vehicle::query()
                    ->where('make', $this->string('make'))
                    ->where('model', $this->string('model'))
                    ->where('year_from', $this->integer('year_from'))
                    ->where('year_to', $this->integer('year_to'))
                    ->when($vehicle, fn ($q) => $q->whereKeyNot($vehicle->id));

                $series = $this->input('series');
                $series === null || $series === ''
                    ? $query->whereNull('series')
                    : $query->where('series', $series);

                $bodyType = $this->input('body_type');
                $bodyType === null || $bodyType === ''
                    ? $query->whereNull('body_type')
                    : $query->where('body_type', $bodyType);

                if ($query->exists()) {
                    $validator->errors()->add(
                        'make',
                        'A vehicle with this exact make/model/series/body type/year range already exists.',
                    );

                    return;
                }

                $isStaggered = $this->boolean('is_staggered');
                $fitmentsInput = $this->input('fitments', []);

                /** @var list<array{position: VehicleFitmentPosition, is_staggered: bool}> $rows */
                $rows = [];

                if (is_array($fitmentsInput)) {
                    foreach ($fitmentsInput as $row) {
                        if (! is_array($row)) {
                            continue;
                        }

                        $position = VehicleFitmentPosition::tryFrom((string) ($row['position'] ?? ''));

                        if ($position !== null) {
                            $rows[] = ['position' => $position, 'is_staggered' => $isStaggered];
                        }
                    }
                }

                $setErrors = app(FitmentSetValidator::class)->validate($rows);

                foreach ($setErrors as $message) {
                    $validator->errors()->add('fitments', $message);
                }
            },
        ];
    }
}
