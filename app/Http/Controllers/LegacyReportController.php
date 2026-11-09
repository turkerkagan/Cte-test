<?php

namespace App\Http\Controllers;

use App\Models\InmateFile;
use App\Models\UnitRecord;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegacyReportController extends Controller
{
    public function leave(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('leave.view'), 403);
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? now()->toDateString();

        return view('reports.leave', [
            'date' => $date,
            'leaving' => $this->leaveRows($date, 'occurred_at', 'Çıkış'),
            'returning' => $this->leaveRows($date, 'ended_at', 'Dönüş'),
        ]);
    }

    public function transfer(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('execution.view'), 403);
        $filters = $this->transferFilters($request);
        $rows = $this->transferRows($filters);

        return view('reports.transfer', compact('filters', 'rows') + [
            'arrivalReasons' => $this->arrivalReasons(),
        ]);
    }

    public function transferExport(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->hasPermission('execution.export') || $request->user()?->hasPermission('inmates.export'), 403);
        $filters = $this->transferFilters($request);
        $rows = $this->transferRows($filters);
        $columns = ['Sıra', 'TC Kimlik', 'Adı Soyadı', 'UYAP No', 'Tahliye Tarihi', 'Tahliyeye Kalan Gün', 'DS Tarihi', 'DS Kalan Gün'];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($columns, null, 'A1');
        if ($rows->isNotEmpty()) {
            $sheet->fromArray($rows->values()->map(fn (array $row, int $index) => [
                $index + 1,
                $row['identity_number'],
                $row['full_name'],
                $row['uyap'],
                $row['release_date'],
                $row['release_days'],
                $row['ds_date'],
                $row['ds_days'],
            ])->all(), null, 'A2');
        }
        $sheet->freezePane('A2');
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $path = tempnam(sys_get_temp_dir(), 'cte_transfer_');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, 'sevk_raporu_'.$filters['type'].'_'.now()->format('Ymd').'.xlsx')->deleteFileAfterSend(true);
    }

    public function notification(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('notification.view'), 403);

        return $this->formView($request, 'notification');
    }

    public function ministryReferral(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('notification.view'), 403);

        return $this->formView($request, 'ministry-referral');
    }

    private function formView(Request $request, string $form): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'inmate_ids' => ['nullable', 'array', 'max:100'],
            'inmate_ids.*' => ['integer', 'exists:inmate_files,id'],
        ]);
        $selectedIds = collect($validated['inmate_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        $inmates = InmateFile::with([
            'activeHousingAssignment.block',
            'activeHousingAssignment.ward',
            'unitRecords' => fn ($query) => $query->whereIn('module_code', ['infaz', 'sorumlu-basmemurluk', 'revir'])->latest('occurred_at')->latest(),
        ])->where('is_active', true)->search($validated['q'] ?? null)->orderBy('full_name')->limit(500)->get();

        return view('reports.legacy-form', [
            'form' => $form,
            'inmates' => $inmates,
            'selectedIds' => $selectedIds,
            'selected' => $inmates->whereIn('id', $selectedIds)->values(),
        ]);
    }

    private function transferFilters(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['nullable', 'in:il_disi,il_ici'],
            'out_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'out_age' => ['nullable', 'integer', 'min:18', 'max:100'],
            'in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'excluded_reasons' => ['nullable', 'array'],
            'excluded_reasons.*' => ['string', 'max:255'],
        ]);

        return [
            'type' => $validated['type'] ?? 'il_disi',
            'out_days' => (int) ($validated['out_days'] ?? 100),
            'out_age' => (int) ($validated['out_age'] ?? 60),
            'in_days' => (int) ($validated['in_days'] ?? 40),
            'excluded_reasons' => $validated['excluded_reasons'] ?? ['EĞİTİM', 'HASTALIK', 'KENDİ İSTEĞİ İLE', 'ASAYİŞ VE GÜVENLİK'],
        ];
    }

    private function transferRows(array $filters)
    {
        $today = CarbonImmutable::today();
        $minimumDays = $filters['type'] === 'il_disi' ? $filters['out_days'] : $filters['in_days'];

        return InmateFile::with([
            'unitRecords' => fn ($query) => $query->whereIn('module_code', ['infaz', 'sorumlu-basmemurluk'])->latest('occurred_at')->latest(),
        ])->where('is_active', true)->orderBy('full_name')->get()
            ->map(function (InmateFile $inmate) use ($today): array {
                $records = $inmate->unitRecords;
                $infaz = $records->firstWhere('module_code', 'infaz');
                $intake = $records->firstWhere('module_code', 'sorumlu-basmemurluk');
                $release = $this->dateValue($infaz?->data ?? [], ['Tahliye tarihi', 'Tahliye Tarihi', 'Şartlı tahliye', 'Şartlı Tahliye Tarihi']);
                $ds = $this->dateValue($infaz?->data ?? [], ['DS Tarihi', 'DS tarihi', 'DS tahliye tarihi']);
                $birth = $this->dateValue(($intake?->data ?? []) + ($infaz?->data ?? []), ['Doğum tarihi', 'Doğum Tarihi']);

                return [
                    'identity_number' => $inmate->identity_number,
                    'full_name' => $inmate->full_name,
                    'uyap' => $this->dataValue($infaz?->data ?? [], ['UYAP No', 'UYAP no', 'UYAP özeti']),
                    'release' => $release,
                    'release_date' => $release?->format('d.m.Y'),
                    'release_days' => $release && $release->greaterThan($today) ? $today->diffInDays($release) : null,
                    'ds' => $ds,
                    'ds_date' => $ds?->format('d.m.Y'),
                    'ds_days' => $ds && $ds->greaterThan($today) ? $today->diffInDays($ds) : null,
                    'birth' => $birth,
                    'age' => $birth ? $birth->age : null,
                    'arrival_reason' => $this->dataValue($infaz?->data ?? [], ['Geliş nedeni', 'Geliş Nedeni']),
                ];
            })
            ->filter(function (array $row) use ($filters, $minimumDays): bool {
                if ($row['arrival_reason'] && in_array($row['arrival_reason'], $filters['excluded_reasons'], true)) {
                    return false;
                }
                $controlDays = $row['ds'] ? $row['ds_days'] : $row['release_days'];
                if ($controlDays === null || $controlDays < $minimumDays) {
                    return false;
                }

                return $filters['type'] !== 'il_disi' || $row['age'] === null || $row['age'] < $filters['out_age'];
            })->values();
    }

    private function arrivalReasons(): array
    {
        return UnitRecord::module('infaz')->get()->map(fn (UnitRecord $record) => $this->dataValue($record->data ?? [], ['Geliş nedeni', 'Geliş Nedeni']))
            ->filter()->unique()->sort()->values()->all();
    }

    private function leaveRows(string $date, string $column, string $dataKey)
    {
        return UnitRecord::with([
            'inmate.activeHousingAssignment.block',
            'inmate.activeHousingAssignment.ward',
            'inmate.activeHousingAssignment.bed',
            'inmate.activeWorkAssignment.place',
            'inmate.unitRecords' => fn ($query) => $query->where('module_code', 'infaz')->latest('occurred_at')->latest(),
        ])->module('izin')->where(function ($query) use ($column, $date, $dataKey): void {
            $query->whereDate($column, $date)->orWhere("data->{$dataKey}", $date);
        })->get()->sortBy(fn (UnitRecord $record) => $record->inmate?->full_name)->values()->map(function (UnitRecord $record): array {
            $inmate = $record->inmate;
            $housing = $inmate?->activeHousingAssignment->first();
            $work = $inmate?->activeWorkAssignment->first();
            $infaz = $inmate?->unitRecords->firstWhere('module_code', 'infaz');

            return [
                'identity_number' => $inmate?->identity_number,
                'full_name' => $inmate?->full_name,
                'uyap' => $this->dataValue($infaz?->data ?? [], ['UYAP No', 'UYAP no', 'UYAP özeti']),
                'work' => $work?->place?->name,
                'block' => $housing?->block?->name,
                'ward' => $housing?->ward?->name,
                'bed' => $housing?->bed?->label,
                'leave_date' => $record->occurred_at?->format('d.m.Y'),
                'leave_time' => $this->dataValue($record->data ?? [], ['Çıkış saati', 'Çıkış Saati']),
                'return_date' => $record->ended_at?->format('d.m.Y'),
                'return_time' => $this->dataValue($record->data ?? [], ['Dönüş saati', 'Dönüş Saati']),
            ];
        });
    }

    private function dataValue(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    private function dateValue(array $data, array $keys): ?CarbonImmutable
    {
        $value = $this->dataValue($data, $keys);
        if (! $value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
