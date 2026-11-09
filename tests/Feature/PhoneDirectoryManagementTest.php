<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\PhoneDirectoryEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneDirectoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_search_and_toggle_entry(): void
    {
        [$admin, $institution, $department] = $this->makeContext();

        $this->actingAs($admin)->post(route('admin.phone-directory.store'), [
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'title' => 'Revir Nöbetçi',
            'person_name' => 'Test Personel',
            'internal_number' => '1234',
            'external_number' => '',
            'mobile_number' => '',
            'email' => 'revir@example.test',
            'notes' => 'Mesai içi',
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.phone-directory.index'));

        $entry = PhoneDirectoryEntry::where('title', 'Revir Nöbetçi')->firstOrFail();
        $this->assertDatabaseHas('phone_directory_entries', ['id' => $entry->id, 'internal_number' => '1234']);

        $this->actingAs($admin)->get(route('admin.phone-directory.index', ['q' => 'Revir']))->assertOk()->assertSee('Revir Nöbetçi');

        $this->actingAs($admin)->put(route('admin.phone-directory.update', $entry), [
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'title' => 'Revir Güncel',
            'person_name' => 'Güncel Personel',
            'internal_number' => '4321',
            'external_number' => '',
            'mobile_number' => '',
            'email' => '',
            'notes' => '',
            'sort_order' => 20,
            'is_active' => '1',
        ])->assertRedirect(route('admin.phone-directory.index'));

        $this->assertDatabaseHas('phone_directory_entries', ['id' => $entry->id, 'title' => 'Revir Güncel', 'internal_number' => '4321']);

        $this->actingAs($admin)->patch(route('admin.phone-directory.toggle', $entry))->assertRedirect();
        $this->assertDatabaseHas('phone_directory_entries', ['id' => $entry->id, 'is_active' => false]);
    }

    public function test_phone_directory_requires_contact_value(): void
    {
        [$admin, $institution, $department] = $this->makeContext();

        $this->actingAs($admin)->post(route('admin.phone-directory.store'), [
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'title' => 'Eksik Kayıt',
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertSessionHasErrors('internal_number');
    }

    public function test_non_admin_cannot_manage_phone_directory(): void
    {
        [, , , $user] = $this->makeContext();

        $this->actingAs($user)->get(route('admin.phone-directory.index'))->assertForbidden();
    }

    private function makeContext(): array
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
        $department = Department::create(['institution_id' => $institution->id, 'code' => 'revir', 'name' => 'Revir']);
        $adminRole = Role::create(['institution_id' => $institution->id, 'code' => 'admin-test', 'name' => 'Admin Test', 'is_active' => true]);
        $userRole = Role::create(['institution_id' => $institution->id, 'code' => 'user-test', 'name' => 'User Test', 'is_active' => true]);
        $adminManage = Permission::create(['key' => 'admin.manage', 'module' => 'admin', 'action' => 'manage', 'label' => 'Yönetim — Yönet', 'is_sensitive' => true]);
        $adminRole->syncPermissionsWithCacheClear([$adminManage->id]);
        $admin = User::factory()->create(['institution_id' => $institution->id, 'department_id' => $department->id, 'role_id' => $adminRole->id, 'is_active' => true, 'is_protected_superadmin' => true]);
        $user = User::factory()->create(['institution_id' => $institution->id, 'department_id' => $department->id, 'role_id' => $userRole->id, 'is_active' => true]);

        return [$admin, $institution, $department, $user];
    }
}
