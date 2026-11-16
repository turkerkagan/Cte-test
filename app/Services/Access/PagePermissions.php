<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PagePermissions
{
    /** All selected pages change together, or none of them do. */
    public function apply(User $actor, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-page-access');
        $data = Validator::make($input, [
            'pages' => ['required', 'array', 'min:1', 'max:200'],
            'pages.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'pages.*.revision' => ['required', 'integer', 'min:1'],
            'target_type' => ['required', Rule::in(['herkes', 'misafir', 'birim', 'unvan', 'kisi'])],
            'target_id' => ['required', 'integer', 'min:0'],
            'operation' => ['required', Rule::in(['grant', 'remove'])],
            'permission' => ['required_if:operation,grant', 'nullable', Rule::in(['giris', 'yazma', 'yok'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();
        if ((in_array($data['target_type'], ['herkes', 'misafir'])) !== ((int) $data['target_id'] === 0)) {
            throw ValidationException::withMessages(['target_id' => 'Hedef kimliği hedef türüne uygun olmalı.']);
        }
        if ($data['operation'] === 'grant' && in_array($data['target_type'], ['herkes', 'misafir']) && $data['permission'] === 'yazma') {
            throw ValidationException::withMessages(['permission' => 'Herkese yazma yetkisi verilemez.']);
        }
        DB::transaction(function () use ($actor, $data): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $pages = DB::table('portal_pages')->whereIn('id', array_column($data['pages'], 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($data['pages'] as $selected) {
                $page = $pages->get($selected['id']);
                if ($page === null || ! $page->is_active || (int) $page->revision !== (int) $selected['revision']) {
                    throw ValidationException::withMessages(['pages' => 'Seçili sayfa kaldırılmış veya değişmiş. Listeyi yenileyin.']);
                }
            }
            if ($data['operation'] === 'grant' && $data['permission'] === 'yok' && ($data['target_type'] !== 'kisi' || $pages->contains(fn ($page) => ! array_key_exists($page->code, PageCatalog::SENSITIVE_PAGES)))) {
                throw ValidationException::withMessages(['permission' => 'Erişim yok seçimi yalnız kişiye ait özel not ve risk politikalarında kullanılabilir.']);
            }
            if ($data['target_type'] === 'kisi' && $data['operation'] === 'grant' && ! trim($data['reason'] ?? '')) {
                throw ValidationException::withMessages(['reason' => 'Kişisel erişim gerekçesi zorunludur.']);
            }
            if ($data['target_type'] === 'misafir' && $pages->contains(fn ($p) => str_starts_with($p->code, 'hassas_'))) {
                throw ValidationException::withMessages(['pages' => 'Misafire hassas veri izni verilemez.']);
            }
            $foreign = match ($data['target_type']) {
                'birim' => ['departments', 'department_id'], 'unvan' => ['job_titles', 'job_title_id'],
                'kisi' => ['users', 'user_id'], default => null,
            };
            // Lock definitions before users, matching personnel assignment/transfer order.
            if ($foreign !== null && $foreign[0] !== 'users') {
                $target = DB::table($foreign[0])->where('id', $data['target_id'])->lockForUpdate()->first();
                $this->checkTarget($target, $data['operation']);
            }
            $users = User::whereIn('id', array_unique([$actor->id, ...($data['target_type'] === 'kisi' ? [(int) $data['target_id']] : [])]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $users->get($actor->id);
            abort_if($currentActor === null, 403);
            abort_if($currentActor->session_version !== $actor->session_version, 403);
            Gate::forUser($currentActor)->authorize('manage-page-access');
            if ($data['target_type'] === 'kisi') {
                $this->checkTarget($users->get($data['target_id']), $data['operation']);
            }
            foreach ($pages as $page) {
                $key = ['page_id' => $page->id, 'target_type' => $data['target_type'], 'target_id' => (int) $data['target_id']];
                $existing = DB::table('page_permissions')->where($key)->first();
                $after = $data['operation'] === 'remove' ? null : $data['permission'];
                if (($existing->permission ?? null) === $after && ($existing->reason ?? null) === ($data['reason'] ?? null)) {
                    continue;
                }
                if ($after === null) {
                    DB::table('page_permissions')->where($key)->delete();
                } else {
                    $values = ['reason' => $data['reason'] ?? null, 'permission' => $after, 'department_id' => null, 'job_title_id' => null, 'user_id' => null, 'updated_at' => now()];
                    if ($foreign !== null) {
                        $values[$foreign[1]] = (int) $data['target_id'];
                    }
                    if ($existing === null) {
                        DB::table('page_permissions')->insert($key + $values + ['created_at' => now()]);
                    } else {
                        DB::table('page_permissions')->where('id', $existing->id)->update($values);
                    }
                }
                DB::table('portal_pages')->where('id', $page->id)->update(['revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
                app(AuditLog::class)->record($currentActor, 'page_access', $page->id, 'permission_changed', [
                    'target_type' => $data['target_type'], 'target_id' => (int) $data['target_id'],
                    'before' => $existing->permission ?? null, 'after' => $after,
                    ...(! empty($data['reason']) ? ['reason' => $data['reason']] : []),
                ]);
            }
        }, 3);
    }

    private function checkTarget(?object $target, string $operation): void
    {
        if ($target === null || ($operation === 'grant' && ! $target->is_active)) {
            throw ValidationException::withMessages(['target_id' => 'Yetki vermek için mevcut ve aktif bir hedef seçin.']);
        }
    }
}
