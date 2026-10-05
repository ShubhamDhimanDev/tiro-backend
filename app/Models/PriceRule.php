<?php

namespace App\Models;

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use Database\Factories\PriceRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A per-zone **service-fee** adjustment — not a full per-zone product
 * repricing matrix. See docs/architecture/06-open-decisions.md item 7
 * (still open, build to this assumption) and
 * docs/architecture/01-data-model.md's `PriceRule` section. Reuses
 * `App\Enums\CancellationFeeType`'s shape (`flat`/`percent`) rather than
 * declaring a near-identical second enum.
 *
 * @property int $id
 * @property int $service_zone_id
 * @property CancellationFeeType $fee_type
 * @property int|null $fee_amount
 * @property int|null $fee_percent
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['service_zone_id', 'fee_type', 'fee_amount', 'fee_percent', 'status'])]
class PriceRule extends Model
{
    /** @use HasFactory<PriceRuleFactory> */
    use HasFactory;

    /**
     * Resolve the active service fee (cents) for `$serviceZoneId` against a
     * `$subtotal` (cents, used only when `fee_type = percent`) — 0 when no
     * active rule exists for the zone. Mirrors
     * `CancellationPolicy::resolveFee()`'s `string`-typed match-over-value
     * shape (not a match directly over the enum-cast property) so the
     * `default => throw` arm stays genuinely reachable/required to phpstan,
     * per that method's own docblock and this project's standing
     * exhaustive-`default => throw` convention.
     */
    public static function feeForZone(?int $serviceZoneId, int $subtotal): int
    {
        if ($serviceZoneId === null) {
            return 0;
        }

        $rule = static::query()
            ->where('service_zone_id', $serviceZoneId)
            ->where('status', Status::Active)
            ->first();

        if ($rule === null) {
            return 0;
        }

        return self::resolveFee($rule->fee_type->value, $rule->fee_amount, $rule->fee_percent, $subtotal);
    }

    private static function resolveFee(string $feeType, ?int $feeAmount, ?int $feePercent, int $subtotal): int
    {
        return match ($feeType) {
            CancellationFeeType::Flat->value => $feeAmount ?? 0,
            CancellationFeeType::Percent->value => (int) round($subtotal * ($feePercent ?? 0) / 100),
            default => throw new LogicException("Unhandled price rule fee type \"{$feeType}\"."),
        };
    }

    /**
     * Get the zone this fee rule applies to.
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_type' => CancellationFeeType::class,
            'fee_amount' => 'integer',
            'fee_percent' => 'integer',
            'status' => Status::class,
        ];
    }
}
