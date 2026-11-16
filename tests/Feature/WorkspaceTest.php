<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflows\CaseFields;
use App\Services\Workspace\DutyScenarios;
use App\Services\Workspace\HandoverSummary;
use App\Services\Workspace\PermissionSimulation;
use App\Services\Workspace\WorkspaceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function login(bool $admin = true): User
    {
        $user = User::factory()->create(['is_system_admin' => $admin])->refresh();
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);

        return $user;
    }

    public function test_management_panels_do_not_allow_staff_and_never_return_credentials(): void
    {
        $staff = $this->login(false);
        $this->getJson('/calisma-merkezi/yetkiler?user='.$staff->id)->assertForbidden();
        $this->getJson('/calisma-merkezi/veri-kalitesi')->assertForbidden();
        $this->login();
        $this->getJson('/calisma-merkezi/yetkiler?user='.$staff->id)->assertOk()
            ->assertJsonPath('selected.id', $staff->id)->assertJsonMissingPath('users.0.password')->assertJsonMissingPath('selected.password')->assertDontSee('remember_token');
    }

    public function test_quality_detects_duplicates_without_modifying_them_and_provenance_is_bounded(): void
    {
        $this->login();
        $person = DB::table('people')->insertGetId(['tc_identity' => '00000000001', 'full_name' => 'SENTETIK']);
        DB::table('people')->insert(['tc_identity' => '00000000001', 'full_name' => 'SENTETIK OTHER']);
        $this->getJson('/calisma-merkezi/veri-kalitesi')->assertOk()->assertJsonPath('issues.duplicate_identity.count', 2)->assertJsonCount(2, 'rows');
        $this->getJson('/calisma-merkezi/veri-kokeni/'.$person)->assertOk()->assertJsonPath('fields.0.source', 'people.full_name')->assertJsonPath('historyLimited', true);
        $this->assertDatabaseCount('people', 2);
    }

    public function test_handover_never_projects_another_users_events_or_sensitive_event_details(): void
    {
        $user = $this->login();
        $other = User::factory()->create();
        foreach ([$user->id, $other->id] as $id) {
            DB::table('audit_events')->insert(['actor_id' => $id, 'resource_type' => 'person', 'resource_id' => 1, 'action' => $id === $user->id ? 'mine' : 'other_user_event', 'details' => '{"secret":"SYNTHETIC_SECRET"}', 'created_at' => now()]);
        }
        $data = app(HandoverSummary::class)->read($user, []);
        $this->assertSame('mine', $data['activity'][0]->action);
        $this->assertStringNotContainsString('SYNTHETIC_SECRET', json_encode($data));
        $this->assertStringNotContainsString('other_user_event', json_encode($data));
    }

    public function test_scenarios_are_read_only_then_require_fresh_confirmation_to_apply(): void
    {
        $actor = $this->login();
        $department = DB::table('departments')->insertGetId(['code' => 'infaz', 'name' => 'SENTETIK', 'is_active' => true]);
        $one = User::factory()->create(['department_id' => $department, 'is_active' => true]);
        User::factory()->create(['department_id' => $department, 'is_active' => true]);
        $service = app(DutyScenarios::class);
        $input = ['from' => '2026-10-20', 'to' => '2026-10-22'];
        $before = DB::table('institution_records')->count();
        $preview = $service->preview($actor, 'infaznobet', $input);
        $this->assertDatabaseCount('institution_records', $before);
        $this->assertCount(2, $preview['scenarios']);
        DB::table('duty_unavailable')->insert(['user_id' => $one->id, 'date' => '2026-10-20', 'reason' => 'SENTETIK']);
        try {
            $service->apply($actor, 'infaznobet', $input + ['token' => $preview['token'], 'strategy' => 'balanced', 'confirmed' => true]);
            $this->fail('A stale scenario was applied.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $fresh = $service->preview($actor, 'infaznobet', $input);
        $this->assertNotSame($one->id, $fresh['scenarios'][0]['rows'][0]['user_id']);
        $result = $service->apply($actor, 'infaznobet', $input + ['token' => $fresh['token'], 'strategy' => 'balanced', 'confirmed' => true]);
        $this->assertSame(3, $result['created']);
    }

    public function test_workspace_rejects_guest_stale_and_password_change_accounts(): void
    {
        $user = $this->login();
        foreach ([['is_guest' => true], ['must_change_password' => true], ['is_active' => false], ['session_version' => $user->session_version + 1]] as $change) {
            DB::table('users')->where('id', $user->id)->update($change);
            try {
                app(WorkspaceAccess::class)->actor($user);
                $this->fail('Unusable account entered workspace.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
            DB::table('users')->where('id', $user->id)->update(['is_guest' => false, 'must_change_password' => false, 'is_active' => true, 'session_version' => $user->session_version]);
        }
    }

    public function test_archived_workflows_do_not_break_handover_and_inactive_user_has_no_access(): void
    {
        $actor = $this->login();
        foreach (['izin', 'infaz', 'mesajlar'] as $code) {
            DB::table('portal_pages')->insert(['code' => $code, 'name' => $code, 'path' => '/'.$code]);
        }
        DB::table('portal_pages')->whereIn('code', ['izin', 'infaz', 'mesajlar'])->update(['is_active' => false]);
        $this->get('/calisma-merkezi')->assertOk();
        $inactive = User::factory()->create(['is_active' => false]);
        $data = app(PermissionSimulation::class)->read($actor, ['user' => $inactive->id]);
        $this->assertNotEmpty($data['pages']);
        foreach ($data['pages'] as $page) {
            $this->assertFalse($page['effective']['giris']);
            $this->assertFalse($page['effective']['yazma']);
        }
    }

    public function test_quality_paginates_and_never_exposes_secret_fields_in_provenance(): void
    {
        $this->login();
        for ($index = 0; $index < 53; $index++) {
            DB::table('people')->insert(['full_name' => 'SENTETIK '.$index]);
        }
        $this->getJson('/calisma-merkezi/veri-kalitesi?issue=missing_identity&page=2')->assertOk()->assertJsonCount(3, 'rows')->assertJsonPath('pages', 2);
        $person = (int) DB::table('people')->min('id');
        $response = $this->getJson('/calisma-merkezi/veri-kokeni/'.$person)->assertOk();
        $secrets = array_keys(array_filter(app(CaseFields::class)->all(), fn ($field) => $field['secret'] ?? false));
        $keys = array_column($response->json('fields'), 'key');
        $this->assertSame([], array_values(array_intersect($keys, $secrets)));
        $this->getJson('/calisma-merkezi/veri-kalitesi?issue=unknown')->assertUnprocessable();
    }

    public function test_duty_scenarios_require_confirmation_and_reject_replay_and_revoked_write(): void
    {
        $actor = $this->login();
        $department = DB::table('departments')->insertGetId(['code' => 'infaz', 'name' => 'SENTETIK', 'is_active' => true]);
        User::factory()->create(['department_id' => $department]);
        $url = '/calisma-merkezi/nobet-senaryolari/infaznobet';
        $input = ['from' => '2026-11-01', 'to' => '2026-11-02'];
        $token = $this->postJson($url, $input + ['action' => 'preview'])->assertOk()->json('token');
        $apply = $input + ['action' => 'apply', 'token' => $token, 'strategy' => 'balanced'];
        $this->postJson($url, $apply)->assertUnprocessable();
        $this->postJson($url, $apply + ['confirmed' => true])->assertOk()->assertJsonPath('created', 2);
        $this->postJson($url, $apply + ['confirmed' => true])->assertConflict();
        $fresh = $this->postJson($url, $input + ['action' => 'preview'])->assertOk()->json('token');
        DB::table('portal_pages')->insert(['code' => 'infaznobet', 'name' => 'SENTETIK', 'path' => '/infaznobet']);
        DB::table('portal_pages')->where('code', 'infaznobet')->update(['is_active' => false]);
        $this->postJson($url, array_replace($apply, ['token' => $fresh, 'confirmed' => true]))->assertForbidden();
        $this->assertSame(2, DB::table('institution_records')->where('kind', 'infaznobet')->count());
    }

    public function test_rehearsal_is_admin_only_requires_confirmation_and_never_runs_on_sqlite(): void
    {
        $this->login(false);
        $this->getJson('/calisma-merkezi/yedek-provalari')->assertForbidden();
        $this->login();
        $this->getJson('/calisma-merkezi/yedek-provalari')->assertOk()->assertJsonPath('supported', false);
        $this->postJson('/calisma-merkezi/yedek-provalari', ['backup' => 1])->assertUnprocessable();
        $this->postJson('/calisma-merkezi/yedek-provalari', ['backup' => 1, 'confirmed' => true])->assertUnprocessable();
    }
}
