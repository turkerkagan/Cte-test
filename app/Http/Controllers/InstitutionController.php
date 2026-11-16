<?php

namespace App\Http\Controllers;

use App\Services\Institution\DutyPlanning;
use App\Services\Institution\InstitutionRecords;
use App\Services\Modules\ModuleExport;
use App\Services\People\PersonConflict;
use App\Services\Workflows\WorkflowAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class InstitutionController extends Controller
{
    private function kind(Request $r): string
    {
        return $r->route('institution');
    }

    private function common(Request $r): array
    {
        return ['applicationName' => config('app.name'), 'management' => (bool) $r->route('management'), 'baseUrl' => $r->route('management') ? route('management.phone') : route(WorkflowAccess::PAGES[$this->kind($r)][1]), 'logoutUrl' => route('logout')];
    }

    public function index(Request $r)
    {
        $data = app(InstitutionRecords::class)->read($r->user(), $this->kind($r), $r->all());
        if ($r->wantsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return $this->noStore(Inertia::render('Institution', $data + $this->common($r))->toResponse($r));
    }

    public function calendar(Request $request)
    {
        $input = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        abort_if(CarbonImmutable::parse($input['from'])->diffInDays(CarbonImmutable::parse($input['to'])) > 31, 422, 'En fazla bir aylık takvim seçin.');
        $data = app(InstitutionRecords::class)->read($request->user(), $this->kind($request), $input, true);

        return response()->json(['rows' => $data['rows']])->header('Cache-Control', 'private, no-store');
    }

    public function directory(Request $request)
    {
        $data = app(InstitutionRecords::class)->read($request->user(), 'telefon', [], true);

        return response()->json(['rows' => $data['rows']])->header('Cache-Control', 'private, no-store');
    }

    public function readers(Request $request, int $record)
    {
        app(InstitutionRecords::class)->authorize($request->user(), 'duyurular', true);
        abort_unless(DB::table(app(InstitutionRecords::class)->table('duyurular'))->where('id', $record)->where('kind', 'duyurular')->where('is_active', true)->exists(), 404);

        return response()->json(['readers' => DB::table('announcement_reads as r')->join('users as u', 'u.id', '=', 'r.user_id')->where('r.record_table', app(InstitutionRecords::class)->table('duyurular'))->where('r.record_id', $record)->orderBy('u.full_name')->pluck('u.full_name')->all()])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $r)
    {
        $result = app(InstitutionRecords::class)->read($r->user(), $this->kind($r), $r->all(), true);

        return response(app(ModuleExport::class)->csv(app(InstitutionRecords::class)->snapshot($result, true)))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="cte-'.$this->kind($r).'.csv"')->header('Cache-Control', 'private, no-store');
    }

    public function print(Request $r)
    {
        $result = app(InstitutionRecords::class)->read($r->user(), $this->kind($r), $r->all(), true);

        return $this->noStore(Inertia::render('WorkflowPrint', app(InstitutionRecords::class)->snapshot($result) + $this->common($r) + ['workflow' => $this->kind($r)])->toResponse($r));
    }

    public function mutate(Request $r)
    {
        $record = $r->route('record') === null ? null : (int) $r->route('record');
        try {
            $result = match ($r->input('action', 'save')) {
                'save' => app(InstitutionRecords::class)->save($r->user(), $this->kind($r), $record, $r->except('action')),
                'archive' => $this->archive($r, $record),
                'restore' => $this->restore($r, $record),
                'receipt' => $this->receipt($r),
                'generate' => app(DutyPlanning::class)->generate($r->user(), $this->kind($r), $r->all()),
                'unavailable' => $this->unavailable($r),
                'board' => $this->board($r),
                default => abort(422),
            };
        } catch (PersonConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (QueryException) {
            return response()->json(['message' => 'Kayıt tamamlanamadı. Girdileriniz korunuyor.'], 422);
        }

        return response()->json(is_array($result) ? $result : ['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    private function archive(Request $r, ?int $record): void
    {
        abort_if($record === null, 404);
        $d = $r->validate(['revision' => ['required', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:500']]);
        app(InstitutionRecords::class)->archive($r->user(), $this->kind($r), $record, $d['revision'], $d['reason'] ?? null);
    }

    private function restore(Request $r, ?int $record): void
    {
        abort_if($record === null, 404);
        $data = $r->validate(['revision' => ['required', 'integer', 'min:1']]);
        app(InstitutionRecords::class)->restore($r->user(), $this->kind($r), $record, $data['revision']);
    }

    private function receipt(Request $r): void
    {
        abort_unless($this->kind($r) === 'duyurular', 404);
        $d = $r->validate(['id' => ['nullable', 'integer', 'min:1'], 'read' => ['required', 'boolean']]);
        app(InstitutionRecords::class)->receipt($r->user(), $d['id'] ?? null, $d['read']);
    }

    private function unavailable(Request $r): void
    {
        abort_unless($this->kind($r) === 'infaznobet', 404);
        app(DutyPlanning::class)->unavailable($r->user(), $r->all());
    }

    private function board(Request $r): void
    {
        abort_unless($this->kind($r) === 'kurul', 404);
        $d = $r->validate(['records' => ['required', 'array', 'list', 'size:12'], 'records.*.id' => ['required', 'integer', 'distinct'], 'records.*.revision' => ['required', 'integer', 'min:1'], 'records.*.values' => ['required', 'array']]);
        DB::transaction(function () use ($r, $d) {
            foreach ($d['records'] as $row) {
                app(InstitutionRecords::class)->save($r->user(), 'kurul', $row['id'], ['revision' => $row['revision'], 'values' => $row['values']]);
            }
        }, 3);
    }
}
