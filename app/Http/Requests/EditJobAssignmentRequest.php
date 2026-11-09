<?php

namespace App\Http\Requests;

use App\Models\WorkAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class EditJobAssignmentRequest extends FormRequest
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
            'work_place_id' => ['required', 'integer', 'exists:work_places,id'],
            'decision_code' => ['nullable', 'string', 'max:100'],
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

            if (WorkAssignment::where('inmate_file_id', $this->integer('inmate_file_id'))->whereNull('ended_at')->whereKeyNot($assignment?->id)->exists()) {
                $validator->errors()->add('inmate_file_id', 'Bu hükümlünün zaten başka bir aktif çalışma kaydı var.');
            }
        });
    }
}
