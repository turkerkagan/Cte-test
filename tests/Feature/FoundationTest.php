<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_html_uses_built_local_assets_without_a_development_server(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $response = $this->get('/giris');
        $response->assertOk()->assertSee('lang="tr"', false)->assertSee('/build/assets/', false);
        $response->assertDontSee('@vite/client')->assertDontSee('fonts.googleapis.com');
        $response->assertInertia(fn (Assert $page) => $page->component('Login'));
    }

    public function test_inertia_response_contains_no_institutional_records(): void
    {
        $this->get('/giris', ['X-Inertia' => 'true', 'X-Inertia-Version' => hash_file('xxh128', public_path('build/manifest.json'))])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Login')
            ->assertJsonPath('props.applicationName', config('app.name'))
            ->assertJsonMissingPath('props.users')
            ->assertJsonMissingPath('props.prisoners');
    }

    public function test_foundation_and_health_work_without_any_database_connection(): void
    {
        config(['database.default' => 'unconfigured']);
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/up')->assertOk();
    }

    public function test_doctor_rejects_the_legacy_database_without_connecting(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'cte',
            'database.connections.mysql.prefix' => 'cte_next_',
            'database.connections.mysql.password' => 'synthetic-secret-do-not-print',
        ]);
        $this->artisan('portal:doctor')
            ->expectsOutputToContain('EKSİK')
            ->doesntExpectOutputToContain('synthetic-secret-do-not-print')
            ->assertExitCode(1);
    }

    public function test_shared_database_wipe_commands_stop_before_any_connection(): void
    {
        config(['database.connections.shared' => ['driver' => 'mysql', 'database' => 'cte', 'prefix' => 'cte_next_', 'host' => 'invalid-host.invalid']]);
        foreach (['db:wipe', 'migrate:fresh'] as $command) {
            try {
                Artisan::call($command, ['--database' => 'shared', '--force' => true]);
                $this->fail('Shared database wipe was allowed');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('bütün tabloları', $error->getMessage());
            }
        }
    }

    public function test_shared_cte_requires_exact_table_namespace_without_connecting(): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'cte',
            'database.connections.mysql.host' => 'invalid-host.invalid']);
        foreach (['', 'cte_', 'users', 'cte_next_'] as $prefix) {
            config(['database.connections.mysql.prefix' => $prefix]);
            $this->artisan('portal:doctor')->assertExitCode($prefix === '' ? 0 : 1);
        }
    }

    public function test_doctor_accepts_a_separate_environment_without_database_access(): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'cte_test_development',
            'database.connections.mysql.host' => 'invalid-host.invalid',
        ]);
        $this->artisan('portal:doctor')
            ->expectsOutputToContain('Veritabanına bağlanılmadı')
            ->assertExitCode(0);
    }
}
