import type { CancellationFeeType } from '@/types/bookings';
import type { Status, TyreCategory } from '@/types/catalog';

/** Mirrors `App\Enums\PromotionType`. */
export type PromotionType =
    | 'percentage'
    | 'fixed'
    | 'bundle'
    | 'buy_x_get_y'
    | 'four_for_three';

/** Mirrors `App\Enums\PromotionEligibilityScope`. */
export type PromotionEligibilityScope =
    | 'brand'
    | 'tyre_model'
    | 'tyre_variant'
    | 'category';

/** Mirrors `App\Enums\PriceGuaranteeClaimStatus`. */
export type PriceGuaranteeClaimStatus = 'pending' | 'approved' | 'rejected';

export type PromotionEligibility = {
    id: number;
    promotion_id: number;
    scope: PromotionEligibilityScope;
    /**
     * Polymorphic-by-convention — a numeric id (as a string) for
     * brand/tyre_model/tyre_variant scopes, or a raw `TyreCategory` value
     * for `category` (no lookup table exists for it). See
     * `App\Models\PromotionEligibility`'s docblock.
     */
    scope_id: string;
    service_zone_id: number | null;
    service_zone?: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
};

export type Promotion = {
    id: number;
    name: string;
    type: PromotionType;
    /** Percentage (0-100) for `type=percentage`, whole cents otherwise. */
    value: number;
    starts_at: string;
    ends_at: string;
    usage_limit: number | null;
    usage_count: number;
    stock_limit: number | null;
    stackable: boolean;
    status: Status;
    eligibilities?: PromotionEligibility[];
    redemptions_count?: number;
    created_at: string;
    updated_at: string;
};

/** Minimal catalogue lookups the eligibility-scope picker needs. */
export type PromotionBrandOption = { id: number; name: string };

export type PromotionTyreModelOption = {
    id: number;
    brand_id: number;
    name: string;
    brand?: { id: number; name: string };
};

export type PromotionTyreVariantOption = {
    id: number;
    tyre_model_id: number;
    sku: string;
    width: number;
    profile: number;
    rim_diameter: number;
    tyre_model?: {
        id: number;
        name: string;
        brand?: { id: number; name: string };
    };
};

export type PriceGuaranteeClaimCustomer = {
    id: number;
    name: string;
    email: string;
};

export type PriceGuaranteeClaimOrder = { id: number; order_number: string };

export type PriceGuaranteeClaimTyreVariant = {
    id: number;
    sku: string;
    tyre_model_id: number;
    tyre_model?: { id: number; name: string };
};

export type PriceGuaranteeClaim = {
    id: number;
    customer_id: number;
    customer?: PriceGuaranteeClaimCustomer;
    order_id: number | null;
    order?: PriceGuaranteeClaimOrder | null;
    competitor_url: string;
    /** Whole cents. */
    competitor_price: number;
    tyre_variant_id: number;
    tyre_variant?: PriceGuaranteeClaimTyreVariant;
    status: PriceGuaranteeClaimStatus;
    approved_discount_amount: number | null;
    expires_at: string | null;
    redeemed_at: string | null;
    admin_note: string | null;
    resolved_by: number | null;
    resolved_at: string | null;
    created_at: string;
    updated_at: string;
};

export type PriceRule = {
    id: number;
    service_zone_id: number;
    service_zone?: { id: number; name: string };
    fee_type: CancellationFeeType;
    /** Whole cents — required when `fee_type = flat`. */
    fee_amount: number | null;
    /** 0-100 — required when `fee_type = percent`. */
    fee_percent: number | null;
    status: Status;
    created_at: string;
    updated_at: string;
};

/** Re-exported for pages that only need the shared `TyreCategory` vocabulary for the `category` eligibility scope. */
export type { TyreCategory };
