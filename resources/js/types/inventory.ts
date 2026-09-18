import type { TyreVariant } from '@/types/catalog';
import type { Status } from '@/types/catalog';

export type StockLocationZoneSummary = {
    id: number;
    name: string;
    status: Status;
};

export type StockLocation = {
    id: number;
    name: string;
    address: string;
    lat: string;
    lng: string;
    service_zones?: StockLocationZoneSummary[];
    inventory_items_count?: number;
    created_at: string;
    updated_at: string;
};

export type InventoryItem = {
    id: number;
    tyre_variant_id: number;
    stock_location_id: number;
    tyre_variant?: TyreVariant;
    qty_on_hand: number;
    qty_reserved: number;
    reorder_point: number;
    created_at: string;
    updated_at: string;
};
