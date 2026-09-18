<?php

namespace App\Http\Requests\Admin\Inventory;

use App\Models\InventoryItem;
use App\Models\StockLocation;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared create/update rules for {@see InventoryItem}.
 * `stock_location_id` is only accepted on create (via the
 * `{stockLocation}` route segment) — a stock row never moves location from
 * this form; add a new row at the new location and remove the old one
 * instead.
 */
class InventoryItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('inventory.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var InventoryItem|null $item */
        $item = $this->route('inventoryItem');

        /** @var StockLocation|null $stockLocation */
        $stockLocation = $this->route('stockLocation');
        $newStockLocationId = $stockLocation?->id;
        $stockLocationId = $newStockLocationId ?? $item?->stock_location_id;

        return [
            'tyre_variant_id' => [
                'required',
                Rule::exists('tyre_variants', 'id'),
                Rule::unique('inventory_items', 'tyre_variant_id')
                    ->where('stock_location_id', $stockLocationId)
                    ->ignore($item),
            ],
            'qty_on_hand' => ['required', 'integer', 'min:0'],
            'qty_reserved' => ['required', 'integer', 'min:0', 'lte:qty_on_hand'],
            'reorder_point' => ['required', 'integer', 'min:0'],
        ];
    }
}
