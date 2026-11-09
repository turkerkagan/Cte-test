<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Institution;
use App\Models\MenuItem;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuModuleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_update_menu_item(): void
    {
        [$admin] = $this->makeContext();
        $permission = Permission::where('key', 'dashboard.view')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.menus.store'), [
            'parent_id' => null,
            'module_code' => 'dashboard',
            'title' => 'Ana Panel Menü',
            'icon' => '⌂',
            'route_name' => 'dashboard',
            'permission_key' => $permission->key,
            'sort_order' => 10,
            'is_active' => '1',
            'is_visible' => '1',
        ])->assertRedirect(route('admin.menus.index'));

        $menu = MenuItem::where('title', 'Ana Panel Menü')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.menus.update', $menu), [
            'parent_id' => null,
            'module_code' => 'dashboard',
            'title' => 'Ana Panel Güncel',
            'icon' => '⌂',
            'route_name' => 'dashboard',
            'permission_key' => $permission->key,
            'sort_order' => 20,
            'is_active' => '1',
            'is_visible' => '1',
        ])->assertRedirect(route('admin.menus.index'));

        $this->assertDatabaseHas('menu_items', [
            'id' => $menu->id,
            'title' => 'Ana Panel Güncel',
            'sort_order' => 20,
        ]);
    }

    public function test_unknown_route_name_is_rejected(): void
    {
        [$admin] = $this->makeContext();

        $this->actingAs($admin)->post(route('admin.menus.store'), [
            'title' => 'Hatalı Menü',
            'route_name' => 'olmayan.route',
            'sort_order' => 10,
            'is_active' => '1',
            'is_visible' => '1',
        ])->assertSessionHasErrors('route_name');
    }

    public function test_non_admin_cannot_manage_menu_items(): void
    {
        [, $user] = $this->makeContext();

        $this->actingAs($user)->get(route('admin.menus.index'))->assertForbidden();
    }

    public function test_menu_render_guard_respects_permission_visibility_and_route(): void
    {
        [$admin, $user] = $this->makeContext();

        $allowed = MenuItem::create([
            'title' => 'Ana Panel',
            'route_name' => 'dashboard',
            'permission_key' => 'dashboard.view',
            'sort_order' => 10,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $hidden = MenuItem::create([
            'title' => 'Gizli',
            'route_name' => 'dashboard',
            'permission_key' => 'dashboard.view',
            'sort_order' => 20,
            'is_active' => true,
            'is_visible' => false,
        ]);
        $badRoute = MenuItem::create([
            'title' => 'Bozuk Route',
            'route_name' => 'missing.route',
            'sort_order' => 30,
            'is_active' => true,
            'is_visible' => true,
        ]);

        $this->assertTrue($allowed->canBeRenderedFor($admin));
        $this->assertTrue($allowed->canBeRenderedFor($user));
        $this->assertFalse($hidden->canBeRenderedFor($admin));
        $this->assertFalse($badRoute->canBeRenderedFor($admin));
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
        $dashboard = Permission::create([
            'key' => 'dashboard.view',
            'module' => 'dashboard',
            'action' => 'view',
            'label' => 'Ana Panel — Görüntüle',
            'is_sensitive' => false,
        ]);
        $adminRole->syncPermissionsWithCacheClear([$adminManage->id, $dashboard->id]);
        $userRole->syncPermissionsWithCacheClear([$dashboard->id]);

        Module::create([
            'code' => 'dashboard',
            'name' => 'Ana Panel',
            'icon' => '⌂',
            'route_name' => 'dashboard',
            'permission_key' => 'dashboard.view',
            'is_active' => true,
            'is_visible' => true,
            'sort_order' => 10,
        ]);

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
            'is_protected_superadmin' => false,
        ]);

        return [$admin, $user];
    }
}
