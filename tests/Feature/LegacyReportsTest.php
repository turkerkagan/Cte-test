<?php

namespace Tests\Feature;

use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\UnitRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_leave_report_preserves_legacy_daily_columns_and_lists(): void
    {
        $user = $this->admin();
        $inmate = $this->inmate();
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'infaz',
            'record_type' => 'UYAP',
            'title' => 'UYAP',
            'occurred_at' => '2026-06-01',
            'data' => ['UYAP No' => 'UYAP-456'],
        ]);
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'izin',
            'record_type' => 'Ödül',
            'title' => 'İzin',
            'occurred_at' => '2026-06-23',
            'ended_at' => '2026-06-23',
            'data' => ['Çıkış' => '2026-06-23', 'Dönüş' => '2026-06-23', 'Çıkış Saati' => '09:00', 'Dönüş Saati' => '18:00'],
        ]);

        $this->actingAs($user)->get(route('reports.leave', ['date' => '2026-06-23']))
            ->assertOk()
            ->assertSee('İzne Ayrılacaklar')
            ->assertSee('İzinden Dönecekler')
            ->assertSee('UYAP-456')
            ->assertSee('09:00')
            ->assertSee('18:00')
            ->assertSee('Çalışma Yeri')
            ->assertSee('Ranza');
    }

    public function test_transfer_report_applies_legacy_ds_priority_day_and_reason_rules(): void
    {
        Carbon::setTestNow('2026-06-23 12:00:00');
        $user = $this->admin();
        $eligible = $this->inmate('Sevke Uygun');
        $excluded = $this->inmate('Geliş Nedeni Hariç');

        UnitRecord::create([
            'inmate_file_id' => $eligible->id,
            'module_code' => 'infaz',
            'record_type' => 'DS/Tahliye',
            'title' => 'Sevk verisi',
            'occurred_at' => '2026-06-01',
            'data' => ['UYAP No' => 'UYGUN-1', 'DS Tarihi' => '2026-10-11', 'Tahliye Tarihi' => '2027-01-01', 'Geliş Nedeni' => 'NAKİL'],
        ]);
        UnitRecord::create([
            'inmate_file_id' => $excluded->id,
            'module_code' => 'infaz',
            'record_type' => 'DS/Tahliye',
            'title' => 'Sevk verisi',
            'occurred_at' => '2026-06-01',
            'data' => ['UYAP No' => 'HARIC-1', 'DS Tarihi' => '2026-12-01', 'Geliş Nedeni' => 'EĞİTİM'],
        ]);

        $response = $this->actingAs($user)->get(route('reports.transfer', [
            'type' => 'il_disi',
            'out_days' => 100,
            'out_age' => 60,
            'excluded_reasons' => ['EĞİTİM'],
        ]));

        $response->assertOk()
            ->assertSee('Sevke Uygun')
            ->assertSee('UYGUN-1')
            ->assertDontSee('Geliş Nedeni Hariç')
            ->assertDontSee('HARIC-1')
            ->assertSee('DS tarihi varsa DS');
    }

    public function test_notification_and_ministry_referral_forms_use_selected_inmates(): void
    {
        $user = $this->admin();
        $selected = $this->inmate('Formda Seçili');
        $other = $this->inmate('Formda Seçilmeyen');
        UnitRecord::create([
            'inmate_file_id' => $selected->id,
            'module_code' => 'infaz',
            'record_type' => 'UYAP',
            'title' => 'UYAP',
            'occurred_at' => '2026-06-01',
            'data' => ['UYAP No' => 'FORM-123', 'DS Tarihi' => '01.10.2026', 'Tahliye Tarihi' => '01.01.2027'],
        ]);

        $this->actingAs($user)->get(route('reports.notification', ['inmate_ids' => [$selected->id]]))
            ->assertOk()
            ->assertSee('Müddetname Tebliğ Tebellüğ Belgesi')
            ->assertSee('Formda Seçili')
            ->assertSee('FORM-123')
            ->assertSee('Tümünü Seç');

        $response = $this->actingAs($user)->get(route('reports.ministry-referral', ['inmate_ids' => [$selected->id]]));
        $response->assertOk()
            ->assertSee('Hükümlü/Tutuklu Sevk Bilgi Formu')
            ->assertSee('Formda Seçili')
            ->assertSee('Devam Eden Yargılama');

        $selectedHtml = $response->getContent();
        $this->assertSame(2, substr_count($selectedHtml, '<strong>Formda Seçili</strong>'));
        $this->assertSame(1, substr_count($selectedHtml, 'Formda Seçilmeyen'));
        $this->assertNotNull($other);
    }

    public function test_transfer_report_exports_real_xlsx(): void
    {
        $response = $this->actingAs($this->admin())->get(route('reports.transfer.export'));

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
    }

    private function institution(): Institution
    {
        return Institution::first() ?? Institution::create([
            'name' => 'Rapor Test Kurumu',
            'short_name' => 'RAPOR',
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Rapor Yöneticisi',
            'login_name' => 'rapor-admin-'.uniqid(),
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function inmate(string $name = 'Rapor Test Hükümlüsü'): InmateFile
    {
        return InmateFile::create([
            'institution_id' => $this->institution()->id,
            'identity_number' => null,
            'full_name' => $name,
            'registered_at' => '2026-01-01',
            'entered_at' => '2026-01-01',
            'is_active' => true,
        ]);
    }
}
