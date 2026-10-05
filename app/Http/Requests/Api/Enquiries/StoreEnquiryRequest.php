<?php

namespace App\Http\Requests\Api\Enquiries;

use App\Enums\EnquiryType;
use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/enquiries` body. One endpoint, four `type`s, with per-type
 * required fields:
 *
 * - `contact`: `message`
 * - `quote`: `phone` and (`tyre_size` or `rego`+`rego_state`)
 * - `fleet`: `phone`, `company`, `fleet_size`
 * - `out_of_area`: `suburb` or `postcode` (a notify-me sign-up)
 *
 * `website` is a honeypot: real users never see it; anything non-empty is
 * treated as a bot by the controller (silently accepted, never stored).
 */
class StoreEnquiryRequest extends FormRequest
{
    use NormalizesEmail;

    /** @var list<string> */
    private const AU_STATES = ['NSW', 'VIC', 'QLD', 'WA', 'SA', 'TAS', 'ACT', 'NT'];

    protected function prepareForValidation(): void
    {
        $this->normalizeEmail();

        foreach (['name', 'phone', 'message', 'tyre_size', 'suburb', 'company'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }

        if (is_string($this->input('rego'))) {
            $this->merge(['rego' => strtoupper(str_replace([' ', '-'], '', $this->input('rego')))]);
        }

        if (is_string($this->input('rego_state'))) {
            $this->merge(['rego_state' => strtoupper(trim($this->input('rego_state')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = EnquiryType::tryFrom((string) $this->input('type'));

        return [
            'type' => ['required', Rule::enum(EnquiryType::class)],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'phone' => [
                in_array($type, [EnquiryType::Quote, EnquiryType::Fleet], true) ? 'required' : 'nullable',
                'string', 'regex:/^[0-9+()\s-]{8,20}$/',
            ],
            'message' => [
                $type === EnquiryType::Contact ? 'required' : 'nullable',
                'string', 'min:5', 'max:3000',
            ],
            'tyre_size' => [
                'nullable', 'string', 'max:40', 'regex:/^[0-9A-Za-z\/\s.\-]{3,40}$/',
                Rule::requiredIf(fn (): bool => $type === EnquiryType::Quote && ! $this->filled('rego')),
            ],
            'rego' => ['nullable', 'string', 'max:10', 'alpha_num:ascii'],
            'rego_state' => [
                'nullable', Rule::in(self::AU_STATES),
                Rule::requiredIf(fn (): bool => $this->filled('rego')),
            ],
            'suburb' => [
                'nullable', 'string', 'max:100',
                Rule::requiredIf(fn (): bool => $type === EnquiryType::OutOfArea && ! $this->filled('postcode')),
            ],
            'postcode' => [
                'nullable', 'string', 'digits:4',
                Rule::requiredIf(fn (): bool => $type === EnquiryType::OutOfArea && ! $this->filled('suburb')),
            ],
            'company' => [
                'nullable', 'string', 'max:150',
                Rule::requiredIf(fn (): bool => $type === EnquiryType::Fleet),
            ],
            'fleet_size' => [
                'nullable', 'integer', 'min:1', 'max:100000',
                Rule::requiredIf(fn (): bool => $type === EnquiryType::Fleet),
            ],
            // Honeypot — see the class docblock.
            'website' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Please choose what your enquiry is about.',
            'type.enum' => 'Enquiry type must be one of: contact, quote, fleet, out_of_area.',
            'name.required' => 'Please tell us your name.',
            'email.required' => 'Please enter your email address so we can reply.',
            'email.email' => 'That email address does not look right. Check it and try again.',
            'phone.required' => 'Please enter a phone number so we can call you back.',
            'phone.regex' => 'Enter a valid phone number, for example 0412 345 678.',
            'message.required' => 'Please tell us how we can help.',
            'message.min' => 'Your message is a little short. Add a few more details.',
            'message.max' => 'Your message is too long. Keep it under 3000 characters.',
            'tyre_size.required' => 'Enter your tyre size (for example 205/55R16) or your number plate.',
            'tyre_size.regex' => 'Enter the tyre size like 205/55R16.',
            'rego.alpha_num' => 'Number plates can only contain letters and numbers.',
            'rego_state.required' => 'Choose the state your number plate is registered in.',
            'rego_state.in' => 'Choose a valid Australian state or territory.',
            'suburb.required' => 'Enter your suburb or postcode so we know where you are.',
            'postcode.required' => 'Enter your postcode or suburb so we know where you are.',
            'postcode.digits' => 'Postcodes are 4 digits.',
            'company.required' => 'Please enter your company name.',
            'fleet_size.required' => 'Please tell us roughly how many vehicles are in your fleet.',
            'fleet_size.integer' => 'Fleet size must be a whole number.',
            'fleet_size.min' => 'Fleet size must be at least 1.',
        ];
    }
}
