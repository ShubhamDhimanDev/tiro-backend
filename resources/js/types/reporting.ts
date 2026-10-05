/** The 5 values `{dashboard}` may take in `GET /admin/reporting/{dashboard}`. */
export type ReportingDashboard =
    | 'sales'
    | 'bookings'
    | 'conversion'
    | 'cancellation'
    | 'product-performance';

/** Mirrors `ReportingService::sales()`'s return shape. */
export type SalesReport = {
    order_count: number;
    paid_order_count: number;
    gross_revenue: number;
    refunds_total: number;
    net_revenue: number;
    average_order_value: number;
    orders_by_status: Record<string, number>;
};

/** Mirrors `ReportingService::bookings()`'s return shape. */
export type BookingsReport = {
    booking_count: number;
    bookings_by_status: Record<string, number>;
};

/**
 * Mirrors `ReportingService::conversion()`'s return shape. This is a
 * hold->order conversion rate, not true site-traffic funnel conversion — see
 * `ReportingService::conversion()`'s own docblock. Always label this
 * "Hold -> Order Conversion Rate" in the UI, never bare "Conversion".
 */
export type ConversionReport = {
    total_holds: number;
    converted_count: number;
    expired_count: number;
    cancelled_count: number;
    conversion_rate: number;
};

/** Mirrors `ReportingService::cancellation()`'s return shape. */
export type CancellationReport = {
    staff_initiated: number;
    customer_initiated: number;
    expired: number;
    total_cancelled: number;
};

/** One row of `ReportingService::productPerformance()`'s return collection. */
export type ProductPerformanceRow = {
    tyre_variant_id: number;
    sku: string;
    tyre_model_id: number;
    tyre_model_name: string;
    units_sold: number;
    revenue: number;
};

export type ReportingFilters = {
    from: string;
    to: string;
    service_zone_id: number | null;
    limit: number;
};
