<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Products\ProductExportRequest;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\TyreVariant;
use App\Services\Products\ProductExportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel (.xlsx) export of the product catalogue — one row per size variant,
 * with the admin choosing which brands/filters apply and which columns the
 * sheet contains. Read-only, so it sits behind `products.view`.
 */
class ProductExportController extends Controller
{
    /**
     * The picker page: columns, filter options, and a live count of the rows
     * the current filters match (a partial-reload prop, recomputed as the
     * admin changes filters).
     */
    public function index(ProductExportRequest $request, ProductExportService $exporter): Response
    {
        $columns = $request->columns();

        return Inertia::render('products/export', [
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'columns' => array_map(
                fn (array $column): array => [
                    'key' => $column['key'],
                    'label' => $column['label'],
                    'group' => $column['group'],
                    'default' => $column['default'],
                ],
                ProductExportService::columns(),
            ),
            'options' => [
                'categories' => $this->options(ProductExportService::CATEGORY_LABELS),
                'tyreTypes' => $this->options(ProductExportService::TYRE_TYPE_LABELS),
                'constructions' => $this->options(ProductExportService::CONSTRUCTION_LABELS),
                'sidewalls' => $this->options(ProductExportService::SIDEWALL_LABELS),
                'statuses' => $this->options(ProductExportService::STATUS_LABELS),
                'rimDiameters' => DB::table('tyre_variants')->distinct()->orderBy('rim_diameter')->pluck('rim_diameter'),
            ],
            'selected' => [
                'columns' => $columns,
                'filters' => $request->filters(),
            ],
            'matchingCount' => fn (): int => $exporter->count($request->filters()),
            'totalCount' => fn (): int => TyreVariant::query()->count(),
        ]);
    }

    /**
     * Stream the workbook. Audited like the catalogue import, since it hands
     * pricing data out of the system.
     */
    public function download(ProductExportRequest $request, ProductExportService $exporter): StreamedResponse
    {
        $columns = $request->columns();

        if ($columns === []) {
            throw ValidationException::withMessages(['columns' => 'Select at least one column to export.']);
        }

        $filters = $request->filters();
        $filename = 'products-'.now()->format('Y-m-d-His').'.xlsx';

        $this->recordExport($request, $columns, $filters, $exporter->count($filters));

        return response()->streamDownload(
            fn () => $exporter->write($columns, $filters),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $filters
     */
    private function recordExport(ProductExportRequest $request, array $columns, array $filters, int $rows): void
    {
        $summary = ['rows' => $rows, 'columns' => $columns, 'filters' => $filters];

        AuditLog::create([
            'auditable_type' => TyreVariant::class,
            'auditable_id' => 0,
            'action' => 'catalog.exported',
            'actor_id' => $request->user()?->id,
            'before' => null,
            'after' => $summary,
        ]);

        Log::info('Catalog export started.', ['actor_id' => $request->user()?->id, 'rows' => $rows, 'column_count' => count($columns)]);
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($labels),
            array_values($labels),
        );
    }
}
