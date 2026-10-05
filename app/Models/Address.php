<?php

namespace App\Models;

use App\Enums\AddressType;
use App\Http\Controllers\Api\V1\Customer\AddressController;
use App\Services\Customers\AddressService;
use Database\Factories\AddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fitting (or, reserved for a future feature, billing) address — see
 * docs/architecture/01-data-model.md's `Address` section. `Order` points at
 * exactly one `Address` (`type = fitting`) for MVP; `billing` exists on the
 * enum for a future separate-billing-address feature, not built this phase.
 * `label`/`is_default` were added in Phase 7 for the account address book
 * ({@see AddressController}); `is_default`
 * is write-layer-enforced (single default per customer) by
 * {@see AddressService::setDefault()}, not a DB
 * constraint.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string|null $label
 * @property int $suburb_id
 * @property string $line1
 * @property string|null $line2
 * @property string $postcode
 * @property string $lat
 * @property string $lng
 * @property string|null $access_instructions
 * @property AddressType $type
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['customer_id', 'label', 'suburb_id', 'line1', 'line2', 'postcode', 'lat', 'lng', 'access_instructions', 'type', 'is_default'])]
class Address extends Model
{
    /** @use HasFactory<AddressFactory> */
    use HasFactory;

    /**
     * Get the customer this address belongs to, if saved to an account
     * (guest checkout addresses aren't deduplicated/owned by anything).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the suburb this address falls within.
     *
     * @return BelongsTo<Suburb, $this>
     */
    public function suburb(): BelongsTo
    {
        return $this->belongsTo(Suburb::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'type' => AddressType::class,
            'is_default' => 'boolean',
        ];
    }
}
