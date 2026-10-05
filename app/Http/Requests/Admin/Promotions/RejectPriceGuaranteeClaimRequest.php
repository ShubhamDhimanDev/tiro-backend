<?php

namespace App\Http\Requests\Admin\Promotions;

use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /admin/price-guarantee-claims/{claim}/reject` body — `admin_note`
 * is required (unlike approve's optional note), per
 * docs/architecture/02-api-contract.md's "Price-guarantee claim review"
 * section.
 */
class RejectPriceGuaranteeClaimRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('promotions.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'admin_note' => ['required', 'string', 'max:2000'],
        ];
    }
}
