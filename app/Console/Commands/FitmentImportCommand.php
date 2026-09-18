<?php

namespace App\Console\Commands;

use App\Services\Vehicles\FitmentImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Thin wrapper around {@see FitmentImportService} — the future admin
 * bulk-upload screen calls the same service, so this command carries no
 * import logic of its own. See docs/architecture/01-data-model.md's
 * "In-house fitment table: import/seeding tooling" section.
 */
#[Signature('fitment:import {path : Path to the CSV or JSON fitment file} {--dry-run : Validate and report without persisting any changes}')]
#[Description('Import (upsert) Vehicle/VehicleFitment rows from a CSV or JSON file')]
class FitmentImportCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FitmentImportService $importer): int
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
            ."Processed {$result->rowsProcessed} row(s): {$result->vehiclesCreated} vehicle(s) created, "
            ."{$result->vehiclesMatched} matched, {$result->fitmentsCreated} fitment(s) created, "
            ."{$result->fitmentsUpdated} updated."
        );

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
