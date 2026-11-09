<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditHousingBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        $block = $this->route('block');

        return [
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash:ascii', Rule::unique('blocks', 'code')->where('institution_id', $this->integer('institution_id'))->ignore($block?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
