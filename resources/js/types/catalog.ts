/**
 * Mirrors `App\Enums\Status` (backend/app/Enums/Status.php) — the shared
 * admin lifecycle/visibility status used across Brand/TyreModel/
 * TyreVariant/PopularSize/State/ServiceZone. Keep in sync with that file.
 */
export type Status = 'draft' | 'active' | 'inactive' | 'archived';

/** Mirrors `App\Enums\TyreCategory`. */
export type TyreCategory = 'car' | 'suv' | '4x4' | 'light_truck';

/** Mirrors `App\Enums\TyreType`. */
export type TyreType =
    | 'highway'
    | 'all_terrain'
    | 'mud_terrain'
    | 'performance'
    | 'eco';

/** Mirrors `App\Enums\TyreConstruction`. */
export type TyreConstruction = 'radial' | 'bias_ply';

/** Mirrors `App\Enums\TyreSidewall`. */
export type TyreSidewall = 'standard' | 'xl' | 'reinforced' | 'commercial';

export type Brand = {
    id: number;
    name: string;
    slug: string;
    logo_path: string | null;
    country_of_origin: string | null;
    status: Status;
    tyre_models_count?: number;
    created_at: string;
    updated_at: string;
};

export type TyreModel = {
    id: number;
    brand_id: number;
    brand?: Brand;
    name: string;
    slug: string;
    category: TyreCategory;
    tyre_type: TyreType;
    construction: TyreConstruction;
    run_flat: boolean;
    description: string | null;
    warranty_text: string | null;
    warranty_km: number | null;
    service_inclusions: string[] | null;
    released_at: string | null;
    images: string[] | null;
    status: Status;
    tyre_variants_count?: number;
    created_at: string;
    updated_at: string;
};

export type TyreVariant = {
    id: number;
    tyre_model_id: number;
    tyre_model?: TyreModel;
    sku: string;
    slug: string;
    width: number;
    profile: number;
    rim_diameter: number;
    load_index: string;
    speed_rating: string;
    sidewall: TyreSidewall;
    ean: string | null;
    weight_kg: string | null;
    /** Whole cents (e.g. `18900` = $189.00) — see TyreVariantRequest. */
    base_price: number;
    status: Status;
    created_at: string;
    updated_at: string;
};
