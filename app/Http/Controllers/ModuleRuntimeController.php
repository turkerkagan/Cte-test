<?php

namespace App\Http\Controllers;

use App\Services\Access\PageAccess;
use App\Services\Modules\DefinitionConflict;
use App\Services\Modules\ModuleAccess;
use App\Services\Modules\ModuleExport;
use App\Services\Modules\ModulePersonLinks;
use App\Services\Modules\ModuleRecords;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class ModuleRuntimeController extends Controller
{
    public function show(Request $request, string $code, ModuleAccess $access, ModuleRecords $records): Response
    {
        $module = DB::table('modules')->where('code', $code)->first(['id', 'code']);
        abort_if($module === null, 404);
        $version = $access->authorize($request->user(), (int) $module->id);
        $definition = json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR);

        return Inertia::render('ModuleRecords', ['applicationName' => config('app.name'), 'title' => $definition['name'], 'recordMode' => $definition['record_mode'], 'baseUrl' => route('module-runtime.show', $code),
            'exportUrl' => route('module-runtime.export', $code), 'printUrl' => route('module-runtime.print', $code), 'createUrl' => route('module-runtime.create', $code), 'canWrite' => $this->canWrite($request, (int) $module->id), 'logoutUrl' => route('logout'), 'definitionVersion' => (int) $version->number,
            'fields' => collect($definition['fields'])->where('show_in_table', true)->map(fn ($field) => array_intersect_key($field, array_flip(['id', 'type', 'label'])))->values()->all(),
            ...$this->withEditLinks($records->runtimePage($request->user(), (int) $module->id, $request->only(['page', 'per_page', 'q', 'filters', 'filter_field', 'filter_op', 'filter_value', 'sort_field', 'sort_direction'])), $code)]);
    }

    public function export(Request $request, string $code, ModuleExport $service): HttpResponse
    {
        $snapshot = $this->exportSnapshot($request, $code, $service);

        return response($service->csv($snapshot), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$code.'.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function print(Request $request, string $code, ModuleExport $service): HttpResponse
    {
        $response = Inertia::render('ModulePrint', ['applicationName' => config('app.name'), 'baseUrl' => route('module-runtime.show', ['code' => $code] + $request->only(['q', 'filters', 'filter_field', 'filter_op', 'filter_value', 'sort_field', 'sort_direction'])),
            ...$this->exportSnapshot($request, $code, $service)])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function exportSnapshot(Request $request, string $code, ModuleExport $service): array
    {
        try {
            return $service->snapshot($request->user(), $this->moduleId($code), $request->only(['q', 'filters', 'filter_field', 'filter_op', 'filter_value', 'sort_field', 'sort_direction']));
        } catch (QueryException) {
            throw ValidationException::withMessages(['export' => 'Çıktı hazırlanamadı. Filtreleri kontrol edip yeniden deneyin.']);
        }
    }

    public function people(Request $request, string $code, ModulePersonLinks $links): JsonResponse
    {
        try {
            $people = $links->lookup($request->user(), $this->moduleId($code), $request->only('q'));
        } catch (QueryException) {
            throw ValidationException::withMessages(['q' => 'Kişi araması yapılamadı. Yeniden deneyin.']);
        }

        return response()->json(['people' => $people])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, string $code, ModuleRecords $records): Response|JsonResponse
    {
        return $this->editor($request, $code, $records);
    }

    public function edit(Request $request, string $code, int $record, ModuleRecords $records): Response|JsonResponse
    {
        return $this->editor($request, $code, $records, $record);
    }

    public function store(Request $request, string $code, ModuleRecords $records): JsonResponse
    {
        return $this->save($request, $code, $records);
    }

    public function update(Request $request, string $code, int $record, ModuleRecords $records): JsonResponse
    {
        return $this->save($request, $code, $records, $record);
    }

    private function moduleId(string $code): int
    {
        $id = DB::table('modules')->where('code', $code)->value('id');
        abort_if($id === null, 404);

        return (int) $id;
    }

    private function canWrite(Request $request, int $moduleId): bool
    {
        $page = DB::table('portal_pages')->where('module_id', $moduleId)->first();

        return app(PageAccess::class)->allows($request->user(), $page->code, 'yazma');
    }

    private function withEditLinks(array $page, string $code): array
    {
        foreach ($page['rows'] as &$row) {
            $row['editUrl'] = route('module-runtime.edit', [$code, $row['id']]);
        }

        return $page;
    }

    private function editor(Request $request, string $code, ModuleRecords $records, ?int $record = null): Response|JsonResponse
    {
        $data = ['applicationName' => config('app.name'), 'baseUrl' => route('module-runtime.show', $code), 'logoutUrl' => route('logout'),
            'peopleUrl' => route('module-runtime.people', $code),
            'editUrl' => $record === null ? null : route('module-runtime.edit', [$code, $record]),
            'submitUrl' => $record === null ? route('module-runtime.store', $code) : route('module-runtime.update', [$code, $record]),
            ...$records->runtimeForm($request->user(), $this->moduleId($code), $record)];

        if ($request->wantsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('ModuleRecordEditor', $data);
    }

    private function save(Request $request, string $code, ModuleRecords $records, ?int $record = null): JsonResponse
    {
        try {
            $saved = $records->runtimeSave($request->user(), $this->moduleId($code), $request->all(), $record);
        } catch (DefinitionConflict) {
            return response()->json(['message' => 'Kayıt veya sayfa tanımı değişti. Girdileriniz korunuyor; güncel kaydı yeniden açıp karşılaştırın.'], 409);
        } catch (QueryException) {
            throw ValidationException::withMessages(['operation' => 'Kayıt kaydedilemedi. Girdilerinizi kontrol edip yeniden deneyin.']);
        }

        return response()->json($saved, $record === null ? 201 : 200);
    }
}
