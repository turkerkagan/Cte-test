<?php

namespace App\Http\Controllers;

use App\Services\Institution\InstitutionRecords;
use App\Services\Workspace\BackupRehearsal;
use App\Services\Workspace\DataQuality;
use App\Services\Workspace\DutyScenarios;
use App\Services\Workspace\HandoverSummary;
use App\Services\Workspace\PermissionSimulation;
use App\Services\Workspace\WorkspaceAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $actor = app(WorkspaceAccess::class)->actor($request->user());
        $kinds = [];
        foreach (['mudurnobet' => 'Müdür nöbeti', 'vardiyanobet' => 'Vardiya nöbeti', 'infaznobet' => 'İnfaz nöbeti'] as $kind => $label) {
            if (app(InstitutionRecords::class)->canWrite($actor, $kind)) {
                $kinds[] = ['id' => $kind, 'label' => $label];
            }
        }
        $data = ['applicationName' => config('app.name'), 'baseUrl' => route('workspace.index'),
            'canManage' => Gate::forUser($actor)->allows('manage-modules'), 'dutyKinds' => $kinds,
            'handover' => app(HandoverSummary::class)->read($actor, $request->only('hours'))];

        return $this->noStore(Inertia::render('Workspace', $data)->toResponse($request));
    }

    public function permissions(Request $request)
    {
        return response()->json(app(PermissionSimulation::class)->read($request->user(), $request->only(['user', 'q'])))->header('Cache-Control', 'private, no-store');
    }

    public function quality(Request $request)
    {
        return response()->json(app(DataQuality::class)->read($request->user(), $request->only(['issue', 'page'])))->header('Cache-Control', 'private, no-store');
    }

    public function provenance(Request $request, int $person)
    {
        return response()->json(app(DataQuality::class)->provenance($request->user(), $person))->header('Cache-Control', 'private, no-store');
    }

    public function scenario(Request $request, string $kind)
    {
        app(WorkspaceAccess::class)->actor($request->user());
        $data = $request->validate(['action' => ['required', 'in:preview,apply']]);
        $service = app(DutyScenarios::class);
        $result = $data['action'] === 'preview' ? $service->preview($request->user(), $kind, $request->all()) : $service->apply($request->user(), $kind, $request->all());

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function rehearsal(Request $request)
    {
        $service = app(BackupRehearsal::class);
        if ($request->isMethod('GET')) {
            return response()->json($service->read($request->user()))->header('Cache-Control', 'private, no-store');
        }
        $data = $request->validate(['backup' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted']]);
        try {
            $result = $service->run($request->user(), (int) $data['backup']);
        } catch (\RuntimeException $error) {
            report($error);

            return response()->json(['message' => 'Geri yükleme provası tamamlanamadı. Sunucu günlüğünü ve prova oluşturma izinlerini kontrol edin.'], 422)->header('Cache-Control', 'private, no-store');
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
