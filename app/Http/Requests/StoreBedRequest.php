<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'ward_id' => ['required', 'integer', 'exists:wards,id'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash:ascii', Rule::unique('beds', 'code')->where('ward_id', $this->integer('ward_id'))],
            'label' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
