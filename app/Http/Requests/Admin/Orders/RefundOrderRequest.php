<?php

namespace App\Http\Requests\Admin\Orders;

use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /admin/orders/{order}/refund` body — see
 * docs/architecture/02-api-contract.md's "Orders admin — refund flow"
 * section. `amount` omitted means a full refund of the remaining
 * refundable balance.
 *
 * **RBAC hardening pass fix, 2026-09-23 (Phase 6):** this class previously
 * had no `authorize()` override (`FormRequest::authorize()`'s default
 * `true` applied) and its docblock explicitly said "route-level
 * `permission:orders.refund` middleware is the actual authorization gate,
 * this class only validates shape" — zero defense-in-depth for an action
 * that moves real money, unlike every other admin mutation FormRequest in
 * this codebase (see e.g.
 * `App\Http\Requests\Admin\Products\BrandRequest::authorize()`). Added to
 * match that established convention.
 */
class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('orders.refund') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
