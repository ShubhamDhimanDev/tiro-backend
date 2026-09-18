<?php

namespace App\Http\Controllers\Api\V1\Catalogue;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Models\Brand;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/v1/brands` — see docs/architecture/02-api-contract.md.
 * Unpaginated: the brand count stays small enough for MVP.
 */
class BrandController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BrandResource::collection(
            Brand::query()->where('status', Status::Active)->orderBy('name')->get(),
        );
    }
}
