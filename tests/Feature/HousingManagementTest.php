<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Block;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_block_ward_and_bed(): void
    {
        [$admin, $institution] = $this->makeContext();

        $this->actingAs($admin)->post(route('admin.housing.blocks.store'), [
            'institution_id' => $institution->id,
            'code' => 'a-blok',
            'name' => 'A Blok',
            'description' => 'Ana yerleşim bloğu',
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.housing.index'));

        $block = Block::where('code', 'a-blok')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.housing.wards.store'), [
            'block_id' => $block->id,
            'code' => 'a1',
            'name' => 'A-1 Koğuşu',
            'capacity' => 12,
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.housing.index'));

        $ward = Ward::where('code', 'a1')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.housing.beds.store'), [
            'ward_id' => $ward->id,
            'code' => 'r1',
            'label' => 'Ranza 1',
            'sort_order' => 10,
            'is_active' => '1',
        ])->assertRedirect(route('admin.housing.index'));

        $this->assertDatabaseHas('beds', ['ward_id' => $ward->id, 'code' => 'r1', 'label' => 'Ranza 1']);
    }

    public function test_duplicate_bed_code_in_same_ward_is_rejected(): void
    {
        [$admin, $institution] = $this->makeContext();
        $block = Block::create(['institution_id' => $institution->id, 'code' => 'b', 'name' => 'B Blok', 'is_active' => true]);
        $ward = Ward::create(['block_id' => $block->id, 'code' => 'b1', 'name' => 'B1', 'capacity' => 2, 'is_active' => true]);
        Bed::create(['ward_id' => $ward->id, 'code' => 'r1', 'label' => 'Ranza 1', 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.housing.beds.store'), [
            'ward_id' => $ward->id,
            'code' => 'r1',
            'label' => 'Ranza 1 Tekrar',
            'sort_order' => 20,
            'is_active' => '1',
        ])->assertSessionHasErrors('code');
    }

    public function test_non_admin_cannot_manage_housing(): void
    {
        [, , $user] = $this->makeContext();

        $this->actingAs($user)->get(route('admin.housing.index'))->assertForbidden();
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

        return [$admin, $institution, $user];
    }
}
