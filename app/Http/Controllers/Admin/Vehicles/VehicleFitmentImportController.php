<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Vehicles\VehicleFitmentImportRequest;
use App\Services\Vehicles\FitmentImportResult;
use App\Services\Vehicles\FitmentImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk CSV/JSON fitment upload — the primary path ops uses to load/refresh
 * the in-house fitment table without shell/deploy access, per
 * docs/architecture/01-data-model.md's "In-house fitment table:
 * import/seeding tooling" section. Decodes the uploaded file into the
 * documented row shape and hands off to {@see FitmentImportService::import()}
 * — deliberately not {@see FitmentImportService::importFromFile()}, which is
 * the CLI-only path bound to a filesystem path argument; this controller is
 * the "request layer decodes an uploaded file into rows" caller that
 * method's own docblock anticipates. Row-level results (including per-row
 * errors) are rendered back on the same page, not reduced to a bare
 * pass/fail toast — a fitment table with silently-dropped rows is a worse
 * failure mode than a visibly-erroring import.
 */
class VehicleFitmentImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('vehicles/import');
    }

    public function store(VehicleFitmentImportRequest $request, FitmentImportService $importer): Response
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $rows = $this->decodeRows($file);
        $result = $importer->import($rows, $request->boolean('dry_run'));

        return Inertia::render('vehicles/import', [
            'result' => $this->serializeResult($result),
        ]);
    }

    /**
     * @return array{rowsProcessed: int, vehiclesCreated: int, vehiclesMatched: int, fitmentsCreated: int, fitmentsUpdated: int, errors: list<array{row: int, message: string}>, dryRun: bool}
     */
    private function serializeResult(FitmentImportResult $result): array
    {
        return [
            'rowsProcessed' => $result->rowsProcessed,
            'vehiclesCreated' => $result->vehiclesCreated,
            'vehiclesMatched' => $result->vehiclesMatched,
            'fitmentsCreated' => $result->fitmentsCreated,
            'fitmentsUpdated' => $result->fitmentsUpdated,
            'errors' => $result->errors,
            'dryRun' => $result->dryRun,
        ];
    }

    /**
     * Decode an uploaded CSV/JSON fitment file into the row shape
     * {@see FitmentImportService::import()} accepts (the same column shape
     * documented in docs/architecture/01-data-model.md). Deliberately does
     * not reuse `FitmentImportService`'s own file readers — they're private
     * and built around a filesystem path for the artisan command, whereas
     * this is the request-layer upload-decoding boundary that service's
     * `import()` docblock explicitly carves out for a caller like this one.
     *
     * @return list<array<string, mixed>>
     */
    private function decodeRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());

        return $extension === 'json'
            ? $this->decodeJson($file)
            : $this->decodeCsv($file);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'Unable to read the uploaded file.']);
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
    private function decodeJson(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            throw ValidationException::withMessages(['file' => 'Unable to read the uploaded file.']);
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['file' => 'The uploaded file is not a valid JSON array.']);
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
