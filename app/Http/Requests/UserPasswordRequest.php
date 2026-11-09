<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'must_change_password' => ['nullable', 'boolean'],
        ];
    }
}
