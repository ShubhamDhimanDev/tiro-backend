<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordResetRequest extends FormRequest
{
    use NormalizesEmail;

    protected function prepareForValidation(): void
    {
        $this->normalizeEmail();
    }

    /**
     * Password complexity for `new_password` is deliberately not checked
     * here — the architecture doc's failure-shape table (§12) keys that
     * error as `errors.password`, not `errors.new_password`, so
     * AuthController re-validates it separately under a `password` key.
     * This only guards presence/shape.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
            'new_password' => ['required', 'string'],
        ];
    }
}
