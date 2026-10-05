import type { Status } from '@/types/catalog';

/** Mirrors `App\Enums\TechnicianEmploymentType`. */
export type TechnicianEmploymentType = 'employee' | 'contractor';

/** Mirrors `App\Enums\CancellationFeeType`. */
export type CancellationFeeType = 'flat' | 'percent';

/** Mirrors `App\Enums\BookingStatus`. */
export type BookingStatus =
    | 'pending_hold'
    | 'confirmed'
    | 'in_progress'
    | 'completed'
    | 'cancelled'
    | 'no_show'
    | 'expired';

/** Mirrors `App\Enums\VehicleFitmentPosition` reused by `BookingLineItem.position`. */
export type BookingLineItemPosition = 'all' | 'front' | 'rear';

export type Van = {
    id: number;
    rego: string;
    name: string;
    home_stock_location_id: number;
    home_stock_location?: { id: number; name: string };
    has_alignment_equipment: boolean;
    max_jobs_per_day: number;
    status: Status;
    created_at: string;
    updated_at: string;
};

export type Technician = {
    id: number;
    user_id: number | null;
    user?: { id: number; name: string; email: string } | null;
    name: string;
    employment_type: TechnicianEmploymentType;
    certifications: string[] | null;
    status: Status;
    created_at: string;
    updated_at: string;
};

export type TechnicianShift = {
    id: number;
    technician_id: number;
    technician?: { id: number; name: string; status: Status };
    van_id: number;
    van?: { id: number; name: string; rego: string };
    service_zone_id: number;
    service_zone?: { id: number; name: string };
    date: string;
    shift_start: string;
    shift_end: string;
    status: Status;
    created_at: string;
    updated_at: string;
};

export type CancellationPolicy = {
    id: number;
    service_zone_id: number | null;
    service_zone?: { id: number; name: string } | null;
    notice_hours: number;
    fee_type: CancellationFeeType;
    fee_amount: number | null;
    fee_percent: number | null;
    status: Status;
    created_at: string;
    updated_at: string;
};

/** A minimal lookup row, shared by the dispatch board's filter dropdowns. */
export type BookingLookup = { id: number; name: string };

export type DispatchShift = {
    id: number;
    technician_id: number;
    technician?: { id: number; name: string; status: Status };
    van_id: number;
    van?: {
        id: number;
        name: string;
        rego: string;
        has_alignment_equipment: boolean;
    };
    service_zone_id: number;
    service_zone?: { id: number; name: string };
    date: string;
    shift_start: string;
    shift_end: string;
    status: Status;
};

export type DispatchBookingLineItem = {
    id: number;
    quantity: number;
    position: BookingLineItemPosition;
    tyre_variant?: {
        id: number;
        sku: string;
        width: number;
        profile: number;
        rim_diameter: number;
    };
};

export type DispatchBooking = {
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
    customer?: { id: number; name: string; mobile: string | null } | null;
    line_items?: DispatchBookingLineItem[];
};

export type DispatchFilters = {
    service_zone_id: number | null;
    date: string;
    technician_id: number | null;
};

export type DispatchAvailabilityCandidate = {
    technician_id: number;
    technician_name: string;
    van_id: number;
};
