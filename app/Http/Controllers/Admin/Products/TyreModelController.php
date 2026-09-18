<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Products\TyreModelRequest;
use App\Models\Brand;
use App\Models\TyreModel;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TyreModelController extends Controller
{
    /**
     * Display the tyre models sold under the given brand.
     */
    public function index(Brand $brand): Response
    {
        $tyreModels = $brand->tyreModels()
            ->withCount('tyreVariants')
            ->orderBy('name')
            ->get();

        return Inertia::render('products/brands/models', [
            'brand' => $brand,
            'tyreModels' => $tyreModels,
        ]);
    }

    /**
     * Store a newly created tyre model under the given brand.
     */
    public function store(TyreModelRequest $request, Brand $brand): RedirectResponse
    {
        $brand->tyreModels()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tyre model created.')]);

        return back();
    }

    /**
     * Update the given tyre model.
     */
    public function update(TyreModelRequest $request, TyreModel $tyreModel): RedirectResponse
    {
        $tyreModel->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tyre model updated.')]);

        return back();
    }
}
