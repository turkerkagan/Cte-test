<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administration\UserAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($user)->withSession(['auth_session_version' => 0]);

        return $user;
    }

    private function payload(User $user, array $extra = []): array
    {
        return $extra + ['username' => $user->username, 'registry_number' => $user->registry_number, 'full_name' => $user->full_name,
            'email' => $user->email, 'phone' => $user->phone, 'department_id' => $user->department_id, 'job_title_id' => $user->job_title_id,
            'level' => $user->is_system_admin ? 'system_admin' : 'personnel', 'is_active' => $user->is_active, 'revision' => $user->revision];
    }

    private function definition(string $table, string $code, bool $active = true): int
    {
        return DB::table($table)->insertGetId(['code' => $code, 'name' => 'SENTETİK '.$code, 'is_active' => $active]);
    }

    public function test_guest_and_staff_cannot_access_any_administration_endpoint(): void
    {
        $this->get('/yonetim/kullanicilar')->assertRedirect(route('login'));
        $user = User::factory()->create()->refresh();
        $this->actingAs($user)->withSession(['auth_session_version' => 0]);
        foreach (['/yonetim/kullanicilar', '/yonetim/kullanicilar/yeni', '/yonetim/kullanicilar/'.$user->id] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/yonetim/kullanicilar')->assertForbidden();
        $this->put('/yonetim/kullanicilar/'.$user->id)->assertForbidden();
        $this->post('/yonetim/kullanicilar/'.$user->id.'/parola-sifirla')->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_title_and_level_are_independent_and_responses_never_expose_hash_or_tokens(): void
    {
        $admin = $this->admin();
        $title = $this->definition('job_titles', 'İnfaz Koruma Memuru');
        $admin->job_title_id = $title;
        $admin->save();
        $staff = User::factory()->create()->refresh();
        $this->get('/yonetim/kullanicilar')->assertInertia(fn (Assert $page) => $page->component('Users')->has('rows', 2)
            ->where('rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $admin->id && $row['job_title'] === 'SENTETİK İnfaz Koruma Memuru' && $row['level'] === 'Sistem Yöneticisi')
                && collect($rows)->contains(fn ($row) => $row['id'] === $staff->id && $row['level'] === 'Kurum Personeli')));
        $this->get('/yonetim/kullanicilar/'.$admin->id)->assertInertia(fn (Assert $page) => $page->component('UserEditor')
            ->missing('user.password')->missing('user.remember_token')->missing('user.session_version')->missing('events.0.details'));
    }

    public function test_create_hashes_initial_password_requires_change_and_ignores_privilege_overposting(): void
    {
        $this->admin();
        $this->post('/yonetim/kullanicilar', ['username' => 'synthetic_new', 'registry_number' => '000012', 'full_name' => 'SENTETİK KİŞİ',
            'level' => 'personnel', 'is_active' => true, 'password' => 'initial-synthetic-password', 'password_confirmation' => 'initial-synthetic-password',
            'is_system_admin' => true, 'must_change_password' => false, 'session_version' => 999])->assertRedirect();
        $user = User::query()->where('username', 'synthetic_new')->firstOrFail();
        $this->assertTrue(Hash::check('initial-synthetic-password', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertFalse($user->is_system_admin);
        $this->assertSame(0, $user->session_version);
        $this->assertSame('000012', $user->registry_number);
        $this->assertStringNotContainsString('initial-synthetic-password', DB::table('audit_events')->value('details'));
        $this->assertStringNotContainsString($user->password, DB::table('audit_events')->value('details'));
    }

    public function test_update_revokes_sessions_preserves_password_and_stale_update_is_rejected(): void
    {
        $this->admin();
        $user = User::factory()->create()->refresh();
        $title = $this->definition('job_titles', 'new');
        $data = $this->payload($user, ['job_title_id' => $title, 'level' => 'system_admin', 'password' => 'injected', 'must_change_password' => false]);
        $this->put('/yonetim/kullanicilar/'.$user->id, $data)->assertRedirect();
        $fresh = $user->fresh();
        $this->assertTrue($fresh->is_system_admin);
        $this->assertSame($title, $fresh->job_title_id);
        $this->assertSame($user->password, $fresh->password);
        $this->assertSame(1, $fresh->session_version);
        $this->assertSame(2, $fresh->revision);
        $this->put('/yonetim/kullanicilar/'.$user->id, $data)->assertSessionHasErrors('revision');
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_no_op_does_not_revoke_session_or_add_fake_audit_event(): void
    {
        $admin = $this->admin();
        $this->put('/yonetim/kullanicilar/'.$admin->id, $this->payload($admin))->assertRedirect(route('users.show', $admin->id));
        $this->assertSame(0, $admin->fresh()->session_version);
        $this->assertSame(1, $admin->fresh()->revision);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertAuthenticated();
    }

    public function test_last_active_admin_cannot_be_deactivated_or_demoted(): void
    {
        $admin = $this->admin();
        foreach ([['level' => 'personnel'], ['is_active' => false]] as $change) {
            $this->put('/yonetim/kullanicilar/'.$admin->id, $this->payload($admin, $change))->assertSessionHasErrors('level');
        }
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_system_admin);
        $this->assertDatabaseCount('audit_events', 0);
        User::factory()->create(['is_system_admin' => true]);
        $this->put('/yonetim/kullanicilar/'.$admin->id, $this->payload($admin, ['level' => 'personnel']))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse($admin->fresh()->is_system_admin);
    }

    public function test_missing_or_inactive_assignment_is_rejected_but_current_inactive_assignment_can_be_retained(): void
    {
        $this->admin();
        $inactive = $this->definition('departments', 'inactive', false);
        $user = User::factory()->create()->refresh();
        foreach ([$inactive, 9999] as $id) {
            $this->put('/yonetim/kullanicilar/'.$user->id, $this->payload($user, ['department_id' => $id]))->assertSessionHasErrors('department_id');
        }
        $user->department_id = $inactive;
        $user->save();
        $this->put('/yonetim/kullanicilar/'.$user->id, $this->payload($user, ['full_name' => 'SENTETİK YENİ']))->assertRedirect();
        $this->assertSame($inactive, $user->fresh()->department_id);
    }

    public function test_reset_requires_confirmation_and_current_revision(): void
    {
        $this->admin();
        $user = User::factory()->create()->refresh();
        $url = '/yonetim/kullanicilar/'.$user->id.'/parola-sifirla';
        $this->post($url, ['revision' => 1, 'confirmed' => false])->assertSessionHasErrors('confirmed');
        $this->post($url, ['revision' => 2, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])->assertSessionHasErrors('revision');
        $this->assertSame($user->password, $user->fresh()->password);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_reset_hash_force_change_old_session_rejection_and_new_password_flow(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['failed_login_attempts' => 5, 'locked_at' => now(), 'remember_token' => 'synthetic-token'])->refresh();
        $this->post('/yonetim/kullanicilar/'.$user->id.'/parola-sifirla', ['revision' => 1, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])->assertRedirect();
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('TemporaryPass!2026', $fresh->password));
        $this->assertNotSame('TemporaryPass!2026', $fresh->password);
        $this->assertTrue($fresh->must_change_password);
        $this->assertNull($fresh->locked_at);
        $this->assertSame(0, $fresh->failed_login_attempts);
        $this->assertNull($fresh->remember_token);
        $event = DB::table('audit_events')->first();
        $this->assertSame('password_reset', $event->action);
        $this->assertSame(['actor_username' => $admin->username, 'actor_name' => $admin->full_name, 'ip_address' => null], json_decode($event->details, true));
        Auth::forgetGuards();
        $this->actingAs($fresh)->withSession(['auth_session_version' => 0])->get('/portal')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/giris', ['username' => $fresh->username, 'password' => 'TemporaryPass!2026'])->assertRedirect(route('password.edit'));
        $this->get('/portal')->assertRedirect(route('password.edit'));
        $this->post('/parola', ['current_password' => 'TemporaryPass!2026', 'password' => 'new-synthetic-password', 'password_confirmation' => 'new-synthetic-password'])->assertRedirect(route('portal'));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertSame(2, $user->fresh()->session_version);
        $this->assertDatabaseHas('audit_events', ['resource_id' => $user->id, 'action' => 'password_changed']);
    }

    public function test_self_reset_logs_out_and_keeps_account_recoverable(): void
    {
        $admin = $this->admin();
        $this->post('/yonetim/kullanicilar/'.$admin->id.'/parola-sifirla', ['revision' => 1, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_system_admin);
    }

    public function test_audit_failure_rolls_back_reset_and_user_update(): void
    {
        $this->admin();
        $user = User::factory()->create()->refresh();
        DB::statement("CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic'); END");
        $this->post('/yonetim/kullanicilar/'.$user->id.'/parola-sifirla', ['revision' => 1, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])->assertSessionHasErrors('operation');
        $this->put('/yonetim/kullanicilar/'.$user->id, $this->payload($user, ['full_name' => 'CHANGED']))->assertSessionHasErrors('operation');
        $this->assertSame($user->password, $user->fresh()->password);
        $this->assertSame($user->full_name, $user->fresh()->full_name);
        $this->assertSame(0, $user->fresh()->session_version);
    }

    public function test_service_rechecks_revoked_administrator_and_rejects_overposted_level(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create()->refresh();
        $this->put('/yonetim/kullanicilar/'.$user->id, $this->payload($user, ['level' => 'superuser']))->assertSessionHasErrors('level');
        DB::table('users')->where('id', $admin->id)->update(['is_system_admin' => false]);
        $this->expectException(AuthorizationException::class);
        app(UserAdministration::class)->resetPassword($admin, $user->id, ['revision' => 1, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026']);
    }

    public function test_modal_json_reads_and_writes_use_same_rules_and_never_expose_credentials(): void
    {
        $this->admin();
        $this->getJson('/yonetim/kullanicilar/yeni')->assertOk()->assertJsonPath('user', null)->assertHeader('Cache-Control', 'no-store, private');
        $created = $this->postJson('/yonetim/kullanicilar', ['username' => 'modal_synthetic', 'full_name' => 'SENTETİK MODAL',
            'level' => 'personnel', 'is_active' => true, 'password' => 'synthetic-initial-password', 'password_confirmation' => 'synthetic-initial-password'])->assertOk();
        $user = User::findOrFail($created->json('id'));
        $this->getJson('/yonetim/kullanicilar/'.$user->id)->assertOk()->assertJsonPath('user.must_change_password', true)
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token')->assertJsonMissingPath('user.session_version')
            ->assertJsonPath('terminateUrl', route('users.terminate', $user->id));
        $this->putJson('/yonetim/kullanicilar/'.$user->id, $this->payload($user, ['full_name' => 'SENTETİK GÜNCEL']))->assertOk()->assertJsonPath('id', $user->id);
        $this->putJson('/yonetim/kullanicilar/'.$user->id, $this->payload($user))->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame('SENTETİK GÜNCEL', $user->fresh()->full_name);
    }

    public function test_terminate_sessions_preserves_password_and_login_state_but_revokes_old_sessions(): void
    {
        $this->admin();
        $user = User::factory()->create(['remember_token' => 'synthetic-token', 'failed_login_attempts' => 2])->refresh();
        $url = '/yonetim/kullanicilar/'.$user->id.'/oturumlari-sonlandir';
        $this->postJson($url, ['revision' => 1, 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson($url, ['revision' => 2, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson($url, ['revision' => 1, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])->assertOk()->assertJsonPath('id', $user->id);
        $fresh = $user->fresh();
        $this->assertSame($user->password, $fresh->password);
        $this->assertSame($user->must_change_password, $fresh->must_change_password);
        $this->assertSame(2, $fresh->failed_login_attempts);
        $this->assertNull($fresh->remember_token);
        $this->assertSame(1, $fresh->session_version);
        $this->assertSame(2, $fresh->revision);
        $this->assertDatabaseHas('audit_events', ['resource_type' => 'users', 'resource_id' => $user->id, 'action' => 'sessions_terminated']);
        Auth::forgetGuards();
        $this->actingAs($fresh)->withSession(['auth_session_version' => 0])->get('/portal')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_modal_custom_temporary_password_requires_policy_and_confirmation_and_is_not_audited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create()->refresh();
        $url = '/yonetim/kullanicilar/'.$user->id.'/parola-sifirla';
        $base = ['revision' => 1, 'confirmed' => true];
        $this->postJson($url, $base)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson($url, $base + ['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson($url, $base + ['password' => 'synthetic-temporary-password', 'password_confirmation' => 'mismatch'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($user->password, $user->fresh()->password);
        $this->postJson($url, $base + ['password' => 'six123', 'password_confirmation' => 'six123'])->assertOk();
        $this->assertTrue(Hash::check('six123', $user->fresh()->password));
        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertSame(2, $user->fresh()->revision);
        $details = json_decode(DB::table('audit_events')->value('details'), true);
        $this->assertSame($admin->username, $details['actor_username']);
        $this->assertArrayNotHasKey('password', $details);
        $this->assertStringNotContainsString('six123', json_encode($details));
    }

    public function test_self_termination_json_logs_out_and_returns_login_destination(): void
    {
        $admin = $this->admin();
        $this->postJson('/yonetim/kullanicilar/'.$admin->id.'/oturumlari-sonlandir', ['revision' => 1, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])
            ->assertOk()->assertJsonPath('redirect', route('login'));
        $this->assertGuest();
        $this->assertSame($admin->password, $admin->fresh()->password);
        $this->assertTrue($admin->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_system_admin);
    }

    public function test_termination_rejects_staff_and_rolls_back_on_audit_failure(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['remember_token' => 'synthetic-token'])->refresh();
        DB::statement("CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic'); END");
        $url = '/yonetim/kullanicilar/'.$user->id.'/oturumlari-sonlandir';
        $this->postJson($url, ['revision' => 1, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertSame(0, $user->fresh()->session_version);
        $this->assertSame(1, $user->fresh()->revision);
        $this->assertSame('synthetic-token', $user->fresh()->remember_token);
        $this->actingAs($user)->withSession(['auth_session_version' => 0])->postJson('/yonetim/kullanicilar/'.$admin->id.'/oturumlari-sonlandir', ['revision' => 1, 'confirmed' => true, 'password' => 'TemporaryPass!2026', 'password_confirmation' => 'TemporaryPass!2026'])->assertForbidden();
        $this->getJson('/yonetim/kullanicilar/'.$admin->id)->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }
}
