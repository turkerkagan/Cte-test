<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Modules\ModuleDefinitions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleDesignerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);

        return $actor;
    }

    private function createModule(User $actor, string $code = 'synthetic-module'): int
    {
        return app(ModuleDefinitions::class)->create($actor, ['code' => $code, 'name' => 'SENTETİK MODÜL', 'record_mode' => 'standalone']);
    }

    private function payload(int $id, array $fields): array
    {
        $module = DB::table('modules')->where('id', $id)->first();
        $version = DB::table('module_versions')->where('module_id', $id)->where('draft_slot', 1)->first();

        return ['revision' => $module->revision, 'version_id' => $version->id, 'version_revision' => $version->revision, 'name' => $module->name, 'fields' => $fields];
    }

    private function field(string $type = 'text', ?int $id = null): array
    {
        return ['id' => $id, 'type' => $type, 'label' => 'SENTETİK ALAN', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true];
    }

    private function fields(int $id): array
    {
        return json_decode(DB::table('module_versions')->where('module_id', $id)->where('draft_slot', 1)->value('definition'), true)['fields'];
    }

    public function test_guest_staff_and_force_change_admin_cannot_manage_module_definitions(): void
    {
        $this->get('/yonetim/moduller')->assertRedirect(route('login'));
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->get('/yonetim/moduller')->assertForbidden();
        $this->postJson('/yonetim/moduller')->assertForbidden();
        $this->get('/yonetim/moduller/1')->assertForbidden();
        $this->putJson('/yonetim/moduller/1/taslak')->assertForbidden();
        $this->postJson('/yonetim/moduller/1/taslak')->assertForbidden();
        $staff->is_system_admin = true;
        $staff->must_change_password = true;
        $staff->save();
        $this->get('/yonetim/moduller')->assertRedirect(route('password.edit'));
        $this->assertDatabaseCount('modules', 0);
    }

    public function test_new_module_is_only_a_draft_without_page_menu_or_business_data_seed(): void
    {
        $this->admin();
        $response = $this->postJson('/yonetim/moduller', ['code' => 'synthetic-module', 'name' => 'SENTETİK', 'record_mode' => 'standalone', 'status' => 'published']);
        $response->assertCreated()->assertJsonPath('version.number', 1)->assertJsonPath('version.status', 'draft')->assertJsonPath('version.definition.fields', []);
        $this->assertDatabaseCount('modules', 1);
        $this->assertDatabaseCount('module_fields', 0);
        $this->assertDatabaseCount('portal_pages', 0);
        $this->assertDatabaseCount('page_permissions', 0);
        $this->get('/yonetim/moduller')->assertInertia(fn (Assert $view) => $view->component('Modules')->has('rows', 1)->has('navigation', 9)->where('navigation.0.label', 'Ana Sayfa'));
        $this->get('/yonetim/moduller/'.$response->json('module.id'))->assertInertia(fn (Assert $view) => $view->component('ModuleDesigner')->missing('user.password'));
    }

    public function test_all_supported_types_get_stable_ids_and_label_reorder_does_not_change_identity(): void
    {
        $id = $this->createModule($this->admin());
        $url = '/yonetim/moduller/'.$id.'/taslak';
        $this->putJson($url, $this->payload($id, array_map(fn ($type) => $this->field($type), array_values(array_diff(ModuleDefinitions::TYPES, ['select', 'multiselect'])))))->assertOk();
        $original = $this->fields($id);
        $this->assertCount(7, array_unique(array_column($original, 'id')));
        $this->assertDatabaseCount('module_fields', 7);
        $reordered = array_reverse($original);
        $reordered[0]['label'] = 'SENTETİK YENİ BAŞLIK';
        $this->putJson($url, $this->payload($id, $reordered))->assertOk();
        $this->assertSame(array_column($reordered, 'id'), array_column($this->fields($id), 'id'));
        $this->assertSame('SENTETİK YENİ BAŞLIK', $this->fields($id)[0]['label']);
        $this->assertDatabaseCount('module_fields', 7);
    }

    public function test_old_revision_returns_409_without_overwriting_current_definition(): void
    {
        $id = $this->createModule($this->admin());
        $stale = $this->payload($id, [$this->field()]);
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $stale)->assertOk();
        $saved = $this->fields($id);
        $stale['name'] = 'SENTETİK ESKİ TARAYICI';
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $stale)->assertStatus(409)->assertJsonStructure(['message', 'errors' => ['revision']]);
        $this->assertSame($saved, $this->fields($id));
        $this->assertDatabaseCount('module_fields', 1);
    }

    public function test_unknown_types_scripts_invisible_required_and_invalid_modes_are_rejected(): void
    {
        $id = $this->createModule($this->admin());
        $url = '/yonetim/moduller/'.$id.'/taslak';
        $this->postJson('/yonetim/moduller', ['code' => 'other', 'name' => 'SENTETİK', 'record_mode' => 'unsupported'])->assertUnprocessable()->assertJsonValidationErrors('record_mode');
        $this->postJson('/yonetim/moduller', ['code' => '../bad', 'name' => 'SENTETİK', 'record_mode' => 'standalone'])->assertUnprocessable();
        foreach ([array_replace($this->field(), ['type' => 'php']), $this->field() + ['script' => 'SYNTHETIC'], array_replace($this->field(), ['required' => true, 'show_in_form' => false])] as $field) {
            $this->putJson($url, $this->payload($id, [$field]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('module_fields', 0);
    }

    public function test_foreign_removed_and_duplicate_ids_and_type_changes_do_not_mutate_definition(): void
    {
        $actor = $this->admin();
        $id = $this->createModule($actor);
        $other = $this->createModule($actor, 'synthetic-other');
        app(ModuleDefinitions::class)->save($actor, $other, $this->payload($other, [$this->field()]));
        $foreignField = $this->fields($other)[0];
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, [$this->field(), $foreignField]))->assertUnprocessable();
        $this->assertDatabaseCount('module_fields', 1); // late invalid identity rolls new field back
        app(ModuleDefinitions::class)->save($actor, $id, $this->payload($id, [$this->field()]));
        $saved = $this->fields($id)[0];
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, [$saved, $saved]))->assertUnprocessable();
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, [array_replace($saved, ['type' => 'date'])]))->assertUnprocessable();
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, []))->assertOk();
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, [$saved]))->assertUnprocessable();
        $this->assertDatabaseCount('module_fields', 2); // identities survive draft removal
    }

    public function test_published_snapshot_cannot_be_edited_and_fork_keeps_history_and_field_ids(): void
    {
        $actor = $this->admin();
        $id = $this->createModule($actor);
        app(ModuleDefinitions::class)->save($actor, $id, $this->payload($id, [$this->field()]));
        $source = DB::table('module_versions')->where('module_id', $id)->first();
        $payload = $this->payload($id, $this->fields($id));
        // Synthetic published state; publication endpoint is intentionally not implemented yet.
        DB::table('module_versions')->where('id', $source->id)->update(['status' => 'published', 'draft_slot' => null]);
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $payload)->assertStatus(409);
        $this->postJson('/yonetim/moduller/'.$id.'/taslak', ['revision' => $payload['revision']])->assertCreated()->assertJsonPath('version.number', 2);
        $this->assertSame($source->definition, DB::table('module_versions')->where('id', $source->id)->value('definition'));
        $this->assertSame(json_decode($source->definition, true)['fields'], $this->fields($id));
        $new = $this->fields($id);
        $new[0]['label'] = 'SENTETİK YENİ SÜRÜM';
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, $new))->assertOk();
        $this->assertSame($source->definition, DB::table('module_versions')->where('id', $source->id)->value('definition'));
        $this->assertDatabaseCount('module_versions', 2);
    }

    public function test_no_op_and_repeated_draft_request_do_not_create_new_versions_or_audit(): void
    {
        $actor = $this->admin();
        $id = $this->createModule($actor);
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, []))->assertOk();
        $this->postJson('/yonetim/moduller/'.$id.'/taslak', ['revision' => 1])->assertCreated();
        $this->assertDatabaseCount('module_versions', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseHas('modules', ['revision' => 1]);
    }

    public function test_late_audit_failure_rolls_back_fields_definition_and_revisions_without_sql_leak(): void
    {
        $id = $this->createModule($this->admin());
        DB::unprepared("CREATE TRIGGER synthetic_module_fail BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC SECRET'); END");
        $response = $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, [$this->field()]));
        $response->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertStringNotContainsString('SYNTHETIC SECRET', $response->getContent());
        $this->assertDatabaseCount('module_fields', 0);
        $this->assertSame([], $this->fields($id));
        $this->assertDatabaseHas('modules', ['revision' => 1]);
        $this->assertDatabaseHas('module_versions', ['revision' => 1]);
    }

    public function test_current_actor_is_rechecked_and_requests_never_issue_ddl(): void
    {
        $actor = $this->admin();
        DB::enableQueryLog();
        $id = $this->createModule($actor);
        app(ModuleDefinitions::class)->save($actor, $id, $this->payload($id, [$this->field()]));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\\s*(create|alter|drop)\\b/i', $query['query']);
        }
        DB::disableQueryLog();
        DB::table('users')->where('id', $actor->id)->update(['is_system_admin' => false]);
        $this->expectException(AuthorizationException::class);
        $this->createModule($actor, 'revoked');
    }

    public function test_live_csrf_blocks_mutation_and_missing_module_returns_404(): void
    {
        $this->admin();
        $this->get('/yonetim/moduller/999')->assertNotFound();
        $this->putJson('/yonetim/moduller/999/taslak', ['revision' => 1, 'version_id' => 1, 'version_revision' => 1, 'name' => 'SENTETİK', 'fields' => []])->assertNotFound();
        $this->app->instance('env', 'production');
        $this->postJson('/yonetim/moduller', ['code' => 'csrf', 'name' => 'SENTETİK', 'record_mode' => 'standalone'])->assertStatus(419);
        $this->assertDatabaseCount('modules', 0);
    }

    public function test_create_and_fork_audit_failures_leave_no_partial_module_or_draft(): void
    {
        $actor = $this->admin();
        DB::unprepared("CREATE TRIGGER synthetic_create_fail BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC'); END");
        $this->postJson('/yonetim/moduller', ['code' => 'synthetic', 'name' => 'SENTETİK', 'record_mode' => 'standalone'])->assertUnprocessable();
        $this->assertDatabaseCount('modules', 0);
        $this->assertDatabaseCount('module_versions', 0);
        DB::unprepared('DROP TRIGGER synthetic_create_fail');
        $id = $this->createModule($actor);
        DB::table('module_versions')->where('module_id', $id)->update(['status' => 'published', 'draft_slot' => null]);
        DB::unprepared("CREATE TRIGGER synthetic_fork_fail BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC'); END");
        $this->postJson('/yonetim/moduller/'.$id.'/taslak', ['revision' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('module_versions', 1);
        $this->assertDatabaseHas('modules', ['id' => $id, 'revision' => 1]);
    }

    public function test_bounds_duplicate_code_and_overposted_module_identity_are_enforced(): void
    {
        $id = $this->createModule($this->admin());
        $this->postJson('/yonetim/moduller', ['code' => 'synthetic-module', 'name' => 'SENTETİK', 'record_mode' => 'standalone'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, array_fill(0, 101, $this->field())))->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $this->payload($id, ['non-list-key' => $this->field()]))->assertUnprocessable()->assertJsonValidationErrors('fields');
        $payload = $this->payload($id, [$this->field()]) + ['code' => 'changed', 'record_mode' => 'person_single', 'status' => 'published', 'draft_slot' => null];
        $this->putJson('/yonetim/moduller/'.$id.'/taslak', $payload)->assertOk();
        $this->assertDatabaseHas('modules', ['id' => $id, 'code' => 'synthetic-module', 'record_mode' => 'standalone']);
        $this->assertDatabaseHas('module_versions', ['module_id' => $id, 'status' => 'draft', 'draft_slot' => 1]);
    }
}
