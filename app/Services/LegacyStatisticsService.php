<?php

namespace App\Services;

use App\Models\InmateFile;
use App\Models\OperationRecord;
use App\Models\UnitRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LegacyStatisticsService
{
    public function general(): array
    {
        $today = CarbonImmutable::today();
        $inmates = $this->inmates();
        $leaveRecords = UnitRecord::module('izin')->get();
        $intakeRows = $inmates->map(function (InmateFile $inmate): array {
            $data = $inmate->unitRecords->firstWhere('module_code', 'sorumlu-basmemurluk')?->data ?? [];

            return [
                'age' => $this->numericValue($data, ['Yaş']),
                'profession' => $this->value($data, ['Meslek']),
                'disability' => $this->value($data, ['Engellilik']),
                'previous_execution' => $this->value($data, ['Önceki infaz durumu']),
                'family_crime' => $this->value($data, ['Aile suç durumu']),
                'self_harm' => $this->value($data, ['Kendine zarar riski']),
                'ds' => $this->value($data, ['DS işlem durumu']),
            ];
        });

        return [
            'summary' => [
                'total' => $inmates->count(),
                'today' => $inmates->filter(fn (InmateFile $inmate) => $inmate->registered_at?->isSameDay($today))->count(),
                'month' => $inmates->filter(fn (InmateFile $inmate) => $inmate->registered_at?->isSameMonth($today))->count(),
                'year' => $inmates->filter(fn (InmateFile $inmate) => $inmate->registered_at?->year === $today->year)->count(),
                'leave' => InmateFile::where('is_active', true)->onLeave()->count(),
                'leaving' => $leaveRecords->filter(fn (UnitRecord $record) => $record->occurred_at?->isSameDay($today) || ($record->data['Çıkış'] ?? null) === $today->toDateString())->count(),
                'returning' => $leaveRecords->filter(fn (UnitRecord $record) => $record->ended_at?->isSameDay($today) || ($record->data['Dönüş'] ?? null) === $today->toDateString())->count(),
                'workplaces' => $inmates->map(fn (InmateFile $inmate) => $inmate->activeWorkAssignment->first()?->place?->name)->filter()->unique()->count(),
            ],
            'distributions' => [
                'Durum' => $this->countValues($inmates->pluck('status_code')),
                'Risk' => $this->countValues($inmates->pluck('risk_code')),
                'Karar' => $this->countValues($inmates->map(fn (InmateFile $inmate) => $inmate->decision_code ?: $inmate->activeWorkAssignment->first()?->decision_code)),
                'Çalışma Yeri' => $this->countValues($inmates->map(fn (InmateFile $inmate) => $inmate->activeWorkAssignment->first()?->place?->name)),
                'Blok' => $this->countValues($inmates->map(fn (InmateFile $inmate) => $inmate->activeHousingAssignment->first()?->block?->name)),
                'Koğuş' => $this->countValues($inmates->map(fn (InmateFile $inmate) => $inmate->activeHousingAssignment->first()?->ward?->name)),
                'Sağlık' => $this->countValues($inmates->pluck('health_status')),
                'Eğitim Statüsü' => $this->unitValues($inmates, 'egitim', ['Statü', 'Eğitim Statüsü']),
                'Öğrenim' => $this->unitValues($inmates, 'egitim', ['Öğrenim', 'Öğrenim Durumu']),
                'Kurs' => $this->unitValues($inmates, 'kurs-hijyen-isg', ['Kurs adı', 'Kurs Adı']),
                'Kronik Rahatsızlık' => $this->unitValues($inmates, 'revir', ['Kronik rahatsızlık', 'Kronik Rahatsızlık']),
                'Yaş Grubu' => $this->countValues($intakeRows->pluck('age')->map(fn ($age) => $this->ageBand($age))),
                'Meslek' => $this->countValues($intakeRows->pluck('profession')),
                'Engellilik' => $this->countValues($intakeRows->pluck('disability')),
                'Önceki İnfaz' => $this->countValues($intakeRows->pluck('previous_execution')),
                'Ailede Suç Öyküsü' => $this->countValues($intakeRows->pluck('family_crime')),
                'Kendine Zarar Riski' => $this->countValues($intakeRows->pluck('self_harm')),
                'DS İşlem Durumu' => $this->countValues($intakeRows->pluck('ds')),
            ],
            'trend' => $inmates->filter(fn (InmateFile $inmate) => $inmate->registered_at?->greaterThanOrEqualTo($today->subMonths(11)->startOfMonth()))
                ->groupBy(fn (InmateFile $inmate) => $inmate->registered_at?->format('Y-m'))
                ->map->count()->sortKeys(),
            'unit_records' => UnitRecord::selectRaw('module_code, count(*) as total')->groupBy('module_code')->pluck('total', 'module_code'),
            'operation_records' => OperationRecord::selectRaw('module_code, count(*) as total')->groupBy('module_code')->pluck('total', 'module_code'),
        ];
    }

    public function mesem(): array
    {
        $inmates = $this->inmates();
        $rows = $inmates->map(function (InmateFile $inmate): array {
            $record = $inmate->unitRecords->firstWhere('module_code', 'mesem');
            $data = $record?->data ?? [];

            return [
                'name' => $inmate->full_name,
                'work' => $inmate->activeWorkAssignment->first()?->place?->name ?: 'Çalışma yeri yok',
                'decision' => $inmate->decision_code ?: $inmate->activeWorkAssignment->first()?->decision_code ?: 'KARARSIZ',
                'interview' => $this->value($data, ['Görüşme', 'Görüşme Durumu']),
                'status' => $this->value($data, ['Durum', 'MESEM Durumu']),
                'petition' => $this->value($data, ['Dilekçe', 'Dilekçe Durumu']),
                'education_document' => $this->value($data, ['Öğrenim Belgesi', 'Öğrenim belgesi']),
                'class' => $this->value($data, ['Sınıf']),
                'branch' => $this->value($data, ['Dal', 'Meslek Dalı']),
                'document' => $this->value($data, ['Belge Türü', 'Mevcut Belge']),
                'employment_date' => $this->value($data, ['İşe Giriş Tarihi', 'İşe giriş tarihi']),
            ];
        });

        $workRows = $rows->groupBy('work')->map(function (Collection $group): array {
            return [
                'total' => $group->count(),
                'isyurtlari' => $group->where('decision', 'İŞYURTLARI')->count(),
                'genel' => $group->where('decision', 'GENEL BÜTÇE')->count(),
                'muhtelif' => $group->where('decision', 'MUHTELİF')->count(),
                'kararsiz' => $group->filter(fn (array $row) => ! in_array($row['decision'], ['İŞYURTLARI', 'GENEL BÜTÇE', 'MUHTELİF'], true))->count(),
                'interviewed' => $group->where('interview', 'GÖRÜŞÜLDÜ')->count(),
                'applications' => $group->where('status', 'BAŞVURU SÜRECİNDE')->count(),
                'petitions' => $group->where('petition', 'DİLEKÇE ALINDI')->count(),
            ];
        })->sortByDesc('total');

        return [
            'summary' => [
                'total' => $rows->count(),
                'interviewed' => $rows->where('interview', 'GÖRÜŞÜLDÜ')->count(),
                'not_interviewed' => $rows->whereNull('interview')->count(),
                'applications' => $rows->where('status', 'BAŞVURU SÜRECİNDE')->count(),
                'does_not_want' => $rows->where('status', 'İSTEMİYOR')->count(),
                'petitions' => $rows->where('petition', 'DİLEKÇE ALINDI')->count(),
                'petition_waiting' => $rows->where('petition', 'BEKLENİYOR')->count(),
                'education_documents' => $rows->where('education_document', 'ALINDI')->count(),
                'in_exam_process' => $rows->whereNotNull('class')->count(),
            ],
            'decision' => $this->countValues($rows->pluck('decision')),
            'branches' => $this->countValues($rows->pluck('branch')),
            'documents' => $this->countValues($rows->pluck('document')),
            'classes' => $this->countValues($rows->pluck('class')),
            'employment_trend' => $this->countValues($rows->pluck('employment_date')->map(fn ($date) => $date ? substr($date, 0, 7) : null))->sortKeysDesc(),
            'work_rows' => $workRows,
            'waiting_isyurtlari' => $rows->where('decision', 'İŞYURTLARI')->whereNull('interview')->take(100),
            'waiting_general' => $rows->where('decision', 'GENEL BÜTÇE')->whereNull('interview')->take(100),
        ];
    }

    private function inmates(): Collection
    {
        return InmateFile::with([
            'activeHousingAssignment.block',
            'activeHousingAssignment.ward',
            'activeWorkAssignment.place',
            'unitRecords' => fn ($query) => $query->latest('occurred_at')->latest(),
        ])->where('is_active', true)->get();
    }

    private function unitValues(Collection $inmates, string $module, array $keys): Collection
    {
        return $this->countValues($inmates->map(function (InmateFile $inmate) use ($module, $keys) {
            return $this->value($inmate->unitRecords->firstWhere('module_code', $module)?->data ?? [], $keys);
        }));
    }

    private function countValues(Collection $values): Collection
    {
        return $values->map(fn ($value) => is_string($value) ? trim($value) : $value)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->countBy()->sortDesc();
    }

    private function value(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    private function numericValue(array $data, array $keys): ?int
    {
        $value = $this->value($data, $keys);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    private function ageBand(?int $age): ?string
    {
        return match (true) {
            $age === null || $age <= 0 => null,
            $age <= 24 => '18-24',
            $age <= 34 => '25-34',
            $age <= 44 => '35-44',
            $age <= 54 => '45-54',
            $age <= 64 => '55-64',
            default => '65+',
        };
    }
}
