<?php

namespace App\Http\Controllers\Admin\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\InventoryItemRequest;
use App\Models\InventoryItem;
use App\Models\StockLocation;
use App\Models\TyreVariant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InventoryItemController extends Controller
{
    /**
     * Display the per-variant stock rows held at the given location.
     *
     * `availableVariants` feeds the "add stock row" picker on the frontend
     * — every active variant, so the admin can search/select one that isn't
     * already stocked here (the `InventoryItemRequest` uniqueness rule is
     * the actual guard against duplicates).
     */
    public function index(StockLocation $stockLocation): Response
    {
        $inventoryItems = $stockLocation->inventoryItems()
            ->with(['tyreVariant.tyreModel.brand'])
            ->get();

        $availableVariants = TyreVariant::query()
            ->with(['tyreModel.brand'])
            ->orderBy('sku')
            ->get(['id', 'tyre_model_id', 'sku', 'width', 'profile', 'rim_diameter', 'load_index', 'speed_rating']);

        return Inertia::render('inventory/locations/items', [
            'stockLocation' => $stockLocation,
            'inventoryItems' => $inventoryItems,
            'availableVariants' => $availableVariants,
        ]);
    }

    /**
     * Store a newly created stock row at the given location.
     */
    public function store(InventoryItemRequest $request, StockLocation $stockLocation): RedirectResponse
    {
        $stockLocation->inventoryItems()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock row added.')]);

        return back();
    }

    /**
     * Update the given stock row's quantities.
     */
    public function update(InventoryItemRequest $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $inventoryItem->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock levels updated.')]);

        return back();
    }

    /**
     * Remove the given stock row.
     */
    public function destroy(InventoryItem $inventoryItem): RedirectResponse
    {
        $inventoryItem->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stock row removed.')]);

        return back();
    }
}
