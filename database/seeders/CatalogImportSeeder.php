<?php

namespace Database\Seeders;

use App\Services\Products\CatalogImportService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the catalogue (brands, tread-pattern models, size variants) from the
 * client's WooCommerce product export in `database/seeders/data/`, through the
 * same {@see CatalogImportService} behind `php artisan catalog:import` and the
 * admin bulk upload. It creates no demo brands or products.
 *
 * Idempotent: brands, models and variants are upserted, so re-running refreshes
 * prices and details instead of duplicating rows. Rows the importer cannot
 * accept (no SKU, a non-numeric size, a duplicate SKU) are skipped and listed,
 * never fatal. The sheet carries no stock figures, so no inventory is seeded:
 * enter stock in the admin. External image URLs are queued for download into
 * our own storage unless `media.localize_on_import` is off.
 *
 * To load a newer export, run `php artisan catalog:import <path>`, or replace
 * the file below and re-seed.
 */
class CatalogImportSeeder extends Seeder
{
    /** Path of the product export, relative to `database/`. */
    public const SHEET = 'seeders/data/wc-product-export-14-9-2026-1789383080910.csv';

    public function run(CatalogImportService $importer): void
    {
        $path = database_path(self::SHEET);

        if (! is_file($path)) {
            throw new RuntimeException("Catalogue sheet not found: {$path}");
        }

        $result = $importer->importFromFile($path);

        $this->command?->info(
            "Catalogue imported from the product sheet: {$result->rowsProcessed} row(s), "
            ."{$result->brandsCreated} brand(s), {$result->modelsCreated} model(s), "
            ."{$result->variantsCreated} variant(s) created; "
            .count($result->skipped).' trashed/duplicate row(s) skipped.'
        );

        if ($result->hasErrors()) {
            $this->command?->warn(count($result->errors).' row(s) could not be imported and need sheet cleanup:');
            $this->command?->table(
                ['Row', 'Error'],
                collect($result->errors)->map(fn (array $error) => [$error['row'], $error['message']])->all(),
            );
        }
    }
}
