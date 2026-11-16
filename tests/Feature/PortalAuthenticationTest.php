<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\InitialAdministrator;
use App\Services\Security\PortalLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class PortalAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'synthetic-password-123';

    private function account(array $attributes = []): User
    {
        return User::factory()->create(['username' => 'synthetic_user', 'password' => self::PASSWORD] + $attributes)->refresh();
    }

    public function test_initial_admin_is_created_once_without_any_demo_organization(): void
    {
        $installer = app(InitialAdministrator::class);
        $user = $installer->create('synthetic_admin', self::PASSWORD, 'SENTETİK YÖNETİCİ');
        $this->assertTrue($user->is_system_admin);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('job_titles', 0);
        $this->assertNotNull(DB::table('portal_installations')->value('installed_at'));
        $user->delete();
        $this->expectException(RuntimeException::class);
        $installer->create('another_admin', self::PASSWORD);
    }

    public function test_nonempty_database_cannot_be_bootstrapped(): void
    {
        $this->account();
        try {
            app(InitialAdministrator::class)->create('new_admin', self::PASSWORD);
            $this->fail('Nonempty installation accepted');
        } catch (RuntimeException $error) {
            $this->assertDatabaseCount('users', 1);
            $this->assertNull(DB::table('portal_installations')->value('installed_at'));
        }
    }

    public function test_cli_refuses_unattended_default_credentials(): void
    {
        $this->artisan('portal:install-admin', ['username' => 'synthetic_admin', '--no-interaction' => true])
            ->expectsOutputToContain('etkileşimli terminal')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_initial_password_cannot_be_shorter_than_six_characters_or_truncated_by_bcrypt(): void
    {
        foreach (['12345', str_repeat('İ', 40)] as $password) {
            try {
                app(InitialAdministrator::class)->create('synthetic_admin', $password);
                $this->fail('Unsafe initial password accepted');
            } catch (ValidationException $error) {
                $this->assertDatabaseCount('users', 0);
                $this->assertNull(DB::table('portal_installations')->value('installed_at'));
            }
        }
    }

    public function test_initial_admin_with_six_character_password_can_log_in(): void
    {
        $user = app(InitialAdministrator::class)->create('synthetic_admin', 'six123');
        $this->assertTrue(Hash::check('six123', $user->password));
        $this->get('/giris')->assertInertia(fn (Assert $page) => $page->where('setupRequired', false));
        $this->post('/giris', ['username' => $user->username, 'password' => 'six123'])
            ->assertRedirect(route('portal'));
        $this->assertAuthenticatedAs($user);
        $this->get('/portal')->assertOk();
    }

    public function test_live_csrf_protection_rejects_login_without_token(): void
    {
        $this->app->instance('env', 'production');
        $this->post('/giris', ['username' => 'synthetic_user', 'password' => self::PASSWORD])->assertStatus(419);
        $this->assertGuest();
        $this->get('/cikis')->assertStatus(405);
    }

    public function test_login_page_and_protected_portal_do_not_leak_account_records(): void
    {
        $this->get('/portal')->assertRedirect(route('login'));
        $this->get('/giris')->assertInertia(fn (Assert $page) => $page->component('Login')->missing('users')->missing('password'));
    }

    public function test_unconfigured_or_empty_target_shows_setup_state_without_sql_details(): void
    {
        $this->get('/giris')->assertInertia(fn (Assert $page) => $page->where('setupRequired', true));
        $connection = config('database.default');
        try {
            config(['database.default' => 'unconfigured']);
            $this->get('/giris')->assertOk()->assertInertia(fn (Assert $page) => $page->where('setupRequired', true));
            $this->post('/giris', ['username' => 'synthetic_user', 'password' => self::PASSWORD])
                ->assertSessionHasErrors(['username' => 'İlk kurulum tamamlanmamış. Kurulum sorumlusuna başvurun.']);
        } finally {
            config(['database.default' => $connection]);
        }
    }

    public function test_login_sets_session_version_and_logout_invalidates_authentication(): void
    {
        $user = $this->account(['session_version' => 3]);
        $this->post('/giris', ['username' => $user->username, 'password' => self::PASSWORD])
            ->assertRedirect(route('portal'))->assertSessionHas('auth_session_version', 3);
        $this->assertAuthenticatedAs($user);
        $this->get('/portal')->assertOk();
        $this->post('/cikis')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_failed_login_counts_lock_and_expiry_are_atomic(): void
    {
        $user = $this->account();
        $login = app(PortalLogin::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($login->verify($user->username, 'wrong'));
        }
        $this->assertSame(5, $user->fresh()->failed_login_attempts);
        $this->assertNotNull($user->fresh()->locked_at);
        $this->assertNull($login->verify($user->username, self::PASSWORD));
        $this->travel(16)->minutes();
        $this->assertNotNull($login->verify($user->username, self::PASSWORD));
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
        $this->assertNull($user->fresh()->locked_at);
    }

    public function test_inactive_and_unknown_accounts_have_the_same_generic_error(): void
    {
        $user = $this->account(['is_active' => false]);
        foreach ([$user->username, 'not_existing'] as $username) {
            $this->post('/giris', ['username' => $username, 'password' => self::PASSWORD])
                ->assertSessionHasErrors(['username' => 'Kullanıcı adı veya parola doğrulanamadı.']);
            $this->assertGuest();
        }
    }

    public function test_changed_session_version_and_deactivation_revoke_sessions(): void
    {
        $user = $this->account(['session_version' => 2]);
        $this->actingAs($user)->withSession(['auth_session_version' => 1])->get('/portal')->assertRedirect(route('login'));
        $this->assertGuest();
        $user->is_active = false;
        $user->save();
        $this->actingAs($user)->withSession(['auth_session_version' => 2])->get('/portal')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_force_change_is_not_cleared_by_invalid_password_submission(): void
    {
        $user = $this->account(['must_change_password' => true]);
        $this->actingAs($user)->withSession(['auth_session_version' => 0])->get('/portal')->assertRedirect(route('password.edit'));
        $this->get('/parola')->assertInertia(fn (Assert $page) => $page->component('Password')->missing('password'));
        $this->post('/parola', ['current_password' => 'wrong', 'password' => 'new-synthetic-password', 'password_confirmation' => 'new-synthetic-password'])
            ->assertSessionHasErrors('current_password');
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->session_version);
    }

    public function test_password_change_updates_hash_and_keeps_current_session_with_new_version(): void
    {
        $user = $this->account(['must_change_password' => true]);
        $this->actingAs($user)->withSession(['auth_session_version' => 0])
            ->post('/parola', ['current_password' => self::PASSWORD, 'password' => 'six123', 'password_confirmation' => 'six123'])
            ->assertRedirect(route('portal'))->assertSessionHas('auth_session_version', 1);
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('six123', $user->fresh()->password));
        Auth::forgetGuards();
        $this->actingAs($user->fresh())->withSession(['auth_session_version' => 1])->get('/portal')->assertOk();
    }

    public function test_ip_rate_limit_blocks_unknown_username_flood(): void
    {
        $this->account();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/giris', ['username' => 'unknown_'.$i, 'password' => 'wrong'])->assertStatus(302);
        }
        $this->post('/giris', ['username' => 'unknown_11', 'password' => 'wrong'])->assertStatus(429);
    }
}
