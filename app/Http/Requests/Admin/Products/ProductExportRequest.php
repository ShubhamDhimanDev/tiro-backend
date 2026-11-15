<?php

namespace App\Http\Requests\Admin\Products;

use App\Services\Products\ProductExportService;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the column picker and filters of the product export. Used by
 * both the options page (live "N rows match" count) and the download, so
 * the two can never disagree about what a given filter set means.
 *
 * @phpstan-import-type Filters from ProductExportService
 */
class ProductExportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('products.view') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $in = fn (array $labels): array => [Rule::in(array_keys($labels))];

        return [
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', Rule::in(ProductExportService::columnKeys())],
            'brand_ids' => ['nullable', 'array'],
            'brand_ids.*' => ['integer', 'exists:brands,id'],
            'categories' => ['nullable', 'array'],
            'categories.*' => $in(ProductExportService::CATEGORY_LABELS),
            'tyre_types' => ['nullable', 'array'],
            'tyre_types.*' => $in(ProductExportService::TYRE_TYPE_LABELS),
            'constructions' => ['nullable', 'array'],
            'constructions.*' => $in(ProductExportService::CONSTRUCTION_LABELS),
            'sidewalls' => ['nullable', 'array'],
            'sidewalls.*' => $in(ProductExportService::SIDEWALL_LABELS),
            'statuses' => ['nullable', 'array'],
            'statuses.*' => $in(ProductExportService::STATUS_LABELS),
            'model_statuses' => ['nullable', 'array'],
            'model_statuses.*' => $in(ProductExportService::STATUS_LABELS),
            'rim_diameters' => ['nullable', 'array'],
            'rim_diameters.*' => ['integer', 'min:1'],
            'run_flat' => ['nullable', Rule::in(['yes', 'no'])],
            'in_stock_only' => ['nullable', 'boolean'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0', 'gte:price_min'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The chosen columns, or an empty list when none were sent.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        /** @var list<string> */
        return array_values($this->validated('columns') ?? []);
    }

    /**
     * The validated filters, in the shape {@see ProductExportService} takes.
     *
     * @return Filters
     */
    public function filters(): array
    {
        $validated = $this->validated();
        unset($validated['columns']);

        $validated['in_stock_only'] = $this->boolean('in_stock_only');

        /** @var Filters */
        return $validated;
    }
}
