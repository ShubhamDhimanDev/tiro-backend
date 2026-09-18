<?php

namespace App\Services\Vehicles;

/**
 * Outcome of a {@see FitmentImportService} run. Row-level errors are always
 * collected and reported individually — never a bare pass/fail count — per
 * docs/architecture/01-data-model.md's import/seeding tooling section.
 */
final class FitmentImportResult
{
    /**
     * @param  list<array{row: int, message: string}>  $errors
     */
    public function __construct(
        public readonly int $rowsProcessed,
        public readonly int $vehiclesCreated,
        public readonly int $vehiclesMatched,
        public readonly int $fitmentsCreated,
        public readonly int $fitmentsUpdated,
        public readonly array $errors,
        public readonly bool $dryRun,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
