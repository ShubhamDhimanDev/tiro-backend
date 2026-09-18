<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Products\BrandRequest;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BrandController extends Controller
{
    /**
     * Display the brand catalogue — entry point of the brand -> models ->
     * variants drill-down.
     */
    public function index(): Response
    {
        $brands = Brand::query()
            ->withCount('tyreModels')
            ->orderBy('name')
            ->get();

        return Inertia::render('products/brands/index', [
            'brands' => $brands,
        ]);
    }

    /**
     * Store a newly created brand.
     */
    public function store(BrandRequest $request): RedirectResponse
    {
        Brand::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand created.')]);

        return back();
    }

    /**
     * Update the given brand.
     */
    public function update(BrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Brand updated.')]);

        return back();
    }
}
