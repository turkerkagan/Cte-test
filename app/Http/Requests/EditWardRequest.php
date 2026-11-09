<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditWardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        $ward = $this->route('ward');

        return [
            'block_id' => ['required', 'integer', 'exists:blocks,id'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash:ascii', Rule::unique('wards', 'code')->where('block_id', $this->integer('block_id'))->ignore($ward?->id)],
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:0', 'max:9999'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
