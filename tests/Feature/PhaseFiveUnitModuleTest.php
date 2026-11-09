<?php

namespace Tests\Feature;

use App\Http\Controllers\UnitRecordController;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\UnitRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseFiveUnitModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_phase_five_modules_are_registered(): void
    {
        $this->assertSame([
            'sorumlu-basmemurluk', 'degerlendirme', 'revir', 'psikososyal', 'infaz', 'izin', 'teblig-bakanlik-sevk', 'ziyaret', 'manevi-rehberlik', 'egitim', 'kurs-hijyen-isg', 'egitim-istatistikleri', 'mesem',
        ], array_keys(UnitRecordController::MODULES));
    }

    public function test_unit_record_can_be_created_for_each_phase_five_module(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        foreach (UnitRecordController::MODULES as $module => $config) {
            $response = $this->actingAs($user)->post(route('unit-records.store', [$module, $inmate]), [
                'record_type' => $config['types'][0],
                'title' => $config['title'].' test kaydı',
                'occurred_at' => '2026-06-22',
                'status_code' => 'takip',
                'data' => [$config['fields'][0] => 'Alan değeri'],
                'notes' => $config['task'].' test notu',
            ]);

            $response->assertRedirect(route('unit-records.index', $module));
        }

        $this->assertSame(count(UnitRecordController::MODULES), UnitRecord::count());
        $this->assertDatabaseHas('unit_records', ['module_code' => 'revir', 'is_sensitive' => true]);
        $this->assertDatabaseHas('unit_records', ['module_code' => 'ziyaret', 'is_sensitive' => false]);
    }

    public function test_unit_module_index_filters_by_search_type_status_and_closed_state(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'izin',
            'record_type' => 'Evci',
            'title' => 'Aranacak izin kaydı',
            'occurred_at' => '2026-06-22',
            'status_code' => 'onayli',
            'is_sensitive' => false,
            'is_closed' => false,
            'data' => ['Süre' => '3 gün'],
        ]);

        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'izin',
            'record_type' => 'Dönüş',
            'title' => 'Başka kayıt',
            'occurred_at' => '2026-05-01',
            'ended_at' => '2026-05-02',
            'closed_at' => '2026-05-02 18:00:00',
            'status_code' => 'kapali',
            'is_sensitive' => false,
            'is_closed' => true,
        ]);

        $response = $this->actingAs($user)->get(route('unit-records.index', 'izin').'?q=Aranacak&record_type=Evci&status_code=onayli&is_closed=0');

        $response->assertOk();
        $response->assertSee('Aranacak izin kaydı');
        $response->assertDontSee('Başka kayıt');
    }

    public function test_unit_record_can_be_closed(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $record = UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'module_code' => 'manevi-rehberlik',
            'record_type' => 'Görüşme',
            'title' => 'Kapatılacak kayıt',
            'occurred_at' => '2026-06-22',
            'is_sensitive' => false,
            'is_closed' => false,
        ]);

        $response = $this->actingAs($user)->patch(route('unit-records.close', ['manevi-rehberlik', $record]));

        $response->assertRedirect(route('unit-records.index', 'manevi-rehberlik'));
        $this->assertDatabaseHas('unit_records', ['id' => $record->id, 'is_closed' => true]);
    }

    public function test_sensitive_unit_module_is_visible_to_superadmin(): void
    {
        $this->actingAs($this->adminUser())->get(route('unit-records.index', 'revir'))->assertOk();
        $this->actingAs($this->adminUser())->get(route('unit-records.index', 'psikososyal'))->assertOk();
    }

    public function test_unit_field_filter_and_encrypted_education_password_work(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $record = UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'egitim',
            'record_type' => 'Profil',
            'title' => 'Şifreli eğitim profili',
            'occurred_at' => '2026-06-22',
            'data' => ['Okul' => 'Açık Lise', 'Okul şifresi' => 'Gizli123'],
        ]);

        $raw = DB::table('unit_records')->where('id', $record->id)->value('data');
        $this->assertStringNotContainsString('Gizli123', $raw);
        $this->assertSame('Gizli123', $record->fresh()->data['Okul şifresi']);
        $this->actingAs($user)->get(route('unit-records.index', ['egitim', 'field' => 'Okul', 'field_value' => 'Açık']))
            ->assertOk()
            ->assertSee('Şifreli eğitim profili');
    }

    public function test_overlapping_leave_is_rejected(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'izin',
            'record_type' => 'Ödül',
            'title' => 'İlk izin',
            'occurred_at' => '2026-07-01',
            'ended_at' => '2026-07-10',
            'data' => ['Çıkış' => '2026-07-01', 'Dönüş' => '2026-07-10'],
        ]);

        $this->actingAs($user)->post(route('unit-records.store', ['izin', $inmate]), [
            'record_type' => 'Ödül',
            'title' => 'Çakışan izin',
            'data' => ['Çıkış' => '2026-07-05', 'Dönüş' => '2026-07-12'],
        ])->assertSessionHasErrors('data.Çıkış');
    }

    private function institution(): Institution
    {
        return Institution::first() ?? Institution::create(['name' => 'Test Kurumu', 'short_name' => 'TEST', 'is_active' => true]);
    }

    private function adminUser(): User
    {
        return User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Faz Beş Yönetici',
            'login_name' => 'phase-five-admin-'.uniqid(),
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function inmateFile(): InmateFile
    {
        return InmateFile::create([
            'institution_id' => $this->institution()->id,
            'identity_number' => null,
            'full_name' => 'Faz Beş Test Hükümlü',
            'registered_at' => now()->toDateString(),
            'entered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
    }
}
