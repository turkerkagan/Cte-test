<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administration\ManagementSettings;
use App\Services\Administration\SqlDump;
use App\Services\Administration\SqlExports;
use App\Services\Institution\InstitutionRecords;
use App\Services\Security\InitialAdministrator;
use App\Services\Security\MasterAdministrator;
use App\Services\Security\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MaintenanceImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private string $privateStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateStorage = sys_get_temp_dir().'/cte-maintenance-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->privateStorage);
        File::makeDirectory($this->privateStorage.'/framework/views', 0700, true);
        config(['view.compiled' => $this->privateStorage.'/framework/views']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->privateStorage);
        parent::tearDown();
    }

    private function login(bool $admin = true): User
    {
        $actor = User::factory()->create(['is_system_admin' => $admin])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => $actor->session_version]);

        return $actor;
    }

    public function test_phone_archive_restore_preserves_values_and_rejects_stale_requests(): void
    {
        $this->login();
        $id = $this->postJson('/yonetim/telefon', ['values' => ['name' => 'SENTETİK', 'phone' => '00123', 'position' => 0]])->assertOk()->json('id');
        $url = '/yonetim/telefon/'.$id;
        $this->postJson($url, ['action' => 'archive', 'revision' => 1])->assertOk();
        $this->assertDatabaseHas('institution_records', ['id' => $id, 'is_active' => false, 'revision' => 2]);
        $this->getJson('/yonetim/telefon?state=archived')->assertOk()->assertJsonPath('rows.0.values.phone', '00123');
        $this->getJson('/kurum/telefon/rehber')->assertOk()->assertJsonCount(0, 'rows');
        $this->postJson($url, ['action' => 'restore', 'revision' => 1])->assertConflict();
        $this->postJson($url, ['action' => 'restore', 'revision' => 2])->assertOk();
        $this->assertDatabaseHas('institution_records', ['id' => $id, 'is_active' => true, 'revision' => 3]);
        $this->assertDatabaseHas('audit_events', ['resource_type' => 'institution_telefon', 'resource_id' => $id, 'action' => 'archived']);
        $this->assertDatabaseHas('audit_events', ['resource_type' => 'institution_telefon', 'resource_id' => $id, 'action' => 'restored']);
        $this->login(false);
        $this->postJson($url, ['action' => 'restore', 'revision' => 3])->assertForbidden();
        $this->getJson('/yonetim/telefon?state=archived')->assertForbidden();
    }

    public function test_master_identity_survives_rename_but_aliases_are_ordinary_administrators(): void
    {
        $this->login();
        $master = User::factory()->create(['username' => 'türker', 'is_system_admin' => true, 'is_master_admin' => true])->refresh();
        $this->assertTrue($master->is_master_admin);
        DB::table('users')->where('id', $master->id)->update(['username' => 'renamed-master']);
        $this->deleteJson('/yonetim/kullanicilar/'.$master->id, ['revision' => 1, 'confirmed' => true, 'confirmation' => 'KULLANICIYI SİL'])->assertUnprocessable();
        $this->postJson('/yonetim/kullanicilar/'.$master->id.'/parola-sifirla', ['revision' => 1, 'confirmed' => true, 'password' => 'SyntheticPass!2026', 'password_confirmation' => 'SyntheticPass!2026'])->assertUnprocessable();
        $this->postJson('/yonetim/kullanicilar/'.$master->id.'/oturumlari-sonlandir', ['revision' => 1, 'confirmed' => true])->assertOk();
        foreach (['admin', 'turker'] as $name) {
            $user = User::factory()->create(['username' => $name, 'is_system_admin' => true])->refresh();
            $this->assertFalse($user->is_master_admin);
            $this->deleteJson('/yonetim/kullanicilar/'.$user->id, ['revision' => 1, 'confirmed' => true, 'confirmation' => 'KULLANICIYI SİL'])->assertOk();
        }
    }

    public function test_legacy_backup_infers_only_turker_and_explicit_identity_is_preserved(): void
    {
        $rows = MasterAdministrator::restore([
            ['username' => 'türker', 'is_system_admin' => true],
            ['username' => 'admin', 'is_system_admin' => true],
            ['username' => 'turker', 'is_system_admin' => true],
            ['username' => 'renamed', 'is_system_admin' => true, 'is_master_admin' => true],
            ['username' => 'türker', 'is_system_admin' => false],
        ]);
        $this->assertSame([true, false, false, true, false], array_column($rows, 'is_master_admin'));
    }

    public function test_panel_cannot_assign_master_identity_even_by_creating_a_turker_administrator(): void
    {
        $this->login();
        $id = $this->postJson('/yonetim/kullanicilar', [
            'username' => 'türker', 'full_name' => 'SENTETİK YÖNETİCİ',
            'level' => 'system_admin', 'is_active' => true, 'is_master_admin' => true,
            'password' => 'SyntheticPass!2026', 'password_confirmation' => 'SyntheticPass!2026',
        ])->assertOk()->json('id');
        $user = User::findOrFail($id);
        $this->assertFalse($user->is_master_admin);
        $this->deleteJson('/yonetim/kullanicilar/'.$id, ['revision' => 1, 'confirmed' => true, 'confirmation' => 'KULLANICIYI SİL'])->assertOk();
    }

    public function test_initial_installation_marks_the_turker_administrator(): void
    {
        $user = app(InitialAdministrator::class)->create('TÜRkER', 'SyntheticPass!2026');
        $this->assertTrue($user->is_master_admin);
    }

    public function test_identity_migration_marks_existing_turker_only(): void
    {
        $users = [];
        foreach (['TÜRkER', 'turker', 'admin'] as $name) {
            $users[] = User::factory()->create(['username' => $name, 'is_system_admin' => true])->id;
        }
        $migration = require database_path('migrations/2026_10_18_000023_add_master_administrator_identity.php');
        $migration->down();
        $migration->up();
        $this->assertSame([true, false, false], array_map(fn ($id) => User::findOrFail($id)->is_master_admin, $users));
    }

    public function test_password_boundary_counts_utf8_bytes(): void
    {
        $this->assertSame('bcrypt', config('hashing.driver'));
        $this->assertFalse(Validator::make(['password' => str_repeat('ş', 36)], ['password' => PasswordPolicy::rules()])->fails());
        $this->assertTrue(Validator::make(['password' => str_repeat('ş', 37)], ['password' => PasswordPolicy::rules()])->fails());
    }

    public function test_large_export_memory_does_not_scale_with_the_dump(): void
    {
        $read = function (int $rows): array {
            $process = new Process([PHP_BINARY, base_path('tests/Support/SqlExportMemoryProbe.php'), (string) $rows]);
            $process->setTimeout(60);
            $process->mustRun();

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        };
        $small = $read(500);
        $large = $read(15000);
        $this->assertGreaterThan(15_000_000, $large['bytes']);
        $this->assertLessThanOrEqual($small['extraPeak'] + 4 * 1024 * 1024, $large['extraPeak']);
    }

    public function test_failed_batch_rolls_back_audit_and_clears_its_authorization_context(): void
    {
        $actor = $this->login();
        $records = app(InstitutionRecords::class);
        $before = DB::table('institution_records')->count();
        try {
            $records->writeBatch($actor, 'telefon', function () use ($records, $actor) {
                $records->save($actor, 'telefon', null, ['values' => ['name' => 'SENTETİK ROLLBACK', 'phone' => '00123', 'position' => 0]]);
                throw new \RuntimeException('Synthetic batch failure');
            });
            $this->fail('Batch failure was swallowed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic batch failure', $error->getMessage());
        }
        $this->assertSame($before, DB::table('institution_records')->count());
        $this->assertDatabaseMissing('audit_events', ['resource_type' => 'institution_telefon', 'action' => 'created']);
        $staff = $this->login(false);
        $this->expectException(HttpException::class);
        $records->save($staff, 'telefon', null, ['values' => ['name' => 'SENTETİK', 'phone' => '0', 'position' => 0]]);
    }

    public function test_all_literal_inertia_pages_have_a_real_frontend_loader(): void
    {
        $resolver = file_get_contents(resource_path('js/lib/pageResolver.ts'));
        preg_match_all("/([A-Za-z0-9_]+):\\s*\\(\\)\\s*=>\\s*import\\(['\"]([^'\"]+)['\"]\\)/", $resolver, $loaders, PREG_SET_ORDER);
        $names = [];
        foreach ($loaders as $loader) {
            $names[] = $loader[1];
            $this->assertFileExists(resource_path('js/lib/'.$loader[2].'.tsx'));
        }
        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            preg_match_all("/Inertia::render\\(['\"]([^'\"]+)['\"]/", $file->getContents(), $pages);
            foreach ($pages[1] as $page) {
                $this->assertContains($page, $names, $file->getFilename().': '.$page);
            }
        }
        $this->assertContains('Workspace', $names);
    }

    public function test_dynamic_option_labels_are_batched_and_phone_lists_do_not_query_receipts(): void
    {
        $actor = $this->login();
        $list = DB::table('case_option_lists')->insertGetId(['code' => 'synthetic-maintenance', 'name' => 'SENTETİK', 'is_active' => true]);
        $option = DB::table('case_option_items')->insertGetId(['option_list_id' => $list, 'label' => 'SENTETİK SEÇENEK', 'is_active' => true, 'position' => 0]);
        $settings = app(ManagementSettings::class);
        $state = $settings->read('workflow_fields', ['items' => []]);
        $settings->save($actor, 'workflow_fields', ['items' => [['key' => 'telefon__custom_review', 'group' => 'telefon', 'type' => 'option', 'label' => 'İnceleme', 'position' => 1, 'table_visible' => true, 'form_visible' => true, 'required' => false, 'readonly' => false, 'option_list' => 'synthetic-maintenance']]], $state['revision']);
        DB::table('institution_records')->insert(array_fill(0, 40, ['kind' => 'telefon', 'values' => json_encode(['name' => 'SENTETİK', 'phone' => '00123', 'custom_review' => $option], JSON_THROW_ON_ERROR)]));
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $result = app(InstitutionRecords::class)->read($actor, 'telefon');
        $this->assertSame('SENTETİK SEÇENEK', $result['rows'][0]['display']['custom_review']);
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query, 'case_option_items') && str_contains($query, '"id" in')));
        $this->assertCount(0, array_filter($queries, fn ($query) => str_contains($query, 'announcement_reads')));
    }

    public function test_sql_file_reads_in_bounded_chunks_and_round_trips_special_values(): void
    {
        $actor = $this->login();
        DB::table('institution_records')->insert(array_fill(0, 1201, ['kind' => 'telefon', 'values' => '{"name":"SENTETİK İŞIK","phone":"00123"}']));
        $expected = DB::table('institution_records')->count();
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            if (str_contains($event->sql, 'institution_records')) {
                $queries[] = $event->sql;
            }
        });
        $path = app(SqlExports::class)->dataFile($actor, 'institution_records');
        try {
            $sql = file_get_contents($path);
            $this->assertSame($expected, substr_count($sql, 'INSERT INTO `institution_records`'));
            $this->assertStringContainsString('SENTETİK İŞIK', $sql);
            $this->assertGreaterThanOrEqual(3, count(array_filter($queries, fn ($sql) => str_contains($sql, 'limit 500'))));
            $dump = app(SqlDump::class);
            $values = ['id' => 1, 'name' => "İşık ' \\ \n", 'empty' => null, 'phone' => '00123'];
            $this->assertSame($values, $dump->parse($dump->encode(['sample' => [$values]]))['tables']['sample'][0]);
        } finally {
            unlink($path);
        }
    }
}
