<?php

namespace App\Services\Modules;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ModulePublication
{
    public function inspect(User $actor, int $moduleId): array
    {
        $this->actor($actor);

        return $this->impact($moduleId);
    }

    public function publish(User $actor, int $moduleId, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'version_id' => ['required', 'integer', 'min:1'],
            'version_revision' => ['required', 'integer', 'min:1'], 'record_count' => ['required', 'integer', 'min:0'],
            'record_snapshot' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/D'], 'confirmed' => ['accepted']])->validate();
        DB::transaction(function () use ($actor, $moduleId, $data): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            DB::table('modules')->where('id', $moduleId)->lockForUpdate()->first();
            $actor = $this->actor($actor, true);
            $impact = $this->impact($moduleId);
            foreach (['revision', 'version_id', 'version_revision', 'record_count', 'record_snapshot'] as $key) {
                if ((string) $impact[$key] !== (string) $data[$key]) {
                    throw new DefinitionConflict;
                }
            }
            if ($impact['blocked'] !== []) {
                throw ValidationException::withMessages(['publication' => $impact['blocked']]);
            }
            $version = DB::table('module_versions')->where('id', $impact['version_id'])->first();
            $definition = json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR);
            $page = DB::table('portal_pages')->where('module_id', $moduleId)->lockForUpdate()->first();
            if ($page === null) {
                DB::table('portal_pages')->insert(['module_id' => $moduleId, 'code' => 'module_'.$moduleId, 'name' => $definition['name'],
                    'is_active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($page->name !== $definition['name']) {
                DB::table('portal_pages')->where('id', $page->id)->update(['name' => $definition['name'], 'revision' => $page->revision + 1, 'updated_at' => now()]);
            }
            DB::table('module_versions')->where('id', $version->id)->update(['status' => 'published', 'draft_slot' => null, 'updated_at' => now()]);
            DB::table('modules')->where('id', $moduleId)->update(['revision' => $impact['revision'] + 1, 'updated_at' => now()]);
            // With records, only presentation changes are accepted. Values and
            // derived rows stay intact; revision invalidates stale edit forms.
            DB::table('module_records')->where('module_id', $moduleId)->update(['version_id' => $version->id, 'revision' => DB::raw('revision + 1'), 'updated_by' => $actor->id, 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'modules', $moduleId, 'published', ['version_id' => (int) $version->id, 'number' => (int) $version->number,
                'affected_records' => $impact['record_count'], 'record_snapshot' => $impact['record_snapshot'], 'definition_hash' => hash('sha256', $version->definition)]);
        }, 3);
    }

    private function impact(int $moduleId): array
    {
        $module = DB::table('modules')->where('id', $moduleId)->first();
        abort_if($module === null, 404);
        $draft = DB::table('module_versions')->where('module_id', $moduleId)->where('status', 'draft')->where('draft_slot', 1)->first();
        if ($draft === null) {
            throw new DefinitionConflict;
        }
        $definition = json_decode($draft->definition, true, 64, JSON_THROW_ON_ERROR);
        $fields = app(ModuleValueValidator::class)->checkedFields($moduleId, $definition);
        $blocked = [];
        if ($fields === [] || ! collect($fields)->contains('show_in_form', true) || ! collect($fields)->contains('show_in_table', true)) {
            $blocked[] = 'En az bir form alanı ve bir tablo sütunu olmalı.';
        }
        $listIds = array_column($fields, 'option_list_id');
        $lists = DB::table('option_lists')->whereIn('id', $listIds)->where('is_active', true)->pluck('id')->all();
        $withItems = DB::table('option_items')->whereIn('option_list_id', $listIds)->where('is_active', true)->distinct()->pluck('option_list_id')->all();
        foreach ($fields as $field) {
            if (isset($field['option_list_id']) && (! in_array($field['option_list_id'], $lists) || ($field['required'] && ! in_array($field['option_list_id'], $withItems)))) {
                $blocked[] = $field['label'].': etkin seçenek listesi ve zorunlu alan için etkin seçenek gerekli.';
            }
        }
        $signature = $this->signature($definition);
        $usedVersions = DB::table('module_records')->where('module_id', $moduleId)->distinct()->pluck('version_id');
        foreach (DB::table('module_versions')->whereIn('id', $usedVersions)->get(['definition']) as $used) {
            if ($signature !== $this->signature(json_decode($used->definition, true, 64, JSON_THROW_ON_ERROR))) {
                $blocked[] = 'Kayıt bulunan modülde alan ekleme/kaldırma, tür, kaynak veya zorunluluk değişikliği için önce açık veri taşıma işlemi gerekir.';
                break;
            }
        }
        if (DB::table('portal_pages')->where('code', 'module_'.$moduleId)->where(function ($query) use ($moduleId): void {
            $query->whereNull('module_id')->orWhere('module_id', '!=', $moduleId);
        })->exists()) {
            $blocked[] = 'Modülün sabit sayfa kodu başka bir sayfada kullanılıyor.';
        }
        $hash = hash_init('sha256');
        $count = 0;
        foreach (DB::table('module_records')->where('module_id', $moduleId)->orderBy('id')->select(['id', 'revision', 'version_id'])->cursor() as $row) {
            hash_update($hash, $row->id.':'.$row->revision.':'.$row->version_id."\n");
            $count++;
        }

        return ['revision' => (int) $module->revision, 'version_id' => (int) $draft->id, 'version_revision' => (int) $draft->revision,
            'record_count' => $count, 'record_snapshot' => hash_final($hash), 'blocked' => $blocked];
    }

    private function signature(array $definition): array
    {
        $signature = ['record_mode' => $definition['record_mode']];
        foreach ($definition['fields'] as $field) {
            $signature[$field['id']] = [$field['type'], $field['required'], $field['option_list_id'] ?? null];
        }
        ksort($signature, SORT_NUMERIC);

        return $signature;
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
