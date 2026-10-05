<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Concerns\AuthorizesCustomerOwnership;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerOrderSummaryResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/v1/customer/orders` — the authenticated customer's order/booking
 * history, standard paginated envelope, `placed_at` descending. `auth:customer`
 * only (route middleware). No separate `/customer/bookings` endpoint — the
 * linked booking's appointment summary is nested per order. Detail view
 * reuses the existing `GET /api/v1/orders/{order}` unmodified.
 */
class OrderController extends Controller
{
    use AuthorizesCustomerOwnership;

    public function index(Request $request): AnonymousResourceCollection
    {
        $customer = $this->authorizedCustomer($request);

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with('booking:id,order_id,scheduled_date,slot_start,slot_end')
            ->orderByDesc('placed_at')
            ->paginate(20)
            ->withQueryString();

        return CustomerOrderSummaryResource::collection($orders);
    }
}
