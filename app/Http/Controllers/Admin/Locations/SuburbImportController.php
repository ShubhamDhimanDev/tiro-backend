<?php

namespace App\Http\Controllers\Admin\Locations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Locations\SuburbImportRequest;
use App\Services\Location\SuburbImportService;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk create/update of suburbs from an Excel (.xlsx) or CSV sheet with
 * the columns Suburb, Postcode, State, Country. Row-level results are
 * rendered back on the same page so no row is silently dropped.
 */
class SuburbImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('locations/suburbs/import');
    }

    public function store(SuburbImportRequest $request, SuburbImportService $importer): Response
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $format = strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';

        $result = $importer->importFile($file->getRealPath(), $format, $request->boolean('dry_run'));

        return Inertia::render('locations/suburbs/import', [
            'result' => $result->toArray(),
        ]);
    }
}
