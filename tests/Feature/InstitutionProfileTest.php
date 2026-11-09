<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstitutionProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_update_institution_profile(): void
    {
        [$admin, $institution] = $this->makeAdminContext();

        $this->actingAs($admin)
            ->get(route('admin.institution.edit'))
            ->assertOk()
            ->assertSee('Kurum bilgileri');

        $this->actingAs($admin)
            ->put(route('admin.institution.update'), [
                'name' => 'Buca Açık Ceza İnfaz Kurumu',
                'short_name' => 'BACİK',
                'address' => 'Buca / İzmir',
                'phone' => '0232 000 00 00',
                'fax' => '0232 000 00 01',
                'email' => 'bilgi@example.test',
                'primary_color' => '#123456',
                'accent_color' => '#abcdef',
                'login_eyebrow' => 'Kurum içi portal',
                'login_heading' => 'Güvenli ve hızlı erişim',
                'login_description' => 'Birimlerin ortak çalışma alanı.',
                'login_security_note' => 'Veriler yerel sunucuda saklanır.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('institutions', [
            'id' => $institution->id,
            'name' => 'Buca Açık Ceza İnfaz Kurumu',
            'short_name' => 'BACİK',
            'primary_color' => '#123456',
            'accent_color' => '#abcdef',
            'login_heading' => 'Güvenli ve hızlı erişim',
        ]);
    }

    public function test_login_page_uses_institution_profile_text(): void
    {
        $institution = Institution::create([
            'name' => 'Test Kurumu',
            'short_name' => 'TEST',
            'primary_color' => '#112233',
            'accent_color' => '#445566',
            'login_eyebrow' => 'Özel giriş alanı',
            'login_heading' => 'Kuruma özel başlık',
            'login_description' => 'Kuruma özel açıklama.',
            'login_security_note' => 'Kuruma özel güvenlik notu.',
            'is_active' => true,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee($institution->login_eyebrow)
            ->assertSee($institution->login_heading)
            ->assertSee($institution->login_description)
            ->assertSee($institution->login_security_note);
    }

    public function test_non_admin_cannot_update_institution_profile(): void
    {
        [$admin, $institution, $department, $role] = $this->makeAdminContext();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('admin.institution.update'), [
                'name' => 'Yetkisiz Değişiklik',
                'primary_color' => '#123456',
                'accent_color' => '#abcdef',
                'login_eyebrow' => 'Yetkisiz',
                'login_heading' => 'Yetkisiz',
                'login_security_note' => 'Yetkisiz',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('institutions', ['name' => 'Yetkisiz Değişiklik']);
    }

    private function makeAdminContext(): array
    {
        $institution = Institution::create([
            'short_name' => 'CTE',
            'name' => 'Ceza İnfaz Kurumu',
            'primary_color' => '#312e81',
            'accent_color' => '#7c3aed',
            'login_eyebrow' => 'Kurum içi yönetim sistemi',
            'login_heading' => 'Bilgiye hızlı erişim',
            'login_security_note' => 'Veriler kurum sunucusundan dışarı çıkmaz.',
            'is_active' => true,
        ]);

        $department = Department::create([
            'institution_id' => $institution->id,
            'code' => 'yonetim',
            'name' => 'Yönetim',
        ]);

        $adminRole = Role::create([
            'institution_id' => $institution->id,
            'code' => 'admin-test',
            'name' => 'Admin Test',
            'is_active' => true,
        ]);

        $standardRole = Role::create([
            'institution_id' => $institution->id,
            'code' => 'standart-test',
            'name' => 'Standart Test',
            'is_active' => true,
        ]);

        $permission = Permission::create([
            'key' => 'admin.manage',
            'module' => 'admin',
            'action' => 'manage',
            'label' => 'Yönetim — Yönet',
            'is_sensitive' => true,
        ]);
        $adminRole->syncPermissionsWithCacheClear([$permission->id]);

        $admin = User::factory()->create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $adminRole->id,
            'login_name' => 'admin-test',
            'is_active' => true,
            'is_protected_superadmin' => true,
        ]);

        return [$admin, $institution, $department, $standardRole];
    }
}
