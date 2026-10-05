/**
 * Select-field options mirroring `backend/app/Enums/*.php` exactly (values
 * and casing verified against those source files — not guessed). Keep in
 * sync with the enum source if a case is ever added/renamed there.
 */
import type {
    Status,
    TyreCategory,
    TyreConstruction,
    TyreSidewall,
    TyreType,
} from '@/types/catalog';
import type {
    BookingStatus,
    CancellationFeeType,
    TechnicianEmploymentType,
} from '@/types/bookings';
import type { ContentPageType, PageStatus } from '@/types/content';
import type {
    NotificationChannel,
    NotificationDeliveryStatus,
} from '@/types/customers';
import type { ServiceZoneType } from '@/types/locations';
import type {
    OrderStatus,
    PaymentStatus,
    PaymentTransactionStatus,
} from '@/types/orders';
import type {
    PriceGuaranteeClaimStatus,
    PromotionEligibilityScope,
    PromotionType,
} from '@/types/promotions';
import type { VehicleFitmentConfidence } from '@/types/vehicles';

type Option<T extends string> = { value: T; label: string };

/** Mirrors `App\Enums\Status`. */
export const STATUS_OPTIONS: Option<Status>[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'archived', label: 'Archived' },
];

/** Mirrors `App\Enums\TyreCategory`. */
export const TYRE_CATEGORY_OPTIONS: Option<TyreCategory>[] = [
    { value: 'car', label: 'Car' },
    { value: 'suv', label: 'SUV' },
    { value: '4x4', label: '4x4' },
    { value: 'light_truck', label: 'Light Truck' },
];

/** Mirrors `App\Enums\TyreType`. */
export const TYRE_TYPE_OPTIONS: Option<TyreType>[] = [
    { value: 'highway', label: 'Highway' },
    { value: 'all_terrain', label: 'All Terrain' },
    { value: 'mud_terrain', label: 'Mud Terrain' },
    { value: 'performance', label: 'Performance' },
    { value: 'eco', label: 'Eco' },
];

/** Mirrors `App\Enums\TyreConstruction`. */
export const TYRE_CONSTRUCTION_OPTIONS: Option<TyreConstruction>[] = [
    { value: 'radial', label: 'Radial' },
    { value: 'bias_ply', label: 'Bias Ply' },
];

/** Mirrors `App\Enums\TyreSidewall`. */
export const TYRE_SIDEWALL_OPTIONS: Option<TyreSidewall>[] = [
    { value: 'standard', label: 'Standard (SL)' },
    { value: 'xl', label: 'Extra Load (XL)' },
    { value: 'reinforced', label: 'Reinforced (RF)' },
    { value: 'commercial', label: 'Commercial (C)' },
];

/** Mirrors `App\Enums\ServiceZoneType`. */
export const SERVICE_ZONE_TYPE_OPTIONS: Option<ServiceZoneType>[] = [
    { value: 'radius', label: 'Radius' },
    { value: 'suburb_list', label: 'Suburb list' },
];

/** Mirrors `App\Enums\VehicleFitmentConfidence`. */
export const VEHICLE_FITMENT_CONFIDENCE_OPTIONS: Option<VehicleFitmentConfidence>[] =
    [
        { value: 'confirmed', label: 'Confirmed' },
        { value: 'likely', label: 'Likely' },
    ];

/** Mirrors `App\Enums\TechnicianEmploymentType`. */
export const TECHNICIAN_EMPLOYMENT_TYPE_OPTIONS: Option<TechnicianEmploymentType>[] =
    [
        { value: 'employee', label: 'Employee' },
        { value: 'contractor', label: 'Contractor' },
    ];

/** Mirrors `App\Enums\CancellationFeeType`. */
export const CANCELLATION_FEE_TYPE_OPTIONS: Option<CancellationFeeType>[] = [
    { value: 'flat', label: 'Flat fee' },
    { value: 'percent', label: 'Percent of order' },
];

/** Mirrors `App\Enums\BookingStatus`. */
export const BOOKING_STATUS_LABELS: Record<BookingStatus, string> = {
    pending_hold: 'Pending hold',
    confirmed: 'Confirmed',
    in_progress: 'In progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
    no_show: 'No show',
    expired: 'Expired',
};

/** Badge variant per booking status, for the dispatch board. */
export function bookingStatusBadgeVariant(
    status: BookingStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'confirmed':
        case 'in_progress':
            return 'default';
        case 'pending_hold':
            return 'secondary';
        case 'completed':
            return 'outline';
        case 'cancelled':
        case 'no_show':
        case 'expired':
            return 'destructive';
    }
}

/** Mirrors `App\Enums\OrderStatus`. */
export const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
    pending_payment: 'Pending payment',
    confirmed: 'Confirmed',
    payment_failed: 'Payment failed',
    refund_required: 'Refund required',
    completed: 'Completed',
    cancelled: 'Cancelled',
    refunded: 'Refunded',
    partially_refunded: 'Partially refunded',
};

/** Badge variant per order status. `refund_required` is deliberately its own visually-distinct (destructive) treatment — see docs/architecture/01-data-model.md's `refund_required` note: it must never be easy to overlook in a list. */
export function orderStatusBadgeVariant(
    status: OrderStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'confirmed':
            return 'default';
        case 'pending_payment':
            return 'secondary';
        case 'completed':
            return 'outline';
        case 'payment_failed':
        case 'refund_required':
        case 'cancelled':
            return 'destructive';
        case 'refunded':
        case 'partially_refunded':
            return 'outline';
    }
}

/** Mirrors `App\Enums\PaymentStatus`. */
export const PAYMENT_STATUS_LABELS: Record<PaymentStatus, string> = {
    pending: 'Pending',
    paid: 'Paid',
    failed: 'Failed',
    refunded: 'Refunded',
    partially_refunded: 'Partially refunded',
};

export function paymentStatusBadgeVariant(
    status: PaymentStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'paid':
            return 'default';
        case 'pending':
            return 'secondary';
        case 'failed':
            return 'destructive';
        case 'refunded':
        case 'partially_refunded':
            return 'outline';
    }
}

/** Mirrors `App\Enums\PaymentTransactionStatus` — a single `Payment` row's own gateway-reported state (distinct from `Order.payment_status`, the order-level aggregate). */
export const PAYMENT_TRANSACTION_STATUS_LABELS: Record<
    PaymentTransactionStatus,
    string
> = {
    pending: 'Pending',
    succeeded: 'Succeeded',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

/** Mirrors `App\Enums\NotificationChannel`. */
export const NOTIFICATION_CHANNEL_LABELS: Record<NotificationChannel, string> =
    {
        mail: 'Email',
        sms: 'SMS',
    };

/** Mirrors `App\Enums\NotificationDeliveryStatus`. `sent` means "accepted by the provider", not confirmed-delivered — see that enum's docblock. */
export const NOTIFICATION_DELIVERY_STATUS_LABELS: Record<
    NotificationDeliveryStatus,
    string
> = {
    queued: 'Queued',
    sent: 'Sent',
    failed: 'Failed',
};

export function notificationDeliveryStatusBadgeVariant(
    status: NotificationDeliveryStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'sent':
            return 'default';
        case 'queued':
            return 'secondary';
        case 'failed':
            return 'destructive';
    }
}

/** Mirrors `App\Enums\PromotionType`. */
export const PROMOTION_TYPE_OPTIONS: Option<PromotionType>[] = [
    { value: 'percentage', label: 'Percentage off' },
    { value: 'fixed', label: 'Fixed amount off' },
    { value: 'bundle', label: 'Bundle' },
    { value: 'buy_x_get_y', label: 'Buy X, get Y' },
    { value: 'four_for_three', label: '4 for 3' },
];

/** Mirrors `App\Enums\PromotionEligibilityScope`. */
export const PROMOTION_ELIGIBILITY_SCOPE_OPTIONS: Option<PromotionEligibilityScope>[] =
    [
        { value: 'brand', label: 'Brand' },
        { value: 'tyre_model', label: 'Tyre model' },
        { value: 'tyre_variant', label: 'Tyre variant (SKU)' },
        { value: 'category', label: 'Category' },
    ];

/** Mirrors `App\Enums\PriceGuaranteeClaimStatus`. */
export const PRICE_GUARANTEE_CLAIM_STATUS_LABELS: Record<
    PriceGuaranteeClaimStatus,
    string
> = {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
};

export function priceGuaranteeClaimStatusBadgeVariant(
    status: PriceGuaranteeClaimStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'approved':
            return 'default';
        case 'pending':
            return 'secondary';
        case 'rejected':
            return 'destructive';
    }
}

/** Mirrors `App\Enums\ContentPageType`. */
export const CONTENT_PAGE_TYPE_OPTIONS: Option<ContentPageType>[] = [
    { value: 'page', label: 'Page' },
    { value: 'blog_post', label: 'Blog post' },
    { value: 'guide', label: 'Guide' },
    { value: 'location_page', label: 'Location page' },
    { value: 'promo_landing', label: 'Promo landing' },
];

/** Mirrors `App\Enums\PageStatus` — shared by `ContentPage` and `Faq`. */
export const PAGE_STATUS_OPTIONS: Option<PageStatus>[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'published', label: 'Published' },
    { value: 'archived', label: 'Archived' },
];

/** Badge variant per `PageStatus` — distinct from `statusBadgeVariant()` below (a different enum, `active`/`inactive` don't apply here). */
export function pageStatusBadgeVariant(
    status: PageStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'published':
            return 'default';
        case 'draft':
            return 'secondary';
        case 'archived':
            return 'outline';
    }
}

/** Tailwind-ish badge variant per status, for consistent status pills. */
export function statusBadgeVariant(
    status: Status,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
            return 'default';
        case 'draft':
            return 'secondary';
        case 'inactive':
            return 'outline';
        case 'archived':
            return 'destructive';
    }
}
