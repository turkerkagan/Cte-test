<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\Institution;
use App\Models\OperationRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitRecord;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InmateListDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_inmate_list_filters_and_sorts_records(): void
    {
        $user = $this->adminUser();
        $institution = $this->institution();

        InmateFile::create([
            'institution_id' => $institution->id,
            'full_name' => 'Zeki Deneme',
            'identity_number' => null,
            'status_code' => 'aktif',
            'risk_code' => 'dusuk',
            'decision_code' => 'karar-a',
            'registered_at' => '2026-01-10',
            'is_active' => true,
        ]);

        InmateFile::create([
            'institution_id' => $institution->id,
            'full_name' => 'Ahmet Deneme',
            'identity_number' => null,
            'status_code' => 'pasif',
            'risk_code' => 'yuksek',
            'decision_code' => 'karar-b',
            'registered_at' => '2026-02-10',
            'is_active' => false,
        ]);

        InmateFile::create([
            'institution_id' => $institution->id,
            'full_name' => 'Mehmet Deneme',
            'identity_number' => null,
            'status_code' => 'aktif',
            'risk_code' => 'yuksek',
            'decision_code' => 'karar-a',
            'registered_at' => '2026-03-10',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('inmates.index', [
            'status_code' => 'aktif',
            'risk_code' => 'yuksek',
            'decision_code' => 'karar-a',
            'is_active' => '1',
            'registered_from' => '2026-03-01',
            'registered_to' => '2026-03-31',
            'sort' => 'registered_at_desc',
        ]));

        $response->assertOk();
        $response->assertSee('Mehmet Deneme');
        $response->assertDontSee('Ahmet Deneme');
        $response->assertDontSee('Zeki Deneme');
    }

    public function test_inmate_list_supports_multi_select_status_filter(): void
    {
        $user = $this->adminUser();
        $institution = $this->institution();

        foreach ([['Aktif Kisi', 'aktif'], ['Izinli Kisi', 'izinli'], ['Pasif Kisi', 'pasif']] as [$name, $status]) {
            InmateFile::create([
                'institution_id' => $institution->id,
                'full_name' => $name,
                'identity_number' => null,
                'status_code' => $status,
                'registered_at' => '2026-01-10',
                'is_active' => $status !== 'pasif',
            ]);
        }

        $response = $this->actingAs($user)->get(route('inmates.index', [
            'status_code' => ['aktif', 'izinli'],
        ]));

        $response->assertOk();
        $response->assertSee('Aktif Kisi');
        $response->assertSee('Izinli Kisi');
        $response->assertDontSee('Pasif Kisi');
    }

    public function test_inmate_list_search_and_descending_sort(): void
    {
        $user = $this->adminUser();
        $institution = $this->institution();

        InmateFile::create(['institution_id' => $institution->id, 'full_name' => 'Ali Arama', 'identity_number' => null, 'registered_at' => '2026-01-01', 'is_active' => true]);
        InmateFile::create(['institution_id' => $institution->id, 'full_name' => 'Veli Arama', 'identity_number' => null, 'registered_at' => '2026-01-02', 'is_active' => true]);
        InmateFile::create(['institution_id' => $institution->id, 'full_name' => 'Başka Kayıt', 'identity_number' => null, 'registered_at' => '2026-01-03', 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('inmates.index', ['q' => 'Arama', 'sort' => 'full_name_desc']));

        $response->assertOk();
        $response->assertSeeInOrder(['Veli Arama', 'Ali Arama']);
        $response->assertDontSee('Başka Kayıt');
    }

    public function test_detail_tabs_render_expected_sections(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();

        $this->actingAs($user)->get(route('inmates.edit', [$inmate, 'tab' => 'identity']))
            ->assertOk()
            ->assertSee('Ana dosya bilgileri')
            ->assertDontSee('Blok / Koğuş / Ranza');

        $this->actingAs($user)->get(route('inmates.edit', [$inmate, 'tab' => 'location']))
            ->assertOk()
            ->assertSee('Blok / Koğuş / Ranza')
            ->assertDontSee('Ana dosya bilgileri');

        $this->actingAs($user)->get(route('inmates.edit', [$inmate, 'tab' => 'files']))
            ->assertOk()
            ->assertSee('Fotoğraf / belge');
    }

    public function test_view_only_user_does_not_see_update_or_delete_actions(): void
    {
        $user = $this->viewOnlyUser();
        $inmate = $this->inmateFile();

        $response = $this->actingAs($user)->get(route('inmates.edit', [$inmate, 'tab' => 'identity']));

        $response->assertOk();
        $response->assertSee('Ana dosya bilgileri');
        $response->assertDontSee('Kalıcı sil');
        $response->assertDontSee('Kaydet');
    }

    public function test_home_summary_uses_live_leave_housing_and_duty_records(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $manager = User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Nöbetçi Müdür',
            'login_name' => 'manager-duty',
            'password' => 'test-password',
            'is_active' => true,
        ]);
        $officer = User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Nöbetçi İnfaz',
            'login_name' => 'execution-duty',
            'password' => 'test-password',
            'is_active' => true,
        ]);

        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'izin',
            'record_type' => 'Ödül',
            'title' => 'Bugün izin çıkışı',
            'occurred_at' => now()->toDateString(),
            'ended_at' => now()->toDateString(),
            'data' => ['Çıkış' => now()->toDateString(), 'Dönüş' => now()->toDateString()],
        ]);
        $block = Block::create([
            'institution_id' => $this->institution()->id,
            'code' => 'A',
            'name' => 'A Blok',
            'is_active' => true,
        ]);
        $ward = Ward::create([
            'block_id' => $block->id,
            'code' => '1',
            'name' => '1. Koğuş',
            'is_active' => true,
        ]);
        HousingAssignment::create([
            'inmate_file_id' => $inmate->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'started_at' => now()->toDateString(),
        ]);
        OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'module_code' => 'nobet',
            'record_type' => 'Müdür nöbeti',
            'title' => 'Müdür nöbeti',
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->endOfDay(),
            'data' => ['Personel' => (string) $manager->id],
            'is_closed' => false,
        ]);
        OperationRecord::create([
            'institution_id' => $this->institution()->id,
            'module_code' => 'nobet',
            'record_type' => 'İnfaz nöbeti',
            'title' => 'İnfaz nöbeti',
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->endOfDay(),
            'data' => ['Personel' => (string) $officer->id],
            'is_closed' => false,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Konum değişikliği')
            ->assertSee('Çıkış 1')
            ->assertSee('Dönüş 1')
            ->assertSee('Nöbetçi Müdür')
            ->assertSee('Nöbetçi İnfaz');
    }

    public function test_home_actions_and_routes_follow_specific_inmate_permissions(): void
    {
        $viewOnly = $this->viewOnlyUser();
        $inmate = $this->inmateFile();

        $response = $this->actingAs($viewOnly)->get(route('dashboard'));
        $response->assertOk()
            ->assertSee('Görüntüle')
            ->assertDontSee('Yeni Kayıt Ekle')
            ->assertDontSee('Toplu işlemler')
            ->assertDontSee('Dışa aktar')
            ->assertDontSee('Sil');

        $this->actingAs($viewOnly)->get(route('inmates.create'))->assertForbidden();
        $this->actingAs($viewOnly)->put(route('inmates.update', $inmate), [
            'institution_id' => $this->institution()->id,
            'identity_number' => null,
            'full_name' => 'Yetkisiz Güncelleme',
            'is_active' => '1',
        ])->assertForbidden();
        $this->actingAs($viewOnly)->get(route('tools.export', ['source' => 'inmates', 'format' => 'csv', 'columns' => ['full_name']]))->assertForbidden();
        $this->actingAs($viewOnly)->get(route('inmates.permanent-delete.create', $inmate))->assertForbidden();
    }

    public function test_legacy_home_popups_render_empty_rooms_leave_lists_and_execution_info(): void
    {
        $user = $this->adminUser();
        $inmate = $this->inmateFile();
        $block = Block::create([
            'institution_id' => $this->institution()->id,
            'code' => 'A',
            'name' => 'A',
            'is_active' => true,
        ]);
        $ward = Ward::create([
            'block_id' => $block->id,
            'code' => '1',
            'name' => '1',
            'capacity' => 2,
            'is_active' => true,
        ]);
        $bed1 = Bed::create(['ward_id' => $ward->id, 'label' => '1', 'is_active' => true]);
        Bed::create(['ward_id' => $ward->id, 'label' => '2', 'is_active' => true]);

        HousingAssignment::create([
            'inmate_file_id' => $inmate->id,
            'block_id' => $block->id,
            'ward_id' => $ward->id,
            'bed_id' => $bed1->id,
            'started_at' => now()->toDateString(),
        ]);
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'izin',
            'record_type' => 'Ödül',
            'title' => 'Bugünkü izin',
            'occurred_at' => now()->toDateString(),
            'ended_at' => now()->toDateString(),
            'data' => ['Çıkış' => now()->toDateString(), 'Dönüş' => now()->toDateString(), 'Çıkış Saati' => '09:00', 'Dönüş Saati' => '17:00'],
        ]);
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'infaz',
            'record_type' => 'DS/Tahliye',
            'title' => 'Tahliye bilgisi',
            'occurred_at' => now()->toDateString(),
            'data' => ['UYAP No' => 'UYAP-123', 'DS Tarihi' => '01.07.2026', 'Tahliye Tarihi' => '15.07.2026'],
            'notes' => 'Kurul notu test',
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Boş Koğuşları Göster')
            ->assertSee('Boş Koğuşlar ve Ranzalar')
            ->assertSee('A / 1')
            ->assertSee('Boş 1')
            ->assertSee('Bugün İzne Ayrılacaklar')
            ->assertSee('Bugün İzinden Dönecekler')
            ->assertSee('09:00')
            ->assertSee('17:00')
            ->assertSee('Tahliye bilgileri')
            ->assertSee('UYAP-123')
            ->assertSee('01.07.2026')
            ->assertSee('15.07.2026')
            ->assertSee('Kurul notu test');
    }

    public function test_home_new_inmate_modal_creates_record_and_returns_to_dashboard(): void
    {
        $user = $this->adminUser();
        $institution = $this->institution();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="newInmateModal"', false)
            ->assertSee('name="return_to" value="dashboard"', false)
            ->assertSee('Yeni Kayıt Ekle');

        $response = $this->actingAs($user)->from(route('dashboard'))->post(route('inmates.store'), [
            'institution_id' => $institution->id,
            'identity_number' => '12345678901',
            'full_name' => 'Ana Sayfa Yeni Kayıt',
            'registered_at' => now()->toDateString(),
            'health_status' => 'SAĞLIKLI',
            'is_active' => '1',
            'return_to' => 'dashboard',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'Hükümlü kaydı oluşturuldu ve ana listeye eklendi.');
        $this->assertDatabaseHas('inmate_files', [
            'institution_id' => $institution->id,
            'identity_number' => '12345678901',
            'full_name' => 'Ana Sayfa Yeni Kayıt',
            'is_active' => true,
        ]);
    }

    public function test_home_new_inmate_modal_reopens_after_validation_error(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->followingRedirects()
            ->post(route('inmates.store'), [
                'institution_id' => $this->institution()->id,
                'full_name' => '',
                'return_to' => 'dashboard',
            ])
            ->assertOk()
            ->assertSee('field-error', false)
            ->assertSee('data-open-on-load="true"', false);
    }

    public function test_legacy_three_level_navigation_is_rendered_for_authorized_user(): void
    {
        $this->actingAs($this->adminUser())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kurum Hakkında')
            ->assertSee('Nöbetler')
            ->assertSee('Müdür Nöbetleri')
            ->assertSee('Vardiya Nöbetleri')
            ->assertSee('İnfaz Nöbetleri')
            ->assertSee('Kurum Birimleri')
            ->assertSee('İnfaz Birimi')
            ->assertSee('Tebliğler')
            ->assertSee('Bakanlık Sevk')
            ->assertSee('Hükümlü İşlemleri')
            ->assertSee('İnfaz Hesaplama Araçları');
    }

    public function test_combined_profile_shows_only_unit_records_user_may_view(): void
    {
        $inmate = $this->inmateFile();
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'egitim',
            'record_type' => 'Profil',
            'title' => 'Görülebilir Eğitim Kaydı',
            'occurred_at' => '2026-06-20',
            'data' => ['Okul' => 'Açık Öğretim'],
        ]);
        UnitRecord::create([
            'inmate_file_id' => $inmate->id,
            'module_code' => 'revir',
            'record_type' => 'Tanı',
            'title' => 'Gizli Sağlık Kaydı',
            'occurred_at' => '2026-06-21',
            'data' => ['Tanı' => 'Hassas bilgi'],
            'is_sensitive' => true,
        ]);

        $response = $this->actingAs($this->profileUser(['inmates.view', 'education.view']))
            ->get(route('inmates.edit', [$inmate, 'tab' => 'overview']));

        $response->assertOk()
            ->assertSee('Birleşik hükümlü profili')
            ->assertSee('Görülebilir Eğitim Kaydı')
            ->assertSee('Açık Öğretim')
            ->assertDontSee('Gizli Sağlık Kaydı')
            ->assertDontSee('Hassas bilgi')
            ->assertDontSee('Kayıt ekle')
            ->assertDontSee('Ana dosyayı düzenle');
    }

    public function test_combined_profile_write_actions_follow_unit_update_permission(): void
    {
        $inmate = $this->inmateFile();

        $this->actingAs($this->profileUser(['inmates.view', 'education.view', 'education.update']))
            ->get(route('inmates.edit', [$inmate, 'tab' => 'overview']))
            ->assertOk()
            ->assertSee('Eğitim')
            ->assertSee('Kayıt ekle');
    }

    private function institution(): Institution
    {
        return Institution::first() ?? Institution::create([
            'name' => 'Test Kurumu',
            'short_name' => 'TEST',
            'is_active' => true,
        ]);
    }

    private function adminUser(): User
    {
        return User::create([
            'institution_id' => $this->institution()->id,
            'full_name' => 'Test Yönetici',
            'login_name' => 'test-admin-list-detail',
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function viewOnlyUser(): User
    {
        $institution = $this->institution();
        $role = Role::create([
            'institution_id' => $institution->id,
            'code' => 'inmate-view-test',
            'name' => 'Hükümlü Görüntüleme Test',
            'is_system' => false,
        ]);
        $permission = Permission::create([
            'key' => 'inmates.view',
            'module' => 'inmates',
            'action' => 'view',
            'label' => 'Hükümlüler — Görüntüle',
            'is_sensitive' => false,
        ]);
        $role->permissions()->sync([$permission->id]);

        return User::create([
            'institution_id' => $institution->id,
            'role_id' => $role->id,
            'full_name' => 'Sadece Görüntüleme',
            'login_name' => 'view-only-list-detail',
            'password' => 'test-password',
            'is_active' => true,
            'is_protected_superadmin' => false,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    private function inmateFile(): InmateFile
    {
        return InmateFile::create([
            'institution_id' => $this->institution()->id,
            'identity_number' => null,
            'full_name' => 'Detay Test Hükümlü',
            'registered_at' => now()->toDateString(),
            'entered_at' => now()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function profileUser(array $permissionKeys): User
    {
        $role = Role::create([
            'institution_id' => $this->institution()->id,
            'code' => 'profile-'.uniqid(),
            'name' => 'Profil Test Rolü',
            'is_system' => false,
        ]);
        $permissionIds = collect($permissionKeys)->map(function (string $key) {
            [$module, $action] = explode('.', $key, 2);

            return Permission::firstOrCreate(
                ['key' => $key],
                ['module' => $module, 'action' => $action, 'label' => $key, 'is_sensitive' => false],
            )->id;
        });
        $role->permissions()->sync($permissionIds);

        return User::create([
            'institution_id' => $this->institution()->id,
            'role_id' => $role->id,
            'full_name' => 'Profil Yetki Kullanıcısı',
            'login_name' => 'profile-user-'.uniqid(),
            'password' => 'test-password',
            'is_active' => true,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }
}
