<?php

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\BookingCompleted;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * `GET /admin/orders` (search/filter), `GET /admin/orders/{order}` (detail),
 * `POST /admin/orders/{order}/cancel`, `PATCH /admin/orders/{order}/status`
 * — see docs/architecture/01-data-model.md's `Order` section and this
 * project's `docs/requirements/functionality-requirements.md` §11. Refund
 * has its own test file (`OrderRefundControllerTest.php`) since it was
 * built and gated on `orders.refund` ahead of this screen.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function ordersManageUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('customer_support'); // orders.manage, no orders.refund

    return $user;
}

function ordersViewOnlyUser(): User
{
    $user = User::factory()->withTwoFactor()->create();
    $user->assignRole('ecommerce'); // orders.view only

    return $user;
}

describe('index', function () {
    it('lists orders for a user holding orders.view', function () {
        Order::factory()->confirmed()->create(['order_number' => 'TMS-20260101-0001']);

        $response = $this->actingAs(ordersViewOnlyUser())->get(route('admin.orders.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('orders/index'));
    });

    it('denies access to a user holding no orders permission', function () {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->get(route('admin.orders.index'));

        $response->assertForbidden();
    });

    it('filters by status, including refund_required', function () {
        $target = Order::factory()->create(['status' => OrderStatus::RefundRequired]);
        Order::factory()->confirmed()->create();

        $response = $this->actingAs(ordersViewOnlyUser())->get(route('admin.orders.index', ['status' => 'refund_required']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('orders/index')
            ->where('orders.data.0.id', $target->id)
            ->where('orders.total', 1)
        );
    });

    it('filters by payment_status', function () {
        $target = Order::factory()->create(['payment_status' => PaymentStatus::Failed]);
        Order::factory()->confirmed()->create();

        $response = $this->actingAs(ordersViewOnlyUser())->get(route('admin.orders.index', ['payment_status' => 'failed']));

        $response->assertInertia(fn ($page) => $page
            ->where('orders.data.0.id', $target->id)
            ->where('orders.total', 1)
        );
    });

    it('searches by order number and by customer email/name', function () {
        $byNumber = Order::factory()->create(['order_number' => 'TMS-20260101-9999']);
        $customer = Customer::factory()->create(['email' => 'jane@example.com', 'name' => 'Jane Citizen']);
        $byCustomer = Order::factory()->create(['customer_id' => $customer->id]);
        Order::factory()->create(['order_number' => 'TMS-20260101-0001']);

        $numberResponse = $this->actingAs(ordersViewOnlyUser())->get(route('admin.orders.index', ['search' => '9999']));
        $numberResponse->assertInertia(fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.id', $byNumber->id));

        $emailResponse = $this->actingAs(ordersViewOnlyUser())->get(route('admin.orders.index', ['search' => 'jane@example.com']));
        $emailResponse->assertInertia(fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.id', $byCustomer->id));
    });

    it('ignores an unrecognised status filter value rather than erroring', function () {
        Order::factory()->confirmed()->create();

        $response = $this->actingAs(ordersViewOnlyUser())->get(route('admin.orders.index', ['status' => 'not-a-real-status']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('filters.status', null));
    });
});

describe('show', function () {
    it('shows order detail with line items, address, booking summary and redacted payment history', function () {
        $booking = Booking::factory()->confirmed()->create();
        $order = Order::factory()->confirmed()->create(['booking_id' => $booking->id]);
        OrderLineItem::factory()->create(['order_id' => $order->id]);
        Payment::factory()->succeeded()->create([
            'order_id' => $order->id,
            'raw_response' => ['id' => 'pi_secret', 'client_secret' => 'super-sensitive'],
        ]);

        $response = $this->actingAs(ordersViewOnlyUser())->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('orders/show')
            ->where('order.id', $order->id)
            ->has('order.line_items', 1)
            ->has('order.booking')
            ->has('order.payments', 1)
        );

        // The raw Stripe payload must never reach the response, in any shape.
        $response->assertDontSee('client_secret');
        $response->assertDontSee('super-sensitive');
    });

    it('denies access to a user holding no orders permission', function () {
        $order = Order::factory()->confirmed()->create();
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->get(route('admin.orders.show', $order));

        $response->assertForbidden();
    });
});

describe('cancel', function () {
    it('cancels a cancellable order and its linked booking, writing an AuditLog row for each', function () {
        $booking = Booking::factory()->confirmed()->create();
        $order = Order::factory()->confirmed()->create(['booking_id' => $booking->id]);
        $admin = ordersManageUser();

        $response = $this->actingAs($admin)->post(route('admin.orders.cancel', $order));

        $response->assertRedirect();
        $response->assertInertiaFlash('toast.type', 'success');

        $order->refresh();
        $booking->refresh();
        expect($order->status)->toBe(OrderStatus::Cancelled)
            ->and($order->payment_status)->toBe(PaymentStatus::Paid) // untouched — refund is a separate action
            ->and($booking->status)->toBe(BookingStatus::Cancelled)
            ->and($booking->hold_expires_at)->toBeNull();

        $orderLog = AuditLog::query()->where('auditable_type', Order::class)->where('auditable_id', $order->id)->where('action', 'orders.cancelled')->sole();
        expect($orderLog->actor_id)->toBe($admin->id)
            ->and($orderLog->before)->toBe(['status' => 'confirmed'])
            ->and($orderLog->after)->toBe(['status' => 'cancelled']);

        $bookingLog = AuditLog::query()->where('auditable_type', Booking::class)->where('auditable_id', $booking->id)->where('action', 'bookings.cancelled')->sole();
        expect($bookingLog->actor_id)->toBe($admin->id);
    });

    it('cancels an order whose booking is already in a terminal state without failing', function () {
        $booking = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'hold_expires_at' => null]);
        $order = Order::factory()->confirmed()->create(['booking_id' => $booking->id]);

        $response = $this->actingAs(ordersManageUser())->post(route('admin.orders.cancel', $order));

        $response->assertRedirect();
        expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
        expect(AuditLog::query()->where('auditable_type', Booking::class)->where('action', 'bookings.cancelled')->count())->toBe(0);
    });

    it('refuses to cancel an order in refund_required, directing ops to the refund flow instead', function () {
        $order = Order::factory()->create(['status' => OrderStatus::RefundRequired, 'payment_status' => PaymentStatus::Paid]);

        $response = $this->actingAs(ordersManageUser())->post(route('admin.orders.cancel', $order));

        $response->assertRedirect();
        $response->assertInertiaFlash('toast.type', 'error');
        expect($order->fresh()->status)->toBe(OrderStatus::RefundRequired);
    });

    it('refuses to cancel an already-cancelled order', function () {
        $order = Order::factory()->create(['status' => OrderStatus::Cancelled]);

        $response = $this->actingAs(ordersManageUser())->post(route('admin.orders.cancel', $order));

        $response->assertInertiaFlash('toast.type', 'error');
        expect(AuditLog::query()->where('action', 'orders.cancelled')->count())->toBe(0);
    });

    it('403s for a user holding only orders.view', function () {
        $order = Order::factory()->confirmed()->create();

        $response = $this->actingAs(ordersViewOnlyUser())->post(route('admin.orders.cancel', $order));

        $response->assertForbidden();
        expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    });
});

describe('updateStatus', function () {
    it('marks a confirmed order completed', function () {
        $order = Order::factory()->confirmed()->create();
        $admin = ordersManageUser();

        $response = $this->actingAs($admin)->patch(route('admin.orders.status.update', $order), ['status' => 'completed']);

        $response->assertRedirect();
        $response->assertInertiaFlash('toast.type', 'success');
        expect($order->fresh()->status)->toBe(OrderStatus::Completed);

        $log = AuditLog::query()->where('auditable_type', Order::class)->where('action', 'orders.status_updated')->sole();
        expect($log->before)->toBe(['status' => 'confirmed'])
            ->and($log->after)->toBe(['status' => 'completed']);
    });

    it('also completes the linked booking (Phase 7 gap fix)', function () {
        $order = Order::factory()->confirmed()->create();
        $order->booking->forceFill(['status' => BookingStatus::Confirmed])->save();

        $this->actingAs(ordersManageUser())->patch(route('admin.orders.status.update', $order), ['status' => 'completed']);

        expect($order->booking->fresh()->status)->toBe(BookingStatus::Completed);
    });

    /**
     * Coverage-gap fill (phase test-scope item 3): the test above proves the
     * `Booking.status` write itself; this proves the actual consequence the
     * fix exists for — that hitting this real admin endpoint makes
     * `App\Notifications\BookingCompleted` reachable in practice, via
     * `App\Observers\BookingNotificationObserver`'s `updated` hook on that
     * same write. Before this phase's fix, nothing in the codebase ever
     * transitioned a `Booking` to `completed` at all, so this notification
     * was provably unreachable regardless of how correct the observer/
     * notification code looked in isolation.
     */
    it('dispatches BookingCompleted when this endpoint completes an order with a resolvable customer (Phase 7 gap fix, end to end)', function () {
        Notification::fake();

        $customer = Customer::factory()->create();
        $order = Order::factory()->confirmed()->create(['customer_id' => $customer->id]);
        $order->booking->forceFill(['status' => BookingStatus::Confirmed, 'customer_id' => $customer->id])->save();

        $this->actingAs(ordersManageUser())->patch(route('admin.orders.status.update', $order), ['status' => 'completed']);

        Notification::assertSentTo($customer, BookingCompleted::class, fn ($notification) => $notification->booking->is($order->booking));
    });

    it('rejects a disallowed transition (e.g. straight to refunded) with a validation error', function () {
        $order = Order::factory()->confirmed()->create();

        $response = $this->actingAs(ordersManageUser())->patch(route('admin.orders.status.update', $order), ['status' => 'refunded']);

        $response->assertSessionHasErrors('status');
        expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('rejects setting status to cancelled through this endpoint — cancellation has its own action', function () {
        $order = Order::factory()->confirmed()->create();

        $response = $this->actingAs(ordersManageUser())->patch(route('admin.orders.status.update', $order), ['status' => 'cancelled']);

        $response->assertSessionHasErrors('status');
        expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);
    });

    it('422s on an invalid status value', function () {
        $order = Order::factory()->confirmed()->create();

        $response = $this->actingAs(ordersManageUser())->patch(route('admin.orders.status.update', $order), ['status' => 'not-a-real-status']);

        $response->assertSessionHasErrors('status');
    });

    it('403s for a user holding only orders.view', function () {
        $order = Order::factory()->confirmed()->create();

        $response = $this->actingAs(ordersViewOnlyUser())->patch(route('admin.orders.status.update', $order), ['status' => 'completed']);

        $response->assertForbidden();
    });
});
