<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $user = $this->route('user');
        if ($user?->is_protected_superadmin) {
            foreach (['is_active', 'role_id', 'login_name'] as $field) {
                if (! $this->exists($field)) {
                    $this->merge([$field => $user->{$field}]);
                }
            }
        }
    }

    public function rules(): array
    {
        $managedUser = $this->route('user');
        $userId = $managedUser?->id;

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'personnel_number' => ['nullable', 'string', 'max:30', Rule::unique('users', 'personnel_number')->ignore($userId)],
            'login_name' => ['nullable', 'string', 'max:50', Rule::unique('users', 'login_name')->ignore($userId)],
            'email' => ['nullable', 'email', 'max:255'],
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('institution_id', $this->integer('institution_id'))],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)->where('institution_id', $this->integer('institution_id'))],
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
}
