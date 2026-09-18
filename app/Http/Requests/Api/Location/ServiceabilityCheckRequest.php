<?php

namespace App\Http\Requests\Api\Location;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/serviceability` body — exactly one of `postcode`/`suburb`.
 * If both are sent, the controller prioritises `postcode`.
 */
class ServiceabilityCheckRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'postcode' => ['required_without:suburb', 'nullable', 'string', 'regex:/^\d{4}$/'],
            'suburb' => ['required_without:postcode', 'nullable', 'string', 'min:2', 'max:255'],
        ];
    }
}
