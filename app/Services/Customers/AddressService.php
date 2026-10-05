<?php

namespace App\Services\Customers;

use App\Models\Address;
use Illuminate\Support\Facades\DB;

/**
 * `Address.is_default` is write-layer-enforced (single default per
 * customer), NOT a DB constraint — see
 * docs/architecture (Phase 7 readiness pass). Mirrors
 * {@see CustomerVehicleService} exactly.
 */
class AddressService
{
    /**
     * Make `$address` this customer's default saved address: unset any
     * other `is_default = true` row for the same `customer_id`, then set
     * this one, inside one transaction.
     */
    public function setDefault(Address $address): Address
    {
        DB::transaction(function () use ($address): void {
            Address::query()
                ->where('customer_id', $address->customer_id)
                ->where('id', '!=', $address->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $address->forceFill(['is_default' => true])->save();
        });

        return $address->refresh();
    }
}
