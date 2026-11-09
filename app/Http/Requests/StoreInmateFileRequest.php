<?php

namespace App\Http\Requests;

use App\Models\Bed;
use App\Models\HousingAssignment;
use App\Models\Ward;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInmateFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('inmates.create') || $this->user()?->hasPermission('admin.manage');
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['required', 'integer', 'exists:institutions,id'],
            'identity_number' => ['nullable', 'digits:11', Rule::unique('inmate_files', 'identity_number')->where('institution_id', $this->integer('institution_id'))->whereNull('deleted_at')],
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
            'block_id' => ['nullable', 'integer', 'exists:blocks,id', 'required_with:ward_id'],
            'ward_id' => ['nullable', 'integer', 'exists:wards,id', 'required_with:block_id'],
            'bed_id' => ['nullable', 'integer', 'exists:beds,id'],
            'work_place_id' => ['nullable', 'integer', 'exists:work_places,id'],
            'return_to' => ['nullable', 'in:dashboard,edit,index'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('ward_id')) {
                $ward = Ward::find($this->integer('ward_id'));
                if ($ward && $ward->block_id !== $this->integer('block_id')) {
                    $validator->errors()->add('ward_id', 'Seçilen koğuş bu bloğa ait değil.');
                }
            }

            if ($this->filled('bed_id')) {
                $bed = Bed::find($this->integer('bed_id'));
                if ($bed && $bed->ward_id !== $this->integer('ward_id')) {
                    $validator->errors()->add('bed_id', 'Seçilen ranza bu koğuşa ait değil.');
                }
                if (HousingAssignment::where('bed_id', $this->integer('bed_id'))->whereNull('ended_at')->exists()) {
                    $validator->errors()->add('bed_id', 'Bu ranza halen başka bir hükümlüye atanmış.');
                }
            }
        });
    }
}
