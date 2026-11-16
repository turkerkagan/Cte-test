<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModulePublication;
use App\Services\Modules\ModuleRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModulePublicationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $designer = app(ModuleDefinitions::class);
        $id = $designer->create($actor, ['code' => 'synthetic-page', 'name' => 'SENTETİK MODÜL', 'record_mode' => 'standalone']);
        $version = DB::table('module_versions')->where('module_id', $id)->first();
        $designer->save($actor, $id, ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK MODÜL', 'fields' => [
            ['id' => null, 'type' => 'text', 'label' => 'SENTETİK BAŞLIK', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true],
            ['id' => null, 'type' => 'integer', 'label' => 'SENTETİK SAYI', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true]]]);

        return [$actor, $id];
    }

    private function publish(User $actor, int $id): array
    {
        $input = app(ModulePublication::class)->inspect($actor, $id) + ['confirmed' => true];
        $this->postJson('/yonetim/moduller/'.$id.'/yayinla', $input)->assertOk();

        return $input;
    }

    private function draft(User $actor, int $id): array
    {
        app(ModuleDefinitions::class)->fork($actor, $id, ['revision' => DB::table('modules')->where('id', $id)->value('revision')]);
        $version = DB::table('module_versions')->where('module_id', $id)->where('draft_slot', 1)->first();
        $definition = json_decode($version->definition, true);

        return ['revision' => DB::table('modules')->where('id', $id)->value('revision'), 'version_id' => $version->id, 'version_revision' => $version->revision, 'name' => $definition['name'], 'fields' => $definition['fields']];
    }

    public function test_publication_creates_server_owned_page_and_no_implicit_personnel_permissions(): void
    {
        [$actor, $id] = $this->fixture();
        $input = $this->publish($actor, $id);
        $this->assertDatabaseHas('portal_pages', ['module_id' => $id, 'code' => 'module_'.$id, 'legacy_file' => null, 'path' => null]);
        $this->assertDatabaseCount('page_permissions', 0);
        $this->assertDatabaseHas('module_versions', ['id' => $input['version_id'], 'status' => 'published', 'draft_slot' => null]);
        $this->get('/moduller/synthetic-page')->assertInertia(fn (Assert $view) => $view->component('ModuleRecords')->has('fields', 2)->has('rows', 0)->has('navigation', 10)->where('navigation.0.label', 'Ana Sayfa'));
        $this->postJson('/yonetim/moduller/'.$id.'/yayinla', $input)->assertStatus(409);
        $page = DB::table('portal_pages')->first();
        $this->assertSame(route('module-runtime.show', 'synthetic-page'), app(PageCatalog::class)->destination($page));
        $this->get('/yonetim/erisim-politikalari/'.$page->id.'/ac')->assertRedirect(route('module-runtime.show', 'synthetic-page'));
    }

    public function test_runtime_read_checks_page_permission_and_filters_hidden_values_on_server(): void
    {
        [$actor, $id] = $this->fixture();
        $this->publish($actor, $id);
        $version = DB::table('module_versions')->where('module_id', $id)->first();
        $fields = json_decode($version->definition, true)['fields'];
        app(ModuleRecords::class)->save($actor, $id, ['version_id' => $version->id, 'values' => [$fields[0]['id'] => 'SENTETİK GÖRÜNÜR', $fields[1]['id'] => 123]]);
        $draft = $this->draft($actor, $id);
        $draft['fields'][1]['show_in_table'] = false;
        app(ModuleDefinitions::class)->save($actor, $id, $draft);
        $this->publish($actor, $id);
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->get('/moduller/synthetic-page')->assertForbidden();
        $page = DB::table('portal_pages')->first();
        app(PagePermissions::class)->apply($actor, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris']);
        $this->get('/moduller/synthetic-page')->assertInertia(fn (Assert $view) => $view->component('ModuleRecords')->has('fields', 1)->has('rows', 1)
            ->where('rows.0.values.'.$fields[0]['id'], 'SENTETİK GÖRÜNÜR')->missing('rows.0.values.'.$fields[1]['id'])->has('navigation', 3));
        $this->postJson('/yonetim/moduller/'.$id.'/yayinla', [])->assertForbidden();
    }

    public function test_presentation_publication_preserves_values_indices_and_old_snapshot_but_increments_record_revision(): void
    {
        [$actor, $id] = $this->fixture();
        $first = $this->publish($actor, $id);
        $old = DB::table('module_versions')->where('id', $first['version_id'])->value('definition');
        $fields = json_decode($old, true)['fields'];
        $record = app(ModuleRecords::class)->save($actor, $id, ['version_id' => $first['version_id'], 'values' => [$fields[0]['id'] => 'SENTETİK', $fields[1]['id'] => 0]]);
        $before = DB::table('module_records')->first()->values;
        $index = DB::table('module_record_index')->get()->toJson();
        $draft = $this->draft($actor, $id);
        $draft['name'] = 'SENTETİK YENİ MODÜL';
        $draft['fields'] = array_reverse($draft['fields']);
        $draft['fields'][0]['label'] = 'SENTETİK YENİ BAŞLIK';
        app(ModuleDefinitions::class)->save($actor, $id, $draft);
        $new = $this->publish($actor, $id);
        $this->assertDatabaseHas('module_records', ['id' => $record['id'], 'version_id' => $new['version_id'], 'revision' => 2]);
        $this->assertSame($before, DB::table('module_records')->first()->values);
        $this->assertSame($index, DB::table('module_record_index')->get()->toJson());
        $this->assertSame($old, DB::table('module_versions')->where('id', $first['version_id'])->value('definition'));
        $this->assertDatabaseCount('portal_pages', 1);
        $this->assertDatabaseHas('portal_pages', ['name' => 'SENTETİK YENİ MODÜL']);
    }

    public function test_structural_change_with_records_is_blocked_without_mutating_publication_or_records(): void
    {
        [$actor, $id] = $this->fixture();
        $first = $this->publish($actor, $id);
        app(ModuleRecords::class)->save($actor, $id, ['version_id' => $first['version_id'], 'values' => []]);
        $draft = $this->draft($actor, $id);
        $draft['fields'][0]['required'] = true;
        app(ModuleDefinitions::class)->save($actor, $id, $draft);
        $impact = app(ModulePublication::class)->inspect($actor, $id);
        $this->assertNotEmpty($impact['blocked']);
        $this->postJson('/yonetim/moduller/'.$id.'/yayinla', $impact + ['confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('publication');
        $this->assertDatabaseHas('module_versions', ['id' => $impact['version_id'], 'status' => 'draft']);
        $this->assertDatabaseHas('module_records', ['version_id' => $first['version_id'], 'revision' => 1]);
    }

    public function test_same_count_record_change_invalidates_impact_hash_and_returns_409(): void
    {
        [$actor, $id] = $this->fixture();
        $first = $this->publish($actor, $id);
        $record = app(ModuleRecords::class)->save($actor, $id, ['version_id' => $first['version_id'], 'values' => []]);
        $this->draft($actor, $id);
        $impact = app(ModulePublication::class)->inspect($actor, $id);
        $field = DB::table('module_fields')->where('module_id', $id)->where('type', 'text')->value('id');
        app(ModuleRecords::class)->save($actor, $id, ['version_id' => $first['version_id'], 'revision' => 1, 'values' => [$field => 'SENTETİK DEĞİŞİKLİK']], $record['id']);
        $this->postJson('/yonetim/moduller/'.$id.'/yayinla', $impact + ['confirmed' => true])->assertStatus(409);
        $this->assertDatabaseHas('module_records', ['version_id' => $first['version_id'], 'revision' => 2]);
    }

    public function test_audit_failure_rolls_back_page_version_and_record_pointer_without_sql_leak(): void
    {
        [$actor, $id] = $this->fixture();
        $first = $this->publish($actor, $id);
        app(ModuleRecords::class)->save($actor, $id, ['version_id' => $first['version_id'], 'values' => []]);
        $draft = $this->draft($actor, $id);
        $draft['name'] = 'SENTETİK BAŞARISIZ';
        app(ModuleDefinitions::class)->save($actor, $id, $draft);
        $impact = app(ModulePublication::class)->inspect($actor, $id);
        DB::unprepared("CREATE TRIGGER synthetic_publish_failure BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC SECRET'); END");
        $response = $this->postJson('/yonetim/moduller/'.$id.'/yayinla', $impact + ['confirmed' => true]);
        $response->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertStringNotContainsString('SYNTHETIC SECRET', $response->getContent());
        $this->assertDatabaseHas('portal_pages', ['name' => 'SENTETİK MODÜL']);
        $this->assertDatabaseHas('module_versions', ['id' => $impact['version_id'], 'status' => 'draft']);
        $this->assertDatabaseHas('module_records', ['version_id' => $first['version_id'], 'revision' => 1]);
    }

    public function test_archived_page_is_not_reactivated_by_publication_and_mapping_cannot_be_edited(): void
    {
        [$actor, $id] = $this->fixture();
        $this->publish($actor, $id);
        $page = DB::table('portal_pages')->first();
        app(PageCatalog::class)->setActive($actor, $page->id, ['revision' => $page->revision, 'permission_count' => 0, 'confirmed' => true], false);
        $this->draft($actor, $id);
        $this->publish($actor, $id);
        $this->get('/moduller/synthetic-page')->assertNotFound();
        $this->get('/yonetim/erisim-politikalari/'.$page->id.'/ac')->assertNotFound();
        $this->putJson('/yonetim/erisim-politikalari/'.$page->id, ['code' => $page->code, 'name' => 'SENTETİK', 'path' => '/portal', 'revision' => 1])->assertUnprocessable();
        $this->postJson('/yonetim/erisim-politikalari', ['code' => 'module_999', 'name' => 'SENTETİK', 'path' => '/portal'])->assertUnprocessable();
    }

    public function test_unpublished_missing_and_unauthorized_endpoints_remain_closed(): void
    {
        $this->get('/moduller/synthetic-page')->assertRedirect(route('login'));
        [$actor, $id] = $this->fixture();
        $this->get('/moduller/synthetic-page')->assertNotFound();
        $this->get('/moduller/missing')->assertNotFound();
        $input = app(ModulePublication::class)->inspect($actor, $id);
        $this->postJson('/yonetim/moduller/'.$id.'/yayinla', $input + ['confirmed' => false])->assertUnprocessable();
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->postJson('/yonetim/moduller/'.$id.'/yayinla', [])->assertForbidden();
        $actor->forceFill(['must_change_password' => true])->save();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $this->post('/yonetim/moduller/'.$id.'/yayinla', [])->assertRedirect(route('password.edit'));
        $actor->forceFill(['must_change_password' => false])->save();
        $this->app->instance('env', 'production');
        $this->postJson('/yonetim/moduller/'.$id.'/yayinla', $input + ['confirmed' => true])->assertStatus(419);
    }
}
