<?php

namespace App\Models;

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use Database\Factories\CancellationPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Notice-window/fee policy evaluated on booking reschedule/cancel — see
 * docs/architecture/01-data-model.md. `service_zone_id = null` is the global
 * default row, applied when no zone-specific row exists (see
 * {@see self::forZone()}).
 *
 * @property int $id
 * @property int|null $service_zone_id
 * @property int $notice_hours
 * @property CancellationFeeType $fee_type
 * @property int|null $fee_amount
 * @property int|null $fee_percent
 * @property Status $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['service_zone_id', 'notice_hours', 'fee_type', 'fee_amount', 'fee_percent', 'status'])]
class CancellationPolicy extends Model
{
    /** @use HasFactory<CancellationPolicyFactory> */
    use HasFactory;

    /**
     * Resolve the applicable policy for a zone: the zone-specific active row
     * if one exists, falling back to the global (`service_zone_id = null`)
     * active row otherwise.
     */
    public static function forZone(?int $serviceZoneId): ?self
    {
        if ($serviceZoneId !== null) {
            $zoneSpecific = static::query()
                ->where('service_zone_id', $serviceZoneId)
                ->where('status', Status::Active)
                ->first();

            if ($zoneSpecific !== null) {
                return $zoneSpecific;
            }
        }

        return static::query()
            ->whereNull('service_zone_id')
            ->where('status', Status::Active)
            ->first();
    }

    /**
     * Compute the cancellation/reschedule fee (cents) for this policy, given
     * how much notice was given. Returns 0 when the notice window was met —
     * the permissive default (`notice_hours = 0`) means this is always 0
     * out of the box, but the mechanism is genuinely wired end-to-end.
     */
    public function feeFor(int $noticeHoursGiven): int
    {
        if ($noticeHoursGiven >= $this->notice_hours) {
            return 0;
        }

        return self::resolveFee($this->fee_type->value, $this->fee_amount);
    }

    /**
     * `$feeType` is declared as plain `string`, not `CancellationFeeType` —
     * unlike a `match` directly over `$this->fee_type` (a fully-typed enum
     * cast, which phpstan would correctly flag as having a statically
     * unreachable `default` arm once every current case is listed), a
     * `string`-typed parameter is a real type boundary phpstan can't see
     * through from the call site, keeping the `default => throw` arm
     * genuinely reachable (and therefore required) rather than dead code.
     * Same convention as `RolesAndPermissionsSeeder::permissionNamesForTier()`
     * — see the fragile-pattern note in `App\Enums\VehicleFitmentPosition`
     * for the bug history this guards against.
     */
    private static function resolveFee(string $feeType, ?int $feeAmount): int
    {
        return match ($feeType) {
            CancellationFeeType::Flat->value => $feeAmount ?? 0,
            // Percent fees apply to an order total, which doesn't exist yet
            // at Phase 3 (no Order model until Phase 4) — the permissive
            // seeded default is `flat`/0, so this arm is unreached in
            // practice today, but must still resolve to a real amount, not
            // silently fall through, once Phase 4 wires an order total in.
            CancellationFeeType::Percent->value => 0,
            default => throw new LogicException("Unhandled cancellation fee type \"{$feeType}\"."),
        };
    }

    /**
     * Get the service zone this policy overrides for, if it's not the
     * global default row.
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
            'notice_hours' => 'integer',
            'fee_type' => CancellationFeeType::class,
            'fee_amount' => 'integer',
            'fee_percent' => 'integer',
            'status' => Status::class,
        ];
    }
}
