<?php

namespace App\Services\Location;

use App\Models\State;
use App\Models\Suburb;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Creates or updates {@see Suburb} rows from an uploaded .xlsx/.csv sheet
 * with the columns `Suburb`, `Postcode`, `State`, `Country` and optional
 * `Latitude`/`Longitude`.
 *
 * A row is matched on the table's own unique key — state + postcode +
 * suburb name (case-insensitive) — so re-importing the same sheet is
 * idempotent. A match is updated (name casing, and coordinates when the
 * sheet supplies them; blank coordinate cells never erase stored values);
 * no match is created. A suburb whose postcode changes is therefore a new
 * row — the old one is left alone, since it may be in zone memberships.
 *
 * Bad rows are reported individually and skipped; every other row is still
 * saved. A dry run walks the same logic without writing.
 *
 * @phpstan-type Cells array<string, string|null>
 */
class SuburbImportService
{
    /** @var array<string, list<string>> canonical column => accepted header aliases */
    private const HEADERS = [
        'name' => ['suburb', 'suburb name', 'name', 'locality'],
        'postcode' => ['postcode', 'post code', 'postal code', 'zip'],
        'state' => ['state', 'state code', 'state name'],
        'country' => ['country'],
        'lat' => ['latitude', 'lat'],
        'lng' => ['longitude', 'lng', 'lon', 'long'],
    ];

    /**
     * Read the sheet at `$path` and import it.
     *
     * @param  'xlsx'|'csv'  $format
     *
     * @throws ValidationException when the file cannot be read or lacks the required columns
     */
    public function importFile(string $path, string $format, bool $dryRun = false): SuburbImportResult
    {
        $reader = $format === 'csv' ? new CsvReader : new XlsxReader;

        try {
            $reader->open($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be read as a '.strtoupper($format).' spreadsheet.']);
        }

        try {
            return $this->import($this->readRows($reader), $dryRun);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is corrupt or not a valid '.strtoupper($format).' spreadsheet.']);
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  iterable<int, array{row: int, cells: Cells}>  $rows  header-keyed rows
     */
    public function import(iterable $rows, bool $dryRun = false): SuburbImportResult
    {
        $states = $this->stateLookup();
        $existing = $this->existingSuburbs();

        $processed = $created = $updated = $unchanged = 0;
        $errors = [];

        $run = function () use ($rows, $states, &$existing, &$processed, &$created, &$updated, &$unchanged, &$errors, $dryRun): void {
            foreach ($rows as $item) {
                $processed++;

                $parsed = $this->parseRow($item['cells'], $states);

                if (is_string($parsed)) {
                    $errors[] = ['row' => $item['row'], 'message' => $parsed];

                    continue;
                }

                $key = $this->key($parsed['state_id'], $parsed['postcode'], $parsed['name']);
                $suburb = $existing[$key] ?? null;

                if ($suburb === null) {
                    $existing[$key] = $dryRun ? (new Suburb($parsed))->syncOriginal() : Suburb::query()->create($parsed);
                    $created++;

                    continue;
                }

                $changes = ['name' => $parsed['name']];

                if ($parsed['lat'] !== null) {
                    $changes['lat'] = $parsed['lat'];
                    $changes['lng'] = $parsed['lng'];
                }

                $suburb->fill($changes);

                if (! $suburb->isDirty()) {
                    $unchanged++;

                    continue;
                }

                if ($dryRun) {
                    $suburb->syncOriginal();
                } else {
                    $suburb->save();
                }

                $updated++;
            }
        };

        $dryRun ? $run() : DB::transaction($run);

        return new SuburbImportResult($processed, $created, $updated, $unchanged, $errors, $dryRun);
    }

    /**
     * @return \Generator<int, array{row: int, cells: Cells}>
     */
    private function readRows(ReaderInterface $reader): \Generator
    {
        $columns = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $number => $row) {
                $values = $this->rowValues($row);

                if ($columns === null) {
                    $columns = $this->mapHeader($values);

                    continue;
                }

                if (array_filter($values, fn (?string $v): bool => $v !== null) === []) {
                    continue;
                }

                $cells = [];
                foreach ($columns as $index => $column) {
                    $cells[$column] = $values[$index] ?? null;
                }

                yield ['row' => $number, 'cells' => $cells];
            }

            // Only the first sheet is imported.
            break;
        }

        if ($columns === null) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is empty.']);
        }
    }

    /**
     * @return list<string|null>
     */
    private function rowValues(Row $row): array
    {
        return array_map(function (Cell $cell): ?string {
            $value = $cell->getValue();

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }

            $value = $value === null ? '' : trim((string) $value);

            return $value === '' ? null : $value;
        }, $row->cells);
    }

    /**
     * @param  list<string|null>  $header
     * @return array<int, string> column index => canonical column name
     *
     * @throws ValidationException
     */
    private function mapHeader(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $label) {
            $label = Str::of((string) $label)->lower()->replace('_', ' ')->squish()->toString();

            foreach (self::HEADERS as $canonical => $aliases) {
                if (in_array($label, $aliases, true) && ! in_array($canonical, $columns, true)) {
                    $columns[$index] = $canonical;
                }
            }
        }

        $missing = array_diff(['name', 'postcode', 'state'], $columns);

        if ($missing !== []) {
            $labels = ['name' => 'Suburb', 'postcode' => 'Postcode', 'state' => 'State'];

            throw ValidationException::withMessages([
                'file' => 'Missing required column(s) in the first row: '.implode(', ', array_map(fn (string $c): string => $labels[$c], $missing)).'.',
            ]);
        }

        return $columns;
    }

    /**
     * @param  Cells  $cells
     * @param  array<string, int>  $states  lowercase name/code => state id
     * @return array{name: string, state_id: int, postcode: string, lat: ?string, lng: ?string}|string a validation message on failure
     */
    private function parseRow(array $cells, array $states): array|string
    {
        $name = Str::of($cells['name'] ?? '')->squish()->toString();

        if ($name === '') {
            return 'Suburb is required.';
        }

        if (mb_strlen($name) > 255) {
            return 'Suburb must be 255 characters or fewer.';
        }

        $country = Str::lower($cells['country'] ?? '');

        if ($country !== '' && ! in_array($country, ['australia', 'au', 'aus'], true)) {
            return "Country \"{$cells['country']}\" is not supported — only Australia.";
        }

        $stateLabel = $cells['state'] ?? '';
        $stateId = $states[Str::lower($stateLabel)] ?? null;

        if ($stateId === null) {
            return $stateLabel === '' ? 'State is required.' : "Unknown state \"{$stateLabel}\".";
        }

        $postcode = $cells['postcode'] ?? '';

        // Excel stores 0800 as the number 800, and may render 3067 as 3067.0.
        if (preg_match('/^\d{3,4}(\.0+)?$/', $postcode) === 1) {
            $postcode = str_pad((string) (int) $postcode, 4, '0', STR_PAD_LEFT);
        }

        if (preg_match('/^\d{4}$/', $postcode) !== 1) {
            return $postcode === '' ? 'Postcode is required.' : "Postcode \"{$postcode}\" must be 4 digits.";
        }

        $lat = $cells['lat'] ?? null;
        $lng = $cells['lng'] ?? null;

        if (($lat === null) !== ($lng === null)) {
            return 'Latitude and longitude must be given together.';
        }

        if ($lat !== null) {
            if (! is_numeric($lat) || (float) $lat < -90 || (float) $lat > 90) {
                return "Latitude \"{$lat}\" must be a number between -90 and 90.";
            }

            if (! is_numeric($lng) || (float) $lng < -180 || (float) $lng > 180) {
                return "Longitude \"{$lng}\" must be a number between -180 and 180.";
            }

            $lat = number_format((float) $lat, 7, '.', '');
            $lng = number_format((float) $lng, 7, '.', '');
        }

        return ['name' => $name, 'state_id' => $stateId, 'postcode' => $postcode, 'lat' => $lat, 'lng' => $lng];
    }

    /**
     * @return array<string, int>
     */
    private function stateLookup(): array
    {
        $lookup = [];

        foreach (State::query()->get(['id', 'code', 'name']) as $state) {
            $lookup[Str::lower($state->code)] = $state->id;
            $lookup[Str::lower($state->name)] = $state->id;
        }

        return $lookup;
    }

    /**
     * @return array<string, Suburb>
     */
    private function existingSuburbs(): array
    {
        $existing = [];

        foreach (Suburb::query()->cursor() as $suburb) {
            $existing[$this->key($suburb->state_id, $suburb->postcode, $suburb->name)] = $suburb;
        }

        return $existing;
    }

    private function key(int $stateId, string $postcode, string $name): string
    {
        return $stateId.'|'.$postcode.'|'.Str::lower($name);
    }
}
