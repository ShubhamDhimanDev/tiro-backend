<?php

namespace App\Http\Controllers\Api\V1\PriceGuaranteeClaims;

use App\Enums\PriceGuaranteeClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PriceGuaranteeClaims\StorePriceGuaranteeClaimRequest;
use App\Http\Resources\PriceGuaranteeClaimResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PriceGuaranteeClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/price-guarantee-claims`, `GET /api/v1/price-guarantee-claims`
 * — see docs/architecture/02-api-contract.md's "Promotions & Price-Guarantee
 * endpoints" section. Both routes are `auth:customer`-only (no guest path —
 * see docs/architecture/05-promotions-pricing.md's "Price-guarantee claim
 * workflow" section for why), enforced at the route level. No
 * `Idempotency-Key` required — a duplicate claim row from a double-submit is
 * an admin-queue nuisance, not a money bug.
 */
class PriceGuaranteeClaimController extends Controller
{
    /**
     * `order_id`, when present, must belong to the authenticated customer —
     * `403` otherwise (existence itself is validated by the FormRequest's
     * `exists:orders,id` rule, a `422`; ownership is a separate, later
     * check, same "malformed vs. not-yours" split this project uses
     * elsewhere).
     */
    public function store(StorePriceGuaranteeClaimRequest $request): JsonResponse
    {
        /** @var Customer $customer route enforces auth:customer */
        $customer = $request->user('customer');

        $orderId = $request->validated('order_id');
        $orderId = $orderId === null ? null : (int) $orderId;

        if ($orderId !== null) {
            $order = Order::query()->find($orderId);

            abort_if($order === null || $order->customer_id !== $customer->id, 403);
        }

        $claim = PriceGuaranteeClaim::create([
            'customer_id' => $customer->id,
            'order_id' => $orderId,
            'competitor_url' => $request->validated('competitor_url'),
            'competitor_price' => $request->validated('competitor_price'),
            'tyre_variant_id' => $request->validated('tyre_variant_id'),
            'status' => PriceGuaranteeClaimStatus::Pending,
        ]);

        return (new PriceGuaranteeClaimResource($claim))->response()->setStatusCode(201);
    }

    /**
     * The authenticated customer's own claims only — standard paginated
     * envelope.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Customer $customer route enforces auth:customer */
        $customer = $request->user('customer');

        $claims = PriceGuaranteeClaim::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return PriceGuaranteeClaimResource::collection($claims)->response();
    }
}
