<?php

namespace Tests\Feature;

use App\Console\Commands\CtePackageCommand;
use App\Console\Commands\CtePreflightCommand;
use App\Services\PreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PhaseNineQualityPackagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_service_reports_expected_quality_checks(): void
    {
        $result = app(PreflightService::class)->run();

        $this->assertArrayHasKey('env_example_exists', $result['checks']);
        $this->assertArrayHasKey('env_example_debug_false', $result['checks']);
        $this->assertArrayHasKey('docs_quality_exists', $result['checks']);
        $this->assertArrayHasKey('docs_production_runbook_exists', $result['checks']);
        $this->assertArrayHasKey('docs_release_exists', $result['checks']);
    }

    public function test_env_example_is_hardened_and_has_no_initial_admin_password(): void
    {
        $env = File::get(base_path('.env.example'));

        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('SESSION_ENCRYPT=true', $env);
        $this->assertDoesNotMatchRegularExpression('/CTE_INITIAL_ADMIN_PASSWORD=\S+/', $env);
    }

    public function test_phase_nine_documentation_exists(): void
    {
        $this->assertFileExists(base_path('docs/phase9-quality.md'));
        $this->assertFileExists(base_path('docs/production-runbook.md'));
        $this->assertFileExists(base_path('docs/release-v1.md'));
        $this->assertStringContainsString('İnternetsiz test', File::get(base_path('docs/phase9-quality.md')));
        $this->assertStringContainsString('Rollback', File::get(base_path('docs/production-runbook.md')));
        $this->assertStringContainsString('v1.0.0', File::get(base_path('docs/release-v1.md')));
    }

    public function test_preflight_command_class_exists(): void
    {
        $this->assertTrue(class_exists(CtePreflightCommand::class));
        $this->assertTrue(class_exists(CtePackageCommand::class));
        $this->assertFileExists(base_path('docs/offline-xampp-package.md'));
    }

    public function test_offline_package_file_list_excludes_secrets_and_runtime_data(): void
    {
        $files = app(CtePackageCommand::class)->buildFileList();

        $this->assertContains('vendor/autoload.php', $files);
        $this->assertContains('public/build/manifest.json', $files);
        $this->assertNotContains('.env', $files);
        $this->assertFalse(collect($files)->contains(fn (string $path) => str_starts_with($path, 'node_modules/') || str_starts_with($path, 'storage/app/private/')));
    }

    public function test_package_information_has_no_source_control_reference(): void
    {
        $source = File::get(base_path('app/Console/Commands/CtePackageCommand.php'));

        $this->assertStringNotContainsString('rev-parse', $source);
        $this->assertStringNotContainsString('Commit:', $source);
        $this->assertStringContainsString('Sürüm: 1.0.0-rc', $source);
        $this->assertStringContainsString("addFile(base_path('.env.example')", $source);
    }
}
