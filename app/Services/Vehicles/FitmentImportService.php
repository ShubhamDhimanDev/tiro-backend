<?php

namespace App\Services\Vehicles;

use App\Console\Commands\FitmentImportCommand;
use App\Enums\Status;
use App\Enums\VehicleFitmentConfidence;
use App\Enums\VehicleFitmentPosition;
use App\Enums\VehicleFitmentSource;
use App\Models\Vehicle;
use App\Models\VehicleFitment;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Upserts `Vehicle`/`VehicleFitment` rows from a CSV or JSON import, per the
 * column shape and upsert behavior documented in
 * docs/architecture/01-data-model.md's "In-house fitment table:
 * import/seeding tooling" section. This is the single code path behind both
 * `php artisan fitment:import` ({@see FitmentImportCommand})
 * and the future admin bulk-upload screen — no second implementation.
 *
 * Behavior: upsert the parent `Vehicle` by its unique tuple (create if
 * missing), then upsert `VehicleFitment` by `(vehicle_id, position)`.
 * Row-level errors (bad enum value, missing required field, non-numeric
 * size, an `is_staggered`/`position` set that fails
 * {@see FitmentSetValidator}) are collected and reported per-row — a
 * malformed row is never silently skipped, and the rest of the file is
 * still processed. A genuine persistence-layer failure (e.g. the database
 * itself becomes unavailable mid-run) is a different failure class: it
 * aborts and rolls back the whole run, since by that point every row has
 * already passed field-level and set-level validation and a further
 * failure is a systemic problem, not a bad-data one.
 */
class FitmentImportService
{
    public function __construct(private readonly FitmentSetValidator $setValidator) {}

    /**
     * Import fitment rows from a CSV or JSON file. Format is detected by
     * extension: `.json` is decoded as a JSON array of objects, anything
     * else is read as CSV with a header row.
     */
    public function importFromFile(string $path, bool $dryRun = false): FitmentImportResult
    {
        if (! is_file($path)) {
            throw new RuntimeException("Import file not found: {$path}");
        }

        $rows = str_ends_with(strtolower($path), '.json')
            ? $this->readJson($path)
            : $this->readCsv($path);

        return $this->import($rows, $dryRun);
    }

    /**
     * Import an already-parsed list of rows. Exposed separately from
     * {@see importFromFile()} so a future admin bulk-upload endpoint (which
     * receives an uploaded file already decoded into rows by the request
     * layer) can call the same core logic without writing to a temp file
     * first.
     *
     * @param  list<array<string, mixed>>  $rows  Each row uses the documented
     *                                            column shape (make, model, series, body_type, year_from,
     *                                            year_to, position, width, profile, rim_diameter, load_index,
     *                                            speed_rating, is_staggered, source, confidence, notes). An
     *                                            optional `_row` key is used as the reported row number;
     *                                            otherwise rows are numbered from 1 in array order.
     */
    public function import(array $rows, bool $dryRun = false): FitmentImportResult
    {
        $errors = [];
        $parsedRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = is_int($row['_row'] ?? null) ? $row['_row'] : $index + 1;
            [$parsed, $rowErrors] = $this->parseRow($row);

            foreach ($rowErrors as $message) {
                $errors[] = ['row' => $rowNumber, 'message' => $message];
            }

            if ($parsed !== null) {
                $parsedRows[] = ['row' => $rowNumber, 'data' => $parsed];
            }
        }

        /** @var array<string, list<array{row: int, data: array<string, mixed>}>> $groups */
        $groups = [];
        foreach ($parsedRows as $entry) {
            $groups[$this->vehicleKey($entry['data'])][] = $entry;
        }

        $vehiclesCreated = 0;
        $vehiclesMatched = 0;
        $fitmentsCreated = 0;
        $fitmentsUpdated = 0;

        DB::beginTransaction();

        try {
            foreach ($groups as $group) {
                $setErrors = $this->setValidator->validate(array_map(
                    fn (array $entry): array => [
                        'position' => $entry['data']['position'],
                        'is_staggered' => $entry['data']['is_staggered'],
                    ],
                    $group,
                ));

                if ($setErrors !== []) {
                    foreach ($group as $entry) {
                        foreach ($setErrors as $message) {
                            $errors[] = ['row' => $entry['row'], 'message' => $message];
                        }
                    }

                    continue;
                }

                $first = $group[0]['data'];

                $vehicle = Vehicle::query()->firstOrNew([
                    'make' => $first['make'],
                    'model' => $first['model'],
                    'series' => $first['series'],
                    'body_type' => $first['body_type'],
                    'year_from' => $first['year_from'],
                    'year_to' => $first['year_to'],
                ]);

                if ($vehicle->exists) {
                    $vehiclesMatched++;
                } else {
                    $vehicle->status = Status::Active;
                    $vehicle->save();
                    $vehiclesCreated++;
                }

                foreach ($group as $entry) {
                    $data = $entry['data'];

                    /** @var VehicleFitmentPosition $position */
                    $position = $data['position'];

                    $fitment = VehicleFitment::query()->firstOrNew([
                        'vehicle_id' => $vehicle->id,
                        'position' => $position->value,
                    ]);

                    $isNew = ! $fitment->exists;

                    $fitment->fill([
                        'vehicle_id' => $vehicle->id,
                        'position' => $data['position'],
                        'width' => $data['width'],
                        'profile' => $data['profile'],
                        'rim_diameter' => $data['rim_diameter'],
                        'load_index' => $data['load_index'],
                        'speed_rating' => $data['speed_rating'],
                        'is_staggered' => $data['is_staggered'],
                        'source' => $data['source'],
                        'confidence' => $data['confidence'],
                        'notes' => $data['notes'],
                        'status' => Status::Active,
                    ])->save();

                    $isNew ? $fitmentsCreated++ : $fitmentsUpdated++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return new FitmentImportResult(
            rowsProcessed: count($rows),
            vehiclesCreated: $vehiclesCreated,
            vehiclesMatched: $vehiclesMatched,
            fitmentsCreated: $fitmentsCreated,
            fitmentsUpdated: $fitmentsUpdated,
            errors: $errors,
            dryRun: $dryRun,
        );
    }

    /**
     * Validate and coerce one raw row. Returns `[parsedData, errors]` — when
     * `errors` is non-empty, `parsedData` is `null` and the row is excluded
     * from import entirely (a malformed row is reported, never partially
     * applied).
     *
     * @param  array<string, mixed>  $row
     * @return array{0: ?array<string, mixed>, 1: list<string>}
     */
    private function parseRow(array $row): array
    {
        $errors = [];

        $make = $this->stringOrNull($row['make'] ?? null);
        $model = $this->stringOrNull($row['model'] ?? null);
        $series = $this->stringOrNull($row['series'] ?? null);
        $bodyType = $this->stringOrNull($row['body_type'] ?? null);

        if ($make === null) {
            $errors[] = 'The make field is required.';
        }
        if ($model === null) {
            $errors[] = 'The model field is required.';
        }

        $yearFrom = $this->parseInteger($row['year_from'] ?? null);
        $yearTo = $this->parseInteger($row['year_to'] ?? null);

        if ($yearFrom === null) {
            $errors[] = 'The year_from field must be a whole number.';
        }
        if ($yearTo === null) {
            $errors[] = 'The year_to field must be a whole number.';
        }
        if ($yearFrom !== null && $yearTo !== null && $yearTo < $yearFrom) {
            $errors[] = 'year_to must be greater than or equal to year_from.';
        }

        $positionRaw = trim((string) ($row['position'] ?? ''));
        $position = VehicleFitmentPosition::tryFrom(strtolower($positionRaw));
        if ($position === null) {
            $errors[] = sprintf(
                'Unrecognized position value "%s" — expected one of: %s.',
                $positionRaw,
                implode(', ', array_column(VehicleFitmentPosition::cases(), 'value')),
            );
        }

        $width = $this->parseInteger($row['width'] ?? null);
        $profile = $this->parseInteger($row['profile'] ?? null);
        $rimDiameter = $this->parseInteger($row['rim_diameter'] ?? null);

        if ($width === null || $width <= 0) {
            $errors[] = 'The width field must be a positive whole number.';
        }
        if ($profile === null || $profile <= 0) {
            $errors[] = 'The profile field must be a positive whole number.';
        }
        if ($rimDiameter === null || $rimDiameter <= 0) {
            $errors[] = 'The rim_diameter field must be a positive whole number.';
        }

        $loadIndex = $this->stringOrNull($row['load_index'] ?? null);
        $speedRating = $this->stringOrNull($row['speed_rating'] ?? null);
        $notes = $this->stringOrNull($row['notes'] ?? null);

        $isStaggeredRaw = $row['is_staggered'] ?? null;
        $isStaggered = $this->parseBoolean($isStaggeredRaw);
        if ($isStaggered === null) {
            $errors[] = sprintf('The is_staggered field must be a recognizable boolean value, got "%s".', (string) $isStaggeredRaw);
        }

        $sourceRaw = trim((string) ($row['source'] ?? ''));
        $source = VehicleFitmentSource::tryFrom(strtolower($sourceRaw));
        if ($source === null) {
            $errors[] = sprintf(
                'Unrecognized source value "%s" — expected one of: %s.',
                $sourceRaw,
                implode(', ', array_column(VehicleFitmentSource::cases(), 'value')),
            );
        }

        $confidenceRaw = trim((string) ($row['confidence'] ?? ''));
        $confidence = VehicleFitmentConfidence::Confirmed;
        if ($confidenceRaw !== '') {
            $parsedConfidence = VehicleFitmentConfidence::tryFrom(strtolower($confidenceRaw));
            if ($parsedConfidence === null) {
                $errors[] = sprintf(
                    'Unrecognized confidence value "%s" — expected one of: %s.',
                    $confidenceRaw,
                    implode(', ', array_column(VehicleFitmentConfidence::cases(), 'value')),
                );
            } else {
                $confidence = $parsedConfidence;
            }
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'make' => $make,
            'model' => $model,
            'series' => $series,
            'body_type' => $bodyType,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
            'position' => $position,
            'width' => $width,
            'profile' => $profile,
            'rim_diameter' => $rimDiameter,
            'load_index' => $loadIndex,
            'speed_rating' => $speedRating,
            'is_staggered' => $isStaggered,
            'source' => $source,
            'confidence' => $confidence,
            'notes' => $notes,
        ], []];
    }

    /**
     * Build the grouping key identifying which parsed rows belong to the
     * same vehicle's unique tuple — case-insensitive, since CSV data entry
     * shouldn't produce two different `Vehicle` rows over a capitalization
     * difference alone.
     *
     * @param  array<string, mixed>  $data
     */
    private function vehicleKey(array $data): string
    {
        return implode('|', [
            mb_strtolower((string) $data['make']),
            mb_strtolower((string) $data['model']),
            mb_strtolower((string) ($data['series'] ?? '')),
            mb_strtolower((string) ($data['body_type'] ?? '')),
            (string) $data['year_from'],
            (string) $data['year_to'],
        ]);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function parseInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        if ((float) $value != (int) $value) {
            return null;
        }

        return (int) $value;
    }

    private function parseBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open import file: {$path}");
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($column) => trim((string) $column), $header);
        $columnCount = count($header);

        $rows = [];
        $rowNumber = 0;

        while (($line = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // A fully blank line (e.g. trailing newline at EOF) parses to [null].
            if ($line === [null]) {
                continue;
            }

            $line = array_slice(array_pad($line, $columnCount, null), 0, $columnCount);
            $row = array_combine($header, $line);
            $row['_row'] = $rowNumber;
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read import file: {$path}");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Import file is not a valid JSON array: {$path}");
        }

        $rows = [];

        foreach (array_values($decoded) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $row['_row'] = $index + 1;
            $rows[] = $row;
        }

        return $rows;
    }
}
