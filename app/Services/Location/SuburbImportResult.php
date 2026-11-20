<?php

namespace App\Services\Location;

/**
 * Outcome of a {@see SuburbImportService} run. Row-level errors are always
 * reported individually (spreadsheet row number + message) rather than
 * collapsed into a pass/fail count.
 */
final class SuburbImportResult
{
    /**
     * @param  list<array{row: int, message: string}>  $errors
     */
    public function __construct(
        public readonly int $rowsProcessed,
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly array $errors,
        public readonly bool $dryRun,
    ) {}

    /**
     * @return array{rowsProcessed: int, created: int, updated: int, unchanged: int, errors: list<array{row: int, message: string}>, dryRun: bool}
     */
    public function toArray(): array
    {
        return [
            'rowsProcessed' => $this->rowsProcessed,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'errors' => $this->errors,
            'dryRun' => $this->dryRun,
        ];
    }
}
