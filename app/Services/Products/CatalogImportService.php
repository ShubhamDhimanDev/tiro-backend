<?php

namespace App\Services\Products;

use App\Console\Commands\CatalogImportCommand;
use App\Enums\Status;
use App\Enums\TyreCategory;
use App\Enums\TyreConstruction;
use App\Enums\TyreSidewall;
use App\Enums\TyreType;
use App\Jobs\LocalizeModelImagesJob;
use App\Models\Brand;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Upserts `Brand`/`TyreModel`/`TyreVariant` rows from a WooCommerce
 * product-export CSV (or an equivalent JSON row list) — the single code
 * path behind both `php artisan catalog:import` ({@see CatalogImportCommand})
 * and the admin bulk-upload screen, mirroring
 * `App\Services\Vehicles\FitmentImportService`'s shape.
 *
 * Behavior: group parsed rows by (brand, resolved tread-pattern model), then
 * per group upsert the `Brand` (matched by slug), the `TyreModel` (matched
 * by slug, brand-prefixed per `database/seeders/CatalogueSeeder.php`'s
 * convention), and each `TyreVariant` (matched by the compound
 * `(tyre_model_id, width, profile, rim_diameter, load_index, speed_rating)`
 * spec tuple — re-running this importer against a refreshed supplier export
 * updates existing variants' price/ean/weight/status/sidewall rather than
 * erroring). Row-level hard errors (unparseable numeric field, no resolvable
 * SKU, no resolvable price) exclude just that row — the rest of the file
 * still imports. Row-level warnings (missing Pattern attribute, an
 * unrecognized Tyre Type/Categories value) still import the row, but flag
 * what was defaulted. Rows that are WooCommerce trash/duplicate exports
 * (`Published < 1` and/or a `" (Copy)"` name suffix) are informational
 * skips — expected, correct exclusions, not data problems.
 *
 * A genuine persistence-layer failure aborts and rolls back the whole run,
 * same reasoning as `FitmentImportService`'s docblock.
 */
class CatalogImportService
{
    /**
     * Maps a CSV "Tyre Type" attribute value (trimmed, uppercased) to
     * {@see TyreCategory}. Absent/unrecognized values default to `car` and
     * are flagged as a warning — see {@see resolveCategory()}.
     *
     * @var array<string, TyreCategory>
     */
    private const CATEGORY_MAP = [
        'PASSENGER' => TyreCategory::Car,
        'PCR' => TyreCategory::Car,
        'PASSENGER CAR' => TyreCategory::Car,
        'PASS (PER)' => TyreCategory::Car,
        'PASS (UHP)' => TyreCategory::Car,
        'UHP' => TyreCategory::Car,
        'SEMI SLICK' => TyreCategory::Car,
        'EV TYRE' => TyreCategory::Car,
        'SUV' => TyreCategory::Suv,
        'RV 4WD & SUV' => TyreCategory::Suv,
        '4X4' => TyreCategory::FourByFour,
        '4X4 / SUV (A-T)' => TyreCategory::FourByFour,
        'LIGHT TRUCK' => TyreCategory::LightTruck,
        'VAN' => TyreCategory::LightTruck,
        'TRUCK' => TyreCategory::LightTruck,
        'LIGHT VAN' => TyreCategory::LightTruck,
        'LVR' => TyreCategory::LightTruck,
    ];

    /**
     * Maps a CSV "Tyre Type" attribute value (trimmed, uppercased) directly
     * to {@see TyreType} — only for values that are themselves an
     * unambiguous tread-pattern signal (UHP/semi-slick -> performance, EV ->
     * eco). Every other raw value (including the "ordinary road tyre"
     * category values above, e.g. PASSENGER/LIGHT TRUCK/SUV) is NOT given a
     * confident tyre_type mapping here — the `Categories` column
     * (mud/all/highway terrain) is checked first in
     * {@see resolveTyreType()}, and anything left over defaults to
     * `highway` with a warning. This is a deliberate, literal reading of
     * the brief's proposed mapping table: it means a large share of
     * ordinary passenger/light-truck rows will carry a "defaulted to
     * highway" warning on a real ~800-row import, but that's the intended
     * transparency trade-off for a real production import, not a bug — see
     * this feature's handback report for the full reasoning.
     *
     * @var array<string, TyreType>
     */
    private const TYRE_TYPE_MAP = [
        'UHP' => TyreType::Performance,
        'PASS (UHP)' => TyreType::Performance,
        'SEMI SLICK' => TyreType::Performance,
        'EV TYRE' => TyreType::Eco,
    ];

    /**
     * Import rows from a CSV or JSON file. Format is detected by extension:
     * `.json` is decoded as a JSON array of objects, anything else is read
     * as CSV with a header row (the WooCommerce product-export shape).
     */
    public function importFromFile(string $path, bool $dryRun = false): CatalogImportResult
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
     * {@see importFromFile()} so the admin bulk-upload controller (which
     * decodes an uploaded file into rows itself) can call the same core
     * logic without writing to a temp file first — same split as
     * `FitmentImportService::import()`/`importFromFile()`.
     *
     * @param  list<array<string, mixed>>  $rows  Each row uses the raw
     *                                            WooCommerce product-export column shape (ID, Type, SKU, Name,
     *                                            Published, Description, Short description, Regular price, Sale
     *                                            price, Categories, Tags, Images, Brands, Weight (kg),
     *                                            GTIN/UPC/EAN/ISBN, and the numbered `Attribute N name`/
     *                                            `Attribute N value(s)` pairs).
     *                                            An optional `_row` key is used as the reported row number;
     *                                            otherwise rows are numbered from 1 in array order.
     */
    public function import(array $rows, bool $dryRun = false): CatalogImportResult
    {
        $errors = [];
        $warnings = [];
        $skipped = [];
        $parsedRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = is_int($row['_row'] ?? null) ? $row['_row'] : $index + 1;
            $outcome = $this->parseRow($row);

            if ($outcome['skip'] !== null) {
                $skipped[] = ['row' => $rowNumber, 'message' => $outcome['skip']];

                continue;
            }

            foreach ($outcome['warnings'] as $message) {
                $warnings[] = ['row' => $rowNumber, 'message' => $message];
            }

            foreach ($outcome['errors'] as $message) {
                $errors[] = ['row' => $rowNumber, 'message' => $message];
            }

            if ($outcome['data'] !== null) {
                $parsedRows[] = ['row' => $rowNumber, 'data' => $outcome['data']];
            }
        }

        /** @var array<string, list<array{row: int, data: array<string, mixed>}>> $groups */
        $groups = [];
        foreach ($parsedRows as $entry) {
            $key = mb_strtolower($entry['data']['brand_slug'].'|'.Str::slug($entry['data']['model_name']));
            $groups[$key][] = $entry;
        }

        $brandsCreated = 0;
        $brandsMatched = 0;
        $modelsCreated = 0;
        $modelsUpdated = 0;
        $variantsCreated = 0;
        $variantsUpdated = 0;
        $touchedModelIds = [];

        DB::beginTransaction();

        try {
            foreach ($groups as $group) {
                $first = $group[0]['data'];
                $modelSlug = Str::slug("{$first['brand_name']} {$first['model_name']}");

                // Pre-check every row in the group for a SKU already owned
                // by a *different* variant before creating/updating the
                // Brand or TyreModel — mirrors FitmentSetValidator's
                // "validate the whole group before writing anything"
                // approach, so a group whose only row(s) hard-conflict on
                // SKU never leaves behind an orphan Brand/TyreModel with no
                // variants.
                $existingModel = TyreModel::query()->where('slug', $modelSlug)->first();
                $validEntries = [];

                foreach ($group as $entry) {
                    $data = $entry['data'];

                    $existingVariant = $existingModel
                        ? TyreVariant::query()
                            ->where('tyre_model_id', $existingModel->id)
                            ->where('width', $data['width'])
                            ->where('profile', $data['profile'])
                            ->where('rim_diameter', $data['rim_diameter'])
                            ->where('load_index', $data['load_index'])
                            ->where('speed_rating', $data['speed_rating'])
                            ->first()
                        : null;

                    $skuConflict = TyreVariant::query()
                        ->where('sku', $data['sku'])
                        ->when($existingVariant, fn ($query) => $query->whereKeyNot($existingVariant->id))
                        ->exists();

                    if ($skuConflict) {
                        $errors[] = [
                            'row' => $entry['row'],
                            'message' => sprintf(
                                'SKU "%s" is already used by a different tyre variant — skipping this row, needs supplier data cleanup.',
                                $data['sku'],
                            ),
                        ];

                        continue;
                    }

                    $validEntries[] = $entry;
                }

                if ($validEntries === []) {
                    continue;
                }

                // Recompute the "first row" used for category/tyre_type
                // below from the valid entries only — the original
                // `$group[0]` may itself have been dropped above for a SKU
                // conflict.
                $first = $validEntries[0]['data'];

                $runFlat = false;
                $description = null;
                $images = [];
                foreach ($validEntries as $entry) {
                    $data = $entry['data'];
                    $runFlat = $runFlat || $data['run_flat'];
                    $description ??= $data['description'];
                    if ($data['image_url'] !== null && ! in_array($data['image_url'], $images, true)) {
                        $images[] = $data['image_url'];
                    }
                }

                $brand = Brand::query()->firstOrNew(['slug' => $first['brand_slug']]);
                if ($brand->exists) {
                    $brandsMatched++;
                } else {
                    $brand->fill([
                        'name' => $first['brand_name'],
                        'slug' => $first['brand_slug'],
                        'logo_path' => null,
                        'country_of_origin' => null,
                        'status' => Status::Active,
                    ])->save();
                    $brandsCreated++;
                }

                $tyreModel = $existingModel ?? new TyreModel(['slug' => $modelSlug]);
                $isNewModel = ! $tyreModel->exists;

                if ($isNewModel) {
                    $tyreModel->fill([
                        'brand_id' => $brand->id,
                        'name' => $first['model_name'],
                        'slug' => $modelSlug,
                        'category' => $first['category'],
                        'tyre_type' => $first['tyre_type'],
                        'construction' => TyreConstruction::Radial,
                        'run_flat' => $runFlat,
                        'description' => $description,
                        'images' => $images === [] ? null : $images,
                        'status' => Status::Active,
                    ])->save();
                    $modelsCreated++;
                } else {
                    $mergedImages = array_values(array_unique([...($tyreModel->images ?? []), ...$images]));
                    $tyreModel->fill([
                        'category' => $first['category'],
                        'tyre_type' => $first['tyre_type'],
                        'construction' => TyreConstruction::Radial,
                        'run_flat' => $tyreModel->run_flat || $runFlat,
                        'description' => $description ?? $tyreModel->description,
                        'images' => $mergedImages === [] ? null : $mergedImages,
                    ])->save();
                    $modelsUpdated++;
                }

                $touchedModelIds[] = $tyreModel->id;

                foreach ($validEntries as $entry) {
                    $data = $entry['data'];

                    $variant = TyreVariant::query()->firstOrNew([
                        'tyre_model_id' => $tyreModel->id,
                        'width' => $data['width'],
                        'profile' => $data['profile'],
                        'rim_diameter' => $data['rim_diameter'],
                        'load_index' => $data['load_index'],
                        'speed_rating' => $data['speed_rating'],
                    ]);

                    $isNewVariant = ! $variant->exists;

                    // Set the slug here rather than leaning on TyreVariant's
                    // `creating` hook: seeders run under WithoutModelEvents,
                    // which suppresses that hook and would leave `slug` unset.
                    if ($isNewVariant && blank($variant->slug)) {
                        $variant->setRelation('tyreModel', $tyreModel);
                        $variant->slug = TyreVariant::generateUniqueSlug($variant);
                    }

                    $variant->fill([
                        'tyre_model_id' => $tyreModel->id,
                        'sku' => $data['sku'],
                        'width' => $data['width'],
                        'profile' => $data['profile'],
                        'rim_diameter' => $data['rim_diameter'],
                        'load_index' => $data['load_index'],
                        'speed_rating' => $data['speed_rating'],
                        'sidewall' => $data['sidewall'],
                        'ean' => $data['ean'],
                        'weight_kg' => $data['weight_kg'],
                        'base_price' => $data['base_price'],
                        'status' => Status::Active,
                    ])->save();

                    $isNewVariant ? $variantsCreated++ : $variantsUpdated++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();

                // Supplier image URLs live on another server: pull them into
                // our own storage (as WebP) in the background.
                if (config('media.localize_on_import')) {
                    foreach (array_unique($touchedModelIds) as $tyreModelId) {
                        LocalizeModelImagesJob::dispatch($tyreModelId);
                    }
                }
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return new CatalogImportResult(
            rowsProcessed: count($rows),
            brandsCreated: $brandsCreated,
            brandsMatched: $brandsMatched,
            modelsCreated: $modelsCreated,
            modelsUpdated: $modelsUpdated,
            variantsCreated: $variantsCreated,
            variantsUpdated: $variantsUpdated,
            errors: $errors,
            warnings: $warnings,
            skipped: $skipped,
            dryRun: $dryRun,
        );
    }

    /**
     * Validate, coerce, and map one raw CSV row.
     *
     * @param  array<string, mixed>  $row
     * @return array{skip: ?string, data: ?array<string, mixed>, errors: list<string>, warnings: list<string>}
     */
    private function parseRow(array $row): array
    {
        $skip = $this->detectSkip($row);
        if ($skip !== null) {
            return ['skip' => $skip, 'data' => null, 'errors' => [], 'warnings' => []];
        }

        $errors = [];
        $warnings = [];

        $name = trim((string) ($row['Name'] ?? ''));
        if ($name === '') {
            $errors[] = 'The Name column is required.';
        }

        $brandRaw = $this->stringOrNull($row['Brands'] ?? null);
        if ($brandRaw === null) {
            $errors[] = 'The Brands column is required.';
        }

        $attributes = $this->extractAttributes($row);
        $productIp = $attributes['product ip'] ?? null;

        [$sku, $skuError] = $this->resolveSku($row, $productIp, $name);
        if ($skuError !== null) {
            $errors[] = $skuError;
        }

        $patternRaw = $attributes['pattern'] ?? null;
        $missingPattern = $patternRaw === null || trim($patternRaw) === '';

        $sizeRaw = (string) ($attributes['size'] ?? '');
        $tyreTypeRaw = $attributes['tyre type'] ?? null;
        $categoriesRaw = $this->stringOrNull($row['Categories'] ?? null);
        $tagsRaw = $this->stringOrNull($row['Tags'] ?? null);

        [$width, $widthError] = $this->parseUnsignedSmallInt($attributes['width'] ?? null, 'Width');
        if ($widthError !== null) {
            $errors[] = $widthError;
        }

        [$profile, $profileError] = $this->parseUnsignedSmallInt($attributes['profile'] ?? null, 'Profile');
        if ($profileError !== null) {
            $errors[] = $profileError;
        }

        [$rimDiameter, $diameterError] = $this->parseUnsignedSmallInt($attributes['diameter'] ?? null, 'Diameter');
        if ($diameterError !== null) {
            $errors[] = $diameterError;
        }

        $loadIndex = $this->stringOrNull($attributes['load index'] ?? null);
        if ($loadIndex === null) {
            $errors[] = 'The Load Index attribute is missing.';
        }

        $speedRating = $this->stringOrNull($attributes['speed rating'] ?? null);
        if ($speedRating === null) {
            $errors[] = 'The Speed Rating attribute is missing.';
        }

        [$priceCents, $priceError] = $this->resolvePrice($row);
        if ($priceError !== null) {
            $errors[] = $priceError;
        }

        $brandName = null;
        $brandSlug = null;
        $modelName = null;

        if ($brandRaw !== null) {
            $brandName = $this->normalizeName($brandRaw);
            $brandSlug = Str::slug($brandName);

            if ($missingPattern) {
                $modelName = "{$brandName} Generic";
                $warnings[] = sprintf(
                    'Pattern attribute absent — variant grouped under fallback model "%s".',
                    $modelName,
                );
            } else {
                $modelName = $this->normalizeName((string) $patternRaw);
            }
        }

        [$category, $categoryWarning] = $this->resolveCategory($tyreTypeRaw);
        if ($categoryWarning !== null) {
            $warnings[] = $categoryWarning;
        }

        [$tyreType, $tyreTypeWarning] = $this->resolveTyreType($categoriesRaw, $tyreTypeRaw, $tagsRaw);
        if ($tyreTypeWarning !== null) {
            $warnings[] = $tyreTypeWarning;
        }

        if ($errors !== []) {
            return ['skip' => null, 'data' => null, 'errors' => $errors, 'warnings' => []];
        }

        $runFlat = $this->resolveRunFlat($categoriesRaw, $tyreTypeRaw, $tagsRaw);
        $sidewall = $this->resolveSidewall((int) $rimDiameter, $sizeRaw, (string) $loadIndex, (string) $speedRating);

        $description = $this->resolveDescription(
            $this->stringOrNull($row['Description'] ?? null),
            $this->stringOrNull($row['Short description'] ?? null),
        );

        $imageUrl = $this->stringOrNull($row['Images'] ?? null);
        if ($imageUrl !== null) {
            $imageUrl = trim(explode(',', $imageUrl)[0]);
            $imageUrl = $imageUrl === '' ? null : $imageUrl;
        }

        $ean = $this->stringOrNull($row['GTIN, UPC, EAN, or ISBN'] ?? null);
        $weightRaw = $this->stringOrNull($row['Weight (kg)'] ?? null);
        $weightKg = ($weightRaw !== null && is_numeric($weightRaw)) ? (float) $weightRaw : null;

        return [
            'skip' => null,
            'errors' => [],
            'warnings' => $warnings,
            'data' => [
                'brand_name' => $brandName,
                'brand_slug' => $brandSlug,
                'model_name' => $modelName,
                'category' => $category,
                'tyre_type' => $tyreType,
                'run_flat' => $runFlat,
                'description' => $description,
                'image_url' => $imageUrl,
                'sku' => $sku,
                'width' => $width,
                'profile' => $profile,
                'rim_diameter' => $rimDiameter,
                'load_index' => $loadIndex,
                'speed_rating' => $speedRating,
                'sidewall' => $sidewall,
                'ean' => $ean,
                'weight_kg' => $weightKg,
                'base_price' => $priceCents,
            ],
        ];
    }

    /**
     * Detect WooCommerce trash/duplicate export rows — `Published < 1`
     * (WooCommerce's convention for a trashed/deleted product) and/or a
     * `" (Copy)"`-suffixed Name. Informational, not an error: this is an
     * expected, correct exclusion, since these rows aren't real sellable
     * listings.
     *
     * @param  array<string, mixed>  $row
     */
    private function detectSkip(array $row): ?string
    {
        $publishedRaw = $row['Published'] ?? null;
        $published = is_numeric($publishedRaw) ? (int) $publishedRaw : 1;
        $name = trim((string) ($row['Name'] ?? ''));
        $isTrashed = $published < 1;
        $isCopy = str_contains(mb_strtolower($name), '(copy)');

        if (! $isTrashed && ! $isCopy) {
            return null;
        }

        return match (true) {
            $isTrashed && $isCopy => sprintf('Skipped: trashed duplicate listing (Published=%d, name contains "(Copy)") — "%s".', $published, $name),
            $isTrashed => sprintf('Skipped: trashed listing (Published=%d) — "%s".', $published, $name),
            default => sprintf('Skipped: duplicate listing (name contains "(Copy)") — "%s".', $name),
        };
    }

    /**
     * Build a `lowercased attribute name => trimmed value` map from the
     * numbered `Attribute N name`/`Attribute N value(s)` column pairs.
     * Deliberately matches by attribute *name*, never by slot position —
     * which attribute lands in which numbered slot varies row to row in
     * the real export.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, ?string>
     */
    private function extractAttributes(array $row): array
    {
        $map = [];

        for ($i = 1; $i <= 10; $i++) {
            $attrName = $this->stringOrNull($row["Attribute {$i} name"] ?? null);
            if ($attrName === null) {
                continue;
            }

            $map[mb_strtolower($attrName)] = $this->stringOrNull($row["Attribute {$i} value(s)"] ?? null);
        }

        return $map;
    }

    /**
     * SKU precedence: explicit `SKU` column (if non-blank) -> `Product IP`
     * attribute value -> parsed suffix of `Name` after `" - "`. Never
     * fabricates a SKU — an unresolvable row is a hard error.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveSku(array $row, ?string $productIp, string $name): array
    {
        $skuColumn = $this->stringOrNull($row['SKU'] ?? null);
        if ($skuColumn !== null) {
            return [$skuColumn, null];
        }

        if ($productIp !== null) {
            return [$productIp, null];
        }

        if (str_contains($name, ' - ')) {
            $parts = explode(' - ', $name);
            $suffix = trim((string) end($parts));
            if ($suffix !== '') {
                return [$suffix, null];
            }
        }

        return [null, 'Unable to resolve a SKU for this row (no SKU column, no Product IP attribute, and no " - " suffix in Name) — needs supplier data cleanup.'];
    }

    /**
     * Price precedence: `Sale price` when present and greater than zero,
     * else `Regular price` — WooCommerce's own precedence. Converts the
     * plain decimal dollar string to whole cents, the same conversion
     * `dollarsInputToCents()` in `resources/js/lib/money.ts` does
     * client-side.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: ?int, 1: ?string}
     */
    private function resolvePrice(array $row): array
    {
        $sale = $this->parseDollarString($row['Sale price'] ?? null);
        $regular = $this->parseDollarString($row['Regular price'] ?? null);

        $dollars = ($sale !== null && $sale > 0) ? $sale : $regular;

        if ($dollars === null || $dollars < 0) {
            return [null, 'Unable to resolve a price for this row (Sale price and Regular price are both blank/invalid).'];
        }

        return [(int) round($dollars * 100), null];
    }

    private function parseDollarString(mixed $value): ?float
    {
        $raw = trim((string) ($value ?? ''));

        return ($raw !== '' && is_numeric($raw)) ? (float) $raw : null;
    }

    /**
     * Category: mapped from the `Tyre Type` attribute via
     * {@see CATEGORY_MAP}. Absent/unrecognized -> defaults to `car`,
     * flagged as a warning with the raw value noted.
     *
     * @return array{0: TyreCategory, 1: ?string}
     */
    private function resolveCategory(?string $tyreTypeRaw): array
    {
        if ($tyreTypeRaw === null || trim($tyreTypeRaw) === '') {
            return [TyreCategory::Car, 'Tyre Type attribute absent — category defaulted to "car".'];
        }

        $key = mb_strtoupper(trim($tyreTypeRaw));
        $category = self::CATEGORY_MAP[$key] ?? null;

        if ($category === null) {
            return [TyreCategory::Car, sprintf('Unrecognized Tyre Type value "%s" — category defaulted to "car".', trim($tyreTypeRaw))];
        }

        return [$category, null];
    }

    /**
     * TyreType: the `Categories` column's mud/all/highway-terrain signal
     * takes priority (case-insensitive, tolerant of the source data's own
     * "Terrian" typo); then the `Tags` column's short terrain codes
     * (`"M/T"` -> mud_terrain, `"A/T"` -> all_terrain, `"H/T"` -> highway —
     * e.g. Tags `"1658013, M/T"`), since the real export's `Categories`
     * column is very often just the placeholder value `"Tyres"` with no
     * real signal, while `Tags` carries meaningfully more terrain
     * information across the full file; then {@see TYRE_TYPE_MAP}'s
     * UHP/semi-slick/EV signals. Everything else -> defaults to `highway`,
     * flagged as a warning with the raw value noted — see
     * {@see TYRE_TYPE_MAP}'s docblock for why this is a deliberately
     * literal reading.
     *
     * @return array{0: TyreType, 1: ?string}
     */
    private function resolveTyreType(?string $categoriesRaw, ?string $tyreTypeRaw, ?string $tagsRaw): array
    {
        $normalizedCategories = mb_strtolower(str_ireplace('terrian', 'terrain', $categoriesRaw ?? ''));

        if (str_contains($normalizedCategories, 'mud terrain')) {
            return [TyreType::MudTerrain, null];
        }
        if (str_contains($normalizedCategories, 'all terrain')) {
            return [TyreType::AllTerrain, null];
        }
        if (str_contains($normalizedCategories, 'highway terrain')) {
            return [TyreType::Highway, null];
        }

        $normalizedTags = mb_strtoupper($tagsRaw ?? '');
        if (str_contains($normalizedTags, 'M/T')) {
            return [TyreType::MudTerrain, null];
        }
        if (str_contains($normalizedTags, 'A/T')) {
            return [TyreType::AllTerrain, null];
        }
        if (str_contains($normalizedTags, 'H/T')) {
            return [TyreType::Highway, null];
        }

        if ($tyreTypeRaw !== null && trim($tyreTypeRaw) !== '') {
            $key = mb_strtoupper(trim($tyreTypeRaw));
            if (isset(self::TYRE_TYPE_MAP[$key])) {
                return [self::TYRE_TYPE_MAP[$key], null];
            }
        }

        $rawForMessage = ($tyreTypeRaw !== null && trim($tyreTypeRaw) !== '') ? trim($tyreTypeRaw) : 'absent';
        $categoriesTrimmed = trim($categoriesRaw ?? '');
        $categoriesForMessage = $categoriesTrimmed !== '' ? $categoriesTrimmed : 'absent';

        return [
            TyreType::Highway,
            sprintf(
                'Tyre Type "%s" (Categories: "%s") did not resolve to a specific tyre type — defaulted to "highway".',
                $rawForMessage,
                $categoriesForMessage,
            ),
        ];
    }

    /**
     * `run_flat` is model-level (not variant-level) in this schema. Detects
     * a "RUNFLAT"/"run flat"/"run-flat" signal in the `Categories` column
     * (e.g. the literal category value `Runflat`), the raw `Tyre Type`
     * value, or the `Tags` column (e.g. Tags `"2254517, Runflat"` — the
     * real export's `Categories` column is very often just the placeholder
     * value `"Tyres"` with no real signal, so `Tags` may carry the only
     * run-flat signal for a given row).
     */
    private function resolveRunFlat(?string $categoriesRaw, ?string $tyreTypeRaw, ?string $tagsRaw): bool
    {
        $haystack = mb_strtolower(($categoriesRaw ?? '').' '.($tyreTypeRaw ?? '').' '.($tagsRaw ?? ''));

        return str_contains($haystack, 'runflat')
            || str_contains($haystack, 'run flat')
            || str_contains($haystack, 'run-flat');
    }

    /**
     * `TyreVariant.sidewall` has no explicit CSV column — derived:
     *   - the rim diameter immediately followed by "C" in the `Size`
     *     attribute string (e.g. "165R13C", "195/75R16C") -> `commercial`.
     *   - `load_index` or `speed_rating` ending in "XL" -> `xl` (checked on
     *     both columns, since real supplier data has been observed to place
     *     this marking in either).
     *   - a compound `load_index` (e.g. "108/106") with no commercial/XL
     *     signal -> `reinforced`.
     *   - otherwise -> `standard`.
     */
    private function resolveSidewall(int $rimDiameter, string $sizeRaw, string $loadIndex, string $speedRating): TyreSidewall
    {
        if (preg_match('/(?<!\d)'.$rimDiameter.'C(?!\d)/i', $sizeRaw) === 1) {
            return TyreSidewall::Commercial;
        }

        if (str_ends_with(mb_strtoupper(trim($speedRating)), 'XL') || str_ends_with(mb_strtoupper(trim($loadIndex)), 'XL')) {
            return TyreSidewall::ExtraLoad;
        }

        if (str_contains($loadIndex, '/')) {
            return TyreSidewall::Reinforced;
        }

        return TyreSidewall::Standard;
    }

    /**
     * Strips CSV `Description`/`Short description` HTML markup down to
     * plain text (`TyreModel.description` renders as plain text on the
     * storefront PDP, not via `dangerouslySetInnerHTML` — see
     * `frontend/app/tyres/[slug]/page.tsx`) and normalizes whitespace.
     * Prefers `Description`, falls back to `Short description`.
     */
    private function resolveDescription(?string $description, ?string $shortDescription): ?string
    {
        $candidate = $description ?? $shortDescription;
        if ($candidate === null) {
            return null;
        }

        $plain = strip_tags($candidate);
        $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5);
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));

        return $plain === '' ? null : $plain;
    }

    /**
     * Title-cases a raw all-caps/mixed-case CSV value (e.g. brand "ZETA" or
     * pattern "ALTIMAX GS5") into a normal display name ("Zeta", "Altimax
     * Gs5") — matches the Title Case convention `database/seeders/CatalogueSeeder.php`
     * already uses for brand/model names.
     */
    private function normalizeName(string $raw): string
    {
        return Str::title(mb_strtolower(trim($raw)));
    }

    /**
     * @param  string  $fieldLabel  Used only in error messages.
     * @return array{0: ?int, 1: ?string}
     */
    private function parseUnsignedSmallInt(mixed $value, string $fieldLabel): array
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            return [null, "The {$fieldLabel} attribute is missing."];
        }

        if (! ctype_digit($raw)) {
            $message = $fieldLabel === 'Profile'
                ? sprintf('Non-numeric Profile value "%s" — likely a commercial/LT-format size without a percentage profile, needs supplier data cleanup.', $raw)
                : sprintf('Non-numeric %s value "%s" — needs supplier data cleanup.', $fieldLabel, $raw);

            return [null, $message];
        }

        $int = (int) $raw;
        if ($int < 1 || $int > 65535) {
            return [null, "The {$fieldLabel} value {$int} is out of range."];
        }

        return [$int, null];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
