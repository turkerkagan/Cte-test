<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OrganizationDefinitions
{
    public function definition(string $kind): array
    {
        return match ($kind) {
            'birimler' => ['table' => 'departments', 'foreign_key' => 'department_id', 'title' => 'Kurum birimleri'],
            'unvanlar' => ['table' => 'job_titles', 'foreign_key' => 'job_title_id', 'title' => 'Unvanlar'],
            default => abort(404),
        };
    }

    public function save(User $actor, string $kind, array $input, ?int $id = null): void
    {
        Gate::forUser($actor)->authorize('manage-organization');
        $definition = $this->definition($kind);
        $table = $definition['table'];
        $data = $this->validateInput($input, [
            'code' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN_.-]+$/u', Rule::unique($table, 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($actor, $table, $kind, $data, $id): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $before = $id === null ? null : DB::table($table)->where('id', $id)->lockForUpdate()->first();
            $actor = $this->currentActor($actor);
            if ($id !== null) {
                abort_if($before === null, 404);
                $this->checkRevision($before, (int) $data['revision']);
            }
            $values = ['code' => $data['code'], 'name' => trim($data['name']), 'is_active' => (bool) $data['is_active'],
                'revision' => $before === null ? 1 : $before->revision + 1, 'updated_at' => now()];
            if ($id === null) {
                $id = DB::table($table)->insertGetId($values + ['created_at' => now()]);
            } else {
                DB::table($table)->where('id', $id)->update($values);
            }
            app(AuditLog::class)->record($actor, $kind, $id, $before === null ? 'created' : 'updated', ['before' => $before, 'after' => $values]);
        }, 3);
    }

    public function delete(User $actor, string $kind, int $id, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-organization');
        $definition = $this->definition($kind);
        $data = $this->validateInput($input, [
            'revision' => ['required', 'integer', 'min:1'],
            'assigned_count' => ['required', 'integer', 'min:0'],
            'target_id' => ['nullable', 'integer', 'min:1'],
            'permission_count' => ['required', 'integer', 'min:0'],
            'permission_snapshot' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'target_permission_count' => ['required_with:target_id', 'nullable', 'integer', 'min:0'],
            'target_permission_snapshot' => ['required_with:target_id', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $targetId = isset($data['target_id']) ? (int) $data['target_id'] : null;
        if ($targetId === $id) {
            throw ValidationException::withMessages(['target_id' => 'Aktarım hedefi silinen tanım olamaz.']);
        }
        DB::transaction(function () use ($actor, $definition, $kind, $id, $data, $targetId): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $locked = DB::table($definition['table'])->whereIn('id', array_filter([$id, $targetId]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $locked->get($id);
            abort_if($source === null, 404);
            $this->checkRevision($source, (int) $data['revision']);
            $target = $targetId === null ? null : $locked->get($targetId);
            if ($targetId !== null && ($target === null || ! $target->is_active)) {
                throw ValidationException::withMessages(['target_id' => 'Aktif ve mevcut bir aktarım hedefi seçin.']);
            }
            $impact = $this->permissionImpact($kind, $id);
            $this->checkImpact($impact, (int) $data['permission_count'], $data['permission_snapshot'], 'permission_snapshot');
            $targetImpact = $targetId === null ? null : $this->permissionImpact($kind, $targetId);
            if ($targetImpact !== null) {
                $this->checkImpact($targetImpact, (int) $data['target_permission_count'], $data['target_permission_snapshot'], 'target_permission_snapshot');
            }
            $users = DB::table('users')->where($definition['foreign_key'], $id)->orderBy('id')->lockForUpdate()->get(['id']);
            $actor = $this->currentActor($actor);
            if ($users->count() !== (int) $data['assigned_count']) {
                throw ValidationException::withMessages(['assigned_count' => 'Personel atamaları değişti. Sayfayı yenileyip etkiyi tekrar inceleyin.']);
            }
            if ($users->isNotEmpty() && $target === null) {
                throw ValidationException::withMessages(['target_id' => 'Atanmış personeli korumak için aktarım hedefi seçin.']);
            }
            if ($users->isNotEmpty()) {
                DB::table('users')->whereIn('id', $users->pluck('id'))->update([
                    $definition['foreign_key'] => $targetId,
                    'revision' => DB::raw('revision + 1'), 'session_version' => DB::raw('session_version + 1'), 'updated_at' => now(),
                ]);
            }
            DB::table($definition['table'])->where('id', $id)->delete();
            DB::table('portal_pages')->whereIn('id', $impact['page_ids'])->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, $kind, $id, 'deleted', ['before' => $source, 'target_id' => $targetId, 'transferred_users' => $users->count(),
                'removed_permissions' => $impact['count'], 'affected_page_ids' => $impact['page_ids'], 'target_permissions' => $targetImpact['count'] ?? 0]);
        }, 3);
    }

    public function permissionImpact(string $kind, int $id): array
    {
        $key = $this->definition($kind)['foreign_key'];

        return $this->summarizePermissions(DB::table('page_permissions')->where($key, $id)->orderBy('id')->get(['id', 'page_id', 'permission']));
    }

    public function permissionImpacts(string $kind): array
    {
        $key = $this->definition($kind)['foreign_key'];

        return DB::table('page_permissions')->whereNotNull($key)->orderBy('id')->get(['id', 'page_id', 'permission', $key])
            ->groupBy($key)->map(fn ($rules) => $this->summarizePermissions($rules))->all();
    }

    public function emptyPermissionImpact(): array
    {
        return $this->summarizePermissions(collect());
    }

    private function summarizePermissions(Collection $rules): array
    {
        $canonical = $rules->map(fn ($rule) => [(int) $rule->id, (int) $rule->page_id, $rule->permission])->values()->all();

        return ['count' => count($canonical), 'snapshot' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)),
            'page_ids' => $rules->pluck('page_id')->map(fn ($id) => (int) $id)->unique()->values()->all()];
    }

    private function checkImpact(array $impact, int $count, string $snapshot, string $field): void
    {
        if ($impact['count'] !== $count || ! hash_equals($impact['snapshot'], $snapshot)) {
            throw ValidationException::withMessages([$field => 'Sayfa izinleri değişti. Silme ve aktarım etkisini yeniden inceleyin.']);
        }
    }

    private function currentActor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        abort_if($current === null, 403);
        Gate::forUser($current)->authorize('manage-organization');

        return $current;
    }

    private function validateInput(array $input, array $rules): array
    {
        return Validator::make($input, $rules, [
            'required' => ':attribute alanı zorunlu.', 'string' => ':attribute metin olmalı.',
            'max' => ':attribute en fazla :max karakter olabilir.', 'min' => ':attribute en az :min olmalı.',
            'integer' => ':attribute geçerli bir tam sayı olmalı.', 'boolean' => 'Aktif durumu geçersiz.',
            'unique' => 'Bu kod zaten kullanılıyor.', 'regex' => 'Kod yalnız harf, rakam, nokta, tire ve alt çizgi içerebilir.',
            'required_with' => ':attribute alanı hedef seçildiğinde zorunlu.',
        ], ['code' => 'Kod', 'name' => 'Ad', 'is_active' => 'Aktif', 'revision' => 'Sürüm',
            'assigned_count' => 'Personel sayısı', 'target_id' => 'Aktarım hedefi',
            'permission_count' => 'Silinecek izin sayısı', 'permission_snapshot' => 'İzin önizlemesi',
            'target_permission_count' => 'Hedef izin sayısı', 'target_permission_snapshot' => 'Hedef izin önizlemesi'])->validate();
    }

    private function checkRevision(object $row, int $revision): void
    {
        if ((int) $row->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Tanım başka bir işlemde değişti. Sayfayı yenileyin.']);
        }
    }
}
