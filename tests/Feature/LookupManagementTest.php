<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Institution;
use App\Models\LookupGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LookupManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_group_and_item(): void
    {
        [$admin] = $this->makeContext();

        $this->actingAs($admin)->post(route('admin.lookups.groups.store'), [
            'code' => 'risk_status',
            'name' => 'Risk Durumu',
            'description' => 'Risk sınıflandırmaları',
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.lookups.index'));

        $group = LookupGroup::where('code', 'risk_status')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.lookups.items.store', $group), [
            'code' => 'high',
            'label' => 'Yüksek Risk',
            'color' => '#b4233b',
            'description' => 'Yakın takip gerektirir.',
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.lookups.index'));

        $this->assertDatabaseHas('definition_items', [
            'definition_group_id' => $group->id,
            'code' => 'high',
            'label' => 'Yüksek Risk',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_update_and_toggle_lookup_item(): void
    {
        [$admin] = $this->makeContext();
        $group = LookupGroup::create(['code' => 'decision', 'name' => 'Karar', 'sort_order' => 10, 'is_active' => true]);
        $item = $group->items()->create(['code' => 'approved', 'label' => 'Onaylandı', 'sort_order' => 10, 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.lookups.items.update', [$group, $item]), [
            'code' => 'rejected',
            'label' => 'Reddedildi',
            'color' => '#312e81',
            'description' => 'Uygun görülmedi.',
            'sort_order' => 20,
            'is_active' => '1',
        ])->assertRedirect(route('admin.lookups.index'));

        $this->assertDatabaseHas('definition_items', ['id' => $item->id, 'code' => 'rejected', 'label' => 'Reddedildi']);

        $this->actingAs($admin)->patch(route('admin.lookups.items.toggle', [$group, $item]))->assertRedirect();
        $this->assertDatabaseHas('definition_items', ['id' => $item->id, 'is_active' => false]);
    }

    public function test_non_admin_cannot_manage_lookups(): void
    {
        [, $user] = $this->makeContext();

        $this->actingAs($user)->get(route('admin.lookups.index'))->assertForbidden();
    }

    public function test_duplicate_item_code_in_same_group_is_rejected(): void
    {
        [$admin] = $this->makeContext();
        $group = LookupGroup::create(['code' => 'visit_type', 'name' => 'Ziyaret Türü', 'sort_order' => 10, 'is_active' => true]);
        $group->items()->create(['code' => 'open', 'label' => 'Açık', 'sort_order' => 10, 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.lookups.items.store', $group), [
            'code' => 'open',
            'label' => 'Açık Ziyaret',
            'sort_order' => 20,
            'is_active' => '1',
        ])->assertSessionHasErrors('code');
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

        $department = Department::create(['institution_id' => $institution->id, 'code' => 'yonetim', 'name' => 'Yönetim']);
        $adminRole = Role::create(['institution_id' => $institution->id, 'code' => 'admin-test', 'name' => 'Admin Test', 'is_active' => true]);
        $userRole = Role::create(['institution_id' => $institution->id, 'code' => 'user-test', 'name' => 'User Test', 'is_active' => true]);

        $adminManage = Permission::create(['key' => 'admin.manage', 'module' => 'admin', 'action' => 'manage', 'label' => 'Yönetim — Yönet', 'is_sensitive' => true]);
        $adminRole->syncPermissionsWithCacheClear([$adminManage->id]);

        $admin = User::factory()->create(['institution_id' => $institution->id, 'department_id' => $department->id, 'role_id' => $adminRole->id, 'is_active' => true, 'is_protected_superadmin' => true]);
        $user = User::factory()->create(['institution_id' => $institution->id, 'department_id' => $department->id, 'role_id' => $userRole->id, 'is_active' => true]);

        return [$admin, $user];
    }
}
