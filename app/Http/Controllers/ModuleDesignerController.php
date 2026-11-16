<?php

namespace App\Http\Controllers;

use App\Services\Modules\DefinitionConflict;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModulePublication;
use App\Services\Modules\OptionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ModuleDesignerController extends Controller
{
    public function index(): Response
    {
        $versions = DB::table('module_versions')->orderByDesc('number')->get(['module_id', 'number', 'status'])->groupBy('module_id');
        $rows = DB::table('modules')->orderBy('name')->get(['id', 'name', 'code', 'record_mode'])->map(fn ($module) => [
            'id' => (int) $module->id, 'name' => $module->name, 'code' => $module->code, 'record_mode' => $module->record_mode,
            'number' => (int) ($versions->get($module->id)?->first()->number ?? 0),
            'has_draft' => $versions->get($module->id, collect())->contains('status', 'draft'),
        ]);

        return Inertia::render('Modules', $this->common() + ['rows' => $rows]);
    }

    public function show(Request $request, int $id, ModulePublication $publication): Response
    {
        $state = $this->definition($id);

        return Inertia::render('ModuleDesigner', $this->common() + $state + ['saveUrl' => route('modules.update', $id), 'forkUrl' => route('modules.fork', $id), 'optionLists' => app(OptionCatalog::class)->all(),
            'publishUrl' => route('modules.publish', $id), 'publication' => $state['version']['status'] === 'draft' ? $publication->inspect($request->user(), $id) : null,
            'runtimeUrl' => DB::table('portal_pages')->where('module_id', $id)->where('is_active', true)->exists() ? route('module-runtime.show', $state['module']['code']) : null]);
    }

    public function publish(Request $request, int $id, ModulePublication $service): RedirectResponse|JsonResponse
    {
        try {
            $this->mutate(fn () => $service->publish($request->user(), $id, $request->all()));
        } catch (DefinitionConflict $error) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error->getMessage()], 409);
            }
            throw ValidationException::withMessages(['revision' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json($this->definition($id)) : redirect()->route('modules.show', $id)->with('notice', 'Kaydedilmiş taslak yayımlandı.');
    }

    public function store(Request $request, ModuleDefinitions $service): RedirectResponse|JsonResponse
    {
        $id = $this->mutate(fn () => $service->create($request->user(), $request->all()));

        return $request->expectsJson() ? response()->json($this->definition($id), 201) : redirect()->route('modules.show', $id);
    }

    public function update(Request $request, int $id, ModuleDefinitions $service): JsonResponse
    {
        try {
            $this->mutate(fn () => $service->save($request->user(), $id, $request->all()));
        } catch (DefinitionConflict $error) {
            return response()->json(['message' => $error->getMessage(), 'errors' => ['revision' => [$error->getMessage()]]], 409);
        }

        return response()->json($this->definition($id));
    }

    public function fork(Request $request, int $id, ModuleDefinitions $service): RedirectResponse|JsonResponse
    {
        try {
            $this->mutate(fn () => $service->fork($request->user(), $id, $request->all()));
        } catch (DefinitionConflict $error) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error->getMessage()], 409);
            }
            throw ValidationException::withMessages(['revision' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json($this->definition($id), 201) : redirect()->route('modules.show', $id);
    }

    private function definition(int $id): array
    {
        $module = DB::table('modules')->where('id', $id)->first(['id', 'code', 'name', 'record_mode', 'revision']);
        abort_if($module === null, 404);
        $history = DB::table('module_versions')->where('module_id', $id)->orderByDesc('number')->get(['id', 'number', 'status', 'revision']);
        $selected = $history->firstWhere('status', 'draft') ?? $history->firstWhere('status', 'published');
        abort_if($selected === null, 404);
        $definition = json_decode(DB::table('module_versions')->where('id', $selected->id)->value('definition'), true, 64, JSON_THROW_ON_ERROR);

        return ['module' => ['id' => (int) $module->id, 'code' => $module->code, 'name' => $module->name, 'record_mode' => $module->record_mode, 'revision' => (int) $module->revision],
            'version' => ['id' => (int) $selected->id, 'number' => (int) $selected->number, 'revision' => (int) $selected->revision, 'status' => $selected->status, 'definition' => $definition],
            'history' => $history->map(fn ($version) => ['id' => (int) $version->id, 'number' => (int) $version->number, 'status' => $version->status])->all()];
    }

    private function mutate(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $error) {
            throw ValidationException::withMessages(['operation' => 'Taslak kaydedilemedi. Modül kodunu ve güncel tanımı kontrol edip tekrar deneyin.']);
        }
    }

    private function common(): array
    {
        return ['applicationName' => config('app.name'), 'baseUrl' => route('modules.index'), 'logoutUrl' => route('logout'), 'optionsUrl' => route('options.index')];
    }
}
