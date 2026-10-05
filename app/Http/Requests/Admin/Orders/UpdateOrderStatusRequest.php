<?php

namespace App\Http\Requests\Admin\Orders;

use App\Enums\OrderStatus;
use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PATCH /admin/orders/{order}/status` body — shape validation only (a
 * well-formed `OrderStatus` value). The actual allow-listed transition
 * check (e.g. `confirmed` -> `completed`, and nothing else) is a business
 * rule enforced in `OrderController::updateStatus()` against
 * `OrderController::MANUAL_STATUS_TRANSITIONS`, not here — same shape/rule
 * split as `RefundOrderRequest`/`RefundService`.
 *
 * **RBAC hardening pass fix, 2026-09-23 (Phase 6):** this class previously
 * had no `authorize()` override at all, meaning `FormRequest::authorize()`'s
 * default `true` applied — this class relied entirely on route-level
 * `permission:orders.manage` middleware with zero defense-in-depth, unlike
 * every other admin mutation FormRequest in this codebase (see e.g.
 * `App\Http\Requests\Admin\Products\BrandRequest::authorize()`). Added to
 * match that established convention.
 */
class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('orders.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(OrderStatus::class)],
        ];
    }
}
