<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'personnel_number' => ['nullable', 'string', 'max:30', 'unique:users,personnel_number'],
            'login_name' => ['nullable', 'string', 'max:50', 'unique:users,login_name'],
            'email' => ['nullable', 'email', 'max:255'],
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('institution_id', $this->integer('institution_id'))],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)->where('institution_id', $this->integer('institution_id'))],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'is_active' => ['nullable', 'boolean'],
            'must_change_password' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['nullable', 'in:inherit,allow,deny'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->filled('personnel_number') && ! $this->filled('login_name')) {
                $validator->errors()->add('personnel_number', 'Sicil numarası veya özel giriş adı alanlarından en az biri doldurulmalıdır.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Ad soyad zorunludur.',
            'role_id.required' => 'Rol seçimi zorunludur.',
            'institution_id.required' => 'Kurum seçimi zorunludur.',
            'password.required' => 'Geçici parola zorunludur.',
            'password.confirmed' => 'Parola tekrarı eşleşmiyor.',
        ];
    }
}
