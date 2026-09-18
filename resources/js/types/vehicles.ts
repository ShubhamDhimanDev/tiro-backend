import type { Status } from '@/types/catalog';

/** Mirrors `App\Enums\VehicleFitmentPosition`. */
export type VehicleFitmentPosition = 'all' | 'front' | 'rear';

/** Mirrors `App\Enums\VehicleFitmentSource`. `manual` is the only value this
 * admin UI ever writes — `vendor_feed` rows can still be viewed/listed here
 * if the importer created them, see the vehicle form's "source" note. */
export type VehicleFitmentSource = 'manual' | 'vendor_feed';

/** Mirrors `App\Enums\VehicleFitmentConfidence`. */
export type VehicleFitmentConfidence = 'confirmed' | 'likely';

export type VehicleFitment = {
    id: number;
    vehicle_id: number;
    position: VehicleFitmentPosition;
    width: number;
    profile: number;
    rim_diameter: number;
    load_index: string | null;
    speed_rating: string | null;
    is_staggered: boolean;
    source: VehicleFitmentSource;
    confidence: VehicleFitmentConfidence;
    notes: string | null;
    status: Status;
    created_at: string;
    updated_at: string;
};

export type Vehicle = {
    id: number;
    make: string;
    model: string;
    series: string | null;
    body_type: string | null;
    year_from: number;
    year_to: number;
    slug: string;
    status: Status;
    fitments: VehicleFitment[];
    created_at: string;
    updated_at: string;
};

export type FitmentImportRowError = { row: number; message: string };

/** Mirrors `App\Services\Vehicles\FitmentImportResult`, as serialized by
 * `VehicleFitmentImportController::serializeResult()`. */
export type FitmentImportResult = {
    rowsProcessed: number;
    vehiclesCreated: number;
    vehiclesMatched: number;
    fitmentsCreated: number;
    fitmentsUpdated: number;
    errors: FitmentImportRowError[];
    dryRun: boolean;
};
