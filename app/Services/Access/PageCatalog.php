<?php

namespace App\Services\Access;

use App\Models\User;
use App\Services\Administration\FieldDefinitions;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PageCatalog
{
    public const SENSITIVE_PAGES = ['hassas_ozel_not' => 'Özel Not', 'hassas_risk' => 'Risk Bilgileri'];

    public function save(User $actor, array $input, ?int $id = null): void
    {
        Gate::forUser($actor)->authorize('manage-page-access');
        if (($id !== null && DB::table('portal_pages')->where('id', $id)->whereNotNull('module_id')->exists()) || (is_string($input['code'] ?? null) && preg_match('/^module_[0-9]+$/D', $input['code']))) {
            throw ValidationException::withMessages(['code' => 'Modül sayfasının adı ve bağlantısı Sayfa düzenlemesi üzerinden yönetilir.']);
        }
        $data = Validator::make($input, [
            'code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('portal_pages')->ignore($id)],
            'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'], 'data_table' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/D', 'max:100'],
            'legacy_file' => ['nullable', 'string', 'max:150'], 'path' => ['nullable', 'string', 'max:200'],
            'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'],
        ])->validate();
        if (! empty($data['data_table'])) {
            if (! isset(app(FieldDefinitions::class)->institutionDefaults()[$data['code']])) {
                throw ValidationException::withMessages(['data_table' => 'Fiziksel tablo eşlemesi kurum kayıt sayfalarında kullanılabilir.']);
            }
            $needed = ['id', 'kind', 'values', 'natural_key', 'event_on', 'position', 'is_active', 'revision', 'created_by', 'created_at', 'updated_at'];
            if (! Schema::hasTable($data['data_table']) || array_diff($needed, Schema::getColumnListing($data['data_table']))) {
                throw ValidationException::withMessages(['data_table' => 'Kurum sayfası kayıt yapısına sahip tablo seçin.']);
            }
        }
        $data['legacy_file'] = ($data['legacy_file'] ?? '') ?: null;
        $data['path'] = ($data['path'] ?? '') ?: null;
        $sensitive = isset(self::SENSITIVE_PAGES[$data['code']]);
        if (($data['legacy_file'] !== null && $data['path'] !== null) || (! $sensitive && $data['legacy_file'] === null && $data['path'] === null)) {
            throw ValidationException::withMessages(['path' => 'PHP dosyası veya yeni uygulama yolu alanlarından yalnız birini doldurun.']);
        }
        if ($data['legacy_file'] !== null && ! $this->validLegacyFile($data['legacy_file'])) {
            throw ValidationException::withMessages(['legacy_file' => 'Yeni uygulamada karşılığı tanımlanmış bir eski sayfa seçin.']);
        }
        if ($data['path'] !== null && ! $this->validPath($data['path'])) {
            throw ValidationException::withMessages(['path' => 'Uygulamada mevcut bir sabit GET sayfasının yolunu girin.']);
        }
        DB::transaction(function () use ($actor, $data, $id): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $page = $id === null ? null : DB::table('portal_pages')->where('id', $id)->lockForUpdate()->first();
            abort_if($id !== null && $page === null, 404);
            $current = $this->actor($actor);
            if ($page !== null) {
                if ($page->module_id !== null) {
                    throw ValidationException::withMessages(['code' => 'Modül bağlantısı bu ekrandan değiştirilemez.']);
                }
                $this->revision($page, (int) $data['revision']);
                if ($page->code !== $data['code']) {
                    throw ValidationException::withMessages(['code' => 'Modül kodu bağlantıları korumak için değiştirilemez.']);
                }
            }
            $values = array_intersect_key($data, array_flip(['code', 'name', 'legacy_file', 'path', 'description', 'data_table']));
            if ($page !== null && collect($values)->every(fn ($value, $key) => $value === $page->$key)) {
                return;
            }
            if ($page === null) {
                $id = DB::table('portal_pages')->insertGetId($values + ['is_active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('portal_pages')->where('id', $id)->update($values + ['revision' => $page->revision + 1, 'updated_at' => now()]);
            }
            app(AuditLog::class)->record($current, 'page_catalog', $id, $page === null ? 'created' : 'updated', ['before' => $page, 'after' => $values]);
        }, 3);
    }

    public function setActive(User $actor, int $id, array $input, bool $active): void
    {
        Gate::forUser($actor)->authorize('manage-page-access');
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted'],
            'permission_count' => ['required', 'integer', 'min:0']])->validate();
        DB::transaction(function () use ($actor, $id, $data, $active): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $page = DB::table('portal_pages')->where('id', $id)->lockForUpdate()->first();
            abort_if($page === null, 404);
            $current = $this->actor($actor);
            $this->revision($page, (int) $data['revision']);
            if (! $active && $page->code === 'hukumlu_kayit') {
                throw ValidationException::withMessages(['operation' => 'Sabit ana sayfa kaldırılmaz.']);
            }
            $count = DB::table('page_permissions')->where('page_id', $id)->count();
            if ($count !== (int) $data['permission_count']) {
                throw ValidationException::withMessages(['permission_count' => 'Yetki kayıtları değişti. Etkiyi yeniden inceleyin.']);
            }
            if ((bool) $page->is_active === $active) {
                return;
            }
            if (! $active) {
                DB::table('page_permissions')->where('page_id', $id)->delete();
            }
            DB::table('portal_pages')->where('id', $id)->update(['is_active' => $active, 'revision' => $page->revision + 1, 'updated_at' => now()]);
            app(AuditLog::class)->record($current, 'page_catalog', $id, $active ? 'restored' : 'archived', ['removed_permissions' => $active ? 0 : $count]);
        }, 3);
    }

    public function destination(object $page): ?string
    {
        if (! $page->is_active || isset(self::SENSITIVE_PAGES[$page->code ?? ''])) {
            return null;
        }
        if (($page->module_id ?? null) !== null) {
            $module = DB::table('modules')->where('id', $page->module_id)->first(['code']);

            return $module === null || ! DB::table('module_versions')->where('module_id', $page->module_id)->where('status', 'published')->whereNull('draft_slot')->exists()
                ? null : route('module-runtime.show', $module->code);
        }
        if ($page->path !== null && $this->validPath($page->path)) {
            return rtrim(config('app.url'), '/').$page->path;
        }
        if ($page->legacy_file !== null && $this->validLegacyFile($page->legacy_file)) {
            $aliases = json_decode(file_get_contents(resource_path('defaults/legacy-route-map.json')), true, 64, JSON_THROW_ON_ERROR);
            if (isset($aliases[$page->legacy_file])) {
                return rtrim(config('app.url'), '/').route($aliases[$page->legacy_file]['route'], [], false);
            }

            return $page->legacy_file === 'index.php' ? rtrim(config('app.url'), '/').route('portal', [], false) : null;
        }

        return null;
    }

    private function validLegacyFile(string $file): bool
    {
        // The allowlist is owned by the new portal; no dependency on retired files.
        $aliases = json_decode(file_get_contents(resource_path('defaults/legacy-route-map.json')), true, 64, JSON_THROW_ON_ERROR);

        return $file === 'index.php' || isset($aliases[$file]);
    }

    private function validPath(string $path): bool
    {
        if (! preg_match('~^/[a-zA-Z0-9_/-]*$~D', $path) || str_contains($path, '//')) {
            return false;
        }
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && '/'.ltrim($route->uri(), '/') === $path && ! str_contains($route->uri(), '{')) {
                return true;
            }
        }

        return false;
    }

    private function actor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        abort_if($current === null, 403);
        Gate::forUser($current)->authorize('manage-page-access');

        return $current;
    }

    private function revision(object $page, int $revision): void
    {
        if ((int) $page->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Sayfa başka bir işlemde değişti. Listeyi yenileyin.']);
        }
    }
}
