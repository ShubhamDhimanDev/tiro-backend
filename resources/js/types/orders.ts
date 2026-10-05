import type { BookingStatus } from '@/types/bookings';

/** Mirrors `App\Enums\OrderStatus`. Distinct from `PaymentStatus` — see docs/architecture/01-data-model.md's `Order` section. */
export type OrderStatus =
    | 'pending_payment'
    | 'confirmed'
    | 'payment_failed'
    | 'refund_required'
    | 'completed'
    | 'cancelled'
    | 'refunded'
    | 'partially_refunded';

/** Mirrors `App\Enums\PaymentStatus` — money state only. */
export type PaymentStatus =
    | 'pending'
    | 'paid'
    | 'failed'
    | 'refunded'
    | 'partially_refunded';

/** Mirrors `App\Enums\PaymentType`. */
export type PaymentTransactionType = 'charge' | 'refund';

/** Mirrors `App\Enums\PaymentTransactionStatus`. */
export type PaymentTransactionStatus =
    | 'pending'
    | 'succeeded'
    | 'failed'
    | 'cancelled';

/** Mirrors `App\Enums\PaymentGateway`. */
export type PaymentGateway = 'stripe' | 'zip';

/** Mirrors `App\Enums\PaymentMethod`. */
export type PaymentMethod =
    | 'card'
    | 'apple_pay'
    | 'google_pay'
    | 'afterpay'
    | 'zip';

export type OrderCustomer = {
    id: number;
    name: string;
    email: string;
    mobile?: string | null;
};

/** A minimal order row for the search/index list. */
export type OrderListRow = {
    id: number;
    order_number: string;
    customer: OrderCustomer | null;
    status: OrderStatus;
    payment_status: PaymentStatus;
    grand_total: number;
    currency: string;
    placed_at: string | null;
    created_at: string;
};

export type OrderFilters = {
    status: OrderStatus | null;
    payment_status: PaymentStatus | null;
    date_from: string | null;
    date_to: string | null;
    search: string | null;
};

/** Shape of a Laravel `LengthAwarePaginator::toArray()` result. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type OrderLineItem = {
    id: number;
    tyre_variant_id: number;
    tyre_variant?: {
        id: number;
        sku: string;
        width: number;
        profile: number;
        rim_diameter: number;
        load_index: string;
        speed_rating: string;
        tyre_model?: {
            id: number;
            name: string;
            brand?: { id: number; name: string };
        };
    };
    quantity: number;
    unit_price: number;
    discount_amount: number;
    tax_amount: number;
    line_total: number;
};

export type OrderAddress = {
    id: number;
    line1: string;
    line2: string | null;
    postcode: string;
    access_instructions: string | null;
    suburb?: {
        id: number;
        name: string;
        postcode: string;
        state?: { id: number; code: string; name: string };
    };
};

export type OrderBooking = {
    id: number;
    service_zone_id: number;
    service_zone?: { id: number; name: string };
    scheduled_date: string;
    slot_start: string;
    slot_end: string;
    technician_id: number | null;
    technician?: { id: number; name: string } | null;
    van_id: number | null;
    van?: { id: number; name: string; rego: string } | null;
    status: BookingStatus;
    duration_minutes: number;
    addons: string[] | null;
    access_notes: string | null;
};

/**
 * `raw_response`/`idempotency_key` are deliberately absent — the server
 * never selects/serializes them into this view (see
 * `OrderController::show()`'s docblock). Only the derived fields useful for
 * an admin payment history are exposed.
 */
export type OrderPayment = {
    id: number;
    type: PaymentTransactionType;
    gateway: PaymentGateway;
    method: PaymentMethod;
    status: PaymentTransactionStatus;
    amount: number;
    gateway_reference: string;
    created_at: string;
};

export type OrderDetail = {
    id: number;
    order_number: string;
    customer: OrderCustomer | null;
    booking_id: number;
    booking: OrderBooking | null;
    address_id: number;
    address: OrderAddress;
    status: OrderStatus;
    payment_status: PaymentStatus;
    subtotal: number;
    discount_total: number;
    tax_total: number;
    service_fee_total: number;
    grand_total: number;
    currency: string;
    placed_at: string | null;
    created_at: string;
    updated_at: string;
    line_items: OrderLineItem[];
    payments: OrderPayment[];
};
