<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_panel_and_permission_matrix(): void
    {
        [$admin] = $this->makeContext();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Admin Paneli')
            ->assertSee('Rol / İzin Matrisi')
            ->assertSee('admin.manage')
            ->assertSee('Sistem kontrolleri');
    }

    public function test_non_admin_cannot_view_panel(): void
    {
        [, $user] = $this->makeContext();

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
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
        $userRole = Role::create([
            'institution_id' => $institution->id,
            'code' => 'user-test',
            'name' => 'User Test',
            'is_active' => true,
        ]);

        $adminManage = Permission::create([
            'key' => 'admin.manage',
            'module' => 'admin',
            'action' => 'manage',
            'label' => 'Yönetim — Yönet',
            'is_sensitive' => true,
        ]);
        $dashboardView = Permission::create([
            'key' => 'dashboard.view',
            'module' => 'dashboard',
            'action' => 'view',
            'label' => 'Ana Panel — Görüntüle',
            'is_sensitive' => false,
        ]);

        $adminRole->syncPermissionsWithCacheClear([$adminManage->id, $dashboardView->id]);
        $userRole->syncPermissionsWithCacheClear([$dashboardView->id]);

        $admin = User::factory()->create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $adminRole->id,
            'is_active' => true,
            'is_protected_superadmin' => true,
        ]);
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $userRole->id,
            'is_active' => true,
        ]);

        return [$admin, $user];
    }
}
