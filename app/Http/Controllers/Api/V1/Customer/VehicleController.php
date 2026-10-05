<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Concerns\AuthorizesCustomerOwnership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\StoreCustomerVehicleRequest;
use App\Http\Requests\Api\Customer\UpdateCustomerVehicleRequest;
use App\Http\Resources\CustomerVehicleResource;
use App\Models\CustomerVehicle;
use App\Services\Customers\CustomerVehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * `/api/v1/customer/vehicles` — a customer's saved vehicles. `auth:customer`
 * only, no guest path (route middleware) — see
 * {@see AuthorizesCustomerOwnership}'s docblock for why a mismatched/
 * nonexistent id is a plain `404`, not `403`.
 */
class VehicleController extends Controller
{
    use AuthorizesCustomerOwnership;

    public function __construct(private readonly CustomerVehicleService $vehicles) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $customer = $this->authorizedCustomer($request);

        $vehicles = CustomerVehicle::query()
            ->where('customer_id', $customer->id)
            ->with('vehicle')
            ->orderByDesc('created_at')
            ->get();

        return CustomerVehicleResource::collection($vehicles);
    }

    public function store(StoreCustomerVehicleRequest $request): JsonResponse
    {
        $customer = $this->authorizedCustomer($request);

        $vehicle = CustomerVehicle::create([
            'customer_id' => $customer->id,
            'label' => $request->validated('label'),
            'rego' => $request->validated('rego'),
            'state' => $request->validated('state'),
            'vin' => $request->validated('vin'),
            'vehicle_id' => $request->validated('vehicle_id'),
            'saved_fitment' => $request->validated('saved_fitment'),
        ]);

        return (new CustomerVehicleResource($vehicle))->response()->setStatusCode(201);
    }

    /**
     * `$vehicle` is deliberately `string`, not `int`/implicit-bound
     * `CustomerVehicle` — see {@see ownedVehicleOrFail()}'s docblock for
     * why. A plain `string` (matching the raw route segment's natural
     * type) rather than `int` avoids a `TypeError` on a non-numeric id
     * under PHP's weak-typing coercion rules — Eloquent's query builder
     * handles a non-numeric string against an integer PK column by
     * matching no rows (a clean `404`), the same graceful behavior
     * implicit binding already had.
     */
    public function update(UpdateCustomerVehicleRequest $request, string $vehicle): CustomerVehicleResource
    {
        $vehicle = $this->ownedVehicleOrFail($request, $vehicle);

        $vehicle->update($request->validated());

        return new CustomerVehicleResource($vehicle);
    }

    public function destroy(Request $request, string $vehicle): Response
    {
        $vehicle = $this->ownedVehicleOrFail($request, $vehicle);

        // Hard delete, no referenced-row restriction — unlike
        // `Address::destroy()`, `CustomerVehicle` is never referenced by
        // `Order`/`Booking` (only the catalogue `Vehicle` is, via
        // `Booking.vehicle_id`).
        $vehicle->delete();

        return response()->noContent();
    }

    public function setDefault(Request $request, string $vehicle): CustomerVehicleResource
    {
        $vehicle = $this->ownedVehicleOrFail($request, $vehicle);

        return new CustomerVehicleResource($this->vehicles->setDefault($vehicle));
    }

    /**
     * Resolve `$id` as a `CustomerVehicle` scoped to the authenticated
     * customer, or throw a `ModelNotFoundException` (404) — identically,
     * whether the row doesn't exist at all or belongs to another customer.
     * Deliberately NOT implicit route-model-binding: binding resolves
     * before ownership is known, and a genuinely nonexistent id throws its
     * own `ModelNotFoundException` (message not suppressed by
     * `APP_DEBUG=false`) *before* this ownership check ever runs — a
     * mismatched-owner row, resolved successfully by that unscoped
     * binding, previously only hit a message-less `abort_if(..., 404)`
     * here instead. That asymmetry let an authenticated customer
     * distinguish "this id exists (just isn't mine)" from "this id doesn't
     * exist anywhere" by reading the response body, even though both
     * returned `404` — see security review, Phase 7 item 2. Scoping the
     * query itself, so both cases hit the exact same `findOrFail()`
     * failure path, closes that gap.
     */
    private function ownedVehicleOrFail(Request $request, string $id): CustomerVehicle
    {
        $customer = $this->authorizedCustomer($request);

        return CustomerVehicle::query()->where('customer_id', $customer->id)->findOrFail($id);
    }
}
