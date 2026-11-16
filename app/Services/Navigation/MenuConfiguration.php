<?php

namespace App\Services\Navigation;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Audit\AuditLog;
use App\Services\Modules\DefinitionConflict;
use App\Services\Modules\ModuleAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class MenuConfiguration
{
    public function state(User $actor): array
    {
        $this->actor($actor);
        $state = DB::table('menu_configuration')->where('id', 1)->first();
        $items = DB::table('menu_items')->orderBy('position')->orderBy('id')->get();

        return ['revision' => (int) $state->revision, 'configured' => (bool) $state->configured,
            'items' => $items->map(fn ($item) => ['id' => (int) $item->id, 'key' => 'saved-'.$item->id, 'parent_key' => $item->parent_id === null ? null : 'saved-'.$item->parent_id, 'label' => $item->label, 'page_id' => $item->page_id === null ? null : (int) $item->page_id, 'is_active' => (bool) $item->is_active])->all(),
            'pages' => $this->pages()->get(['portal_pages.id', 'portal_pages.name'])->map(fn ($page) => ['id' => (int) $page->id, 'name' => $page->name])->all()];
    }

    public function save(User $actor, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'items' => ['present', 'array', 'list', 'max:100'],
            'items.*' => ['array:id,key,parent_key,label,page_id,is_active'], 'items.*.id' => ['present', 'nullable', 'integer', 'min:1', 'distinct'],
            'items.*.key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/D', 'distinct'], 'items.*.parent_key' => ['present', 'nullable', 'string', 'max:80'],
            'items.*.label' => ['required', 'string', 'max:150'], 'items.*.page_id' => ['present', 'nullable', 'integer', 'min:1', 'distinct'], 'items.*.is_active' => ['required', 'boolean']])->validate();
        DB::transaction(function () use ($actor, $data): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $config = DB::table('menu_configuration')->where('id', 1)->lockForUpdate()->first();
            $actor = $this->actor($actor, true);
            if ((int) $config->revision !== (int) $data['revision']) {
                throw new DefinitionConflict;
            }
            $existing = DB::table('menu_items')->get()->keyBy('id');
            $keys = collect($data['items'])->keyBy('key');
            $pages = $this->pages()->pluck('portal_pages.id')->all();
            foreach ($data['items'] as $i => $item) {
                $prior = $item['id'] === null ? null : $existing->get($item['id']);
                $retainedPage = $prior !== null && $prior->page_id !== null && (int) $prior->page_id === (int) $item['page_id'];
                $parent = $item['parent_key'] === null ? null : $keys->get($item['parent_key']);
                if (($item['id'] !== null && ! $existing->has($item['id'])) || ($item['parent_key'] !== null && ($parent === null || $parent['parent_key'] !== null || $parent['page_id'] !== null || $parent['key'] === $item['key'])) || ($item['parent_key'] !== null && $item['page_id'] === null) || ($item['page_id'] !== null && ! in_array($item['page_id'], $pages) && ! $retainedPage) || trim($item['label']) === '') {
                    throw ValidationException::withMessages(['items.'.$i => 'Menü hedefini ve en fazla iki seviyeli grup yapısını kontrol edin.']);
                }
            }
            $unchanged = $config->configured && $existing->count() === count($data['items']);
            foreach ($data['items'] as $position => $item) {
                $before = $item['id'] === null ? null : $existing->get($item['id']);
                $parentId = $item['parent_key'] === null ? null : ($keys[$item['parent_key']]['id'] ?? null);
                $unchanged = $unchanged && $before !== null && (int) $before->position === $position && $before->label === trim($item['label']) && (bool) $before->is_active === (bool) $item['is_active'] && ($before->page_id === null ? null : (int) $before->page_id) === ($item['page_id'] === null ? null : (int) $item['page_id']) && ($before->parent_id === null ? null : (int) $before->parent_id) === ($parentId === null ? null : (int) $parentId);
            }
            if ($unchanged) {
                return;
            }
            // Release unique targets before a reorder/reassignment; the transaction hides intermediate state.
            DB::table('menu_items')->update(['page_id' => null]);
            $assigned = [];
            foreach ([false, true] as $children) {
                foreach ($data['items'] as $position => $item) {
                    if (($item['parent_key'] !== null) !== $children) {
                        continue;
                    }
                    $values = ['parent_id' => $children ? $assigned[$item['parent_key']] : null, 'page_id' => $item['page_id'], 'label' => trim($item['label']), 'is_active' => $item['is_active'], 'position' => $position, 'updated_at' => now()];
                    if ($item['id'] === null) {
                        $id = DB::table('menu_items')->insertGetId($values + ['created_at' => now()]);
                    } else {
                        $id = $item['id'];
                        DB::table('menu_items')->where('id', $id)->update($values);
                    }
                    $assigned[$item['key']] = $id;
                }
            }
            $removed = $existing->keys()->diff(array_values($assigned));
            DB::table('menu_items')->whereIn('id', $removed)->whereNotNull('parent_id')->delete();
            DB::table('menu_items')->whereIn('id', $removed)->delete();
            DB::table('menu_configuration')->where('id', 1)->update(['revision' => $config->revision + 1, 'configured' => true]);
            app(AuditLog::class)->record($actor, 'menu_configuration', 1, 'updated', ['revision' => $config->revision + 1, 'item_count' => count($assigned)]);
        }, 3);
    }

    public function navigation(User $actor): array
    {
        if (! DB::table('menu_configuration')->where('id', 1)->value('configured')) {
            return app(ModuleAccess::class)->navigation($actor);
        }
        $matrix = app(PageAccess::class)->displayMatrix($actor);
        $pages = $this->pages()->get(['portal_pages.id', 'portal_pages.code', 'modules.code as module_code'])->keyBy('id');
        $items = DB::table('menu_items')->where('is_active', true)->orderBy('position')->orderBy('id')->get();
        $link = function ($item) use ($pages, $matrix) {
            $page = $pages->get($item->page_id);

            return $page !== null && ($matrix[$page->code]['giris'] ?? false) ? ['label' => $item->label, 'url' => route('module-runtime.show', $page->module_code)] : null;
        };
        $result = [];
        foreach ($items->whereNull('parent_id') as $item) {
            if ($item->page_id !== null) {
                $entry = $link($item);
                if ($entry !== null) {
                    $result[] = $entry;
                }
            } else {
                $children = $items->where('parent_id', $item->id)->map($link)->filter()->values()->all();
                if ($children !== []) {
                    $result[] = ['label' => $item->label, 'url' => '#menu-group-'.$item->id, 'children' => $children];
                }
            }
        }

        return $result;
    }

    private function pages(): Builder
    {
        return DB::table('portal_pages')->join('modules', 'modules.id', '=', 'portal_pages.module_id')->where('portal_pages.is_active', true)->whereExists(fn ($query) => $query->selectRaw('1')->from('module_versions')->whereColumn('module_versions.module_id', 'modules.id')->where('status', 'published')->whereNull('draft_slot'))->orderBy('portal_pages.name');
    }

    private function actor(User $actor, bool $lock = false): User
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $query = User::whereKey($actor->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($current === null, 403);
        Gate::forUser($current)->authorize('manage-modules');

        return $current;
    }
}
