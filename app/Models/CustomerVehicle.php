<?php

namespace App\Models;

use Database\Factories\CustomerVehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A vehicle saved on a customer's account — see
 * docs/architecture/01-data-model.md. Schema-only this phase: the
 * saved-vehicle feature itself ships in Phase 7, once `Customer` account UI
 * exists. This model exists only so future phases' foreign keys resolve
 * against a real table/relationship.
 *
 * @property int $id
 * @property int $customer_id
 * @property string|null $rego
 * @property string|null $state
 * @property string|null $vin
 * @property int|null $vehicle_id
 * @property array<string, mixed> $saved_fitment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['customer_id', 'rego', 'state', 'vin', 'vehicle_id', 'saved_fitment'])]
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
        ];
    }
}
