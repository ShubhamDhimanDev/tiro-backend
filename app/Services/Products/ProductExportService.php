<?php

namespace App\Services\Products;

use App\Models\TyreVariant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Builds the admin "Export products" .xlsx — one row per sellable
 * {@see TyreVariant} (the SKU), with its tyre model and brand
 * columns alongside, filtered and column-trimmed by what the admin picked.
 *
 * Reads with the query builder (no Eloquent hydration) and only selects the
 * columns that were asked for, then streams rows through OpenSpout so memory
 * stays flat regardless of catalogue size.
 *
 * @phpstan-type Column array{
 *     key: string, label: string, group: string, default: bool, width: float,
 *     select: list<string>, format: \Closure(object): (string|int|float|null),
 * }
 * @phpstan-type Filters array{
 *     brand_ids?: list<int>, categories?: list<string>, tyre_types?: list<string>,
 *     constructions?: list<string>, sidewalls?: list<string>, statuses?: list<string>,
 *     model_statuses?: list<string>, rim_diameters?: list<int>, run_flat?: 'yes'|'no'|null,
 *     in_stock_only?: bool, price_min?: float|int|string|null, price_max?: float|int|string|null,
 *     search?: string|null,
 * }
 */
class ProductExportService
{
    /** Excel's hard per-cell limit is 32,767 characters. */
    private const MAX_CELL_LENGTH = 32000;

    /** @var array<string, string> */
    public const CATEGORY_LABELS = [
        'car' => 'Car', 'suv' => 'SUV', '4x4' => '4x4', 'light_truck' => 'Light truck',
    ];

    /** @var array<string, string> */
    public const TYRE_TYPE_LABELS = [
        'highway' => 'Highway', 'all_terrain' => 'All terrain', 'mud_terrain' => 'Mud terrain',
        'performance' => 'Performance', 'eco' => 'Eco',
    ];

    /** @var array<string, string> */
    public const CONSTRUCTION_LABELS = ['radial' => 'Radial', 'bias_ply' => 'Bias ply'];

    /** @var array<string, string> */
    public const SIDEWALL_LABELS = [
        'standard' => 'Standard', 'xl' => 'Extra load (XL)', 'reinforced' => 'Reinforced', 'commercial' => 'Commercial',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'draft' => 'Draft', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived',
    ];

    /** @var array<string, string> */
    private const TIER_LABELS = ['premium' => 'Premium', 'mid' => 'Mid', 'budget' => 'Budget'];

    /**
     * The exportable columns, in the order they appear in the sheet.
     *
     * @return list<Column>
     */
    public static function columns(): array
    {
        $text = fn (string $property): \Closure => fn (object $row): ?string => self::text($row->{$property} ?? null);
        $number = fn (string $property): \Closure => fn (object $row): int|float|null => $row->{$property} === null ? null : $row->{$property} + 0;
        $label = fn (string $property, array $labels): \Closure => fn (object $row): ?string => self::label($row->{$property} ?? null, $labels);
        $date = fn (string $property): \Closure => fn (object $row): ?string => self::date($row->{$property} ?? null);
        $list = fn (string $property, string $glue): \Closure => fn (object $row): ?string => self::joinedList($row->{$property} ?? null, $glue);

        $stockSub = fn (string $expression, string $alias): string => "(select coalesce({$expression}, 0) from inventory_items where inventory_items.tyre_variant_id = v.id) as {$alias}";

        return [
            // Brand
            self::column('brand_name', 'Brand', 'Brand', true, 22, 'b.name as brand_name', $text('brand_name')),
            self::column('brand_slug', 'Brand slug', 'Brand', false, 22, 'b.slug as brand_slug', $text('brand_slug')),
            self::column('brand_country', 'Country of origin', 'Brand', false, 18, 'b.country_of_origin as brand_country', $text('brand_country')),
            self::column('brand_tier', 'Brand tier', 'Brand', false, 12, 'b.tier as brand_tier', $label('brand_tier', self::TIER_LABELS)),
            self::column('brand_status', 'Brand status', 'Brand', false, 12, 'b.status as brand_status', $label('brand_status', self::STATUS_LABELS)),

            // Tyre model
            self::column('model_name', 'Model', 'Tyre model', true, 26, 'm.name as model_name', $text('model_name')),
            self::column('model_slug', 'Model slug', 'Tyre model', false, 26, 'm.slug as model_slug', $text('model_slug')),
            self::column('category', 'Category', 'Tyre model', true, 14, 'm.category as category', $label('category', self::CATEGORY_LABELS)),
            self::column('tyre_type', 'Tyre type', 'Tyre model', true, 16, 'm.tyre_type as tyre_type', $label('tyre_type', self::TYRE_TYPE_LABELS)),
            self::column('construction', 'Construction', 'Tyre model', false, 14, 'm.construction as construction', $label('construction', self::CONSTRUCTION_LABELS)),
            self::column('run_flat', 'Run-flat', 'Tyre model', false, 10, 'm.run_flat as run_flat', fn (object $row): string => $row->run_flat ? 'Yes' : 'No'),
            self::column('warranty_km', 'Warranty (km)', 'Tyre model', false, 14, 'm.warranty_km as warranty_km', $number('warranty_km')),
            self::column('warranty_text', 'Warranty details', 'Tyre model', false, 36, 'm.warranty_text as warranty_text', $text('warranty_text')),
            self::column('service_inclusions', 'Service inclusions', 'Tyre model', false, 40, 'm.service_inclusions as service_inclusions', $list('service_inclusions', '; ')),
            self::column('description', 'Description', 'Tyre model', false, 60, 'm.description as description', $text('description')),
            self::column('images', 'Image URLs', 'Tyre model', false, 50, 'm.images as images', $list('images', "\n")),
            self::column('released_at', 'Released', 'Tyre model', false, 12, 'm.released_at as released_at', $date('released_at')),
            self::column('model_status', 'Model status', 'Tyre model', false, 12, 'm.status as model_status', $label('model_status', self::STATUS_LABELS)),

            // Size variant (the SKU)
            self::column('sku', 'SKU', 'Size variant', true, 20, 'v.sku as sku', $text('sku')),
            self::column('size', 'Size', 'Size variant', true, 16, ['v.width as width', 'v.profile as profile', 'v.rim_diameter as rim_diameter'], fn (object $row): string => "{$row->width}/{$row->profile}R{$row->rim_diameter}"),
            self::column('width', 'Width (mm)', 'Size variant', false, 12, 'v.width as width', $number('width')),
            self::column('profile', 'Profile', 'Size variant', false, 10, 'v.profile as profile', $number('profile')),
            self::column('rim_diameter', 'Rim (in)', 'Size variant', false, 10, 'v.rim_diameter as rim_diameter', $number('rim_diameter')),
            self::column('load_index', 'Load index', 'Size variant', true, 11, 'v.load_index as load_index', $text('load_index')),
            self::column('speed_rating', 'Speed rating', 'Size variant', true, 12, 'v.speed_rating as speed_rating', $text('speed_rating')),
            self::column('sidewall', 'Sidewall', 'Size variant', false, 16, 'v.sidewall as sidewall', $label('sidewall', self::SIDEWALL_LABELS)),
            self::column('ean', 'EAN', 'Size variant', false, 16, 'v.ean as ean', $text('ean')),
            self::column('weight_kg', 'Weight (kg)', 'Size variant', false, 12, 'v.weight_kg as weight_kg', $number('weight_kg')),
            self::column('base_price', 'Base price ($)', 'Size variant', true, 14, 'v.base_price as base_price', fn (object $row): int|float => round($row->base_price / 100, 2)),
            self::column('variant_slug', 'Variant slug', 'Size variant', false, 30, 'v.slug as variant_slug', $text('variant_slug')),
            self::column('variant_status', 'Status', 'Size variant', true, 12, 'v.status as variant_status', $label('variant_status', self::STATUS_LABELS)),
            self::column('variant_created_at', 'Created', 'Size variant', false, 18, 'v.created_at as variant_created_at', $date('variant_created_at')),
            self::column('variant_updated_at', 'Last updated', 'Size variant', false, 18, 'v.updated_at as variant_updated_at', $date('variant_updated_at')),

            // Stock — summed across every stock location
            self::column('stock_on_hand', 'Stock on hand', 'Stock', false, 14, $stockSub('sum(qty_on_hand)', 'stock_on_hand'), $number('stock_on_hand')),
            self::column('stock_reserved', 'Stock reserved', 'Stock', false, 14, $stockSub('sum(qty_reserved)', 'stock_reserved'), $number('stock_reserved')),
            self::column('stock_available', 'Stock available', 'Stock', false, 14, $stockSub('sum(qty_on_hand - qty_reserved)', 'stock_available'), $number('stock_available')),
        ];
    }

    /**
     * @return list<string>
     */
    public static function columnKeys(): array
    {
        return array_column(self::columns(), 'key');
    }

    /**
     * The base query: one row per variant, joined to its model and brand,
     * with every filter applied. Select list is added by {@see write()}.
     *
     * @param  Filters  $filters
     */
    public function query(array $filters): Builder
    {
        $query = DB::table('tyre_variants as v')
            ->join('tyre_models as m', 'm.id', '=', 'v.tyre_model_id')
            ->join('brands as b', 'b.id', '=', 'm.brand_id');

        $query
            ->when($filters['brand_ids'] ?? [], fn (Builder $q, array $v) => $q->whereIn('b.id', $v))
            ->when($filters['categories'] ?? [], fn (Builder $q, array $v) => $q->whereIn('m.category', $v))
            ->when($filters['tyre_types'] ?? [], fn (Builder $q, array $v) => $q->whereIn('m.tyre_type', $v))
            ->when($filters['constructions'] ?? [], fn (Builder $q, array $v) => $q->whereIn('m.construction', $v))
            ->when($filters['model_statuses'] ?? [], fn (Builder $q, array $v) => $q->whereIn('m.status', $v))
            ->when($filters['sidewalls'] ?? [], fn (Builder $q, array $v) => $q->whereIn('v.sidewall', $v))
            ->when($filters['statuses'] ?? [], fn (Builder $q, array $v) => $q->whereIn('v.status', $v))
            ->when($filters['rim_diameters'] ?? [], fn (Builder $q, array $v) => $q->whereIn('v.rim_diameter', $v));

        if (($filters['run_flat'] ?? null) !== null) {
            $query->where('m.run_flat', $filters['run_flat'] === 'yes');
        }

        if (($filters['price_min'] ?? null) !== null && $filters['price_min'] !== '') {
            $query->where('v.base_price', '>=', (int) round((float) $filters['price_min'] * 100));
        }

        if (($filters['price_max'] ?? null) !== null && $filters['price_max'] !== '') {
            $query->where('v.base_price', '<=', (int) round((float) $filters['price_max'] * 100));
        }

        if (! empty($filters['in_stock_only'])) {
            $query->whereExists(fn (Builder $sub) => $sub
                ->selectRaw('1')
                ->from('inventory_items')
                ->whereColumn('inventory_items.tyre_variant_id', 'v.id')
                ->whereRaw('inventory_items.qty_on_hand - inventory_items.qty_reserved > 0'));
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(fn (Builder $q) => $q
                ->where('v.sku', 'like', $like)
                ->orWhere('v.ean', 'like', $like)
                ->orWhere('m.name', 'like', $like)
                ->orWhere('b.name', 'like', $like));
        }

        return $query;
    }

    /**
     * @param  Filters  $filters
     */
    public function count(array $filters): int
    {
        return $this->query($filters)->count();
    }

    /**
     * Write the workbook to `$target` (default: the response body) and
     * return how many product rows were written.
     *
     * @param  list<string>  $columnKeys
     * @param  Filters  $filters
     */
    public function write(array $columnKeys, array $filters, string $target = 'php://output'): int
    {
        $columns = array_values(array_filter(
            self::columns(),
            fn (array $column): bool => in_array($column['key'], $columnKeys, true),
        ));

        $options = new Options;
        foreach ($columns as $index => $column) {
            $options->setColumnWidth($column['width'], $index + 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($target);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Products');
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $writer->addRow(Row::fromValuesWithStyle(
            array_column($columns, 'label'),
            (new Style)->withFontBold(true)->withBackgroundColor('E8EDF2'),
        ));

        $selects = array_values(array_unique(array_merge(...array_column($columns, 'select'))));

        $rows = 0;

        $this->query($filters)
            ->selectRaw(implode(', ', $selects))
            ->orderBy('b.name')
            ->orderBy('m.name')
            ->orderBy('v.width')
            ->orderBy('v.profile')
            ->orderBy('v.rim_diameter')
            ->orderBy('v.id')
            ->lazy(1000)
            ->each(function (object $row) use ($writer, $columns, &$rows): void {
                $writer->addRow(Row::fromValues(array_map(
                    fn (array $column): string|int|float|null => ($column['format'])($row),
                    $columns,
                )));
                $rows++;
            });

        $writer->close();

        return $rows;
    }

    /**
     * @param  \Closure(object): (string|int|float|null)  $format
     * @return Column
     */
    private static function column(string $key, string $label, string $group, bool $default, float $width, string|array $select, \Closure $format): array
    {
        $select = (array) $select;

        return compact('key', 'label', 'group', 'default', 'width', 'select', 'format');
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::limit((string) $value, self::MAX_CELL_LENGTH, '');
    }

    /**
     * @param  array<string, string>  $labels
     */
    private static function label(mixed $value, array $labels): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $labels[$value] ?? Str::headline((string) $value);
    }

    /**
     * Dates as `Y-m-d`, timestamps as `Y-m-d H:i`.
     */
    private static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 16);
    }

    private static function joinedList(mixed $json, string $glue): ?string
    {
        $items = is_string($json) ? json_decode($json, true) : $json;

        if (! is_array($items) || $items === []) {
            return null;
        }

        return self::text(implode($glue, array_map('strval', $items)));
    }
}
