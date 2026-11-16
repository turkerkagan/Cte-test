<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Request-local calculation; permissions are never cached across requests. */
final class PageAccess
{
    /** UI projections may share a matrix for one response; authorization always calls matrix() afresh. */
    public function displayMatrix(User $user): array
    {
        $request = request();
        $cache = $request->attributes->get('portal.display_access');
        if (! is_array($cache)) {
            return $this->matrix($user);
        }
        if (! array_key_exists($user->id, $cache)) {
            $cache[$user->id] = $this->matrix($user);
            $request->attributes->set('portal.display_access', $cache);
        }

        return $cache[$user->id];
    }

    public function matrix(User $user): array
    {
        $current = User::find($user->id);
        $pages = DB::table('portal_pages')->where('is_active', true)->orderBy('name')->orderBy('id')->get();
        $usable = $current !== null && $current->is_active && ! $current->must_change_password;
        $targets = [];
        if ($usable) {
            if (! $current->is_guest) {
                $targets['kisi'] = (int) $current->id;
            }
            foreach (['birim' => ['departments', 'department_id'], 'unvan' => ['job_titles', 'job_title_id']] as $type => [$table, $key]) {
                if ($current->$key && DB::table($table)->where('id', $current->$key)->where('is_active', true)->exists()) {
                    $targets[$type] = (int) $current->$key;
                }
            }
        }
        $rules = $usable && ! $current->is_system_admin
            ? DB::table('page_permissions')->whereIn('page_id', $pages->pluck('id'))->where(function ($query) use ($targets, $current): void {
                $query->where(function ($everyone) use ($current): void {
                    $everyone->where('target_type', $current->is_guest ? 'misafir' : 'herkes')->where('target_id', 0)->whereNull('department_id')->whereNull('job_title_id')->whereNull('user_id');
                });
                foreach ($targets as $type => $id) {
                    $foreignKey = match ($type) {
                        'birim' => 'department_id', 'unvan' => 'job_title_id', 'kisi' => 'user_id'
                    };
                    $query->orWhere(function ($target) use ($type, $id, $foreignKey): void {
                        $target->where('target_type', $type)->where('target_id', $id)->where($foreignKey, $id);
                        foreach (['department_id', 'job_title_id', 'user_id'] as $key) {
                            if ($key !== $foreignKey) {
                                $target->whereNull($key);
                            }
                        }
                    });
                }
            })->get()->groupBy('page_id') : collect();

        $matrix = [];
        foreach ($pages as $page) {
            $sources = [];
            $read = $usable && ($current->is_system_admin || (! $current->is_guest && $page->code === 'hukumlu_kayit'));
            $write = $usable && $current->is_system_admin;
            if ($usable && $current->is_system_admin) {
                $sources[] = ['type' => 'system_admin', 'permission' => 'yazma'];
            } elseif ($read) {
                $sources[] = ['type' => 'homepage', 'permission' => 'giris'];
            }
            foreach ($rules->get($page->id, collect()) as $rule) {
                if ($rule->permission === 'giris' || ($rule->permission === 'yazma' && $rule->target_type !== 'herkes')) {
                    $read = true;
                    $write = $write || $rule->permission === 'yazma';
                    $sources[] = ['type' => $rule->target_type, 'target_id' => (int) $rule->target_id, 'permission' => $rule->permission];
                }
            }
            if ($usable && ! $current->is_system_admin && array_key_exists($page->code, PageCatalog::SENSITIVE_PAGES)) {
                $override = $rules->get($page->id, collect())->firstWhere('target_type', 'kisi');
                if ($override && in_array($override->permission, ['yok', 'giris', 'yazma'], true)) {
                    $read = $override->permission !== 'yok';
                    $write = $override->permission === 'yazma';
                    $sources = [['type' => 'kisi', 'target_id' => (int) $current->id, 'permission' => $override->permission]];
                }
            }
            if ($current?->is_guest) {
                $write = false;
            }
            $matrix[$page->code] = ['page_id' => (int) $page->id, 'name' => $page->name, 'giris' => $read, 'yazma' => $write, 'sources' => $sources];
        }

        return $matrix;
    }

    public function allows(User $user, string $code, string $permission = 'giris'): bool
    {
        if (! in_array($permission, ['giris', 'yazma'], true)) {
            return false;
        }
        $matrix = $this->matrix($user);
        if (isset($matrix[$code])) {
            return $matrix[$code][$permission];
        }
        // Preserve the fixed homepage read contract before its module is registered.
        $current = User::find($user->id);

        return $code === 'hukumlu_kayit' && $permission === 'giris' && $current !== null && $current->is_active && ! $current->is_guest && ! $current->must_change_password;
    }
}
