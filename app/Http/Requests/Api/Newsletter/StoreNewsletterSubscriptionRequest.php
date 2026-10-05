<?php

namespace App\Http\Requests\Api\Newsletter;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/newsletter-subscriptions` body. `website` is a honeypot (see
 * the controller).
 */
class StoreNewsletterSubscriptionRequest extends FormRequest
{
    use NormalizesEmail;

    public const SOURCES = ['footer', 'home', 'checkout', 'offers', 'blog'];

    protected function prepareForValidation(): void
    {
        $this->normalizeEmail();

        if (is_string($this->input('first_name'))) {
            $this->merge(['first_name' => trim($this->input('first_name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'source' => ['sometimes', 'nullable', Rule::in(self::SOURCES)],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter your email address.',
            'email.email' => 'That email address does not look right. Check it and try again.',
            'email.max' => 'That email address does not look right. Check it and try again.',
            'source.in' => 'Unknown sign-up source.',
        ];
    }
}
