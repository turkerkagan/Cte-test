<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PagePermissions;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModuleExport;
use App\Services\Modules\ModulePublication;
use App\Services\Modules\ModuleRecords;
use App\Services\People\PersonRecords;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModulePersonLinksTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $mode = 'person_single'): array
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $people = app(PersonRecords::class);
        $one = $people->save($actor, ['tc_identity' => '00000000001', 'full_name' => 'SENTETİK IŞIK %_TAM!', 'admitted_on' => '2024-02-29', 'registered_on' => null]);
        $two = $people->save($actor, ['tc_identity' => '00000000001', 'full_name' => 'SENTETİK İPEK', 'admitted_on' => null, 'registered_on' => null]);
        $designer = app(ModuleDefinitions::class);
        $id = $designer->create($actor, ['code' => 'synthetic-person', 'name' => 'SENTETİK KİŞİ MODÜLÜ', 'record_mode' => $mode]);
        $version = DB::table('module_versions')->where('module_id', $id)->first();
        $designer->save($actor, $id, ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK KİŞİ MODÜLÜ', 'fields' => [
            ['id' => null, 'type' => 'text', 'label' => 'Açıklama', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true],
        ]]);
        $publication = app(ModulePublication::class);
        $publication->publish($actor, $id, $publication->inspect($actor, $id) + ['confirmed' => true]);
        $field = (int) DB::table('module_fields')->where('module_id', $id)->value('id');
        $url = '/moduller/synthetic-person';

        return compact('actor', 'one', 'two', 'id', 'version', 'field', 'url', 'mode');
    }

    private function input(array $f, ?int $person = null, string $text = 'SENTETİK A'): array
    {
        return ['version_id' => $f['version']->id, 'values' => [$f['field'] => $text]] + ($person === null ? [] : ['person_id' => $person]);
    }

    public function test_single_and_multiple_modes_use_one_shared_person_and_never_match_by_tc(): void
    {
        $f = $this->fixture();
        $this->postJson($f['url'].'/kayitlar', $this->input($f, $f['one']))->assertCreated();
        $this->postJson($f['url'].'/kayitlar', $this->input($f, $f['two']))->assertCreated();
        $this->postJson($f['url'].'/kayitlar', $this->input($f, $f['one']))->assertUnprocessable()->assertJsonValidationErrors('person_id');
        $this->assertDatabaseCount('module_records', 2);
        $this->assertDatabaseCount('people', 2);
        $this->assertDatabaseHas('module_records', ['module_id' => $f['id'], 'person_id' => $f['one'], 'single_person_slot' => 1]);
        $this->get($f['url'].'/yeni')->assertInertia(fn (Assert $v) => $v->where('recordMode', 'person_single')->where('record', null)->where('peopleUrl', url($f['url'].'/kisiler')));
        $creator = app(ModuleDefinitions::class);
        $multiple = $creator->create($f['actor'], ['code' => 'synthetic-multiple', 'name' => 'SENTETİK ÇOKLU', 'record_mode' => 'person_multiple']);
        $draft = DB::table('module_versions')->where('module_id', $multiple)->first();
        $creator->save($f['actor'], $multiple, ['revision' => 1, 'version_id' => $draft->id, 'version_revision' => 1, 'name' => 'SENTETİK ÇOKLU', 'fields' => [['id' => null, 'type' => 'text', 'label' => 'İşlem', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true]]]);
        $publication = app(ModulePublication::class);
        $publication->publish($f['actor'], $multiple, $publication->inspect($f['actor'], $multiple) + ['confirmed' => true]);
        $field = (int) DB::table('module_fields')->where('module_id', $multiple)->value('id');
        foreach (['SENTETİK İŞLEM1', 'SENTETİK İŞLEM2'] as $text) {
            app(ModuleRecords::class)->save($f['actor'], $multiple, ['version_id' => $draft->id, 'person_id' => $f['one'], 'values' => [$field => $text]]);
        }
        $this->assertSame(2, DB::table('module_records')->where('module_id', $multiple)->where('person_id', $f['one'])->whereNull('single_person_slot')->count());
        $this->assertDatabaseCount('people', 2);
    }

    public function test_person_binding_is_required_immutable_and_cannot_be_overposted_into_standalone_records(): void
    {
        $f = $this->fixture();
        foreach ([$this->input($f), $this->input($f, 999), $this->input($f, $f['one']) + ['single_person_slot' => 2]] as $input) {
            $this->postJson($f['url'].'/kayitlar', $input)->assertUnprocessable();
        }
        $saved = app(ModuleRecords::class)->save($f['actor'], $f['id'], $this->input($f, $f['one']));
        $id = $saved['id'];
        $this->putJson($f['url'].'/kayitlar/'.$id, $this->input($f, $f['two']) + ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('person_id');
        $this->putJson($f['url'].'/kayitlar/'.$id, $this->input($f) + ['revision' => 1])->assertOk()->assertJsonPath('revision', 1);
        $this->putJson($f['url'].'/kayitlar/'.$id, $this->input($f, $f['one'], 'SENTETİK B') + ['revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->assertDatabaseHas('module_records', ['id' => $id, 'person_id' => $f['one'], 'single_person_slot' => 1]);
        $this->get($f['url'].'/kayitlar/'.$id.'/duzenle')->assertInertia(fn (Assert $v) => $v->where('record.person.id', $f['one'])->where('record.person.tc_identity', '00000000001')->missing('record.person.admitted_on'));
        $designer = app(ModuleDefinitions::class);
        $other = $designer->create($f['actor'], ['code' => 'synthetic-standalone', 'name' => 'SENTETİK', 'record_mode' => 'standalone']);
        $draft = DB::table('module_versions')->where('module_id', $other)->first();
        try {
            app(ModuleRecords::class)->save($f['actor'], $other, ['person_id' => $f['one'], 'version_id' => $draft->id, 'values' => []]);
            $this->fail('Bağımsız modüle kişi bağlanamaz.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('person_id', $error->errors());
        }
    }

    public function test_foreign_key_and_unique_slot_protect_records_at_database_level(): void
    {
        $f = $this->fixture();
        $saved = app(ModuleRecords::class)->save($f['actor'], $f['id'], $this->input($f, $f['one']));
        foreach (['duplicate', 'orphan', 'delete'] as $operation) {
            try {
                if ($operation === 'delete') {
                    DB::table('people')->where('id', $f['one'])->delete();
                } else {
                    DB::table('module_records')->insert(['module_id' => $f['id'], 'version_id' => $f['version']->id, 'person_id' => $operation === 'orphan' ? 999 : $f['one'], 'single_person_slot' => 1, 'values' => '{}']);
                }
                $this->fail('DB ilişki kuralını korumalı.');
            } catch (QueryException) {
                $this->assertDatabaseCount('module_records', 1);
                $this->assertDatabaseHas('people', ['id' => $f['one']]);
            }
        }
        $this->assertSame($f['one'], app(ModuleRecords::class)->detail($f['actor'], $f['id'], $saved['id'])['person']['id']);
    }

    public function test_lookup_is_write_scoped_bounded_literal_turkish_and_identity_only(): void
    {
        $f = $this->fixture();
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->getJson($f['url'].'/kisiler?'.http_build_query(['q' => 'ışık']))->assertForbidden();
        $permissions = app(PagePermissions::class);
        $page = DB::table('portal_pages')->where('module_id', $f['id'])->first();
        $grant = ['pages' => [['id' => $page->id, 'revision' => 1]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris'];
        $permissions->apply($f['actor'], $grant);
        $this->getJson($f['url'].'/kisiler?'.http_build_query(['q' => 'ışık']))->assertForbidden();
        $permissions->apply($f['actor'], array_replace($grant, ['pages' => [['id' => $page->id, 'revision' => 2]], 'permission' => 'yazma']));
        $this->getJson($f['url'].'/kisiler?'.http_build_query(['q' => 'ışık']))->assertOk()->assertJsonCount(1, 'people')->assertJsonPath('people.0.id', $f['one'])->assertJsonMissingPath('people.0.admitted_on');
        $this->getJson($f['url'].'/kisiler?q=ipek')->assertOk()->assertJsonPath('people.0.id', $f['two']);
        $this->getJson($f['url'].'/kisiler?q=%25_')->assertOk()->assertJsonCount(1, 'people');
        $this->getJson($f['url'].'/kisiler?q=a')->assertUnprocessable();
        for ($i = 0; $i < 30; $i++) {
            DB::table('people')->insert(['full_name' => 'SENTETİK KALABALIK'.$i]);
        }
        $this->getJson($f['url'].'/kisiler?'.http_build_query(['q' => 'kalabalık']))->assertOk()->assertJsonCount(25, 'people');
        DB::table('users')->where('id', $staff->id)->update(['is_active' => false]);
        $this->getJson($f['url'].'/kisiler?'.http_build_query(['q' => 'ışık']))->assertForbidden();
    }

    public function test_identity_search_list_export_and_print_share_the_same_person_and_sorted_result(): void
    {
        $f = $this->fixture();
        $records = app(ModuleRecords::class);
        $records->save($f['actor'], $f['id'], $this->input($f, $f['one'], 'Z SON'));
        $records->save($f['actor'], $f['id'], $this->input($f, $f['two'], 'A İLK'));
        $query = ['sort_field' => $f['field'], 'sort_direction' => 'asc'];
        $page = $records->runtimePage($f['actor'], $f['id'], $query);
        $this->assertSame($f['two'], $page['rows'][0]['person']['id']);
        $this->assertSame(1, $records->runtimePage($f['actor'], $f['id'], ['q' => 'ışık'])['pagination']['total']);
        $this->assertSame(2, $records->runtimePage($f['actor'], $f['id'], ['q' => '00000000001'])['pagination']['total']);
        $snapshot = app(ModuleExport::class)->snapshot($f['actor'], $f['id'], $query);
        $this->assertSame(['tc_identity', 'full_name', $f['field']], array_column($snapshot['fields'], 'id'));
        $this->assertSame(['00000000001', 'SENTETİK İPEK', 'A İLK'], $snapshot['rows'][0]);
        $this->get($f['url'].'?'.http_build_query(['q' => 'ışık']))->assertInertia(fn (Assert $v) => $v->where('recordMode', 'person_single')->has('rows', 1)->where('rows.0.person.id', $f['one']));
        $this->get($f['url'].'/yazdir?sort_field='.$f['field'])->assertInertia(fn (Assert $v) => $v->where('fields.0.kind', 'tc')->where('fields.1.kind', 'name')->where('rows.0.1', 'SENTETİK İPEK'));
        $csv = $this->get($f['url'].'/cikti?'.http_build_query(['q' => 'ışık']))->assertOk()->getContent();
        $this->assertStringContainsString('TC Kimlik', $csv);
        $this->assertStringContainsString('SENTETİK IŞIK', $csv);
        $this->assertStringNotContainsString('SENTETİK İPEK', $csv);
    }

    public function test_linked_record_audit_failure_rolls_back_identity_slot_values_and_indices(): void
    {
        $f = $this->fixture();
        $records = app(ModuleRecords::class);
        $saved = $records->save($f['actor'], $f['id'], $this->input($f, $f['one']));
        $before = (array) DB::table('module_records')->where('id', $saved['id'])->first();
        $indices = DB::table('module_record_index')->get()->toJson();
        $audit = DB::table('audit_events')->count();
        DB::statement("CREATE TRIGGER synthetic_link_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        foreach ([null, $saved['id']] as $id) {
            try {
                $input = $this->input($f, $id === null ? $f['two'] : $f['one'], 'SENTETİK GERİ AL');
                $records->save($f['actor'], $f['id'], $id === null ? $input : $input + ['revision' => 1], $id);
                $this->fail('Audit hatası geri alınmalı.');
            } catch (QueryException) {
                $this->assertSame($before, (array) DB::table('module_records')->where('id', $saved['id'])->first());
                $this->assertSame($indices, DB::table('module_record_index')->get()->toJson());
                $this->assertDatabaseCount('module_records', 1);
                $this->assertSame($audit, DB::table('audit_events')->count());
            }
        }
    }

    public function test_linked_pagination_batches_person_identities_instead_of_querying_each_row(): void
    {
        $f = $this->fixture('person_multiple');
        $records = app(ModuleRecords::class);
        $records->save($f['actor'], $f['id'], $this->input($f, $f['one']));
        DB::enableQueryLog();
        $records->page($f['actor'], $f['id'], ['per_page' => 25]);
        $firstQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        for ($i = 0; $i < 24; $i++) {
            $records->save($f['actor'], $f['id'], $this->input($f, $f['two']));
        }
        DB::enableQueryLog();
        $page = $records->page($f['actor'], $f['id'], ['per_page' => 25]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(25, $page['rows']);
        $this->assertSame(6, $firstQueries);
        $this->assertCount($firstQueries, $queries);
        $this->assertSame($f['one'], $page['rows'][0]['person']['id']);
        $this->assertSame($f['two'], $page['rows'][24]['person']['id']);
    }

    public function test_mode_cannot_change_after_creation_and_presentation_publication_preserves_binding(): void
    {
        $f = $this->fixture();
        $records = app(ModuleRecords::class);
        $saved = $records->save($f['actor'], $f['id'], $this->input($f, $f['one']));
        $designer = app(ModuleDefinitions::class);
        $module = DB::table('modules')->where('id', $f['id'])->first();
        $designer->fork($f['actor'], $f['id'], ['revision' => $module->revision]);
        $draft = DB::table('module_versions')->where('module_id', $f['id'])->where('status', 'draft')->first();
        $definition = json_decode($draft->definition, true);
        $this->assertSame('person_single', $definition['record_mode']);
        $definition['fields'][0]['label'] = 'Yeni açıklama';
        $module = DB::table('modules')->where('id', $f['id'])->first();
        $designer->save($f['actor'], $f['id'], ['revision' => $module->revision, 'version_id' => $draft->id, 'version_revision' => $draft->revision, 'name' => $module->name, 'record_mode' => 'standalone', 'fields' => $definition['fields']]);
        $publication = app(ModulePublication::class);
        $publication->publish($f['actor'], $f['id'], $publication->inspect($f['actor'], $f['id']) + ['confirmed' => true]);
        $this->assertDatabaseHas('modules', ['id' => $f['id'], 'record_mode' => 'person_single']);
        $this->assertDatabaseHas('module_records', ['id' => $saved['id'], 'person_id' => $f['one'], 'single_person_slot' => 1, 'revision' => 2]);
        $wrong = json_decode(DB::table('module_versions')->where('id', $draft->id)->value('definition'), true);
        $wrong['record_mode'] = 'standalone';
        DB::table('module_versions')->where('id', $draft->id)->update(['definition' => json_encode($wrong)]);
        $this->getJson($f['url'].'/kisiler?'.http_build_query(['q' => 'ışık']))->assertUnprocessable()->assertJsonValidationErrors('definition');
    }
}
