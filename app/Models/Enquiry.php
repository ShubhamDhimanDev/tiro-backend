<?php

namespace App\Models;

use App\Enums\EnquiryStatus;
use App\Enums\EnquiryType;
use Database\Factories\EnquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A storefront form submission (contact, quote, fleet, out-of-area
 * notify-me). The public API only ever exposes `reference`, never `id`.
 *
 * @property int $id
 * @property string $reference
 * @property EnquiryType $type
 * @property EnquiryStatus $status
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $message
 * @property string|null $tyre_size
 * @property string|null $rego
 * @property string|null $rego_state
 * @property string|null $suburb
 * @property string|null $postcode
 * @property string|null $company
 * @property int|null $fleet_size
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'reference', 'type', 'status', 'name', 'email', 'phone', 'message', 'tyre_size', 'rego',
    'rego_state', 'suburb', 'postcode', 'company', 'fleet_size', 'ip_address',
])]
class Enquiry extends Model
{
    /** @use HasFactory<EnquiryFactory> */
    use HasFactory;

    /**
     * A short, non-sequential customer-facing reference (e.g. `ENQ-7K3P9XQ2`).
     * Excludes visually ambiguous characters and retries on the (very
     * unlikely) collision.
     */
    public static function generateReference(): string
    {
        do {
            $reference = 'ENQ-'.strtoupper(Str::random(8));
            $reference = strtr($reference, ['O' => '7', '0' => '8', 'I' => '9', '1' => '6']);
        } while (self::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EnquiryType::class,
            'status' => EnquiryStatus::class,
            'fleet_size' => 'integer',
        ];
    }
}
