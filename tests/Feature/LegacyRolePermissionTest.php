<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyRolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_unit_roles_receive_only_their_expected_write_modules(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expectations = [
            'infaz' => ['execution', 'leave', 'notification', 'referral'],
            'infaz2' => ['execution', 'leave', 'notification', 'referral'],
            'egitim' => ['education'],
            'revir' => ['health'],
            'ziyaret' => ['visit'],
            'manevi' => ['spiritual'],
            'psikososyal' => ['psychosocial'],
            'mesem' => ['mesem'],
        ];

        foreach ($expectations as $roleCode => $modules) {
            $role = Role::where('code', $roleCode)->firstOrFail();
            foreach ($modules as $module) {
                $this->assertTrue($role->permissions()->where('key', $module.'.view')->exists(), "{$roleCode} {$module}.view eksik");
                $this->assertTrue($role->permissions()->where('key', $module.'.update')->exists(), "{$roleCode} {$module}.update eksik");
            }
        }

        $this->assertFalse(Role::where('code', 'egitim')->firstOrFail()->permissions()->where('key', 'health.view')->exists());
        $this->assertFalse(Role::where('code', 'revir')->firstOrFail()->permissions()->where('key', 'education.update')->exists());
        $this->assertFalse(Role::where('code', 'standart')->firstOrFail()->permissions()->whereIn('action', ['create', 'update', 'delete'])->exists());
    }

    public function test_all_legacy_roles_can_view_home_and_common_pages(): void
    {
        $this->seed(DatabaseSeeder::class);
        $roleCodes = ['superadmin', 'basmemur', 'sbasmemur', 'idari', 'infaz', 'infaz2', 'egitim', 'revir', 'ziyaret', 'manevi', 'standart'];

        foreach ($roleCodes as $roleCode) {
            $role = Role::where('code', $roleCode)->firstOrFail();
            $this->assertTrue($role->permissions()->where('key', 'inmates.view')->exists());
            $this->assertTrue($role->permissions()->where('key', 'announcement.view')->exists());
            $this->assertTrue($role->permissions()->where('key', 'phone.view')->exists());
        }

        $this->assertSame(0, Permission::whereNull('module')->orWhereNull('action')->count());
    }
}
