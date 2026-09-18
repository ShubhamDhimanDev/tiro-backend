<?php

namespace App\Http\Controllers\Admin\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Products\TyreVariantRequest;
use App\Models\TyreModel;
use App\Models\TyreVariant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TyreVariantController extends Controller
{
    /**
     * Display the sellable size variants for the given tyre model.
     */
    public function index(TyreModel $tyreModel): Response
    {
        $tyreModel->load('brand');

        $tyreVariants = $tyreModel->tyreVariants()
            ->orderBy('width')
            ->orderBy('profile')
            ->orderBy('rim_diameter')
            ->get();

        return Inertia::render('products/models/variants', [
            'tyreModel' => $tyreModel,
            'tyreVariants' => $tyreVariants,
        ]);
    }

    /**
     * Store a newly created variant under the given tyre model.
     */
    public function store(TyreVariantRequest $request, TyreModel $tyreModel): RedirectResponse
    {
        $tyreModel->tyreVariants()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Variant created.')]);

        return back();
    }

    /**
     * Update the given tyre variant.
     */
    public function update(TyreVariantRequest $request, TyreVariant $tyreVariant): RedirectResponse
    {
        $tyreVariant->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Variant updated.')]);

        return back();
    }
}
