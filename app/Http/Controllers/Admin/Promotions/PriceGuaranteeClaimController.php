<?php

namespace App\Http\Controllers\Admin\Promotions;

use App\Enums\PriceGuaranteeClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Promotions\ApprovePriceGuaranteeClaimRequest;
use App\Http\Requests\Admin\Promotions\RejectPriceGuaranteeClaimRequest;
use App\Models\AuditLog;
use App\Models\PriceGuaranteeClaim;
use App\Services\Commerce\RefundService;
use App\Support\Auth\AdminGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin price-guarantee claim review queue — gated `promotions.manage`, not
 * Customer Support, per docs/architecture/07-admin-auth-permissions.md §3.2
 * ("a margin decision, CS can see claim status but not approve/reject").
 * See docs/architecture/05-promotions-pricing.md's "Price-guarantee claim
 * workflow" section and docs/architecture/02-api-contract.md's
 * "Price-guarantee claim review" section for the exact mechanics.
 *
 * Deliberately human-reviewed, never auto-approved.
 */
class PriceGuaranteeClaimController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    /**
     * Review queue, filterable by `status`.
     */
    public function index(Request $request): Response
    {
        $status = $this->validStatusFilter($request);

        $claims = PriceGuaranteeClaim::query()
            ->with(['customer:id,name,email', 'order:id,order_number', 'tyreVariant:id,sku,tyre_model_id', 'tyreVariant.tyreModel:id,name'])
            ->when($status, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('promotions/price-guarantee-claims/index', [
            'filters' => ['status' => $status?->value],
            'claims' => $claims,
        ]);
    }

    /**
     * Approve a claim. Post-purchase (`order_id` present) reuses Phase 4's
     * exact refund mechanism verbatim via {@see RefundService} — a
     * server-generated `Idempotency-Key` (this action has no incoming HTTP
     * `Idempotency-Key` header of its own to reuse, unlike
     * `POST /admin/orders/{order}/refund`), a new `type=refund` `Payment`
     * row, and an `Order.payment_status` update. Pre-purchase (`order_id`
     * null) sets `expires_at` (the redemption window,
     * `config('promotions.price_guarantee_redemption_days')`) directly on
     * the claim instead — no refund call, nothing to refund against yet.
     */
    public function approve(ApprovePriceGuaranteeClaimRequest $request, PriceGuaranteeClaim $priceGuaranteeClaim): RedirectResponse
    {
        // `$priceGuaranteeClaim` — the controller parameter name must match
        // the route segment ({priceGuaranteeClaim}) for Laravel's implicit
        // route-model binding to resolve it; a mismatched name (e.g.
        // `$claim`) leaves it as the raw route-parameter string instead —
        // caught by this file's own test suite.
        $claim = $priceGuaranteeClaim;
        $actor = AdminGuard::user($request);

        if ($claim->status !== PriceGuaranteeClaimStatus::Pending) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This claim has already been resolved.')]);

            return back();
        }

        $amount = (int) $request->validated('approved_discount_amount');
        $adminNote = $request->validated('admin_note');

        DB::transaction(function () use ($claim, $actor, $amount, $adminNote): void {
            $before = ['status' => $claim->status->value];

            if ($claim->order_id !== null) {
                $order = $claim->order()->firstOrFail();

                $this->refunds->refund($order, $actor, $amount, __('Price guarantee claim #:id', ['id' => $claim->id]), (string) Str::uuid());

                $claim->forceFill(['redeemed_at' => now()])->save();
            } else {
                $days = (int) config('promotions.price_guarantee_redemption_days');

                $claim->forceFill(['expires_at' => now()->addDays($days)])->save();
            }

            $claim->forceFill([
                'status' => PriceGuaranteeClaimStatus::Approved,
                'approved_discount_amount' => $amount,
                'admin_note' => $adminNote,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            AuditLog::create([
                'auditable_type' => PriceGuaranteeClaim::class,
                'auditable_id' => $claim->id,
                'action' => 'price_guarantee_claims.approved',
                'actor_id' => $actor->id,
                'before' => $before,
                'after' => ['status' => PriceGuaranteeClaimStatus::Approved->value, 'approved_discount_amount' => $amount],
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Claim approved.')]);

        return back();
    }

    /**
     * Reject a claim — `admin_note` required.
     */
    public function reject(RejectPriceGuaranteeClaimRequest $request, PriceGuaranteeClaim $priceGuaranteeClaim): RedirectResponse
    {
        $claim = $priceGuaranteeClaim;
        $actor = AdminGuard::user($request);

        if ($claim->status !== PriceGuaranteeClaimStatus::Pending) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This claim has already been resolved.')]);

            return back();
        }

        $adminNote = $request->validated('admin_note');
        $before = ['status' => $claim->status->value];

        DB::transaction(function () use ($claim, $actor, $adminNote, $before): void {
            $claim->forceFill([
                'status' => PriceGuaranteeClaimStatus::Rejected,
                'admin_note' => $adminNote,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            AuditLog::create([
                'auditable_type' => PriceGuaranteeClaim::class,
                'auditable_id' => $claim->id,
                'action' => 'price_guarantee_claims.rejected',
                'actor_id' => $actor->id,
                'before' => $before,
                'after' => ['status' => PriceGuaranteeClaimStatus::Rejected->value],
            ]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Claim rejected.')]);

        return back();
    }

    private function validStatusFilter(Request $request): ?PriceGuaranteeClaimStatus
    {
        $value = $request->string('status')->trim()->toString();

        if ($value === '') {
            return null;
        }

        return PriceGuaranteeClaimStatus::tryFrom($value);
    }
}
