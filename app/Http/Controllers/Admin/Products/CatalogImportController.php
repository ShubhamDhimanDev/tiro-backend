<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Products\CatalogImportRequest;
use App\Services\Products\CatalogImportResult;
use App\Services\Products\CatalogImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk WooCommerce product-export CSV/JSON upload — loads a real supplier
 * export into the Brand/TyreModel/TyreVariant catalog. Mirrors
 * `App\Http\Controllers\Admin\Vehicles\VehicleFitmentImportController`:
 * decodes the uploaded file into rows itself and hands off to
 * {@see CatalogImportService::import()} (not `importFromFile()`, which is
 * the CLI-only path bound to a filesystem path argument). Row-level results
 * (errors/warnings/skipped, all per-row) are rendered back on the same
 * page, not reduced to a bare pass/fail toast.
 */
class CatalogImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('products/import');
    }

    public function store(CatalogImportRequest $request, CatalogImportService $importer): Response
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $rows = $this->decodeRows($file);
        $result = $importer->import($rows, $request->boolean('dry_run'));

        return Inertia::render('products/import', [
            'result' => $this->serializeResult($result),
        ]);
    }

    /**
     * @return array{
     *     rowsProcessed: int, brandsCreated: int, brandsMatched: int,
     *     modelsCreated: int, modelsUpdated: int, variantsCreated: int, variantsUpdated: int,
     *     errors: list<array{row: int, message: string}>,
     *     warnings: list<array{row: int, message: string}>,
     *     skipped: list<array{row: int, message: string}>,
     *     dryRun: bool,
     * }
     */
    private function serializeResult(CatalogImportResult $result): array
    {
        return [
            'rowsProcessed' => $result->rowsProcessed,
            'brandsCreated' => $result->brandsCreated,
            'brandsMatched' => $result->brandsMatched,
            'modelsCreated' => $result->modelsCreated,
            'modelsUpdated' => $result->modelsUpdated,
            'variantsCreated' => $result->variantsCreated,
            'variantsUpdated' => $result->variantsUpdated,
            'errors' => $result->errors,
            'warnings' => $result->warnings,
            'skipped' => $result->skipped,
            'dryRun' => $result->dryRun,
        ];
    }

    /**
     * Decode an uploaded CSV/JSON catalog file into the row shape
     * {@see CatalogImportService::import()} accepts. Deliberately does not
     * reuse `CatalogImportService`'s own file readers — they're private and
     * built around a filesystem path for the artisan command, whereas this
     * is the request-layer upload-decoding boundary that service's
     * `import()` docblock explicitly carves out for a caller like this one
     * (same split as `VehicleFitmentImportController::decodeRows()`).
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
