<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\LegacyTransferRun;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\LegacyTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseEightAuditBackupLegacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_service_redacts_personal_and_secret_metadata(): void
    {
        $user = $this->adminUser();
        $this->actingAs($user);
        $event = app(AuditService::class)->record('inmate.updated', null, ['identity_number' => '12345678901', 'full_name' => 'Gizli Kişi', 'safe' => 'ok', 'nested' => ['password' => 'secret']]);

        $this->assertDatabaseHas('audit_events', ['id' => $event->id, 'event_type' => 'inmate.updated']);
        $this->assertSame('[redacted]', $event->metadata['identity_number']);
        $this->assertSame('[redacted]', $event->metadata['full_name']);
        $this->assertSame('[redacted]', $event->metadata['nested']['password']);
        $this->assertSame('ok', $event->metadata['safe']);
    }

    public function test_audit_events_cascade_when_inmate_is_force_deleted(): void
    {
        $inmate = $this->inmateFile();
        AuditEvent::create(['inmate_file_id' => $inmate->id, 'event_type' => 'inmate.viewed', 'metadata' => ['safe' => 'ok']]);

        $inmate->forceDelete();

        $this->assertDatabaseMissing('audit_events', ['event_type' => 'inmate.viewed']);
    }

    public function test_backup_service_creates_manifest_archive_without_raw_private_paths(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('inmate-private-files/1/photo.jpg', 'photo-content');
        $directory = storage_path('framework/testing-backups');

        $result = app(BackupService::class)->createArchive($directory, 'test');

        $this->assertFileExists($result['path']);
        $this->assertNotEmpty($result['sha256']);
        $this->assertSame('test', $result['manifest']['label']);
        $this->assertArrayHasKey('path_hash', $result['manifest']['private_storage_files'][0]);
        $this->assertArrayNotHasKey('path', $result['manifest']['private_storage_files'][0]);
        $this->assertSame('inmate-private-files/1/photo.jpg', $result['manifest']['files'][1]['restore_path']);
        $verification = app(BackupService::class)->verifyArchive($result['path']);
        $this->assertTrue($verification['valid']);
        $this->assertCount(2, $verification['manifest']['files']);

        $zip = new \ZipArchive;
        $zip->open($result['path']);
        $this->assertNotFalse($zip->getFromName('database/database.sql'));
        $this->assertSame('photo-content', $zip->getFromName($verification['manifest']['files'][1]['archive_name']));
        $zip->close();
    }

    public function test_legacy_dry_run_maps_valid_rows_and_reports_errors(): void
    {
        $service = app(LegacyTransferService::class);
        $report = $service->dryRun([
            ['id' => 10, 'huk_tc' => '12345678901', 'huk_adi' => 'Geçerli Hükümlü', 'huk_durum' => 'aktif'],
            ['id' => 11, 'huk_tc' => '123', 'huk_adi' => 'Hatalı Hükümlü'],
            ['id' => 12, 'huk_tc' => '', 'huk_adi' => ''],
        ]);

        $this->assertSame(3, $report['rows']);
        $this->assertSame(1, $report['valid']);
        $this->assertSame(2, $report['invalid']);
        $this->assertSame('Geçerli Hükümlü', $report['mapped_preview'][0]['target']['full_name']);
    }

    public function test_legacy_migrate_requires_valid_rows_and_creates_target_records(): void
    {
        $service = app(LegacyTransferService::class);
        $institution = $this->institution();
        $report = $service->migrateRows([
            ['id' => 10, 'huk_tc' => '12345678901', 'huk_adi' => 'Aktarılan Hükümlü', 'huk_durum' => 'aktif'],
        ], $institution->id);

        $this->assertSame('completed', $report['status']);
        $this->assertSame(1, $report['created']);
        $this->assertDatabaseHas('inmate_files', ['identity_number' => '12345678901', 'full_name' => 'Aktarılan Hükümlü', 'legacy_id' => '10']);
    }

    public function test_legacy_compare_counts_reports_mismatch(): void
    {
        $report = app(LegacyTransferService::class)->compareCounts(['inmates' => 5, 'visits' => 2], ['inmates' => 5, 'visits' => 1]);

        $this->assertTrue($report['inmates']['matched']);
        $this->assertFalse($report['visits']['matched']);
    }

    public function test_full_legacy_manifest_contains_the_real_thirteen_tables(): void
    {
        $this->assertSame([
            'duyuru',
            'duyuru_okundu',
            'infaz_nobet',
            'kismi_arama',
            'kullanici_durum',
            'kurul_komisyon',
            'mesaj_okunma',
            'mudur_nobet',
            'sohbet',
            'tablo',
            'users',
            'vardiya_nobet',
            'yemek_listesi',
        ], LegacyTransferService::EXPECTED_TABLES);

        $manifest = json_decode(file_get_contents(base_path('docs/legacy-fihrist-manifest.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(13, $manifest['table_count']);
        $this->assertSame(1238, $manifest['source_rows']);
        $this->assertSame(LegacyTransferService::EXPECTED_TABLES, array_keys($manifest['tables']));
        $this->assertSame('completed', $manifest['migration']['status']);
    }

    public function test_full_legacy_import_requires_explicit_confirmation(): void
    {
        $this->artisan('cte:legacy-migrate-all', ['institution_id' => $this->institution()->id])
            ->expectsOutputToContain('Tam aktarım engellendi')
            ->assertFailed();
    }

    public function test_legacy_transfer_run_stores_manifest_hash(): void
    {
        $run = LegacyTransferRun::create(['run_type' => 'dry_run', 'status' => 'completed', 'summary' => ['rows' => 1], 'report' => ['ok' => true], 'manifest_sha256' => str_repeat('a', 64)]);

        $this->assertSame(['rows' => 1], $run->summary);
        $this->assertSame(str_repeat('a', 64), $run->manifest_sha256);
    }

    private function institution(): Institution
    {
        return Institution::first() ?? Institution::create(['name' => 'Test Kurumu', 'short_name' => 'TEST', 'is_active' => true]);
    }

    private function adminUser(): User
    {
        return User::create(['institution_id' => $this->institution()->id, 'full_name' => 'Faz Sekiz Yönetici', 'login_name' => 'phase-eight-admin-'.uniqid(), 'password' => 'test-password', 'is_active' => true, 'is_protected_superadmin' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
    }

    private function inmateFile(): InmateFile
    {
        return InmateFile::create(['institution_id' => $this->institution()->id, 'identity_number' => null, 'full_name' => 'Audit Test Hükümlü', 'registered_at' => now()->toDateString(), 'entered_at' => now()->toDateString(), 'is_active' => true]);
    }
}
