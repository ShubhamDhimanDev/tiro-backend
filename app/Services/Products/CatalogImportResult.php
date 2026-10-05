<?php

namespace App\Services\Products;

/**
 * Outcome of a {@see CatalogImportService} run. Row-level errors are always
 * collected and reported individually — never a bare pass/fail count —
 * mirroring `App\Services\Vehicles\FitmentImportResult`.
 *
 * Extended with two collections that Fitment's result doesn't need:
 *  - `warnings`: the row *was* imported, but something was defaulted or
 *    assumed (e.g. a missing Pattern attribute, an unrecognized Tyre Type
 *    value) — the user needs to see these to sanity-check a real supplier
 *    export, even though they aren't fatal.
 *  - `skipped`: the row was deliberately excluded because it's not real
 *    sellable data (WooCommerce trash/duplicate export rows) — informational,
 *    distinct from both errors and warnings, since it's an expected/correct
 *    exclusion rather than a data problem.
 */
final class CatalogImportResult
{
    /**
     * @param  list<array{row: int, message: string}>  $errors
     * @param  list<array{row: int, message: string}>  $warnings
     * @param  list<array{row: int, message: string}>  $skipped
     */
    public function __construct(
        public readonly int $rowsProcessed,
        public readonly int $brandsCreated,
        public readonly int $brandsMatched,
        public readonly int $modelsCreated,
        public readonly int $modelsUpdated,
        public readonly int $variantsCreated,
        public readonly int $variantsUpdated,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $skipped,
        public readonly bool $dryRun,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    public function hasSkipped(): bool
    {
        return $this->skipped !== [];
    }
}
