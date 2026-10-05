<?php

namespace Database\Seeders;

use App\Enums\CancellationFeeType;
use App\Enums\Status;
use App\Models\CancellationPolicy;
use Illuminate\Database\Seeder;

/**
 * Seeds the one permissive global `CancellationPolicy` row
 * (`service_zone_id = null`) so the reschedule/cancel notice-window/fee
 * *mechanism* ships and is exercised end-to-end in Phase 3, without waiting
 * on the client's real numbers (requirements §12.2, still unconfirmed — see
 * docs/architecture/06-open-decisions.md item 2). Ops tightens this via
 * admin CRUD once confirmed; no migration/redeploy needed.
 *
 * Idempotent — safe to re-run (`firstOrCreate`, never a bare `create`).
 */
class CancellationPolicySeeder extends Seeder
{
    public function run(): void
    {
        CancellationPolicy::query()->firstOrCreate(
            ['service_zone_id' => null],
            [
                'notice_hours' => 0,
                'fee_type' => CancellationFeeType::Flat,
                'fee_amount' => 0,
                'fee_percent' => null,
                'status' => Status::Active,
            ],
        );

        $this->command->info('Cancellation policy seeded: 1 global default row.');
    }
}
