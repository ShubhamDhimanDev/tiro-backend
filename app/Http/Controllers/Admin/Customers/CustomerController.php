<?php

namespace App\Http\Controllers\Admin\Customers;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\NotificationLog;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers admin — search/view only, no staff-side mutation actions this
 * phase. Every action here is gated on `customers.view` at the route level
 * (`routes/admin.php`); `customers.manage` implies `customers.view` for
 * every role that holds it (`RolesAndPermissionsSeeder::permissionNamesForTier()`),
 * so this controller never needs to distinguish the two tiers itself — same
 * convention as `App\Http\Controllers\Admin\Orders\OrderController`'s
 * docblock.
 *
 * Saved vehicles/addresses are shown read-only on the detail screen: both
 * are a customer self-service feature reachable via
 * `/api/v1/customer/vehicles` and `/api/v1/customer/addresses` (see
 * `App\Http\Controllers\Api\V1\Customer\VehicleController`/`AddressController`),
 * and no staff support workflow this phase needs an admin-side edit/remove
 * action for either — if one emerges, add it as its own gated mutation
 * rather than widening this controller silently.
 *
 * The "recent notifications" panel on the detail screen reuses
 * `NotificationLog` under this same `customers.view` gate — no new
 * permission, a deliberate Phase 7 decision (see
 * docs/architecture/07-admin-auth-permissions.md §3.4). It exposes only
 * type/channel/status/recipient/created_at/error_message — `NotificationLog`
 * stores no message subject/body by design (see that model's docblock), so
 * there is nothing else to expose.
 */
class CustomerController extends Controller
{
    /** Recent order/notification rows shown on the detail screen. Both are a
     * capped recent list, not a second paginated sub-view — see this
     * class's docblock and the task brief's "don't duplicate the Orders
     * admin module's detail view" instruction. */
    private const RECENT_LIMIT = 20;

    /**
     * Search/list customers by name, email, or mobile.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->toString() ?: null;

        $customers = Customer::query()
            ->select(['id', 'name', 'email', 'mobile', 'created_at'])
            ->when($search, function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('customers/index', [
            'filters' => ['search' => $search],
            'customers' => $customers,
        ]);
    }

    /**
     * Customer detail: profile, saved vehicles, saved addresses, the most
     * recent {@see RECENT_LIMIT} orders (a link into the existing Order
     * admin detail screen for anything deeper — this project already has a
     * full Orders admin module, this screen doesn't duplicate it), and the
     * most recent {@see RECENT_LIMIT} `NotificationLog` rows for this
     * customer.
     */
    public function show(Customer $customer): Response
    {
        $vehicles = CustomerVehicle::query()
            ->where('customer_id', $customer->id)
            ->with('vehicle:id,make,model,series,year_from,year_to')
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        $addresses = Address::query()
            ->where('customer_id', $customer->id)
            ->with(['suburb:id,name,state_id,postcode', 'suburb.state:id,code,name'])
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'order_number', 'status', 'payment_status', 'grand_total', 'currency', 'placed_at', 'created_at']);

        $ordersCount = Order::query()->where('customer_id', $customer->id)->count();

        $notifications = NotificationLog::query()
            ->where('notifiable_type', Customer::class)
            ->where('notifiable_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'type', 'channel', 'status', 'recipient', 'error_message', 'created_at']);

        return Inertia::render('customers/show', [
            'customer' => $customer,
            'vehicles' => $vehicles,
            'addresses' => $addresses,
            'orders' => $orders,
            'ordersCount' => $ordersCount,
            'notifications' => $notifications,
        ]);
    }
}
