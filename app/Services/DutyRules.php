<?php

namespace App\Services;

use App\Models\OperationRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DutyRules
{
    public const TYPES = ['Müdür nöbeti', 'Vardiya', 'İnfaz nöbeti'];

    // Called inside the institution lock held by OperationRecord::save.
    public function validate(OperationRecord $record): void
    {
        if ($record->is_closed || $record->trashed()) {
            return;
        }
        $data = $record->data ?? [];
        Validator::make($data, ['Uygun olmayan tarih' => ['nullable', 'date_format:Y-m-d']])->validate();
        if ($record->record_type === 'Uygun değil' && ! empty($data['Uygun olmayan tarih'])) {
            $record->starts_at = Carbon::parse($data['Uygun olmayan tarih'])->startOfDay();
            $record->ends_at = $record->starts_at->copy()->addDay();
        }
        Validator::make(['data' => ['Personel' => $data['Personel'] ?? null], 'record_type' => $record->record_type,
            'starts_at' => $record->starts_at, 'ends_at' => $record->ends_at, 'unavailable_date' => $data['Uygun olmayan tarih'] ?? null], [
                'data.Personel' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('institution_id', $record->institution_id)],
                'record_type' => ['required', Rule::in([...self::TYPES, 'Uygun değil'])],
                'starts_at' => ['required_without:unavailable_date', 'nullable', 'date'],
                'ends_at' => ['required_with:starts_at', 'nullable', 'date', 'after:starts_at'],
                'unavailable_date' => ['nullable', 'date_format:Y-m-d'],
            ])->validate();
        $personnel = User::whereKey((int) $data['Personel'])->lockForUpdate()->firstOrFail();
        $start = $record->starts_at;
        $end = $record->ends_at;
        if ($record->record_type === 'Uygun değil' && ! empty($data['Uygun olmayan tarih'])) {
            $start = Carbon::parse($data['Uygun olmayan tarih'])->startOfDay();
            $end = $start->copy()->addDay();
        }
        if (! $start || ! $end) {
            throw ValidationException::withMessages(['starts_at' => 'Başlangıç ve bitiş zorunludur.']);
        }
        $conflict = OperationRecord::module('nobet')->where('institution_id', $record->institution_id)
            ->where('is_closed', false)->where('data->Personel', (string) $personnel->id)
            ->when($record->exists, fn ($q) => $q->whereKeyNot($record->id))
            ->when($record->record_type === 'Uygun değil', fn ($q) => $q->whereIn('record_type', self::TYPES))
            ->where(function ($q) use ($start, $end): void {
                $q->where(fn ($q) => $q->where('starts_at', '<', $end)->where('ends_at', '>', $start))
                    ->orWhere(fn ($q) => $q->where('record_type', 'Uygun değil')->where('data->Uygun olmayan tarih', '>=', $start->toDateString())->where('data->Uygun olmayan tarih', '<=', $end->copy()->subSecond()->toDateString()));
            })->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['data.Personel' => 'Personelin nöbeti veya uygun olmayan tarihi bu aralıkla çakışıyor.']);
        }
    }
}
