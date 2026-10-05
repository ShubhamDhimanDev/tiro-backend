import type { OrderStatus, PaymentStatus } from '@/types/orders';

/** Mirrors `App\Enums\NotificationChannel`. */
export type NotificationChannel = 'mail' | 'sms';

/** Mirrors `App\Enums\NotificationDeliveryStatus`. */
export type NotificationDeliveryStatus = 'queued' | 'sent' | 'failed';

/** A row for the customer search/index list. */
export type CustomerListRow = {
    id: number;
    name: string;
    email: string;
    mobile: string | null;
    created_at: string;
};

export type CustomerFilters = {
    search: string | null;
};

/** Full `Customer` profile — matches `CustomerController::show()`'s raw model prop. */
export type CustomerProfile = {
    id: number;
    name: string;
    email: string;
    mobile: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
};

/** Nested catalogue `Vehicle` summary, present only when `vehicle_id` is set. */
export type CustomerVehicleCatalogueEntry = {
    id: number;
    make: string;
    model: string;
    series: string | null;
    year_from: number;
    year_to: number;
};

/**
 * One fitment position's saved size — see `App\Rules\SavedFitmentShape`'s
 * docblock for the two accepted shapes this mirrors.
 */
export type SavedFitmentSize = {
    width: number;
    profile: number;
    rim_diameter: number;
    load_index?: string;
    speed_rating?: string;
    confidence?: 'confirmed' | 'likely';
};

/** `saved_fitment` is keyed by either `all` alone, or `front` + `rear` together. */
export type SavedFitment =
    | { all: SavedFitmentSize }
    | { front: SavedFitmentSize; rear: SavedFitmentSize };

/** A customer's saved vehicle — read-only on the admin detail screen. */
export type CustomerVehicleRow = {
    id: number;
    label: string | null;
    rego: string | null;
    state: string | null;
    vin: string | null;
    vehicle_id: number | null;
    vehicle: CustomerVehicleCatalogueEntry | null;
    saved_fitment: SavedFitment | Record<string, never>;
    is_default: boolean;
    created_at: string;
};

/** A customer's saved address — read-only on the admin detail screen. */
export type CustomerAddressRow = {
    id: number;
    label: string | null;
    line1: string;
    line2: string | null;
    postcode: string;
    access_instructions: string | null;
    is_default: boolean;
    created_at: string;
    suburb: {
        id: number;
        name: string;
        postcode: string;
        state: { id: number; code: string; name: string } | null;
    } | null;
};

/** A row in the customer detail screen's recent order history list. */
export type CustomerOrderRow = {
    id: number;
    order_number: string;
    status: OrderStatus;
    payment_status: PaymentStatus;
    grand_total: number;
    currency: string;
    placed_at: string | null;
    created_at: string;
};

/**
 * A `NotificationLog` row for the "recent notifications" panel.
 * `error_message` is only ever meaningful when `status === 'failed'` — see
 * `NotificationLog`'s docblock: this table stores no message subject/body,
 * by design, so there is nothing else to render here.
 */
export type CustomerNotificationRow = {
    id: number;
    type: string;
    channel: NotificationChannel;
    status: NotificationDeliveryStatus;
    recipient: string;
    error_message: string | null;
    created_at: string;
};
