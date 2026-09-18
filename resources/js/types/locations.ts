import type { Status } from '@/types/catalog';

/** Mirrors `App\Enums\ServiceZoneType`. */
export type ServiceZoneType = 'radius' | 'suburb_list';

export type DayHours = { open: string; close: string } | null;

/**
 * Locked shape (see docs/architecture/01-data-model.md) — plain zone-local
 * `HH:mm` 24h strings, no timezone. `null` = closed that day.
 */
export type OperatingHours = {
    mon: DayHours;
    tue: DayHours;
    wed: DayHours;
    thu: DayHours;
    fri: DayHours;
    sat: DayHours;
    sun: DayHours;
};

export const DAYS_OF_WEEK = [
    ['mon', 'Monday'],
    ['tue', 'Tuesday'],
    ['wed', 'Wednesday'],
    ['thu', 'Thursday'],
    ['fri', 'Friday'],
    ['sat', 'Saturday'],
    ['sun', 'Sunday'],
] as const;

export const CLOSED_WEEK: OperatingHours = {
    mon: null,
    tue: null,
    wed: null,
    thu: null,
    fri: null,
    sat: null,
    sun: null,
};

export type State = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    status: Status;
    service_zones_count?: number;
    suburbs_count?: number;
    created_at: string;
    updated_at: string;
};

export type ServiceZoneSuburbSummary = {
    id: number;
    name: string;
    postcode: string;
    state_id: number;
};

export type ServiceZone = {
    id: number;
    name: string;
    state_id: number;
    state?: { id: number; code: string; name: string };
    type: ServiceZoneType;
    origin_lat: string | null;
    origin_lng: string | null;
    radius_km: string | null;
    operating_hours: OperatingHours;
    priority: number;
    status: Status;
    suburbs?: ServiceZoneSuburbSummary[];
    stock_locations_count?: number;
    created_at: string;
    updated_at: string;
};

export type SuburbZoneSummary = {
    id: number;
    name: string;
    status: Status;
    state_id: number;
};

export type Suburb = {
    id: number;
    name: string;
    state_id: number;
    state?: { id: number; code: string; name: string };
    postcode: string;
    lat: string;
    lng: string;
    service_zones?: SuburbZoneSummary[];
    created_at: string;
    updated_at: string;
};
