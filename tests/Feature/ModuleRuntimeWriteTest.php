<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PagePermissions;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModulePublication;
use App\Services\Modules\ModuleRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleRuntimeWriteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $permission = 'yazma'): array
    {
        $admin = User::factory()->create(['is_system_admin' => true])->refresh();
        $designer = app(ModuleDefinitions::class);
        $module = $designer->create($admin, ['code' => 'synthetic-runtime', 'name' => 'SENTETİK KAYIT TESTİ', 'record_mode' => 'standalone']);
        $version = DB::table('module_versions')->where('module_id', $module)->first();
        $designer->save($admin, $module, ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK KAYIT TESTİ', 'fields' => [
            ['id' => null, 'type' => 'text', 'label' => 'SENTETİK BAŞLIK', 'helper' => '', 'required' => true, 'show_in_form' => true, 'show_in_table' => true],
            ['id' => null, 'type' => 'decimal', 'label' => 'SENTETİK SAYI', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => false]]]);
        $publication = app(ModulePublication::class);
        $publication->publish($admin, $module, $publication->inspect($admin, $module) + ['confirmed' => true]);
        $staff = User::factory()->create()->refresh();
        $page = DB::table('portal_pages')->where('module_id', $module)->first();
        app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => $permission]);
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);

        return [$admin, $staff, $module, (int) $version->id, DB::table('module_fields')->where('module_id', $module)->orderBy('id')->pluck('id')->all()];
    }

    public function test_staff_write_create_update_and_safe_form_dto(): void
    {
        [$admin, $staff, , $version, $fields] = $this->fixture();
        $this->get('/moduller/synthetic-runtime/yeni')->assertInertia(fn (Assert $v) => $v->component('ModuleRecordEditor')->where('versionId', $version)->has('fields', 2)->where('record', null));
        $response = $this->postJson('/moduller/synthetic-runtime/kayitlar', ['version_id' => $version, 'values' => [$fields[0] => 'SENTETİK', $fields[1] => '123456789012.123456'], 'created_by' => $admin->id])->assertCreated();
        $id = $response->json('id');
        $this->assertDatabaseHas('module_records', ['id' => $id, 'created_by' => $staff->id, 'revision' => 1]);
        $this->get('/moduller/synthetic-runtime/kayitlar/'.$id.'/duzenle')->assertInertia(fn (Assert $v) => $v->where('record.values.'.$fields[1], '123456789012.123456')->missing('record.created_by'));
        $this->putJson('/moduller/synthetic-runtime/kayitlar/'.$id, ['version_id' => $version, 'revision' => 1, 'values' => [$fields[0] => 'SENTETİK YENİ', $fields[1] => '0']])->assertOk()->assertJsonPath('revision', 2);
        $this->get('/moduller/synthetic-runtime')->assertInertia(fn (Assert $v) => $v->where('canWrite', true)->has('rows.0.editUrl')->missing('rows.0.values.'.$fields[1]));
    }

    public function test_dialog_loads_the_same_authorized_form_without_navigation(): void
    {
        [, , , $version, $fields] = $this->fixture();
        $form = $this->getJson('/moduller/synthetic-runtime/yeni')->assertOk()
            ->assertJsonPath('versionId', $version)->assertJsonPath('record', null);
        $this->assertTrue($form->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($form->headers->hasCacheControlDirective('private'));
        $id = $this->postJson('/moduller/synthetic-runtime/kayitlar', [
            'version_id' => $version, 'values' => [$fields[0] => 'SENTETİK POPUP', $fields[1] => null],
        ])->assertCreated()->json('id');
        $form = $this->getJson('/moduller/synthetic-runtime/kayitlar/'.$id.'/duzenle')->assertOk()
            ->assertJsonPath('record.id', $id)->assertJsonPath('record.revision', 1)
            ->assertJsonMissingPath('record.created_by');
        $this->assertTrue($form->headers->hasCacheControlDirective('no-store'));
    }

    public function test_read_only_staff_cannot_load_a_dialog_write_form(): void
    {
        $this->fixture('giris');
        $this->getJson('/moduller/synthetic-runtime/yeni')->assertForbidden();
    }

    public function test_read_only_and_revoked_permissions_cannot_write(): void
    {
        [$admin, , $module, $version, $fields] = $this->fixture('giris');
        $this->get('/moduller/synthetic-runtime')->assertInertia(fn (Assert $v) => $v->where('canWrite', false));
        $this->get('/moduller/synthetic-runtime/yeni')->assertForbidden();
        $this->postJson('/moduller/synthetic-runtime/kayitlar', [])->assertForbidden();
        $record = app(ModuleRecords::class)->save($admin, $module, ['version_id' => $version, 'values' => [$fields[0] => 'SENTETİK']]);
        $this->get('/moduller/synthetic-runtime/kayitlar/'.$record['id'].'/duzenle')->assertForbidden();
        $this->putJson('/moduller/synthetic-runtime/kayitlar/'.$record['id'], [])->assertForbidden();
        DB::table('page_permissions')->update(['permission' => 'yazma']);
        $this->get('/moduller/synthetic-runtime/yeni')->assertOk();
        DB::table('page_permissions')->delete();
        $this->postJson('/moduller/synthetic-runtime/kayitlar', ['version_id' => $version, 'values' => [$fields[0] => 'SENTETİK']])->assertForbidden();
        $this->assertDatabaseCount('module_records', 1);
    }

    public function test_invalid_values_stale_revision_scope_and_audit_failure_are_atomic(): void
    {
        [, , , $version, $fields] = $this->fixture();
        $this->postJson('/moduller/synthetic-runtime/kayitlar', ['version_id' => $version, 'values' => [$fields[0] => '', $fields[1] => '1.1234567']])->assertUnprocessable()->assertJsonValidationErrors(['values.'.$fields[0], 'values.'.$fields[1]]);
        $id = $this->postJson('/moduller/synthetic-runtime/kayitlar', ['version_id' => $version, 'values' => [$fields[0] => 'SENTETİK']])->json('id');
        $this->putJson('/moduller/synthetic-runtime/kayitlar/'.$id, ['version_id' => $version, 'revision' => 2, 'values' => [$fields[0] => 'SENTETİK ESKİ']])->assertStatus(409);
        $this->get('/moduller/synthetic-runtime/kayitlar/99999/duzenle')->assertNotFound();
        $this->putJson('/moduller/synthetic-runtime/kayitlar/99999', ['version_id' => $version, 'revision' => 1, 'values' => []])->assertNotFound();
        DB::unprepared("CREATE TRIGGER synthetic_runtime_failure BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC SECRET'); END");
        $response = $this->putJson('/moduller/synthetic-runtime/kayitlar/'.$id, ['version_id' => $version, 'revision' => 1, 'values' => [$fields[0] => 'SENTETİK BAŞARISIZ']])->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertStringNotContainsString('SYNTHETIC SECRET', $response->getContent());
        $this->assertDatabaseHas('module_records', ['id' => $id, 'revision' => 1]);
    }

    public function test_old_version_force_change_csrf_and_guest_are_closed(): void
    {
        [$admin,$staff,$module,$version,$fields] = $this->fixture();
        app(ModuleDefinitions::class)->fork($admin, $module, ['revision' => DB::table('modules')->where('id', $module)->value('revision')]);
        $publication = app(ModulePublication::class);
        $publication->publish($admin, $module, $publication->inspect($admin, $module) + ['confirmed' => true]);
        $this->postJson('/moduller/synthetic-runtime/kayitlar', ['version_id' => $version, 'values' => [$fields[0] => 'SENTETİK']])->assertStatus(409);
        $staff->forceFill(['must_change_password' => true])->save();
        $this->post('/moduller/synthetic-runtime/kayitlar', [])->assertRedirect(route('password.edit'));
        $staff->forceFill(['must_change_password' => false])->save();
        $this->app->instance('env', 'production');
        $this->postJson('/moduller/synthetic-runtime/kayitlar', [])->assertStatus(419);
        $this->app->instance('env', 'testing');
        auth()->logout();
        $this->get('/moduller/synthetic-runtime/yeni')->assertRedirect(route('login'));
    }

    public function test_form_returns_only_existing_archived_choices_and_preserves_hidden_values(): void
    {
        [, $staff, $module, $version, $ids] = $this->fixture();
        $list = DB::table('option_lists')->insertGetId(['code' => 'synthetic-choices', 'name' => 'SENTETİK', 'is_active' => true]);
        $old = DB::table('option_items')->insertGetId(['option_list_id' => $list, 'label' => 'SENTETİK ESKİ', 'position' => 0, 'is_active' => true]);
        $active = DB::table('option_items')->insertGetId(['option_list_id' => $list, 'label' => 'SENTETİK ETKİN', 'position' => 1, 'is_active' => true]);
        $choice = DB::table('module_fields')->insertGetId(['module_id' => $module, 'type' => 'select', 'option_list_id' => $list]);
        $definition = json_decode(DB::table('module_versions')->where('id', $version)->value('definition'), true);
        $definition['fields'][] = ['id' => $choice, 'type' => 'select', 'label' => 'SENTETİK SEÇİM', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true, 'option_list_id' => $list];
        DB::table('module_versions')->where('id', $version)->update(['definition' => json_encode($definition)]);
        $records = app(ModuleRecords::class);
        $record = $records->runtimeSave($staff, $module, ['version_id' => $version, 'values' => [$ids[0] => 'SENTETİK', $ids[1] => '14', $choice => $old]]);
        $definition['fields'][1]['show_in_form'] = false;
        DB::table('module_versions')->where('id', $version)->update(['definition' => json_encode($definition)]);
        DB::table('option_items')->where('id', $old)->update(['is_active' => false, 'label' => 'SENTETİK DEĞİŞTİ']);
        $form = $records->runtimeForm($staff, $module, $record['id']);
        $this->assertArrayNotHasKey($ids[1], $form['record']['values']);
        $this->assertSame([$active, $old], array_column($form['fields'][1]['options'], 'id'));
        $this->assertSame('SENTETİK ESKİ', $form['fields'][1]['options'][1]['label']);
        $this->assertSame([$active], array_column($records->runtimeForm($staff, $module)['fields'][1]['options'], 'id'));
        $records->runtimeSave($staff, $module, ['version_id' => $version, 'revision' => 1, 'values' => [$ids[0] => 'SENTETİK YENİ', $choice => $old]], $record['id']);
        $this->assertSame('14', json_decode(DB::table('module_records')->where('id', $record['id'])->value('values'), true)[$ids[1]]);
        $this->postJson('/moduller/synthetic-runtime/kayitlar', ['version_id' => $version, 'values' => [$ids[0] => 'SENTETİK', $choice => $old]])->assertUnprocessable();
    }
}
