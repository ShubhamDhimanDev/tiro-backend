<?php

namespace App\Http\Requests\Admin;

use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Defense in depth alongside the `permission:roles-users.manage` route
     * middleware — a request that reaches here without that permission is
     * denied here too.
     */
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('roles-users.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => [
                'required',
                'string',
                Rule::exists(config('permission.table_names.roles'), 'name')->where('guard_name', 'web'),
            ],
        ];
    }
}
