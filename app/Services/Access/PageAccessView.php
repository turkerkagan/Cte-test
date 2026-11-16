<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PageAccessView
{
    public function rows(?User $person = null): array
    {
        $matrix = $person === null ? [] : app(PageAccess::class)->matrix($person);
        $current = $person === null ? null : User::find($person->id);
        $pages = DB::table('portal_pages')->orderBy('name')->orderBy('id')->get();
        $archived = $pages->where('is_active', false)->pluck('code')->all();
        $menu = DB::table('menu_items')->orderBy('position')->orderBy('id')->get()->groupBy(fn ($item) => $item->parent_id ?? 0);
        $menuOrder = [];
        $visited = [];
        $walk = function (int $parent) use (&$walk, &$menuOrder, &$visited, $menu): void {
            foreach ($menu->get($parent, collect()) as $item) {
                if (isset($visited[$item->id])) {
                    continue;
                }
                $visited[$item->id] = true;
                if ($item->page_id !== null) {
                    $menuOrder[(int) $item->page_id] = count($menuOrder);
                }
                $walk((int) $item->id);
            }
        };
        $walk(0);
        $history = $person === null ? DB::query()->fromSub(DB::table('audit_events')->where('resource_type', 'page_access')
            ->select(['id', 'resource_id', 'actor_id', 'created_at', 'details'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY resource_id ORDER BY id DESC) as position'), 'events')
            ->leftJoin('users as actors', 'actors.id', '=', 'events.actor_id')->where('events.position', '<=', 15)
            ->orderByDesc('events.id')->get(['events.id', 'events.resource_id', 'events.created_at', 'events.details', 'actors.full_name as actor'])
            ->groupBy('resource_id') : collect();
        $rules = DB::table('page_permissions')->leftJoin('departments', 'departments.id', '=', 'page_permissions.department_id')
            ->leftJoin('job_titles', 'job_titles.id', '=', 'page_permissions.job_title_id')->leftJoin('users', 'users.id', '=', 'page_permissions.user_id')
            ->get(['page_permissions.page_id', 'target_type', 'target_id', 'permission', 'page_permissions.reason', 'departments.name as department', 'job_titles.name as job_title', 'users.full_name as person', 'users.username'])
            ->groupBy('page_id');

        return $pages->map(function ($page) use ($matrix, $rules, $person, $history, $current, $archived, $menuOrder): array {
            $permissions = $rules->get($page->id, collect());
            $effective = $matrix[$page->code] ?? ['giris' => false, 'yazma' => false, 'sources' => []];
            $source = WorkflowAccess::SOURCE_POLICIES[$page->code] ?? null;
            $inherited = $source === null ? null : ($matrix[$source] ?? null);
            if ($inherited === null && $source === 'hukumlu_kayit' && $current?->is_active && ! $current->must_change_password) {
                $inherited = ['name' => 'Ana Sayfa', 'giris' => true, 'yazma' => false, 'sources' => [['type' => 'homepage', 'permission' => 'giris']]];
            }
            if ($inherited !== null) {
                $effective['giris'] = $effective['giris'] || $inherited['giris'];
                $effective['yazma'] = $effective['yazma'] || $inherited['yazma'];
                $effective['sources'] = array_merge($effective['sources'], array_map(fn ($item) => $item + ['page_name' => $inherited['name']], $inherited['sources']));
            }
            if (in_array($page->code, $archived, true) || ($source !== null && in_array($source, $archived, true))
                || ($page->code === 'custom_rapor' && ! $current?->is_system_admin)) {
                $effective = ['giris' => false, 'yazma' => false, 'sources' => []];
            }
            $readOnly = in_array($page->code, WorkflowAccess::READ_ONLY_PAGES, true);
            if ($readOnly) {
                $effective['yazma'] = false;
            }

            return ['id' => (int) $page->id, 'code' => $page->code, 'name' => $page->name, 'legacy_file' => $page->legacy_file,
                'path' => $page->path, 'description' => $page->description, 'data_table' => $page->data_table, 'managed_module' => $page->module_id !== null, 'is_active' => (bool) $page->is_active, 'revision' => (int) $page->revision,
                'permission_count' => $permissions->count(), 'open_url' => app(PageCatalog::class)->destination($page) === null ? null : route('page-access.open', $page->id),
                'rules' => $person !== null ? [] : $permissions->map(fn ($rule) => ['target_type' => $rule->target_type, 'target_id' => (int) $rule->target_id, 'permission' => $rule->permission, 'reason' => $rule->reason,
                    'name' => match ($rule->target_type) {
                        'birim' => $rule->department, 'unvan' => $rule->job_title, 'kisi' => $rule->person ?: $rule->username, 'misafir' => 'Misafir', default => 'Herkes'
                    }])->all(),
                'personal_reason' => $person === null ? null : $permissions->first(fn ($rule) => $rule->target_type === 'kisi' && (int) $rule->target_id === $person->id)?->reason,
                'personal_permission' => $person === null ? null : $permissions->first(fn ($rule) => $rule->target_type === 'kisi' && (int) $rule->target_id === $person->id)?->permission,
                'effective' => $effective, 'read_only' => $readOnly, 'menu_order' => $menuOrder[$page->id] ?? null,
                'history' => $history->get($page->id, collect())->map(fn ($event) => ['id' => (int) $event->id, 'actor' => $event->actor,
                    'created_at' => Carbon::parse($event->created_at)->toIso8601String(),
                    'details' => array_intersect_key(json_decode($event->details, true, 512, JSON_THROW_ON_ERROR), array_flip(['target_type', 'target_id', 'before', 'after', 'reason']))])->all(),
            ];
        })->all();
    }

    public function targets(): array
    {
        $definitions = fn ($table) => DB::table($table)->orderBy('name')->get(['id', 'name', 'is_active'])->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'is_active' => (bool) $row->is_active])->all();

        return ['birim' => $definitions('departments'), 'unvan' => $definitions('job_titles'),
            'kisi' => DB::table('users')->orderBy('full_name')->get(['id', 'full_name', 'username', 'registry_number', 'is_active'])
                ->map(fn ($row) => ['id' => (int) $row->id, 'name' => ($row->full_name ?: $row->username).' · '.$row->username.($row->registry_number ? ' · '.$row->registry_number : ''), 'is_active' => (bool) $row->is_active])->all()];
    }
}
