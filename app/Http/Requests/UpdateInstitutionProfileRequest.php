<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInstitutionProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'fax' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'login_eyebrow' => ['required', 'string', 'max:120'],
            'login_heading' => ['required', 'string', 'max:160'],
            'login_description' => ['nullable', 'string', 'max:500'],
            'login_security_note' => ['required', 'string', 'max:180'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Kurum adı zorunludur.',
            'primary_color.regex' => 'Ana renk #312e81 biçiminde olmalıdır.',
            'accent_color.regex' => 'Vurgu rengi #7c3aed biçiminde olmalıdır.',
            'logo.image' => 'Logo geçerli bir görsel dosyası olmalıdır.',
            'logo.max' => 'Logo en fazla 2 MB olabilir.',
        ];
    }
}
