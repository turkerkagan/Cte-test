<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class PreflightService
{
    public function run(): array
    {
        $checks = [
            'env_example_exists' => File::exists(base_path('.env.example')),
            'env_example_debug_false' => str_contains(File::exists(base_path('.env.example')) ? File::get(base_path('.env.example')) : '', 'APP_DEBUG=false'),
            'env_example_no_admin_password' => ! preg_match('/CTE_INITIAL_ADMIN_PASSWORD=\S+/', File::exists(base_path('.env.example')) ? File::get(base_path('.env.example')) : ''),
            'public_storage_not_required' => config('filesystems.default') !== 'public',
            'local_disk_available' => Storage::disk('local') !== null,
            'private_storage_directory_ready' => is_dir(Storage::disk('local')->path('')) && is_writable(Storage::disk('local')->path('')),
            'zip_extension_available' => class_exists(\ZipArchive::class),
            'php_version_supported' => version_compare(PHP_VERSION, '8.4.0', '>='),
            'docs_quality_exists' => File::exists(base_path('docs/phase9-quality.md')),
            'docs_production_runbook_exists' => File::exists(base_path('docs/production-runbook.md')),
            'docs_release_exists' => File::exists(base_path('docs/release-v1.md')),
        ];

        return [
            'passed' => collect($checks)->every(fn ($value) => $value === true),
            'checks' => $checks,
            'failed' => collect($checks)->filter(fn ($value) => $value !== true)->keys()->values()->all(),
        ];
    }
}
