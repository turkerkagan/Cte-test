<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\PermissionCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionGateAndCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_gate_uses_role_permission(): void
    {
        [$user, $role, $permission] = $this->makeUserRoleAndPermission();

        $this->assertFalse(Gate::forUser($user)->allows('permission', 'dashboard.view'));

        $role->syncPermissionsWithCacheClear([$permission->id]);

        $this->assertTrue(Gate::forUser($user->fresh())->allows('permission', 'dashboard.view'));
    }

    public function test_user_permission_override_clears_cached_permission_result(): void
    {
        [$user, , $permission] = $this->makeUserRoleAndPermission();

        $this->assertFalse(Gate::forUser($user)->allows('permission', 'dashboard.view'));

        $override = UserPermissionOverride::create([
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'allowed' => true,
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('permission', 'dashboard.view'));

        $override->update(['allowed' => false]);

        $this->assertFalse(Gate::forUser($user)->allows('permission', 'dashboard.view'));
    }

    public function test_protected_active_superadmin_passes_permission_gate(): void
    {
        [$user] = $this->makeUserRoleAndPermission([
            'is_protected_superadmin' => true,
        ]);

        $this->assertTrue(Gate::forUser($user)->allows('permission', 'admin.manage'));
    }

    public function test_inactive_user_cannot_use_role_permission(): void
    {
        [$user, $role, $permission] = $this->makeUserRoleAndPermission([
            'is_active' => false,
        ]);

        $role->syncPermissionsWithCacheClear([$permission->id]);

        $this->assertFalse(Gate::forUser($user->fresh())->allows('permission', 'dashboard.view'));
    }

    /**
     * @return array{0: User, 1: Role, 2: Permission}
     */
    private function makeUserRoleAndPermission(array $userAttributes = []): array
    {
        PermissionCache::flushAll();

        $institution = Institution::create([
            'short_name' => 'CTE',
            'name' => 'Ceza İnfaz Kurumu',
            'is_active' => true,
        ]);

        $role = Role::create([
            'institution_id' => $institution->id,
            'code' => 'test-role',
            'name' => 'Test Rolü',
            'is_system' => false,
        ]);

        $permission = Permission::create([
            'key' => 'dashboard.view',
            'module' => 'dashboard',
            'action' => 'view',
            'label' => 'Ana Panel — Görüntüle',
            'is_sensitive' => false,
        ]);

        $user = User::factory()->create(array_merge([
            'institution_id' => $institution->id,
            'role_id' => $role->id,
            'is_active' => true,
            'is_protected_superadmin' => false,
        ], $userAttributes));

        return [$user, $role, $permission];
    }
}
