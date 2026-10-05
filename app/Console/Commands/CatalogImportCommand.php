<?php

namespace App\Console\Commands;

use App\Services\Products\CatalogImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Thin wrapper around {@see CatalogImportService} — the admin bulk-upload
 * screen calls the same service, so this command carries no import logic
 * of its own. Mirrors `App\Console\Commands\FitmentImportCommand`.
 */
#[Signature('catalog:import {path : Path to the CSV or JSON product-export file} {--dry-run : Validate and report without persisting any changes}')]
#[Description('Import (upsert) Brand/TyreModel/TyreVariant rows from a WooCommerce product-export CSV or JSON file')]
class CatalogImportCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CatalogImportService $importer): int
    {
        $path = (string) $this->argument('path');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path)) {
            $this->error("Import file not found: {$path}");

            return self::FAILURE;
        }

        $result = $importer->importFromFile($path, $dryRun);

        $this->info(
            ($dryRun ? '[dry-run] ' : '')
            ."Processed {$result->rowsProcessed} row(s): {$result->brandsCreated} brand(s) created, "
            ."{$result->brandsMatched} matched, {$result->modelsCreated} model(s) created, "
            ."{$result->modelsUpdated} updated, {$result->variantsCreated} variant(s) created, "
            ."{$result->variantsUpdated} updated."
        );

        if ($result->hasSkipped()) {
            $this->newLine();
            $this->comment(count($result->skipped).' row(s) skipped (trashed/duplicate export rows):');
            $this->table(
                ['Row', 'Note'],
                collect($result->skipped)->map(fn (array $skip) => [$skip['row'], $skip['message']])->all(),
            );
        }

        if ($result->hasWarnings()) {
            $this->newLine();
            $this->warn(count($result->warnings).' row(s) had warnings (imported, but a value was defaulted/assumed):');
            $this->table(
                ['Row', 'Warning'],
                collect($result->warnings)->map(fn (array $warning) => [$warning['row'], $warning['message']])->all(),
            );
        }

        if ($result->hasErrors()) {
            $this->newLine();
            $this->error(count($result->errors).' row(s) had errors:');
            $this->table(
                ['Row', 'Error'],
                collect($result->errors)->map(fn (array $error) => [$error['row'], $error['message']])->all(),
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
