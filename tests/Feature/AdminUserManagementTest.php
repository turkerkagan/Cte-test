<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user(): void
    {
        [$admin, $institution, $department, $role] = $this->makeAdminContext();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'full_name' => 'Yeni Personel',
            'personnel_number' => '123456',
            'login_name' => null,
            'email' => 'personel@example.test',
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'password' => 'Parola12345',
            'password_confirmation' => 'Parola12345',
            'is_active' => '1',
            'must_change_password' => '1',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', [
            'full_name' => 'Yeni Personel',
            'personnel_number' => '123456',
            'is_active' => true,
            'must_change_password' => true,
        ]);
    }

    public function test_admin_can_update_user_and_deactivate_user_sessions(): void
    {
        [$admin, $institution, $department, $role] = $this->makeAdminContext();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'full_name' => 'Eski Ad',
            'personnel_number' => '654321',
            'is_active' => true,
        ]);
        DB::table('sessions')->insert([
            'id' => 'session-to-delete',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)->put(route('admin.users.update', $user), [
            'full_name' => 'Yeni Ad',
            'personnel_number' => '654321',
            'login_name' => 'yeniad',
            'email' => null,
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'is_active' => '1',
            'must_change_password' => '0',
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'full_name' => 'Yeni Ad', 'login_name' => 'yeniad']);

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $user))->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
        $this->assertDatabaseMissing('sessions', ['id' => 'session-to-delete']);
    }

    public function test_admin_can_update_user_password_and_clear_sessions(): void
    {
        [$admin, $institution, $department, $role] = $this->makeAdminContext();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
        ]);
        DB::table('sessions')->insert([
            'id' => 'password-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin)->patch(route('admin.users.password.update', $user), [
            'password' => 'YeniParola123',
            'password_confirmation' => 'YeniParola123',
            'must_change_password' => '1',
        ])->assertRedirect();

        $user->refresh();
        $this->assertTrue(Hash::check('YeniParola123', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseMissing('sessions', ['id' => 'password-session']);
    }

    public function test_protected_admin_cannot_be_deactivated(): void
    {
        [$admin] = $this->makeAdminContext();

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $admin))->assertServerError();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_permission_dependencies_add_view_and_view_deny_closes_module(): void
    {
        [$admin, $institution, $department, $role] = $this->makeAdminContext();
        $view = Permission::create(['key' => 'education.view', 'module' => 'education', 'action' => 'view', 'label' => 'Eğitim görüntüle']);
        $update = Permission::create(['key' => 'education.update', 'module' => 'education', 'action' => 'update', 'label' => 'Eğitim düzenle']);
        $user = User::factory()->create(['institution_id' => $institution->id, 'department_id' => $department->id, 'role_id' => $role->id]);
        $payload = [
            'full_name' => $user->full_name,
            'personnel_number' => $user->personnel_number,
            'login_name' => $user->login_name,
            'email' => $user->email,
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'is_active' => '1',
            'permissions' => [$update->id => 'allow'],
        ];

        $this->actingAs($admin)->put(route('admin.users.update', $user), $payload)->assertRedirect();
        $this->assertDatabaseHas('user_permission_overrides', ['user_id' => $user->id, 'permission_id' => $view->id, 'allowed' => true]);
        $this->assertDatabaseHas('user_permission_overrides', ['user_id' => $user->id, 'permission_id' => $update->id, 'allowed' => true]);

        $payload['permissions'] = [$view->id => 'deny', $update->id => 'allow'];
        $this->actingAs($admin)->put(route('admin.users.update', $user), $payload)->assertRedirect();
        $this->assertDatabaseHas('user_permission_overrides', ['user_id' => $user->id, 'permission_id' => $view->id, 'allowed' => false]);
        $this->assertDatabaseHas('user_permission_overrides', ['user_id' => $user->id, 'permission_id' => $update->id, 'allowed' => false]);
    }

    private function makeAdminContext(): array
    {
        $institution = Institution::create([
            'short_name' => 'CTE',
            'name' => 'Ceza İnfaz Kurumu',
            'is_active' => true,
        ]);

        $department = Department::create([
            'institution_id' => $institution->id,
            'code' => 'yonetim',
            'name' => 'Yönetim',
        ]);

        $role = Role::create([
            'institution_id' => $institution->id,
            'code' => 'admin-test',
            'name' => 'Admin Test',
            'is_active' => true,
        ]);

        $permission = Permission::create([
            'key' => 'admin.manage',
            'module' => 'admin',
            'action' => 'manage',
            'label' => 'Yönetim — Yönet',
            'is_sensitive' => true,
        ]);
        $role->syncPermissionsWithCacheClear([$permission->id]);

        $admin = User::factory()->create([
            'institution_id' => $institution->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'login_name' => 'admin-test',
            'is_active' => true,
            'is_protected_superadmin' => true,
        ]);

        return [$admin, $institution, $department, $role];
    }
}
