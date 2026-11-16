<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access\BuiltinPageCatalog;
use App\Services\Access\PageAccessView;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\Administration\FieldDefinitions;
use App\Services\Audit\AuditLog;
use App\Services\Workflows\CaseFields;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PageAccessController extends Controller
{
    public function index(Request $request, PageAccessView $view): Response
    {
        return Inertia::render('PagePolicies', ['applicationName' => config('app.name'), 'rows' => $view->rows(), 'targets' => $view->targets(),
            'baseUrl' => route('page-access.index'), 'batchUrl' => route('page-access.batch'), 'logoutUrl' => route('logout'),
            'selectedPageId' => $request->integer('sayfa_id') ?: null, 'sensitiveOnly' => $request->boolean('hassas')]);
    }

    public function legacy(Request $request): RedirectResponse
    {
        return redirect()->route('page-access.index', $request->only(['sayfa_id', 'hassas']));
    }

    public function sync(Request $request, BuiltinPageCatalog $catalog): RedirectResponse
    {
        $created = $catalog->sync();
        if ($created > 0) {
            app(AuditLog::class)->record($request->user(), 'page_catalog', 0, 'builtin_pages_registered', ['created' => $created]);
        }

        return redirect()->route('page-access.index')->with('notice', $created.' eksik sayfa tanımı eklendi. Mevcut yetkiler korundu.');
    }

    public function fields(int $id): JsonResponse
    {
        $page = DB::table('portal_pages')->where('id', $id)->first();
        abort_if($page === null, 404);
        $state = app(FieldDefinitions::class)->state();
        $group = $page->code;
        $keys = array_keys(app(CaseFields::class)->group($group));
        if (isset(app(FieldDefinitions::class)->institutionDefaults()[$group])) {
            $keys = array_keys(array_filter($state['defaults'], fn ($field) => $field['group'] === $group));
        }
        foreach ($state['values']['items'] as $field) {
            if ($field['group'] === $group) {
                $keys[] = $field['key'];
            }
        }

        return response()->json($state + ['group' => $group, 'fieldKeys' => array_values(array_unique($keys)),
            'supported' => isset($state['groups'][$group]), 'saveUrl' => route('management.fields')])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, PageCatalog $catalog): RedirectResponse
    {
        return $this->mutate(fn () => $catalog->save($request->user(), $request->all()));
    }

    public function update(Request $request, int $id, PageCatalog $catalog): RedirectResponse
    {
        return $this->mutate(fn () => $catalog->save($request->user(), $request->all(), $id));
    }

    public function archive(Request $request, int $id, PageCatalog $catalog): RedirectResponse
    {
        return $this->mutate(fn () => $catalog->setActive($request->user(), $id, $request->all(), false));
    }

    public function restore(Request $request, int $id, PageCatalog $catalog): RedirectResponse
    {
        return $this->mutate(fn () => $catalog->setActive($request->user(), $id, $request->all(), true));
    }

    public function batch(Request $request, PagePermissions $permissions): RedirectResponse
    {
        return $this->mutate(fn () => $permissions->apply($request->user(), $request->all()));
    }

    public function personal(Request $request, int $id, PagePermissions $permissions): RedirectResponse|JsonResponse
    {
        User::findOrFail($id);
        // The person in the URL is authoritative; posted target fields cannot redirect the operation.
        $this->mutate(fn () => $permissions->apply($request->user(), array_merge($request->only(['pages', 'operation', 'permission', 'reason']), ['target_type' => 'kisi', 'target_id' => $id])));

        return $request->wantsJson() ? response()->json(['id' => $id, 'message' => 'Kişisel izinler güncellendi. Birim ve unvan izinleri korunur.'])
            : redirect()->route('users.show', $id)->with('notice', 'Kişisel izinler güncellendi. Birim ve unvan izinleri korunur.');
    }

    public function open(int $id, PageCatalog $catalog): RedirectResponse
    {
        $page = DB::table('portal_pages')->where('id', $id)->first();
        abort_if($page === null, 404);
        $url = $catalog->destination($page);
        abort_if($url === null, 404);

        return redirect()->to($url);
    }

    private function mutate(callable $operation): RedirectResponse
    {
        try {
            $operation();
        } catch (QueryException $error) {
            throw ValidationException::withMessages(['operation' => 'İşlem tamamlanamadı. Listeyi yenileyip tekrar deneyin.']);
        }

        return redirect()->route('page-access.index')->with('notice', 'İşlem kaydedildi.');
    }
}
