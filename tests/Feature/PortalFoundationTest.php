<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_application_uses_turkish_cte_configuration(): void
    {
        $this->assertSame('CTE Portal', config('app.name'));
        $this->assertSame('tr', config('app.locale'));
        $this->assertSame('Europe/Istanbul', config('app.timezone'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/giris')->assertOk()->assertSee('Sicil No / Yönetici Adı');
    }

    public function test_protected_admin_can_login_with_login_name(): void
    {
        $this->post('/giris', ['identifier' => 'türker', 'password' => 'cte-test-admin-password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->get('/')->assertOk()->assertSee('Toplam Mevcut')->assertSee('Ana dosyalar');
    }

    public function test_dashboard_is_the_inmate_work_screen(): void
    {
        $admin = User::where('login_name', 'türker')->firstOrFail();

        $this->actingAs($admin)->get('/')
            ->assertOk()
            ->assertSee('Toplam Mevcut')
            ->assertSee('Bugün Yapılan Kayıt')
            ->assertSee('Ana dosyalar')
            ->assertSee('Sağlık')
            ->assertSee('Çalışma Yeri');
    }

    public function test_invalid_login_uses_generic_error(): void
    {
        $this->post('/giris', ['identifier' => 'bilinmeyen', 'password' => 'yanlis'])
            ->assertSessionHasErrors(['identifier']);
        $this->assertGuest();
    }

    public function test_personnel_can_login_by_number_and_inactive_session_is_closed(): void
    {
        $admin = User::where('login_name', 'türker')->firstOrFail();
        $user = User::create([
            'institution_id' => $admin->institution_id,
            'department_id' => $admin->department_id,
            'role_id' => Role::where('code', 'standart')->value('id'),
            'full_name' => 'Test Personeli',
            'personnel_number' => '123456',
            'password' => 'personel-parola',
            'is_active' => true,
        ]);

        $this->post('/giris', ['identifier' => '123456', 'password' => 'personel-parola'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $user->update(['is_active' => false]);
        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_superadmin_has_every_permission(): void
    {
        $admin = User::where('login_name', 'türker')->firstOrFail();
        $this->assertTrue($admin->hasPermission('inmates.delete'));
        $this->assertTrue($admin->is_protected_superadmin);
    }

    public function test_protected_admin_cannot_be_disabled_demoted_or_deleted(): void
    {
        $admin = User::where('login_name', 'türker')->firstOrFail();

        foreach ([['is_active' => false], ['is_protected_superadmin' => false], ['role_id' => Role::where('code', 'standart')->value('id')]] as $change) {
            try {
                $admin->update($change);
                $this->fail('Korunan yönetici değişikliği engellenmeliydi.');
            } catch (\LogicException) {
                $admin->refresh();
                $this->assertTrue($admin->is_active);
                $this->assertTrue($admin->is_protected_superadmin);
            }
        }

        $this->expectException(\LogicException::class);
        $admin->delete();
    }

    public function test_user_override_wins_over_role_permission(): void
    {
        $admin = User::where('login_name', 'türker')->firstOrFail();
        $user = User::create([
            'institution_id' => $admin->institution_id,
            'department_id' => $admin->department_id,
            'role_id' => Role::where('code', 'standart')->value('id'),
            'full_name' => 'Test Personeli',
            'personnel_number' => '999999',
            'password' => 'test-password',
            'is_active' => true,
        ]);
        $permission = Permission::where('key', 'inmates.view')->firstOrFail();
        $user->role->permissions()->syncWithoutDetaching($permission);
        $this->assertTrue($user->hasPermission('inmates.view'));

        UserPermissionOverride::create(['user_id' => $user->id, 'permission_id' => $permission->id, 'allowed' => false]);
        $this->assertFalse($user->hasPermission('inmates.view'));
        $this->actingAs($user)->get('/')->assertForbidden();
    }

    public function test_status_does_not_expose_sensitive_data(): void
    {
        $this->getJson('/status')->assertOk()
            ->assertJsonPath('application', 'CTE Portal')
            ->assertJsonStructure(['status', 'checks' => ['database', 'private_storage'], 'time'])
            ->assertDontSee('DB_PASSWORD');
    }
}
