<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EditInmateFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inmates.update') || $this->user()?->hasPermission('admin.manage');
    }

    public function rules(): array
    {
        $inmate = $this->route('inmate');

        return [
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'identity_number' => ['nullable', 'digits:11', Rule::unique('inmate_files', 'identity_number')->where('institution_id', $this->integer('institution_id'))->whereNull('deleted_at')->ignore($inmate?->id)],
            'full_name' => ['required', 'string', 'max:255'],
            'registered_at' => ['nullable', 'date'],
            'entered_at' => ['nullable', 'date'],
            'status_code' => ['nullable', 'string', 'max:100'],
            'risk_code' => ['nullable', 'string', 'max:100'],
            'decision_code' => ['nullable', 'string', 'max:100'],
            'health_status' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'legacy_id' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
