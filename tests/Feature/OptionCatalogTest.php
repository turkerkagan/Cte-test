<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\OptionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OptionCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);

        return $actor;
    }

    private function createList(User $actor, string $code = 'synthetic-options'): int
    {
        return app(OptionCatalog::class)->create($actor, ['code' => $code, 'name' => 'SENTETİK LİSTE']);
    }

    private function payload(int $id, array $items): array
    {
        $list = DB::table('option_lists')->where('id', $id)->first();

        return ['revision' => $list->revision, 'name' => $list->name, 'is_active' => (bool) $list->is_active, 'items' => $items];
    }

    private function item(?int $id = null, string $label = 'SENTETİK TÜRKÇE SEÇENEK'): array
    {
        return ['id' => $id, 'label' => $label, 'is_active' => true];
    }

    public function test_authorization_force_change_csrf_and_missing_list(): void
    {
        $this->get('/yonetim/secenek-listeleri')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create()->refresh())->withSession(['auth_session_version' => 0]);
        $this->get('/yonetim/secenek-listeleri')->assertForbidden();
        $this->postJson('/yonetim/secenek-listeleri')->assertForbidden();
        $this->putJson('/yonetim/secenek-listeleri/1')->assertForbidden();
        $actor = $this->admin();
        $this->putJson('/yonetim/secenek-listeleri/999', ['revision' => 1, 'name' => 'SENTETİK', 'is_active' => true, 'items' => []])->assertNotFound();
        $actor->forceFill(['must_change_password' => true])->save();
        $this->get('/yonetim/secenek-listeleri')->assertRedirect(route('password.edit'));
        $actor->forceFill(['must_change_password' => false])->save();
        $this->app->instance('env', 'production');
        $this->postJson('/yonetim/secenek-listeleri', ['code' => 'csrf', 'name' => 'SENTETİK'])->assertStatus(419);
        $this->assertDatabaseCount('option_lists', 0);
    }

    public function test_list_identity_item_identity_reordering_archiving_and_no_op(): void
    {
        $this->admin();
        $id = $this->postJson('/yonetim/secenek-listeleri', ['code' => 'synthetic', 'name' => 'SENTETİK', 'is_active' => false])->assertCreated()->json('id');
        $url = '/yonetim/secenek-listeleri/'.$id;
        $this->putJson($url, $this->payload($id, [$this->item(), $this->item(null, 'SENTETİK İKİ')]))->assertOk();
        $items = app(OptionCatalog::class)->all()[0]['items'];
        $changed = array_reverse($items);
        $changed[0]['label'] = 'SENTETİK YENİ ETİKET';
        $changed[1]['is_active'] = false;
        $payload = $this->payload($id, $changed);
        $payload['name'] = 'SENTETİK YENİ LİSTE';
        $payload['code'] = 'hacked';
        $this->putJson($url, $payload)->assertOk();
        $this->assertSame($changed, app(OptionCatalog::class)->all()[0]['items']);
        $this->assertDatabaseHas('option_lists', ['id' => $id, 'code' => 'synthetic', 'revision' => 3, 'is_active' => true]);
        $this->putJson($url, $this->payload($id, $changed))->assertOk();
        $this->assertDatabaseCount('option_items', 2);
        $this->assertDatabaseCount('audit_events', 3);
        $this->get('/yonetim/secenek-listeleri')->assertInertia(fn (Assert $view) => $view->component('OptionLists')->has('lists', 1)->has('lists.0.items', 2));
    }

    public function test_stale_revision_invalid_item_ids_and_omission_never_lose_options(): void
    {
        $actor = $this->admin();
        $id = $this->createList($actor);
        $foreign = $this->createList($actor, 'foreign');
        app(OptionCatalog::class)->save($actor, $foreign, $this->payload($foreign, [$this->item()]));
        $foreignId = DB::table('option_items')->where('option_list_id', $foreign)->value('id');
        $stale = $this->payload($id, [$this->item()]);
        $url = '/yonetim/secenek-listeleri/'.$id;
        $this->putJson($url, $stale)->assertOk();
        $this->putJson($url, $stale)->assertStatus(409);
        $ownId = DB::table('option_items')->where('option_list_id', $id)->value('id');
        $this->putJson($url, $this->payload($id, []))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($url, $this->payload($id, [$this->item($ownId), $this->item(), $this->item($foreignId)]))->assertUnprocessable()->assertJsonValidationErrors('items.2.id');
        $this->putJson($url, $this->payload($id, [$this->item($ownId), $this->item($ownId)]))->assertUnprocessable();
        $this->assertDatabaseCount('option_items', 2);
        $this->assertDatabaseHas('option_lists', ['id' => $id, 'revision' => 2]);
    }

    public function test_invalid_payload_and_scripts_are_rejected(): void
    {
        $id = $this->createList($this->admin());
        $this->postJson('/yonetim/secenek-listeleri', ['code' => 'synthetic-options', 'name' => 'SENTETİK'])->assertUnprocessable();
        $url = '/yonetim/secenek-listeleri/'.$id;
        $this->putJson($url, $this->payload($id, [$this->item() + ['script' => 'alert(1)']]))->assertUnprocessable();
        $this->putJson($url, $this->payload($id, ['wrong-key' => $this->item()]))->assertUnprocessable();
        $this->putJson($url, $this->payload($id, array_fill(0, 501, $this->item())))->assertUnprocessable();
        $this->putJson($url, $this->payload($id, [['id' => null, 'label' => '', 'is_active' => true]]))->assertUnprocessable();
        $this->assertDatabaseCount('option_items', 0);
    }

    public function test_audit_failure_rolls_back_create_and_update_without_sql_leak(): void
    {
        $id = $this->createList($this->admin());
        DB::unprepared("CREATE TRIGGER synthetic_option_fail BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC SECRET'); END");
        $response = $this->putJson('/yonetim/secenek-listeleri/'.$id, $this->payload($id, [$this->item()]));
        $response->assertUnprocessable()->assertJsonValidationErrors('operation');
        $this->assertStringNotContainsString('SYNTHETIC SECRET', $response->getContent());
        $this->postJson('/yonetim/secenek-listeleri', ['code' => 'rollback', 'name' => 'SENTETİK'])->assertUnprocessable();
        $this->assertDatabaseCount('option_lists', 1);
        $this->assertDatabaseCount('option_items', 0);
        $this->assertDatabaseHas('option_lists', ['revision' => 1]);
    }

    public function test_fresh_actor_and_no_request_ddl(): void
    {
        $actor = $this->admin();
        DB::enableQueryLog();
        $id = $this->createList($actor);
        app(OptionCatalog::class)->save($actor, $id, $this->payload($id, [$this->item()]));
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(create|alter|drop)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        DB::table('users')->where('id', $actor->id)->update(['is_system_admin' => false]);
        $this->expectException(AuthorizationException::class);
        app(OptionCatalog::class)->save($actor, $id, $this->payload($id, []));
    }

    public function test_select_and_multiselect_sources_are_foreign_keyed_and_immutable(): void
    {
        $actor = $this->admin();
        $listId = $this->createList($actor);
        $otherId = $this->createList($actor, 'other');
        $service = app(ModuleDefinitions::class);
        $moduleId = $service->create($actor, ['code' => 'synthetic', 'name' => 'SENTETİK', 'record_mode' => 'standalone']);
        $version = DB::table('module_versions')->where('module_id', $moduleId)->first();
        $field = ['id' => null, 'type' => 'select', 'label' => 'SENTETİK SEÇİM', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true, 'option_list_id' => $listId];
        $payload = ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK', 'fields' => [$field, array_replace($field, ['type' => 'multiselect'])]];
        $url = '/yonetim/moduller/'.$moduleId.'/taslak';
        $response = $this->putJson($url, $payload)->assertOk();
        $fields = $response->json('version.definition.fields');
        $this->assertSame([$listId, $listId], array_column($fields, 'option_list_id'));
        $this->assertDatabaseHas('module_fields', ['id' => $fields[0]['id'], 'option_list_id' => $listId]);
        $payload = ['revision' => 2, 'version_id' => $version->id, 'version_revision' => 2, 'name' => 'SENTETİK', 'fields' => $fields];
        $payload['fields'][0]['option_list_id'] = $otherId;
        $this->putJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('fields.0.option_list_id');
        $payload['fields'][0]['option_list_id'] = null;
        $this->putJson($url, $payload)->assertUnprocessable();
        $payload['fields'][0]['option_list_id'] = 999;
        $this->putJson($url, $payload)->assertUnprocessable();
        $payload['fields'][0] = array_replace($field, ['type' => 'text']);
        $this->putJson($url, $payload)->assertUnprocessable();
        DB::table('option_lists')->where('id', $listId)->update(['is_active' => false]);
        $payload['fields'] = $fields;
        $this->putJson($url, $payload)->assertOk();
        $payload['fields'][] = $field;
        $this->putJson($url, $payload)->assertUnprocessable();
        $this->assertDatabaseCount('module_fields', 2);
    }
}
