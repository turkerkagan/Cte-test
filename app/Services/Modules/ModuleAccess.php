<?php

namespace App\Services\Modules;

use App\Models\User;
use App\Services\Access\PageAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ModuleAccess
{
    public function navigation(User $actor): array
    {
        $matrix = app(PageAccess::class)->displayMatrix($actor);

        return DB::table('portal_pages')->join('modules', 'modules.id', '=', 'portal_pages.module_id')->where('portal_pages.is_active', true)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('module_versions')->whereColumn('module_versions.module_id', 'modules.id')->where('status', 'published')->whereNull('draft_slot'))
            ->orderBy('portal_pages.name')->get(['portal_pages.code', 'portal_pages.name', 'modules.code as module_code'])
            ->filter(fn ($page) => $matrix[$page->code]['giris'] ?? false)->map(fn ($page) => ['label' => $page->name, 'url' => route('module-runtime.show', $page->module_code)])->values()->all();
    }

    public function authorize(User $actor, int $moduleId, string $permission = 'giris'): object
    {
        $page = DB::table('portal_pages')->join('modules', 'modules.id', '=', 'portal_pages.module_id')->where('module_id', $moduleId)->where('portal_pages.is_active', true)->first(['portal_pages.*', 'modules.record_mode']);
        abort_if($page === null, 404);
        abort_unless(app(PageAccess::class)->allows($actor, $page->code, $permission), 403);
        $version = DB::table('module_versions')->where('module_id', $moduleId)->where('status', 'published')->whereNull('draft_slot')->orderByDesc('number')->first();
        abort_if($version === null, 404);

        $definition = json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR);
        if (! in_array($definition['record_mode'] ?? null, ModuleDefinitions::RECORD_MODES, true) || $definition['record_mode'] !== $page->record_mode) {
            throw ValidationException::withMessages(['definition' => 'Modül kayıt modu ve yayımlanmış tanım uyuşmuyor.']);
        }
        if ($definition['record_mode'] !== 'standalone') {
            abort_unless(app(PageAccess::class)->allows($actor, 'hukumlu_kayit'), 403);
        }

        return $version;
    }
}
