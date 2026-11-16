<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Access\PagePermissions;
use App\Services\Organization\OrganizationDefinitions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($user)->withSession(['auth_session_version' => 0]);

        return $user;
    }

    private function deleteDefinition(string $url, array $data = [])
    {
        if (preg_match('~^/yonetim/(birimler|unvanlar)/(\d+)$~', $url, $match)) {
            $service = app(OrganizationDefinitions::class);
            $impact = $service->permissionImpact($match[1], (int) $match[2]);
            $data += ['permission_count' => $impact['count'], 'permission_snapshot' => $impact['snapshot']];
            if (isset($data['target_id'])) {
                $target = $service->permissionImpact($match[1], (int) $data['target_id']);
                $data += ['target_permission_count' => $target['count'], 'target_permission_snapshot' => $target['snapshot']];
            }
        }

        return $this->delete($url, $data);
    }

    private function definition(string $table, string $code = 'synthetic', bool $active = true): int
    {
        return DB::table($table)->insertGetId(['code' => $code, 'name' => 'SENTETİK '.$code, 'is_active' => $active]);
    }

    public function test_guest_staff_and_inactive_admin_cannot_read_or_mutate_definitions(): void
    {
        $this->get('/yonetim/birimler')->assertRedirect(route('login'));
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        foreach (['birimler', 'unvanlar'] as $kind) {
            $this->get('/yonetim/'.$kind)->assertForbidden();
            $this->post('/yonetim/'.$kind, ['code' => 'bad', 'name' => 'bad', 'is_active' => true])->assertForbidden();
            $this->put('/yonetim/'.$kind.'/1')->assertForbidden();
            $this->deleteDefinition('/yonetim/'.$kind.'/1')->assertForbidden();
        }
        $staff->is_system_admin = true;
        $staff->is_active = false;
        $staff->save();
        $this->actingAs($staff)->get('/yonetim/birimler')->assertRedirect(route('login'));
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_database_definitions_can_be_created_edited_and_deleted_without_seeds(): void
    {
        $this->admin();
        foreach (['birimler' => 'departments', 'unvanlar' => 'job_titles'] as $kind => $table) {
            $url = '/yonetim/'.$kind;
            $this->post($url, ['code' => 'synthetic', 'name' => 'SENTETİK TANIM', 'is_active' => true, 'is_system_admin' => true])->assertRedirect();
            $row = DB::table($table)->first();
            $this->put($url.'/'.$row->id, ['code' => 'synthetic', 'name' => 'SENTETİK YENİ', 'is_active' => false, 'revision' => 1])->assertRedirect();
            $this->assertDatabaseHas($table, ['id' => $row->id, 'name' => 'SENTETİK YENİ', 'is_active' => false, 'revision' => 2]);
            $this->deleteDefinition($url.'/'.$row->id, ['revision' => 2, 'assigned_count' => 0])->assertRedirect();
            $this->get($url)->assertOk();
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(6, DB::table('audit_events')->where('resource_type', '!=', 'page_visit')->count());
    }

    public function test_list_exposes_counts_not_personnel_records_and_limits_dynamic_tables(): void
    {
        $this->admin();
        $id = $this->definition('departments');
        User::factory()->create(['department_id' => $id]);
        $this->get('/yonetim/birimler')->assertInertia(fn (Assert $page) => $page->component('Organization')->has('rows', 1)
            ->where('rows.0.assigned_count', 1)->missing('users')->missing('rows.0.password')->has('navigation', 9)->where('navigation.0.label', 'Ana Sayfa'));
        $this->get('/yonetim/users')->assertNotFound();
        $this->deleteDefinition('/yonetim/users/1')->assertNotFound();
    }

    public function test_duplicate_code_invalid_fields_and_stale_revision_leave_data_unchanged(): void
    {
        $this->admin();
        $id = $this->definition('job_titles');
        $this->post('/yonetim/unvanlar', ['code' => 'synthetic', 'name' => 'duplicate', 'is_active' => true])->assertSessionHasErrors('code');
        $this->post('/yonetim/unvanlar', ['code' => 'bad code', 'name' => '', 'is_active' => true])->assertSessionHasErrors(['code', 'name']);
        $this->put('/yonetim/unvanlar/'.$id, ['code' => 'changed', 'name' => 'changed', 'is_active' => true, 'revision' => 2])->assertSessionHasErrors('revision');
        $this->deleteDefinition('/yonetim/unvanlar/'.$id, ['revision' => 2, 'assigned_count' => 0])->assertSessionHasErrors('revision');
        $this->assertDatabaseHas('job_titles', ['id' => $id, 'code' => 'synthetic', 'revision' => 1]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_assigned_definitions_require_valid_target_and_fresh_impact_count(): void
    {
        $this->admin();
        $id = $this->definition('departments');
        $inactive = $this->definition('departments', 'inactive', false);
        User::factory()->create(['department_id' => $id]);
        $url = '/yonetim/birimler/'.$id;
        $this->deleteDefinition($url, ['revision' => 1, 'assigned_count' => 1])->assertSessionHasErrors('target_id');
        foreach ([$id, $inactive, 9999] as $target) {
            $this->deleteDefinition($url, ['revision' => 1, 'assigned_count' => 1, 'target_id' => $target])->assertSessionHasErrors('target_id');
        }
        $this->deleteDefinition($url, ['revision' => 1, 'assigned_count' => 0])->assertSessionHasErrors('assigned_count');
        $this->assertDatabaseHas('users', ['department_id' => $id]);
        $this->assertDatabaseCount('departments', 2);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_both_transfer_types_preserve_people_hash_and_other_assignment_but_revoke_sessions(): void
    {
        $this->admin();
        foreach (['birimler' => ['departments', 'department_id'], 'unvanlar' => ['job_titles', 'job_title_id']] as $kind => [$table, $foreignKey]) {
            $source = $this->definition($table, 'source');
            $target = $this->definition($table, 'target');
            $otherKey = $kind === 'birimler' ? 'job_title_id' : 'department_id';
            $otherTable = $kind === 'birimler' ? 'job_titles' : 'departments';
            $other = $this->definition($otherTable, 'other_'.$kind);
            $user = User::factory()->create([$foreignKey => $source, $otherKey => $other, 'session_version' => 7])->refresh();
            $this->deleteDefinition('/yonetim/'.$kind.'/'.$source, ['revision' => 1, 'assigned_count' => 1, 'target_id' => $target])->assertRedirect();
            $after = $user->fresh();
            $this->assertSame($target, $after->$foreignKey);
            $this->assertSame($other, $after->$otherKey);
            $this->assertSame($user->password, $after->password);
            $this->assertSame(8, $after->session_version);
            $this->assertSame(2, $after->revision);
            $this->assertDatabaseMissing($table, ['id' => $source]);
            $event = json_decode(DB::table('audit_events')->where('resource_type', $kind)->value('details'), true);
            $this->assertSame(1, $event['transferred_users']);
            $this->assertArrayNotHasKey('password', $event);
        }
        $this->assertDatabaseCount('users', 3);
    }

    public function test_audit_failure_rolls_back_transfer_and_deletion(): void
    {
        $actor = $this->admin();
        $source = $this->definition('departments', 'source');
        $target = $this->definition('departments', 'target');
        $user = User::factory()->create(['department_id' => $source])->refresh();
        DB::statement("CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic'); END");
        $this->deleteDefinition('/yonetim/birimler/'.$source, ['revision' => 1, 'assigned_count' => 1, 'target_id' => $target])->assertSessionHasErrors('operation');
        $this->assertDatabaseHas('departments', ['id' => $source]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'department_id' => $source, 'session_version' => 0, 'revision' => 1]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function grant(User $actor, int $page, string $type, int $target, string $permission): void
    {
        app(PagePermissions::class)->apply($actor, ['pages' => [['id' => $page, 'revision' => DB::table('portal_pages')->where('id', $page)->value('revision')]],
            'target_type' => $type, 'target_id' => $target, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => $permission]);
    }

    public function test_transfer_cleans_only_source_permissions_preserves_other_sources_and_audits_impact(): void
    {
        $actor = $this->admin();
        foreach (['birimler' => ['departments', 'department_id', 'birim'], 'unvanlar' => ['job_titles', 'job_title_id', 'unvan']] as $kind => [$table, $foreign, $type]) {
            $source = $this->definition($table, 'policy_source');
            $target = $this->definition($table, 'policy_target');
            $person = User::factory()->create([$foreign => $source])->refresh();
            $pageId = DB::table('portal_pages')->insertGetId(['code' => 'synthetic_'.$kind, 'name' => 'SENTETİK']);
            $this->grant($actor, $pageId, $type, $source, 'giris');
            $this->grant($actor, $pageId, $type, $target, 'yazma');
            $this->grant($actor, $pageId, 'kisi', $person->id, 'giris');
            $this->get('/yonetim/'.$kind)->assertInertia(fn (Assert $view) => $view->where('rows', fn ($rows) => collect($rows)->every(fn ($row) => $row['permission_count'] === 1 && strlen($row['permission_snapshot']) === 64)));
            $revision = (int) DB::table('portal_pages')->where('id', $pageId)->value('revision');
            $this->deleteDefinition('/yonetim/'.$kind.'/'.$source, ['revision' => 1, 'assigned_count' => 1, 'target_id' => $target])->assertRedirect();
            $this->assertDatabaseMissing('page_permissions', [$foreign => $source]);
            $this->assertDatabaseHas('page_permissions', [$foreign => $target, 'permission' => 'yazma']);
            $this->assertDatabaseHas('page_permissions', ['user_id' => $person->id]);
            $this->assertTrue(app(PageAccess::class)->allows($person, 'synthetic_'.$kind, 'yazma'));
            $this->assertDatabaseHas('portal_pages', ['id' => $pageId, 'revision' => $revision + 1]);
            $event = json_decode(DB::table('audit_events')->where('resource_type', $kind)->where('action', 'deleted')->orderByDesc('id')->value('details'), true);
            $this->assertSame(1, $event['removed_permissions']);
            $this->assertSame(1, $event['target_permissions']);
            $this->assertSame([$pageId], $event['affected_page_ids']);
        }
    }

    public function test_source_and_target_permission_change_with_same_count_reject_stale_preview(): void
    {
        $actor = $this->admin();
        $source = $this->definition('departments', 'source');
        $target = $this->definition('departments', 'target');
        $person = User::factory()->create(['department_id' => $source])->refresh();
        $page = DB::table('portal_pages')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $service = app(OrganizationDefinitions::class);
        foreach ([$source => 'permission_snapshot', $target => 'target_permission_snapshot'] as $id => $errorKey) {
            $this->grant($actor, $page, 'birim', $id, 'giris');
            $before = $service->permissionImpact('birimler', $id);
            $this->grant($actor, $page, 'birim', $id, 'yazma');
            $this->deleteDefinition('/yonetim/birimler/'.$source, ['revision' => 1, 'assigned_count' => 1, 'target_id' => $target, $errorKey => $before['snapshot']])->assertSessionHasErrors($errorKey);
            $this->assertDatabaseHas('departments', ['id' => $source]);
            $this->assertSame($source, $person->fresh()->department_id);
        }
        $this->assertSame(0, DB::table('audit_events')->where('action', 'deleted')->count());
    }

    public function test_policy_cleanup_and_page_revisions_roll_back_when_delete_audit_fails(): void
    {
        $actor = $this->admin();
        $source = $this->definition('departments', 'source');
        $page = DB::table('portal_pages')->insertGetId(['code' => 'synthetic', 'name' => 'SENTETİK']);
        $this->grant($actor, $page, 'birim', $source, 'giris');
        $revision = DB::table('portal_pages')->where('id', $page)->value('revision');
        DB::unprepared("CREATE TRIGGER synthetic_delete_fail BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC'); END");
        $this->deleteDefinition('/yonetim/birimler/'.$source, ['revision' => 1, 'assigned_count' => 0])->assertSessionHasErrors('operation');
        $this->assertDatabaseHas('departments', ['id' => $source]);
        $this->assertDatabaseHas('page_permissions', ['department_id' => $source]);
        $this->assertDatabaseHas('portal_pages', ['id' => $page, 'revision' => $revision]);
    }

    public function test_missing_policy_preview_and_revoked_actor_cannot_delete_or_save(): void
    {
        $actor = $this->admin();
        $id = $this->definition('departments');
        $this->delete('/yonetim/birimler/'.$id, ['revision' => 1, 'assigned_count' => 0])->assertSessionHasErrors(['permission_count', 'permission_snapshot']);
        DB::table('users')->where('id', $actor->id)->update(['is_system_admin' => false]);
        foreach (['save', 'delete'] as $operation) {
            try {
                $service = app(OrganizationDefinitions::class);
                if ($operation === 'save') {
                    $service->save($actor, 'birimler', ['code' => 'changed', 'name' => 'SENTETİK', 'is_active' => true, 'revision' => 1], $id);
                } else {
                    $empty = $service->emptyPermissionImpact();
                    $service->delete($actor, 'birimler', $id, ['revision' => 1, 'assigned_count' => 0, 'permission_count' => 0, 'permission_snapshot' => $empty['snapshot']]);
                }
                $this->fail('Revoked administrator accepted');
            } catch (AuthorizationException $error) {
                $this->assertDatabaseHas('departments', ['id' => $id, 'code' => 'synthetic']);
            }
        }
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_service_authorization_cannot_be_bypassed_outside_http(): void
    {
        $user = User::factory()->create()->refresh();
        $this->expectException(AuthorizationException::class);
        app(OrganizationDefinitions::class)->save($user, 'birimler', ['code' => 'bad', 'name' => 'bad', 'is_active' => true]);
    }
}
