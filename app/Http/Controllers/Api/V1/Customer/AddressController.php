<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\AddressType;
use App\Http\Controllers\Concerns\AuthorizesCustomerOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\StoreCustomerAddressRequest;
use App\Http\Requests\Api\Customer\UpdateCustomerAddressRequest;
use App\Http\Resources\CustomerAddressResource;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Order;
use App\Models\Suburb;
use App\Services\Customers\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * `/api/v1/customer/addresses` — a customer's saved address book.
 * `auth:customer` only, no guest path (route middleware) — see
 * {@see AuthorizesCustomerOwnership}'s docblock for why a mismatched/
 * nonexistent id is a plain `404`, not `403`. Includes historical addresses
 * captured via checkout — `OrderController::createAddress()` already sets
 * `Address.customer_id` on every order, so `index()` is a normal
 * `where customer_id = ...` query, no backfill needed.
 */
class AddressController extends Controller
{
    use AuthorizesCustomerOwnership;

    public function __construct(private readonly AddressService $addresses) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $customer = $this->authorizedCustomer($request);

        $addresses = Address::query()
            ->where('customer_id', $customer->id)
            ->with(['suburb.state'])
            ->orderByDesc('created_at')
            ->get();

        return CustomerAddressResource::collection($addresses);
    }

    /**
     * `type` is always `fitting`, not client-settable — `billing` stays
     * unbuilt (pre-existing decision, not touched here). `postcode` is
     * derived from the linked `Suburb.postcode`, same as
     * `OrderController::createAddress()`.
     */
    public function store(StoreCustomerAddressRequest $request): JsonResponse
    {
        $customer = $this->authorizedCustomer($request);

        $suburb = Suburb::query()->findOrFail($request->integer('suburb_id'));

        $address = Address::create([
            'customer_id' => $customer->id,
            'label' => $request->validated('label'),
            'suburb_id' => $suburb->id,
            'line1' => $request->validated('line1'),
            'line2' => $request->validated('line2'),
            'postcode' => $suburb->postcode,
            'lat' => $request->validated('lat'),
            'lng' => $request->validated('lng'),
            'access_instructions' => $request->validated('access_instructions'),
            'type' => AddressType::Fitting,
        ]);

        return (new CustomerAddressResource($address))->response()->setStatusCode(201);
    }

    /**
     * `$address` is deliberately `string`, not `int`/implicit-bound
     * `Address` — see {@see ownedAddressOrFail()}'s docblock.
     */
    public function update(UpdateCustomerAddressRequest $request, string $address): CustomerAddressResource
    {
        $address = $this->ownedAddressOrFail($request, $address);

        $attributes = $request->validated();

        if (array_key_exists('suburb_id', $attributes)) {
            $suburb = Suburb::query()->findOrFail((int) $attributes['suburb_id']);
            $attributes['postcode'] = $suburb->postcode;
        }

        $address->update($attributes);

        return new CustomerAddressResource($address);
    }

    /**
     * Hard delete ONLY IF zero `Order`/`Booking` rows reference this
     * address — otherwise `409`, see this method's response message.
     */
    public function destroy(Request $request, string $address): Response|JsonResponse
    {
        $address = $this->ownedAddressOrFail($request, $address);

        $referenced = Order::query()->where('address_id', $address->id)->exists()
            || Booking::query()->where('address_id', $address->id)->exists();

        if ($referenced) {
            return response()->json([
                'message' => __("This address is attached to an order and can't be removed — you can stop it being your default instead."),
            ], 409);
        }

        $address->delete();

        return response()->noContent();
    }

    public function setDefault(Request $request, string $address): CustomerAddressResource
    {
        $address = $this->ownedAddressOrFail($request, $address);

        return new CustomerAddressResource($this->addresses->setDefault($address));
    }

    /**
     * Resolve `$id` as an `Address` scoped to the authenticated customer,
     * or throw a `ModelNotFoundException` (404) — identically, whether the
     * row doesn't exist at all or belongs to another customer. See
     * `VehicleController::ownedVehicleOrFail()`'s docblock for the full
     * "why not implicit route-model-binding" explanation (security review,
     * Phase 7 item 2) — this mirrors it exactly for `Address`.
     */
    private function ownedAddressOrFail(Request $request, string $id): Address
    {
        $customer = $this->authorizedCustomer($request);

        return Address::query()->where('customer_id', $customer->id)->findOrFail($id);
    }
}
