<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditJobPlaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.manage') ?? false;
    }

    public function rules(): array
    {
        $place = $this->route('place');

        return [
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'code' => ['required', 'string', 'max:80', 'alpha_dash:ascii', Rule::unique('work_places', 'code')->where('institution_id', $this->integer('institution_id'))->ignore($place?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
