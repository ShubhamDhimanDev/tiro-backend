<?php

namespace App\Models;

use App\Http\Controllers\Api\V1\Customer\VehicleController;
use App\Services\Customers\CustomerVehicleService;
use Database\Factories\CustomerVehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vehicle saved on a customer's account — see
 * docs/architecture/01-data-model.md. `label`/`is_default` were added in
 * Phase 7 alongside the account endpoints that actually manage these rows
 * ({@see VehicleController}); `is_default`
 * is write-layer-enforced (single default per customer) by
 * {@see CustomerVehicleService::setDefault()}, not a
 * DB constraint.
 *
 * @property int $id
 * @property int $customer_id
 * @property string|null $label
 * @property string|null $rego
 * @property string|null $state
 * @property string|null $vin
 * @property int|null $vehicle_id
 * @property array<string, mixed> $saved_fitment
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['customer_id', 'label', 'rego', 'state', 'vin', 'vehicle_id', 'saved_fitment', 'is_default'])]
class CustomerVehicle extends Model
{
    /** @use HasFactory<CustomerVehicleFactory> */
    use HasFactory;

    /**
     * Get the customer this saved vehicle belongs to.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the resolved catalogue vehicle, if any.
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'saved_fitment' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
