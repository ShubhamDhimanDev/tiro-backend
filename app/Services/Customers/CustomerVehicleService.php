<?php

namespace App\Services\Customers;

use App\Models\CustomerVehicle;
use Illuminate\Support\Facades\DB;

/**
 * `CustomerVehicle.is_default` is write-layer-enforced (single default per
 * customer), NOT a DB constraint — see
 * docs/architecture (Phase 7 readiness pass). This service is the one place
 * that invariant is upheld; every write path that can set `is_default` true
 * (the `set-default` endpoint, and — for a first saved vehicle — future
 * callers) should go through it rather than writing the column directly.
 */
class CustomerVehicleService
{
    /**
     * Make `$vehicle` this customer's default saved vehicle: unset any
     * other `is_default = true` row for the same `customer_id`, then set
     * this one, inside one transaction.
     */
    public function setDefault(CustomerVehicle $vehicle): CustomerVehicle
    {
        DB::transaction(function () use ($vehicle): void {
            CustomerVehicle::query()
                ->where('customer_id', $vehicle->customer_id)
                ->where('id', '!=', $vehicle->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $vehicle->forceFill(['is_default' => true])->save();
        });

        return $vehicle->refresh();
    }
}
