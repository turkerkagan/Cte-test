<?php

namespace App\Http\Controllers;

use App\Services\Housing\HousingConflict;
use App\Services\Housing\PersonPlacement;
use App\Services\Modules\DefinitionConflict;
use App\Services\Modules\ModuleExport;
use App\Services\Modules\OptionCatalog;
use App\Services\People\PersonConflict;
use App\Services\Workflows\CaseCalculations;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\CaseReports;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class WorkflowController extends Controller
{
    private function group(Request $request): string
    {
        return $request->route('workflow');
    }

    private function common(string $group): array
    {
        return ['applicationName' => config('app.name'), 'logoutUrl' => route('logout'), 'baseUrl' => route(WorkflowAccess::PAGES[$group][1]),
            'workflow' => $group, 'unitList' => in_array($group, WorkflowAccess::UNIT_PAGES, true)];
    }

    private function render(Request $request, string $component, array $props)
    {
        $response = Inertia::render($component, $props)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function result(Request $request, bool $export = false): array
    {
        $group = $this->group($request);

        return match ($group) {
            'izin_rapor' => app(CaseReports::class)->leave($request->user(), $request->all(), $export),
            'infaz_sevk_raporu' => app(CaseReports::class)->transfer($request->user(), $request->all(), $export),
            default => app(CaseList::class)->page($request->user(), $group, $request->all(), $export),
        };
    }

    public function index(Request $request)
    {
        $group = $this->group($request);
        $result = $this->result($request);

        if ($request->expectsJson()) {
            return response()->json($this->common($group) + $result)->header('Cache-Control', 'private, no-store');
        }

        return $this->render($request, 'WorkflowList', $this->common($group) + $result);
    }

    public function edit(Request $request, int $person)
    {
        $group = $this->group($request);

        $props = $this->common($group) + app(CaseRecords::class)->detail($request->user(), $group, $person) + ['title' => WorkflowAccess::PAGES[$group][0], 'submitUrl' => route($request->route()->getName(), $person)];
        if ($group === 'infaz') {
            $props['person']['status'] = app(PersonPlacement::class)->summaries([$person])[$person]['status'] ?? null;
            $photo = DB::table('person_photos')->where('person_id', $person)->first(['path', 'revision']);
            $props['photoUrl'] = $photo?->path ? route('sentences.photo', $person).'?v='.$photo->revision : null;
        }
        if ($request->expectsJson()) {
            return response()->json($props)->header('Cache-Control', 'private, no-store');
        }

        return $this->render($request, 'WorkflowEditor', $props);
    }

    public function columnValues(Request $request)
    {
        return response()->json(app(CaseList::class)->columnValues($request->user(), $this->group($request), $request->all()))->header('Cache-Control', 'private, no-store');
    }

    public function calculations(Request $request)
    {
        app(WorkflowAccess::class)->authorize($request->user(), 'infaz');
        $data = $request->validate(['birth' => ['nullable', 'date_format:Y-m-d'], 'ds' => ['nullable', 'date_format:Y-m-d'], 'release' => ['nullable', 'date_format:Y-m-d'], 'uyap' => ['nullable', 'string', 'max:255'], 'year' => ['nullable', 'integer'], 'month' => ['nullable', 'integer'], 'day' => ['nullable', 'integer']]);
        $calc = app(CaseCalculations::class);
        $now = now()->toDateTimeImmutable();

        return response()->json(['remaining' => $calc->remaining($data['ds'] ?? null, $data['release'] ?? null, $now), 'age' => $calc->sentenceAge($data['birth'] ?? null, $now), 'uyapValid' => (bool) preg_match('/^[0-9]+\/[0-9]+$/D', $data['uyap'] ?? ''), 'mean' => $calc->mean((object) ['infaz_ceza_yil' => $data['year'] ?? 0, 'infaz_ceza_ay' => $data['month'] ?? 0, 'infaz_ceza_gun' => $data['day'] ?? 0])])->header('Cache-Control', 'private, no-store');
    }

    private function mutate(callable $action)
    {
        try {
            $result = $action();
        } catch (PersonConflict|HousingConflict|DefinitionConflict $error) {
            return response()->json(['message' => $error->getMessage()], 409)->header('Cache-Control', 'private, no-store');
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'İşlem kaydedilemedi. Girdileriniz korunuyor; yeniden deneyin.']);
        }

        return response()->json($result ?? ['saved' => true])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $person)
    {
        return $this->mutate(fn () => app(CaseRecords::class)->save($request->user(), $this->group($request), $person, $request->all()));
    }

    public function reset(Request $request)
    {
        return $this->mutate(fn () => app(CaseRecords::class)->dsReset($request->user(), $request->all()));
    }

    public function mesemQuick(Request $request, int $person)
    {
        app(WorkflowAccess::class)->authorize($request->user(), 'mesem', 'yazma');
        $data = $request->validate(['action' => ['required', 'in:basvuru,istemiyor'], 'revision' => ['required', 'integer', 'min:0']]);
        $semantic = $data['action'] === 'basvuru' ? ['mesem_gorusme' => 'GÖRÜŞÜLDÜ', 'mesem_ogrenim_belgesi' => 'BEKLENİYOR', 'mesem_dilekce' => 'DİLEKÇE ALINDI', 'mesem_durumu' => 'BAŞVURU SÜRECİNDE'] : ['mesem_gorusme' => 'GÖRÜŞÜLDÜ', 'mesem_durumu' => 'İSTEMİYOR'];
        $options = app(CaseRecords::class)->options();
        $values = [];
        foreach ($semantic as $key => $value) {
            $option = collect($options[$key])->first(fn ($o) => $o['semantic'] === $value && $o['is_active']);
            if ($option === null) {
                throw ValidationException::withMessages(['action' => 'Hızlı işlem için gereken seçenek etkin değil: '.$value]);
            }
            $values[$key] = $option['id'];
        }

        return $this->mutate(fn () => app(CaseRecords::class)->save($request->user(), 'mesem', $person, ['revision' => $data['revision'], 'values' => $values]));
    }

    private function snapshot(Request $request): array
    {
        $result = $this->result($request, true);

        return in_array($this->group($request), ['izin_rapor', 'infaz_sevk_raporu'], true) ? app(CaseList::class)->snapshot($result) : $result;
    }

    public function export(Request $request)
    {
        $snapshot = $this->snapshot($request);

        return response(app(ModuleExport::class)->csv($snapshot))->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="cte-'.$this->group($request).'.csv"')->header('Cache-Control', 'private, no-store');
    }

    public function print(Request $request)
    {
        return $this->render($request, 'WorkflowPrint', $this->common($this->group($request)) + $this->snapshot($request));
    }

    public function options(Request $request)
    {
        return $this->render($request, 'OptionLists', ['applicationName' => config('app.name'), 'lists' => (new OptionCatalog('case_'))->all(), 'baseUrl' => route('workflow-options.index'), 'modulesUrl' => route('modules.index'), 'logoutUrl' => route('logout'), 'allowCreate' => true]);
    }

    public function optionCreate(Request $request)
    {
        (new OptionCatalog('case_'))->create($request->user(), $request->all());

        return redirect()->route('workflow-options.index');
    }

    public function optionUpdate(Request $request, int $id)
    {
        try {
            (new OptionCatalog('case_'))->save($request->user(), $id, $request->all());
        } catch (DefinitionConflict $error) {
            throw ValidationException::withMessages(['revision' => $error->getMessage()]);
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'Seçenekler kaydedilemedi.']);
        }

        return redirect()->route('workflow-options.index');
    }
}
