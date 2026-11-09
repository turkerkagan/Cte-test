<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditPhoneDirectoryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'title' => ['required', 'string', 'max:255'],
            'person_name' => ['nullable', 'string', 'max:255'],
            'internal_number' => ['nullable', 'string', 'max:30'],
            'external_number' => ['nullable', 'string', 'max:30'],
            'mobile_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->filled('internal_number') && ! $this->filled('external_number') && ! $this->filled('mobile_number') && ! $this->filled('email')) {
                $validator->errors()->add('internal_number', 'En az bir telefon numarası veya e-posta girilmelidir.');
            }
        });
    }
}
