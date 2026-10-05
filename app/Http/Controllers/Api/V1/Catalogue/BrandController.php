<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\BrandDetailResource;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/v1/brands` — see docs/architecture/02-api-contract.md.
 * Unpaginated: the brand count stays small enough for MVP.
 */
class BrandController extends Controller
{
    /**
     * `GET /api/v1/brands/{slug}` — one active brand, with its active model
     * count. `404` for an unknown or inactive brand.
     */
    public function show(string $slug): BrandDetailResource
    {
        $brand = Brand::query()
            ->where('status', Status::Active)
            ->where('slug', $slug)
            ->withCount(['tyreModels' => fn ($query) => $query->where('status', Status::Active)])
            ->first();

        abort_if($brand === null, 404);

        return new BrandDetailResource($brand);
    }

    public function index(): AnonymousResourceCollection
    {
        return BrandResource::collection(
            Brand::query()->where('status', Status::Active)->orderBy('name')->get(),
        );
    }
}
