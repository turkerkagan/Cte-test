<?php

namespace Tests\Feature;

use App\Services\Exports\LegacyOrganizationAdapter;
use App\Services\Exports\LegacyOrganizationReader;
use App\Services\Exports\PrivateOrganizationExport;
use App\Services\Imports\OrganizationSnapshot;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\OrganizationSnapshotFixture;
use Tests\TestCase;

class OrganizationExportTest extends TestCase
{
    public function test_adapter_preserves_security_identity_and_explicitly_reports_normalizations(): void
    {
        $input = OrganizationSnapshotFixture::make();
        $input['birimler'][0]['created_at'] = '0000-00-00 00:00:00';
        $input['kullanicilar'][0]['son_giris'] = '';
        $input['kullanicilar'][0]['rol_id'] = 4;
        $input['kullanicilar'][0]['ozel_not_seviye'] = 'yazar';
        $result = app(LegacyOrganizationAdapter::class)->adapt('test_source', $input);
        $this->assertNull($result['snapshot']['birimler'][0]['created_at']);
        $this->assertNull($result['snapshot']['kullanicilar'][0]['son_giris']);
        $this->assertSame(['birimler.created_at' => 1, 'kullanicilar.son_giris' => 1], $result['report']['empty_or_zero_dates_to_null']);
        $this->assertSame(['kullanicilar.rol_id' => 1, 'kullanicilar.ozel_not_seviye' => 1], $result['report']['deferred_access_columns_non_null']);
        $this->assertFalse($result['report']['access_migration_complete']);
        $this->assertSame($input['kullanicilar'][0]['sifre'], $result['snapshot']['kullanicilar'][0]['sifre']);
        $this->assertSame('000012', $result['snapshot']['kullanicilar'][0]['sicil']);
        $this->assertSame(3, $result['snapshot']['kullanicilar'][0]['oturum_surumu']);
        $this->assertSame(1, $result['snapshot']['kullanicilar'][0]['sifre_degistir']);
        $this->assertSame('2026-10-03 12:00:00', $result['snapshot']['kullanicilar'][0]['kilitli_baslangic']);
        $this->assertArrayNotHasKey('rol_id', $result['snapshot']['kullanicilar'][0]);
        $this->assertStringNotContainsString($input['kullanicilar'][0]['sifre'], json_encode($result['report']));
        $this->assertSame(['departments' => 1, 'job_titles' => 1, 'users' => 1], OrganizationSnapshot::fromArray($result['snapshot'])->counts());
    }

    public function test_invalid_security_flags_are_not_silently_defaulted(): void
    {
        $input = OrganizationSnapshotFixture::make();
        $input['kullanicilar'][0]['aktif'] = null;
        $this->expectException(ValidationException::class);
        app(LegacyOrganizationAdapter::class)->adapt('test', $input);
    }

    public function test_partial_zero_date_is_rejected_instead_of_erased(): void
    {
        $input = OrganizationSnapshotFixture::make();
        $input['kullanicilar'][0]['son_giris'] = '2026-00-03 12:00:00';
        $this->expectException(ValidationException::class);
        app(LegacyOrganizationAdapter::class)->adapt('test', $input);
    }

    public function test_new_target_and_dsn_injection_are_rejected_before_connecting(): void
    {
        foreach (['cte_next_development', 'cte;host=attacker'] as $database) {
            try {
                app(LegacyOrganizationReader::class)->read('test', ['database' => $database, 'host' => '127.0.0.1', 'port' => 3306, 'username' => 'unused']);
                $this->fail('Unsafe source accepted');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Kaynak bağlantısı', $error->getMessage());
            }
        }
    }

    public function test_private_export_is_complete_reimportable_and_never_overwritten(): void
    {
        $name = 'synthetic_export_'.bin2hex(random_bytes(6));
        $writer = app(PrivateOrganizationExport::class);
        $result = app(LegacyOrganizationAdapter::class)->adapt('test', OrganizationSnapshotFixture::make());
        $directory = storage_path('app/private/imports/'.$name);
        try {
            $path = $writer->write($name, $result);
            $this->assertSame($result['snapshot'], json_decode(file_get_contents($path), true));
            $this->assertFileExists($directory.'/report.json');
            $this->assertFileDoesNotExist($directory.'/snapshot.partial');
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0600, fileperms($path) & 0777);
                $this->assertSame(0700, fileperms($directory) & 0777);
            }
            $this->artisan('portal:import-organization', ['file' => $path])->assertSuccessful();
            try {
                $writer->write($name, $result);
                $this->fail('Existing export overwritten');
            } catch (RuntimeException $error) {
                $this->assertSame($result['snapshot'], json_decode(file_get_contents($path), true));
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_output_traversal_and_failed_write_leave_no_partial_snapshot(): void
    {
        $writer = app(PrivateOrganizationExport::class);
        try {
            $writer->write('../escape', []);
            $this->fail('Traversal allowed');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Çıktı adı', $error->getMessage());
        }
        $name = 'synthetic_failed_'.bin2hex(random_bytes(6));
        try {
            $writer->write($name, ['snapshot' => ['invalid_utf8' => "\xFF"], 'report' => []]);
            $this->fail('Invalid JSON accepted');
        } catch (\JsonException $error) {
            $this->assertDirectoryDoesNotExist(storage_path('app/private/imports/'.$name));
        }
    }

    public function test_command_hides_connection_errors_and_rejects_invalid_namespace(): void
    {
        config(['legacy.database' => 'cte_next_development', 'legacy.password' => 'secret_never_print']);
        $this->artisan('portal:export-organization', ['source' => 'test'])
            ->expectsOutputToContain('Dışa aktarım tamamlanamadı')
            ->doesntExpectOutputToContain('secret_never_print')->assertFailed();
        $this->artisan('portal:export-organization', ['source' => '../invalid'])
            ->expectsOutputToContain('Kaynak eşleme adı geçersiz')->assertFailed();
    }
}
