<?php

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\Payment;
use App\Models\ServiceZone;
use App\Services\Reporting\ReportingService;

/**
 * `App\Services\Reporting\ReportingService` — the live-query layer behind
 * `GET /admin/reporting/{dashboard}` (super-admin-agent's controller/UI,
 * per the Phase 6 task brief; this test exercises the service directly).
 */
beforeEach(function () {
    $this->service = new ReportingService;
    $this->from = now()->startOfMonth();
    $this->to = now()->endOfMonth();
});

describe('sales', function () {
    it('sums gross/net revenue and counts only orders placed within the window and status set', function () {
        Order::factory()->confirmed()->create(['placed_at' => $this->from->clone()->addDays(2), 'grand_total' => 10000]);
        Order::factory()->confirmed()->create(['placed_at' => $this->from->clone()->addDays(5), 'grand_total' => 20000]);
        // Outside the window — excluded entirely.
        Order::factory()->confirmed()->create(['placed_at' => $this->from->clone()->subMonth(), 'grand_total' => 99999]);
        // Inside the window but never paid — counted in order_count, not revenue.
        Order::factory()->create(['placed_at' => $this->from->clone()->addDay(), 'status' => OrderStatus::PendingPayment, 'grand_total' => 5000]);
        // Cancelled — excluded from revenue.
        Order::factory()->create(['placed_at' => $this->from->clone()->addDay(), 'status' => OrderStatus::Cancelled, 'grand_total' => 7000]);

        $result = $this->service->sales($this->from, $this->to);

        expect($result['order_count'])->toBe(4);
        expect($result['paid_order_count'])->toBe(2);
        expect($result['gross_revenue'])->toBe(30000);
        expect($result['refunds_total'])->toBe(0);
        expect($result['net_revenue'])->toBe(30000);
        expect($result['average_order_value'])->toBe(15000.0);
        expect($result['orders_by_status'])->toBe([
            'confirmed' => 2,
            'pending_payment' => 1,
            'cancelled' => 1,
        ]);
    });

    it('subtracts succeeded refund payments from gross revenue', function () {
        $order = Order::factory()->confirmed()->create(['placed_at' => $this->from->clone()->addDay(), 'grand_total' => 40000]);
        Payment::factory()->for($order)->refund()->create(['amount' => 15000]);
        // A pending (not yet settled) refund must not be subtracted.
        Payment::factory()->for($order)->refund()->create(['amount' => 5000, 'status' => PaymentTransactionStatus::Pending]);

        $result = $this->service->sales($this->from, $this->to);

        expect($result['gross_revenue'])->toBe(40000);
        expect($result['refunds_total'])->toBe(15000);
        expect($result['net_revenue'])->toBe(25000);
    });

    it('filters by service zone via the order\'s linked booking', function () {
        $zoneA = ServiceZone::factory()->create();
        $zoneB = ServiceZone::factory()->create();

        $bookingA = Booking::factory()->for($zoneA, 'serviceZone')->create();
        Order::factory()->confirmed()->for($bookingA)->create(['placed_at' => $this->from->clone()->addDay(), 'grand_total' => 10000]);

        $bookingB = Booking::factory()->for($zoneB, 'serviceZone')->create();
        Order::factory()->confirmed()->for($bookingB)->create(['placed_at' => $this->from->clone()->addDay(), 'grand_total' => 20000]);

        $result = $this->service->sales($this->from, $this->to, $zoneA->id);

        expect($result['order_count'])->toBe(1);
        expect($result['gross_revenue'])->toBe(10000);
    });
});

describe('bookings', function () {
    it('counts bookings scheduled within the window and zone, broken down by status', function () {
        $zone = ServiceZone::factory()->create();
        $otherZone = ServiceZone::factory()->create();

        Booking::factory()->for($zone, 'serviceZone')->confirmed()->create(['scheduled_date' => $this->from->clone()->addDays(3)]);
        Booking::factory()->for($zone, 'serviceZone')->create(['scheduled_date' => $this->from->clone()->addDays(4), 'status' => BookingStatus::Cancelled]);
        // Outside the window.
        Booking::factory()->for($zone, 'serviceZone')->create(['scheduled_date' => $this->from->clone()->subMonth()]);
        // Different zone.
        Booking::factory()->for($otherZone, 'serviceZone')->create(['scheduled_date' => $this->from->clone()->addDays(3)]);

        $result = $this->service->bookings($this->from, $this->to, $zone->id);

        expect($result['booking_count'])->toBe(2);
        expect($result['bookings_by_status'])->toBe([
            'confirmed' => 1,
            'cancelled' => 1,
        ]);
    });
});

describe('conversion', function () {
    it('computes the hold-to-paid-order conversion rate for holds created within the window', function () {
        // Converted: booking whose linked order has a succeeded charge
        // payment. `Order::factory()`'s own `booking_id` default is a
        // nested `Booking::factory()` — using `->for($booking)` here
        // attaches the specific booking below instead of silently minting
        // a second, unrelated one (Order.booking_id is required+unique, so
        // every Order needs *a* booking either way).
        $convertedBooking = Booking::factory()->create(['created_at' => $this->from->clone()->addDay()]);
        $convertedOrder = Order::factory()->for($convertedBooking)->confirmed()->create();
        $convertedBooking->update(['order_id' => $convertedOrder->id]);
        Payment::factory()->for($convertedOrder)->succeeded()->create();

        // Has an order, but payment never succeeded — not converted.
        $unpaidBooking = Booking::factory()->create(['created_at' => $this->from->clone()->addDay()]);
        $unpaidOrder = Order::factory()->for($unpaidBooking)->create(['status' => OrderStatus::PendingPayment]);
        $unpaidBooking->update(['order_id' => $unpaidOrder->id]);
        Payment::factory()->for($unpaidOrder)->create(); // pending, not succeeded

        Booking::factory()->create(['status' => BookingStatus::Expired, 'created_at' => $this->from->clone()->addDay()]);
        Booking::factory()->create(['status' => BookingStatus::Cancelled, 'created_at' => $this->from->clone()->addDay()]);

        // Outside the window entirely.
        Booking::factory()->create(['created_at' => $this->from->clone()->subMonth()]);

        $result = $this->service->conversion($this->from, $this->to);

        expect($result['total_holds'])->toBe(4);
        expect($result['converted_count'])->toBe(1);
        expect($result['expired_count'])->toBe(1);
        expect($result['cancelled_count'])->toBe(1);
        expect($result['conversion_rate'])->toBe(0.25);
    });

    it('still counts a booking as converted even after its order was later fully refunded', function () {
        $booking = Booking::factory()->create(['created_at' => $this->from->clone()->addDay()]);
        $order = Order::factory()->for($booking)->create(['status' => OrderStatus::Refunded, 'payment_status' => PaymentStatus::Refunded]);
        $booking->update(['order_id' => $order->id]);
        Payment::factory()->for($order)->succeeded()->create(); // the original charge, still on record
        Payment::factory()->for($order)->refund()->create();

        $result = $this->service->conversion($this->from, $this->to);

        expect($result['converted_count'])->toBe(1);
    });
});

describe('cancellation', function () {
    it('splits cancelled bookings into staff- vs. customer-initiated via the AuditLog join', function () {
        $staffCancelled = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'scheduled_date' => $this->from->clone()->addDay()]);
        AuditLog::factory()->create([
            'auditable_type' => Booking::class,
            'auditable_id' => $staffCancelled->id,
            'action' => 'bookings.cancelled',
        ]);

        // Customer-initiated: no matching AuditLog row.
        Booking::factory()->create(['status' => BookingStatus::Cancelled, 'scheduled_date' => $this->from->clone()->addDays(2)]);

        // An unrelated AuditLog row against the same booking id but a
        // different action/auditable_type must not count as a match.
        $decoy = Booking::factory()->create(['status' => BookingStatus::Cancelled, 'scheduled_date' => $this->from->clone()->addDays(3)]);
        AuditLog::factory()->create([
            'auditable_type' => Order::class,
            'auditable_id' => $decoy->id,
            'action' => 'bookings.cancelled',
        ]);

        Booking::factory()->create(['status' => BookingStatus::Expired, 'scheduled_date' => $this->from->clone()->addDay()]);

        $result = $this->service->cancellation($this->from, $this->to);

        expect($result['staff_initiated'])->toBe(1);
        expect($result['customer_initiated'])->toBe(2);
        expect($result['expired'])->toBe(1);
        expect($result['total_cancelled'])->toBe(3);
    });
});

describe('productPerformance', function () {
    it('ranks tyre variants by revenue within the window, excluding non-revenue orders', function () {
        $bestSeller = OrderLineItem::factory()->create(['quantity' => 4, 'line_total' => 40000]);
        $bestSeller->order()->update(['status' => OrderStatus::Confirmed, 'placed_at' => $this->from->clone()->addDay()]);

        $secondSeller = OrderLineItem::factory()->create(['quantity' => 2, 'line_total' => 10000]);
        $secondSeller->order()->update(['status' => OrderStatus::Confirmed, 'placed_at' => $this->from->clone()->addDay()]);

        // Same variant as $secondSeller, another order in-window — sums together.
        OrderLineItem::factory()->create([
            'tyre_variant_id' => $secondSeller->tyre_variant_id,
            'quantity' => 1,
            'line_total' => 5000,
        ])->order->update(['status' => OrderStatus::Confirmed, 'placed_at' => $this->from->clone()->addDays(2)]);

        // Excluded: order never paid.
        $excluded = OrderLineItem::factory()->create(['quantity' => 10, 'line_total' => 999999]);
        $excluded->order()->update(['status' => OrderStatus::PendingPayment, 'placed_at' => $this->from->clone()->addDay()]);

        // Excluded: outside the window.
        $outOfRange = OrderLineItem::factory()->create();
        $outOfRange->order()->update(['status' => OrderStatus::Confirmed, 'placed_at' => $this->from->clone()->subMonth()]);

        $result = $this->service->productPerformance($this->from, $this->to);

        expect($result)->toHaveCount(2);
        expect($result->first()->tyre_variant_id)->toBe($bestSeller->tyre_variant_id);
        expect((int) $result->first()->revenue)->toBe(40000);
        expect((int) $result->first()->units_sold)->toBe(4);

        $second = $result->last();
        expect($second->tyre_variant_id)->toBe($secondSeller->tyre_variant_id);
        expect((int) $second->revenue)->toBe(15000);
        expect((int) $second->units_sold)->toBe(3);
    });
});
