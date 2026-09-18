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
import type { ServiceZoneType } from '@/types/locations';
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
