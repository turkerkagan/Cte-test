<?php

namespace Tests\Feature;

use App\Services\Imports\ImportConflict;
use App\Services\Imports\ImportTargetGuard;
use App\Services\Imports\OrganizationImporter;
use App\Services\Imports\OrganizationSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\OrganizationSnapshotFixture;
use Tests\TestCase;

class OrganizationImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_import_preserves_ids_links_hash_and_security_state(): void
    {
        $input = OrganizationSnapshotFixture::make();
        $snapshot = OrganizationSnapshot::fromArray($input);
        $importer = app(OrganizationImporter::class);
        $this->assertSame(['inserted' => 3, 'unchanged' => 0], $importer->apply($snapshot));
        $this->assertSame(['inserted' => 0, 'unchanged' => 3], $importer->apply($snapshot));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('import_mappings', 3);
        $this->assertDatabaseHas('users', [
            'id' => 11, 'department_id' => 7, 'job_title_id' => 9, 'registry_number' => '000012',
            'password' => $input['kullanicilar'][0]['sifre'], 'is_system_admin' => 1,
            'must_change_password' => 1, 'session_version' => 3, 'failed_login_attempts' => 2,
            'locked_at' => '2026-10-03 12:00:00', 'created_at' => '2020-02-29 08:09:10', 'updated_at' => null,
        ]);
        $this->assertDatabaseHas('import_mappings', ['source_table' => 'kullanicilar', 'source_id' => 11, 'target_id' => 11]);
    }

    public function test_a_late_constraint_failure_rolls_back_earlier_tables_and_mappings(): void
    {
        $input = OrganizationSnapshotFixture::make();
        DB::table('users')->insert(['id' => 50, 'username' => $input['kullanicilar'][0]['kullanici_adi'], 'password' => $input['kullanicilar'][0]['sifre']]);
        try {
            app(OrganizationImporter::class)->apply(OrganizationSnapshot::fromArray($input));
            $this->fail('Unique kullanıcı çakışması reddedilmeliydi.');
        } catch (QueryException $error) {
            $this->assertDatabaseCount('departments', 0);
            $this->assertDatabaseCount('job_titles', 0);
            $this->assertDatabaseCount('import_mappings', 0);
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_changed_target_is_not_overwritten(): void
    {
        $snapshot = OrganizationSnapshot::fromArray(OrganizationSnapshotFixture::make());
        app(OrganizationImporter::class)->apply($snapshot);
        DB::table('users')->where('id', 11)->update(['full_name' => 'YENİ SİSTEMDE DÜZENLENEN AD', 'revision' => 2]);
        try {
            app(OrganizationImporter::class)->apply($snapshot);
            $this->fail('Hedef değişikliği reddedilmeliydi.');
        } catch (ImportConflict $error) {
            $this->assertDatabaseHas('users', ['id' => 11, 'full_name' => 'YENİ SİSTEMDE DÜZENLENEN AD', 'revision' => 2]);
            $this->assertDatabaseCount('import_mappings', 3);
        }
    }

    public function test_changed_source_is_not_applied_silently(): void
    {
        $input = OrganizationSnapshotFixture::make();
        app(OrganizationImporter::class)->apply(OrganizationSnapshot::fromArray($input));
        $input['birimler'][0]['ad'] = 'KAYNAKTA DEĞİŞEN BİRİM';
        $this->expectException(ImportConflict::class);
        app(OrganizationImporter::class)->apply(OrganizationSnapshot::fromArray($input));
    }

    public function test_missing_relation_and_plain_password_are_rejected_before_writing(): void
    {
        foreach (['relation', 'password'] as $scenario) {
            $input = OrganizationSnapshotFixture::make();
            $input['kullanicilar'][0][$scenario === 'relation' ? 'birim_id' : 'sifre'] = $scenario === 'relation' ? 999 : 'plain-text';
            try {
                OrganizationSnapshot::fromArray($input);
                $this->fail('Hatalı snapshot reddedilmeliydi.');
            } catch (ValidationException $error) {
                $this->assertDatabaseCount('users', 0);
                $this->assertDatabaseCount('departments', 0);
            }
        }
    }

    public function test_dry_run_has_no_database_access_and_prints_no_personal_fields(): void
    {
        $root = storage_path('app/private/imports');
        File::ensureDirectoryExists($root);
        $path = $root.'/test-'.bin2hex(random_bytes(6)).'.json';
        $input = OrganizationSnapshotFixture::make();
        File::put($path, json_encode($input, JSON_THROW_ON_ERROR));
        try {
            config(['database.default' => 'unconfigured']);
            $this->artisan('portal:import-organization', ['file' => $path])
                ->expectsOutputToContain('veritabanına bağlanılmadı')
                ->doesntExpectOutputToContain($input['kullanicilar'][0]['sifre'])
                ->doesntExpectOutputToContain($input['kullanicilar'][0]['ad_soyad'])
                ->assertExitCode(0);
        } finally {
            File::delete($path);
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_legacy_database_and_url_override_are_rejected_before_connection(): void
    {
        foreach ([['database' => 'cte', 'prefix' => 'cte_next_', 'url' => null], ['database' => 'cte_test_import', 'url' => 'mysql://invalid.invalid/cte']] as $settings) {
            config(['database.connections.unsafe' => $settings + ['driver' => 'mysql']]);
            try {
                app(ImportTargetGuard::class)->assertSafe('unsafe');
                $this->fail('Güvensiz hedef reddedilmeliydi.');
            } catch (RuntimeException $error) {
                $this->assertDatabaseCount('users', 0);
            }
        }
    }

    public function test_cte_requires_unprefixed_tables_and_rejects_destructive_commands(): void
    {
        config(['database.connections.shared_cte' => ['driver' => 'mysql', 'database' => 'cte', 'prefix' => '', 'url' => null]]);
        $guard = app(ImportTargetGuard::class);
        $guard->assertSafe('shared_cte');
        $guard->assertCommandSafe('migrate', 'shared_cte');
        foreach (['migrate:fresh', 'db:wipe'] as $command) {
            try {
                $guard->assertCommandSafe($command, 'shared_cte');
                $this->fail('Shared cte must reject whole-database deletion.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Paylaşılan cte', $error->getMessage());
            }
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_organization_with_assigned_staff_cannot_be_deleted_directly(): void
    {
        app(OrganizationImporter::class)->apply(OrganizationSnapshot::fromArray(OrganizationSnapshotFixture::make()));
        $this->expectException(QueryException::class);
        DB::table('departments')->where('id', 7)->delete();
    }

    public function test_snapshot_outside_private_imports_is_rejected(): void
    {
        $this->artisan('portal:import-organization', ['file' => base_path('composer.json')])
            ->expectsOutputToContain('private/imports')
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_unknown_columns_duplicate_ids_and_invalid_calendar_dates_are_rejected(): void
    {
        foreach (['column', 'duplicate', 'date'] as $scenario) {
            $input = OrganizationSnapshotFixture::make();
            if ($scenario === 'column') {
                $input['kullanicilar'][0]['unknown_column'] = 'ignored';
            } elseif ($scenario === 'duplicate') {
                $input['birimler'][] = $input['birimler'][0];
            } else {
                $input['kullanicilar'][0]['kilitli_baslangic'] = '2026-02-30 12:00:00';
            }
            try {
                OrganizationSnapshot::fromArray($input);
                $this->fail('Geçersiz kayıt kabul edilmemeliydi.');
            } catch (ValidationException $error) {
                $this->assertDatabaseCount('users', 0);
            }
        }
    }
}
