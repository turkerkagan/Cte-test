<?php

namespace App\Services\Access;

use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class BuiltinPageCatalog
{
    /** Register missing definitions only; existing names, archives and access rules are untouched. */
    public function sync(): int
    {
        $definitions = ['hukumlu_kayit' => ['Ana Sayfa', 'portal']] + WorkflowAccess::PAGES;
        $created = 0;
        foreach ($definitions as $code => [$name, $route]) {
            if (! Route::has($route)) {
                continue;
            }
            $path = '/'.ltrim(app('router')->getRoutes()->getByName($route)->uri(), '/');
            if (str_contains($path, '{')) {
                continue;
            }
            $created += DB::table('portal_pages')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'path' => $path,
                'is_active' => true, 'revision' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (PageCatalog::SENSITIVE_PAGES as $code => $name) {
            $created += DB::table('portal_pages')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'is_active' => true, 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $created;
    }
}
