<?php

namespace App\Http\Requests\Admin\Media;

use App\Support\Auth\AdminGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MediaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AdminGuard::optionalUser($this)?->can('content.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:10240'],
        ];
    }
}
