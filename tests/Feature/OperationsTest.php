<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Operations\OperationalState;
use App\Services\Operations\PortalBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cte-ops-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->app->useStoragePath($this->directory.'/storage');
        mkdir(storage_path('app/private'), 0700, true);
        mkdir(storage_path('framework/views'), 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_health_is_admin_only_and_does_not_leak_credentials(): void
    {
        $this->get('/yonetim/saglik')->assertRedirect('/giris');
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0])->get('/yonetim/saglik')->assertForbidden();
        $admin = User::factory()->create(['is_system_admin' => true])->refresh();
        config(['database.connections.sqlite.password' => 'SYNTHETIC_SECRET']);
        $response = $this->actingAs($admin)->withSession(['auth_session_version' => 0])->get('/yonetim/saglik');
        $response->assertOk()->assertInertia(fn (Assert $p) => $p->component('PortalHealth')->where('health.database', true)->where('health.backup', null)->where('health.job', null));
        $response->assertDontSee('SYNTHETIC_SECRET');
    }

    public function test_metrics_keep_route_templates_without_values_and_record_failure(): void
    {
        config(['operations.measure_requests' => true]);
        $admin = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($admin)->withSession(['auth_session_version' => 0])->get('/portal?q=SYNTHETIC_SENSITIVE')->assertOk();
        $state = app(OperationalState::class)->read();
        $this->assertSame(1, $state['routes']['GET portal']['requests']);
        $this->assertGreaterThan(0, $state['routes']['GET portal']['max_queries']);
        $this->assertStringNotContainsString('SYNTHETIC_SENSITIVE', json_encode($state));
        app(OperationalState::class)->result('backup', false);
        $this->assertFalse(app(OperationalState::class)->read()['backup']['success']);
    }

    public function test_permission_and_validation_denials_are_not_server_errors(): void
    {
        config(['operations.measure_requests' => true]);
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0])->get('/yonetim/saglik')->assertForbidden();
        $admin = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($admin)->withSession(['auth_session_version' => 0])->postJson('/yonetim/moduller', [])->assertUnprocessable();
        $state = app(OperationalState::class)->read();
        $this->assertSame(0, array_sum(array_column($state['routes'], 'errors')));
        $this->assertSame(2, array_sum(array_column($state['routes'], 'requests')));
    }

    public function test_backup_requires_maintenance_and_restore_rejects_source_target(): void
    {
        try {
            app(PortalBackup::class)->create($this->directory, 'unreachable');
            $this->fail('Maintenance guard bypassed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bakım', $e->getMessage());
        }
        foreach (['cte', 'cte_next_current', '../escape'] as $target) {
            try {
                app(PortalBackup::class)->rehearse('missing.zip', $target, $this->directory, 'unreachable');
                $this->fail('Source restore accepted');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('prova hedefi', $e->getMessage());
            }
        }
    }

    public function test_corrupted_archives_fail_before_database_access(): void
    {
        foreach (['database.sql', 'files/../../outside'] as $name) {
            $path = $this->directory.'/'.bin2hex(random_bytes(4)).'.zip';
            $z = new ZipArchive;
            $z->open($path, ZipArchive::CREATE);
            $z->addFromString('database.sql', 'SQL');
            $z->addFromString('configuration.env', 'KEY');
            $entries = ['database.sql' => ['sha256' => hash('sha256', 'SQL'), 'bytes' => 3], 'configuration.env' => ['sha256' => hash('sha256', 'KEY'), 'bytes' => 3]];
            $z->addFromString($name, 'tampered');
            $entries[$name] = ['sha256' => str_repeat('0', 64), 'bytes' => 8];
            $z->addFromString('manifest.json', json_encode(['format' => 1, 'prefix' => 'cte_next_', 'tables' => ['cte_next_users' => 1], 'contentHashes' => ['cte_next_users' => str_repeat('0', 64)], 'entries' => $entries]));
            $z->close();
            $this->artisan('portal:verify-backup', ['archive' => $path])->assertExitCode(1);
        }
        $this->assertFileDoesNotExist($this->directory.'/outside');
    }
}
