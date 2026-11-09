<?php

namespace App\Http\Controllers;

use App\Models\InmateFile;
use App\Models\OperationRecord;
use App\Models\SavedReport;
use App\Models\UnitRecord;
use App\Services\LegacyStatisticsService;
use App\Services\SentenceCalculator;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ToolboxController extends Controller
{
    public function calculator(Request $request, SentenceCalculator $calculator): View
    {
        abort_unless(auth()->user()?->hasPermission('execution.view'), 403);
        $tools = [
            'date-interval' => 'Tarih Aralığı', 'apc' => 'APC', 'terror-one-third' => 'Terör 1/3', 'fine' => 'Para Cezası',
            'one-tenth' => 'Onda Bir', 'ds-one' => 'DS 1 Yıl', 'ds-three' => 'DS 3 Yıl',
            'law-7550' => '7550 S.K.',
        ];
        $selectedTool = $request->input('tool', 'terror-one-third');
        $result = null;
        if ($request->has('start_date')) {
            if (! $request->filled('tool')) {
                $legacy = $request->validate([
                    'start_date' => ['required', 'date'], 'years' => ['nullable', 'integer', 'min:0', 'max:80'],
                    'months' => ['nullable', 'integer', 'min:0', 'max:960'], 'days' => ['nullable', 'integer', 'min:0', 'max:30000'],
                    'ratio' => ['required', 'numeric', 'min:0', 'max:1'],
                ]);

                return view('tools.calculator', ['result' => $calculator->calculate($legacy), 'tools' => $tools, 'selectedTool' => $selectedTool, 'examples' => $calculator->verifyExamples()]);
            }

            $validated = $request->validate([
                'tool' => ['required', Rule::in(array_keys($tools))],
                'start_date' => ['required', 'date'], 'end_date' => ['nullable', Rule::requiredIf(in_array($selectedTool, ['date-interval', 'law-7550'], true)), 'date'],
                'years' => ['nullable', 'integer', 'min:0', 'max:80'], 'months' => ['nullable', 'integer', 'min:0', 'max:960'], 'days' => ['nullable', 'integer', 'min:0', 'max:30000'],
                'credit_days' => ['nullable', 'integer', 'min:0', 'max:30000'], 'value_days' => ['nullable', 'integer', 'min:0', 'max:30000'],
            ]);
            try {
                $result = $calculator->calculateTool($selectedTool, $validated);
            } catch (\InvalidArgumentException $exception) {
                return back()->withInput()->withErrors(['calculation' => $exception->getMessage()]);
            }
        }

        return view('tools.calculator', ['result' => $result, 'tools' => $tools, 'selectedTool' => $selectedTool, 'examples' => $calculator->verifyExamples()]);
    }

    public function reports(): View
    {
        abort_unless(auth()->user()?->hasPermission('reports.view'), 403);

        return view('tools.reports', ['sources' => $this->reportSources(), 'reports' => SavedReport::where(fn ($q) => $q->where('is_shared', true)->orWhere('created_by', auth()->id()))->latest()->paginate(20)]);
    }

    public function storeReport(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('reports.update'), 403);
        $sources = $this->reportSources();
        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'source' => ['required', Rule::in(array_keys($sources))], 'columns' => ['required', 'array', 'min:1'], 'columns.*' => ['required', 'string'], 'is_shared' => ['nullable', 'boolean']]);
        $allowed = $sources[$validated['source']]['columns'];
        $columns = array_values(array_intersect($validated['columns'], $allowed));
        abort_if($columns === [], 422);
        SavedReport::create(['created_by' => auth()->id(), 'name' => $validated['name'], 'source' => $validated['source'], 'columns' => $columns, 'filters' => [], 'is_shared' => (bool) ($validated['is_shared'] ?? false)]);

        return redirect()->route('tools.reports')->with('status', 'Rapor tanımı kaydedildi.');
    }

    public function export(Request $request): Response|BinaryFileResponse
    {
        abort_unless(auth()->user()?->hasPermission('inmates.export'), 403);
        $sources = $this->reportSources();
        $validated = $request->validate(['source' => ['required', Rule::in(array_keys($sources))], 'format' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf'])], 'columns' => ['required', 'array', 'min:1'], 'columns.*' => ['required', 'string']]);
        $allowed = $sources[$validated['source']]['columns'];
        $columns = array_values(array_intersect($validated['columns'], $allowed));
        abort_if($columns === [], 422);
        $rows = $this->rows($validated['source'], $columns)->take(1000)->map(fn ($row) => collect($columns)->mapWithKeys(fn (string $column) => [$column => data_get($row, $column)])->all())->values();
        $format = $validated['format'] ?? 'csv';

        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray($columns, null, 'A1');
            if ($rows->isNotEmpty()) {
                $sheet->fromArray($rows->map(fn (array $row) => array_values($row))->all(), null, 'A2');
            }
            $sheet->freezePane('A2');
            $path = tempnam(sys_get_temp_dir(), 'cte_xlsx_');
            (new Xlsx($spreadsheet))->save($path);
            $spreadsheet->disconnectWorksheets();

            return response()->download($path, 'cte_export.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
        }

        if ($format === 'pdf') {
            $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:4px;text-align:left}th{background:#eee}</style><h1>CTE Portal Raporu</h1><table><thead><tr>';
            foreach ($columns as $column) {
                $html .= '<th>'.$escape($column).'</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>'.collect($row)->map(fn ($value) => '<td>'.$escape($value).'</td>')->implode('').'</tr>';
            }
            $html .= '</tbody></table>';
            $pdf = new Dompdf(['isRemoteEnabled' => false]);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="cte_export.pdf"']);
        }

        $csv = implode(',', $columns)."\n";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($value) => '"'.str_replace('"', '""', (string) $value).'"', array_values($row)))."\n";
        }

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="cte_export.csv"']);
    }

    public function statistics(LegacyStatisticsService $statistics): View
    {
        abort_unless(auth()->user()?->hasPermission('statistics.view'), 403);

        return view('tools.statistics', ['stats' => $statistics->general()]);
    }

    public function mesemStatistics(LegacyStatisticsService $statistics): View
    {
        abort_unless(auth()->user()?->hasPermission('mesem.view'), 403);

        return view('tools.mesem-statistics', ['stats' => $statistics->mesem()]);
    }

    private function rows(string $source, array $columns)
    {
        return match ($source) {
            'inmates' => InmateFile::query()->select($columns)->orderBy('full_name')->get(),
            'unit_records' => UnitRecord::query()->select($columns)->latest('occurred_at')->get(),
            'operation_records' => OperationRecord::query()->select($columns)->latest('starts_at')->get(),
            'legacy_profiles' => $this->legacyProfileRows(),
        };
    }

    private function reportSources(): array
    {
        $legacyColumns = [
            'full_name', 'identity_number', 'legacy_id', 'status_code', 'risk_code', 'decision_code',
            'health_status', 'registered_at', 'entered_at', 'notes', 'is_active',
            'housing.block', 'housing.ward', 'housing.bed', 'work.place', 'work.decision',
        ];

        foreach (app(\App\Services\UnitRegistry::class)->visible() as $module => $config) {
            foreach ($config['fields'] as $field) {
                $legacyColumns[] = $module.'.'.$field;
            }
        }

        return [
            'legacy_profiles' => ['title' => 'Eski sistem alanları (birleşik)', 'columns' => array_values(array_unique($legacyColumns))],
            'inmates' => ['title' => 'Hükümlüler', 'columns' => ['full_name', 'identity_number', 'status_code', 'risk_code', 'decision_code', 'registered_at', 'is_active']],
            'unit_records' => ['title' => 'Birim kayıtları', 'columns' => ['module_code', 'record_type', 'title', 'occurred_at', 'status_code', 'is_closed']],
            'operation_records' => ['title' => 'Operasyon kayıtları', 'columns' => ['module_code', 'record_type', 'title', 'starts_at', 'status_code', 'is_closed']],
        ];
    }

    private function legacyProfileRows()
    {
        return InmateFile::query()
            ->with([
                'activeHousingAssignment.block',
                'activeHousingAssignment.ward',
                'activeHousingAssignment.bed',
                'activeWorkAssignment.place',
                'unitRecords' => fn ($query) => $query->latest('occurred_at')->latest(),
            ])
            ->orderBy('full_name')
            ->get()
            ->map(function (InmateFile $inmate): array {
                $housing = $inmate->activeHousingAssignment->first();
                $work = $inmate->activeWorkAssignment->first();
                $row = [
                    'full_name' => $inmate->full_name,
                    'identity_number' => $inmate->identity_number,
                    'legacy_id' => $inmate->legacy_id,
                    'status_code' => $inmate->status_code,
                    'risk_code' => $inmate->risk_code,
                    'decision_code' => $inmate->decision_code,
                    'health_status' => $inmate->health_status,
                    'registered_at' => $inmate->registered_at?->toDateString(),
                    'entered_at' => $inmate->entered_at?->toDateString(),
                    'notes' => $inmate->notes,
                    'is_active' => $inmate->is_active ? 'Evet' : 'Hayır',
                    'housing' => ['block' => $housing?->block?->name, 'ward' => $housing?->ward?->name, 'bed' => $housing?->bed?->label],
                    'work' => ['place' => $work?->place?->name, 'decision' => $work?->decision_code],
                ];

                foreach ($inmate->unitRecords->groupBy('module_code') as $module => $records) {
                    $row[$module] = $records->reduce(
                        fn (array $data, UnitRecord $record): array => array_replace($data, $record->data ?? []),
                        [],
                    );
                }

                return $row;
            });
    }
}
