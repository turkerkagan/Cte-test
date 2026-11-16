<?php

namespace App\Http\Controllers;

use App\Services\Organization\OrganizationDefinitions;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationController extends Controller
{
    public function index(Request $request, string $kind, OrganizationDefinitions $service): Response|JsonResponse
    {
        $definition = $service->definition($kind);
        $table = $definition['table'];
        $impacts = $service->permissionImpacts($kind);
        $emptyImpact = $service->emptyPermissionImpact();
        $rows = DB::table($table)->select(['id', 'code', 'name', 'is_active', 'revision'])
            ->selectSub(DB::table('users')->selectRaw('COUNT(*)')->whereColumn($definition['foreign_key'], $table.'.id'), 'assigned_count')
            ->orderBy('name')->orderBy('id')->get()->map(fn ($row) => [
                'id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name, 'is_active' => (bool) $row->is_active,
                'revision' => (int) $row->revision, 'assigned_count' => (int) $row->assigned_count,
                'permission_count' => ($impacts[$row->id] ?? $emptyImpact)['count'],
                'permission_snapshot' => ($impacts[$row->id] ?? $emptyImpact)['snapshot'],
            ]);

        if ($request->expectsJson()) {
            return response()->json(['rows' => $rows])->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('Organization', ['applicationName' => config('app.name'), 'title' => $definition['title'],
            'kind' => $kind, 'rows' => $rows, 'baseUrl' => route('organization.index', $kind), 'logoutUrl' => route('logout')]);
    }

    public function store(Request $request, string $kind, OrganizationDefinitions $service): RedirectResponse
    {
        return $this->mutate($kind, fn () => $service->save($request->user(), $kind, $request->all()));
    }

    public function update(Request $request, string $kind, int $id, OrganizationDefinitions $service): RedirectResponse
    {
        return $this->mutate($kind, fn () => $service->save($request->user(), $kind, $request->all(), $id));
    }

    public function destroy(Request $request, string $kind, int $id, OrganizationDefinitions $service): RedirectResponse
    {
        return $this->mutate($kind, fn () => $service->delete($request->user(), $kind, $id, $request->all()));
    }

    private function mutate(string $kind, callable $operation): RedirectResponse
    {
        try {
            $operation();
        } catch (QueryException $error) {
            // Transaction has rolled back: never expose SQL or partial transfers.
            throw ValidationException::withMessages(['operation' => 'İşlem tamamlanamadı. Kodun benzersizliğini ve güncel atamaları kontrol edip yeniden deneyin.']);
        }

        return redirect()->route('organization.index', $kind);
    }
}
