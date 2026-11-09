<?php

namespace App\Http\Requests;

use App\Models\HousingAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class EditHousingAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inmates.update') || $this->user()?->hasPermission('admin.manage');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('inmate_file_id') && (int) $this->input('inmate_file_id') !== $this->route('inmate')?->id) {
            throw ValidationException::withMessages(['inmate_file_id' => 'İstek hükümlüsü adresle eşleşmiyor.']);
        }
        $this->merge(['inmate_file_id' => $this->route('inmate')?->id]);
    }

    public function rules(): array
    {
        return [
            'inmate_file_id' => ['required', 'integer', 'exists:inmate_files,id'],
            'block_id' => ['required', 'integer', 'exists:blocks,id'],
            'ward_id' => ['required', 'integer', 'exists:wards,id'],
            'bed_id' => ['nullable', 'integer', 'exists:beds,id'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $assignment = $this->route('assignment');

            if ($this->filled('ended_at')) {
                return;
            }

            if (HousingAssignment::where('inmate_file_id', $this->integer('inmate_file_id'))->whereNull('ended_at')->whereKeyNot($assignment?->id)->exists()) {
                $validator->errors()->add('inmate_file_id', 'Bu hükümlünün zaten başka bir aktif konum kaydı var.');
            }

            if ($this->filled('bed_id') && HousingAssignment::where('bed_id', $this->integer('bed_id'))->whereNull('ended_at')->whereKeyNot($assignment?->id)->exists()) {
                $validator->errors()->add('bed_id', 'Bu ranza için zaten başka bir aktif konum kaydı var.');
            }
        });
    }
}
