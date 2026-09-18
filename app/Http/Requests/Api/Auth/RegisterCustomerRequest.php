<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterCustomerRequest extends FormRequest
{
    use NormalizesEmail;

    protected function prepareForValidation(): void
    {
        $this->normalizeEmail();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(8)->uncompromised()],
            // Not part of the documented request contract ({email, password}
            // only) but accepted if sent, since `customers.name` is NOT NULL
            // and the architecture doc's register endpoint doesn't collect
            // one — see AuthController::defaultNameFromEmail() for the
            // fallback used when this is omitted.
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
