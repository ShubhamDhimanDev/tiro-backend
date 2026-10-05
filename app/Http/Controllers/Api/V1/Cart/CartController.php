<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Http\Controllers\Concerns\AuthorizesBookingAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Cart\CartCalculateRequest;
use App\Models\Booking;
use App\Models\ServiceZone;
use App\Services\Commerce\CartItemInput;
use App\Services\Commerce\PricingService;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/cart/calculate` — stateless pricing preview, no row
 * created/persisted. See docs/architecture/02-api-contract.md's "Cart,
 * Checkout & Payment endpoints" section.
 */
class CartController extends Controller
{
    use AuthorizesBookingAccess;

    public function __construct(private readonly PricingService $pricing) {}

    public function calculate(CartCalculateRequest $request): JsonResponse
    {
        $customer = $request->user('customer');

        if ($request->filled('booking_id')) {
            $booking = Booking::query()->find($request->integer('booking_id'));

            abort_if($booking === null, 404);

            $this->authorizeGuestOrOwner($request, $booking);

            return response()->json(['data' => $this->pricing->priceBooking($booking, $customer)->toArray()]);
        }

        $zone = ServiceZone::query()->find($request->integer('zone_id'));

        abort_if($zone === null, 404);

        /** @var list<array{tyre_variant_id: int|string, quantity: int|string}> $itemsInput */
        $itemsInput = $request->validated('items') ?? [];

        $items = collect($itemsInput)->map(fn (array $item): CartItemInput => new CartItemInput(
            tyreVariantId: (int) $item['tyre_variant_id'],
            quantity: (int) $item['quantity'],
        ));

        return response()->json(['data' => $this->pricing->priceItems(
            $items,
            $zone->id,
            $customer,
            promoCode: $request->validated('promo_code'),
            flexible: $request->boolean('flexible'),
        )->toArray()]);
    }
}
