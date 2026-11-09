<?php

namespace Tests\Feature;

use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\OperationRecord;
use App\Models\SavedReport;
use App\Models\UnitRecord;
use App\Models\User;
use App\Services\SentenceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseSevenToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sentence_calculator_returns_core_dates_and_equivalence_examples_pass(): void
    {
        $calculator = new SentenceCalculator;
        $result = $calculator->calculate(['start_date' => '2026-01-01', 'years' => 3, 'ratio' => 1 / 3]);

        $this->assertSame(1095, $result['total_days']);
        $this->assertSame(365, $result['served_days']);
        $this->assertSame('2027-01-01', $result['conditional_release_date']);
        $this->assertTrue(collect($calculator->verifyExamples())->every(fn ($example) => $example['passed']));
    }

    public function test_calculator_screen_renders_result(): void
    {
        $response = $this->actingAs($this->adminUser())->get(route('tools.calculator', ['start_date' => '2026-01-01', 'years' => 3, 'ratio' => 0.333333]));

        $response->assertOk();
        $response->assertSee('conditional_release_date');
        $response->assertSee('Örnek eşdeğerlik testleri');
    }

    public function test_custom_report_stores_only_allowed_columns(): void
    {
        $user = $this->adminUser();

        $response = $this->actingAs($user)->post(route('tools.reports.store'), [
            'name' => 'Hükümlü temel raporu',
            'source' => 'inmates',
            'columns' => ['full_name', 'identity_number', 'password', 'deleted_at'],
            'is_shared' => '1',
        ]);

        $response->assertRedirect(route('tools.reports'));
        $report = SavedReport::firstOrFail();
        $this->assertSame(['full_name', 'identity_number'], $report->columns);
        $this->assertTrue($report->is_shared);
    }

    public function test_csv_export_uses_allowed_columns_and_omits_disallowed_columns(): void
    {
        $user = $this->adminUser();
        InmateFile::create(['institution_id' => $this->institution()->id, 'identity_number' => null, 'full_name' => 'CSV Test Hükümlü', 'registered_at' => '2026-06-22', 'entered_at' => '2026-06-22', 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('tools.export', ['source' => 'inmates', 'columns' => ['full_name', 'identity_number', 'password']]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertSee('full_name,identity_number', false);
        $response->assertSee('CSV Test Hükümlü');
        $response->assertDontSee('password');
    }

    public function test_xlsx_and_pdf_exports_are_real_downloads(): void
    {
        $user = $this->adminUser();
        InmateFile::create(['institution_id' => $this->institution()->id, 'identity_number' => null, 'full_name' => 'Dosya Test Hükümlü', 'registered_at' => '2026-06-22', 'entered_at' => '2026-06-22', 'is_active' => true]);
        $parameters = ['source' => 'inmates', 'columns' => ['full_name']];

        $xlsx = $this->actingAs($user)->get(route('tools.export', $parameters + ['format' => 'xlsx']));
        $xlsx->assertOk()->assertDownload('cte_export.xlsx');
        $this->assertStringStartsWith('PK', $xlsx->getFile()->getContent());

        $pdf = $this->actingAs($user)->get(route('tools.export', $parameters + ['format' => 'pdf']));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_legacy_profile_export_flattens_unit_and_core_fields(): void
    {
        $user = $this->adminUser();
        $inmate = InmateFile::create(['institution_id' => $this->institution()->id, 'full_name' => 'Birleşik Rapor Hükümlüsü', 'registered_at' => '2026-06-22', 'is_active' => true]);
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'egitim',
            'record_type' => 'Profil',
            'title' => 'Eğitim',
            'occurred_at' => '2026-06-22',
            'data' => ['Okul' => 'Açık Öğretim Lisesi'],
        ]);

        $this->actingAs($user)->get(route('tools.export', [
            'source' => 'legacy_profiles',
            'columns' => ['full_name', 'egitim.Okul', 'password'],
        ]))->assertOk()
            ->assertSee('full_name,egitim.Okul', false)
            ->assertSee('Birleşik Rapor Hükümlüsü')
            ->assertSee('Açık Öğretim Lisesi')
            ->assertDontSee('password');
    }

    public function test_statistics_screen_counts_core_sources(): void
    {
        $user = $this->adminUser();
        $inmate = InmateFile::create(['institution_id' => $this->institution()->id, 'identity_number' => null, 'full_name' => 'İstatistik Hükümlü', 'status_code' => 'aktif', 'risk_code' => 'dusuk', 'registered_at' => '2026-06-22', 'entered_at' => '2026-06-22', 'is_active' => true]);
        UnitRecord::create(['inmate_file_id' => $inmate->id, 'module_code' => 'egitim', 'record_type' => 'Profil', 'title' => 'Eğitim kaydı', 'occurred_at' => '2026-06-22', 'is_sensitive' => true, 'is_closed' => false]);
        OperationRecord::create(['institution_id' => $this->institution()->id, 'module_code' => 'duyurular', 'record_type' => 'Genel duyuru', 'title' => 'Duyuru kaydı', 'starts_at' => '2026-06-22 09:00:00']);

        $response = $this->actingAs($user)->get(route('tools.statistics'));

        $response->assertOk();
        $response->assertSee('Genel istatistik');
        $response->assertSee('egitim');
        $response->assertSee('duyurular');
    }

    public function test_general_statistics_preserve_legacy_distributions(): void
    {
        $user = $this->adminUser();
        $inmate = InmateFile::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Dağılım Test Hükümlüsü',
            'status_code' => 'MEVCUT',
            'risk_code' => 'YÜKSEK',
            'decision_code' => 'İŞYURTLARI',
            'health_status' => 'KRONİK',
            'registered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'egitim',
            'record_type' => 'Profil',
            'title' => 'Eğitim',
            'occurred_at' => now()->toDateString(),
            'data' => ['Statü' => 'ÖĞRENCİ', 'Öğrenim' => 'LİSE'],
        ]);

        $this->actingAs($user)->get(route('tools.statistics'))
            ->assertOk()
            ->assertSee('Toplam Kayıt')
            ->assertSee('Bu Ay')
            ->assertSee('Çalışma Yeri')
            ->assertSee('Eğitim Statüsü')
            ->assertSee('ÖĞRENCİ')
            ->assertSee('Kronik Rahatsızlık')
            ->assertSee('Kayıt Trendi');
    }

    public function test_general_statistics_include_legacy_demography_and_profession(): void
    {
        $user = $this->adminUser();
        $inmate = InmateFile::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Demografi Test Hükümlüsü',
            'registered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'sorumlu-basmemurluk',
            'record_type' => 'İlk görüş',
            'title' => 'İlk görüş',
            'occurred_at' => now()->toDateString(),
            'data' => [
                'Yaş' => '42',
                'Meslek' => 'ELEKTRİKÇİ',
                'Engellilik' => 'YOK',
                'Aile suç durumu' => 'YOK',
                'Kendine zarar riski' => 'DÜŞÜK',
            ],
        ]);

        $this->actingAs($user)->get(route('tools.statistics'))
            ->assertOk()
            ->assertSee('Yaş Grubu')
            ->assertSee('35-44')
            ->assertSee('Meslek')
            ->assertSee('ELEKTRİKÇİ')
            ->assertSee('Engellilik')
            ->assertSee('Ailede Suç Öyküsü');
    }

    public function test_mesem_statistics_preserve_legacy_process_indicators(): void
    {
        $user = $this->adminUser();
        $inmate = InmateFile::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'MESEM İstatistik Hükümlüsü',
            'decision_code' => 'İŞYURTLARI',
            'registered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'mesem',
            'record_type' => 'Profil',
            'title' => 'MESEM profil',
            'occurred_at' => now()->toDateString(),
            'data' => [
                'Görüşme' => 'GÖRÜŞÜLDÜ',
                'Durum' => 'BAŞVURU SÜRECİNDE',
                'Dilekçe' => 'DİLEKÇE ALINDI',
                'Öğrenim Belgesi' => 'ALINDI',
                'Sınıf' => '9',
                'Dal' => 'ELEKTRİK',
                'Belge Türü' => 'KALFALIK',
                'İşe Giriş Tarihi' => '2026-06-01',
            ],
        ]);

        $this->actingAs($user)->get(route('tools.mesem-statistics'))
            ->assertOk()
            ->assertSee('MESEM İstatistik Paneli')
            ->assertSee('Görüşülen')
            ->assertSee('Başvuru Sürecinde')
            ->assertSee('ELEKTRİK')
            ->assertSee('KALFALIK')
            ->assertSee('Çalışma yeri matrisi');
    }

    private function institution(): Institution
    {
        return Institution::first() ?? Institution::create(['name' => 'Test Kurumu', 'short_name' => 'TEST', 'is_active' => true]);
    }

    private function adminUser(): User
    {
        return User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Faz Yedi Yönetici',
            'login_name' => 'phase-seven-admin-'.uniqid(),
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }
}
