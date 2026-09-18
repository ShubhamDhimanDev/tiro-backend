<?php

namespace App\Http\Requests\Api\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequestPasswordResetRequest extends FormRequest
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
        ];
    }
}
