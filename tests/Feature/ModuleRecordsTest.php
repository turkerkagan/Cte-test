<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Modules\DefinitionConflict;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModuleRecords;
use App\Services\Modules\OptionCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ModuleRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $code = 'synthetic'): array
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $designer = app(ModuleDefinitions::class);
        $module = $designer->create($actor, ['code' => $code, 'name' => 'SENTETİK MODÜL', 'record_mode' => 'standalone']);
        $version = DB::table('module_versions')->where('module_id', $module)->first();
        $catalog = app(OptionCatalog::class);
        $list = $catalog->create($actor, ['code' => $code.'-options', 'name' => 'SENTETİK LİSTE']);
        $catalog->save($actor, $list, ['revision' => 1, 'name' => 'SENTETİK LİSTE', 'is_active' => true, 'items' => [
            ['id' => null, 'label' => 'SENTETİK ESKİ ETİKET', 'is_active' => true], ['id' => null, 'label' => 'SENTETİK İKİ', 'is_active' => true]]]);
        $options = DB::table('option_items')->where('option_list_id', $list)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $fields = array_map(fn ($type) => ['id' => null, 'type' => $type, 'label' => 'SENTETİK '.$type, 'helper' => '', 'required' => true, 'show_in_form' => true, 'show_in_table' => true]
            + (in_array($type, ['select', 'multiselect'], true) ? ['option_list_id' => $list] : []), ModuleDefinitions::TYPES);
        $designer->save($actor, $module, ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK MODÜL', 'fields' => $fields]);
        // Published fixtures remain synthetic until the publication service exists.
        DB::table('module_versions')->where('id', $version->id)->update(['status' => 'published', 'draft_slot' => null]);
        $ids = DB::table('module_fields')->where('module_id', $module)->pluck('id', 'type')->map(fn ($id) => (int) $id)->all();
        $samples = ['text' => 'SENTETİK İğdır', 'longtext' => "SENTETİK\nAçıklama", 'integer' => '9007199254740991', 'decimal' => '999999999999.999999',
            'boolean' => false, 'date' => '2024-02-29', 'datetime' => '2026-10-04T14:00', 'select' => $options[0], 'multiselect' => $options];
        $values = [];
        foreach ($samples as $type => $value) {
            $values[$ids[$type]] = $value;
        }

        return ['actor' => $actor, 'module' => $module, 'version' => (int) $version->id, 'list' => $list, 'options' => $options, 'ids' => $ids, 'values' => $values];
    }

    private function save(array $fixture, ?array $record = null, ?array $values = null): array
    {
        return app(ModuleRecords::class)->save($fixture['actor'], $fixture['module'], ['version_id' => $fixture['version'], 'values' => $values ?? $fixture['values']]
            + ($record === null ? [] : ['revision' => $record['revision']]), $record['id'] ?? null);
    }

    public function test_create_canonical_json_typed_indices_usage_and_safe_audit_commit_together(): void
    {
        $f = $this->fixture();
        $record = $this->save($f);
        $stored = DB::table('module_records')->where('id', $record['id'])->first();
        $values = json_decode($stored->values, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $record['revision']);
        $this->assertStringStartsWith('{', $stored->values);
        $this->assertSame(9007199254740991, $values[$f['ids']['integer']]);
        $this->assertSame('999999999999.999999', $values[$f['ids']['decimal']]);
        $this->assertSame('2026-10-04 14:00:00', $values[$f['ids']['datetime']]);
        $this->assertSame(false, $values[$f['ids']['boolean']]);
        $this->assertSame($f['actor']->id, (int) $stored->created_by);
        $this->assertDatabaseCount('module_record_index', 7);
        $this->assertDatabaseCount('module_record_options', 3);
        $this->assertDatabaseHas('module_record_index', ['field_id' => $f['ids']['integer'], 'integer_value' => 9007199254740991]);
        $this->assertDatabaseHas('module_record_options', ['field_id' => $f['ids']['select'], 'option_id' => $f['options'][0], 'label_snapshot' => 'SENTETİK ESKİ ETİKET']);
        $audit = DB::table('audit_events')->where('resource_type', 'module_records')->first();
        $this->assertStringNotContainsString('SENTETİK İğdır', $audit->details);
        $this->assertSame('created', $audit->action);
    }

    public function test_update_revision_conflict_and_no_op_preserve_record_and_creator(): void
    {
        $f = $this->fixture();
        $record = $this->save($f);
        $this->assertSame($record, $this->save($f, $record));
        $values = $f['values'];
        $values[$f['ids']['text']] = 'SENTETİK DEĞİŞİKLİK';
        $changed = $this->save($f, $record, $values);
        $this->assertSame(2, $changed['revision']);
        $this->assertDatabaseHas('module_record_index', ['field_id' => $f['ids']['text'], 'text_value' => 'SENTETİK DEĞİŞİKLİK']);
        $this->assertSame(2, DB::table('audit_events')->where('resource_type', 'module_records')->count());
        $this->assertDatabaseHas('module_records', ['created_by' => $f['actor']->id, 'revision' => 2]);
        $this->expectException(DefinitionConflict::class);
        $this->save($f, $record);
    }

    public function test_archived_choices_and_historical_labels_are_preserved_only_from_the_existing_record(): void
    {
        $f = $this->fixture();
        $record = $this->save($f);
        DB::table('option_items')->where('option_list_id', $f['list'])->update(['is_active' => false, 'label' => 'SENTETİK YENİ ETİKET']);
        DB::table('option_lists')->where('id', $f['list'])->update(['is_active' => false]);
        $values = $f['values'];
        $values[$f['ids']['text']] = 'SENTETİK DÜZENLEME';
        $record = $this->save($f, $record, $values);
        $detail = app(ModuleRecords::class)->detail($f['actor'], $f['module'], $record['id']);
        $this->assertSame('SENTETİK ESKİ ETİKET', $detail['option_labels'][0]['label']);
        $values[$f['ids']['multiselect']] = [$f['options'][0]];
        $record = $this->save($f, $record, $values);
        $values[$f['ids']['multiselect']] = $f['options'];
        try {
            $this->save($f, $record, $values);
            $this->fail('Removed archived choice added back');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('values.'.$f['ids']['multiselect'], $error->errors());
        }
        try {
            $this->save($f);
            $this->fail('Archived choices accepted for new record');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('values.'.$f['ids']['select'], $error->errors());
        }
        $this->assertDatabaseCount('module_records', 1);
    }

    public function test_late_audit_failure_rolls_back_create_and_all_update_projections(): void
    {
        $f = $this->fixture();
        $record = $this->save($f);
        $before = DB::table('module_records')->first();
        $indices = DB::table('module_record_index')->orderBy('field_id')->get()->toJson();
        $usages = DB::table('module_record_options')->orderBy('field_id')->orderBy('option_id')->get()->toJson();
        DB::unprepared("CREATE TRIGGER synthetic_record_audit_failure BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'SYNTHETIC'); END");
        foreach ([null, $record] as $target) {
            try {
                $values = $f['values'];
                $values[$f['ids']['text']] = 'SENTETİK BAŞARISIZ';
                $this->save($f, $target, $values);
                $this->fail('Audit failure accepted');
            } catch (QueryException $error) {
                $this->assertSame($before->values, DB::table('module_records')->first()->values);
                $this->assertSame(1, (int) DB::table('module_records')->first()->revision);
                $this->assertSame($indices, DB::table('module_record_index')->orderBy('field_id')->get()->toJson());
                $this->assertSame($usages, DB::table('module_record_options')->orderBy('field_id')->orderBy('option_id')->get()->toJson());
            }
        }
        $this->assertDatabaseCount('module_records', 1);
    }

    public function test_projection_failure_cannot_leave_a_canonical_record_without_its_indices(): void
    {
        $f = $this->fixture();
        DB::unprepared("CREATE TRIGGER synthetic_record_index_failure BEFORE INSERT ON module_record_index BEGIN SELECT RAISE(ABORT, 'SYNTHETIC'); END");
        try {
            $this->save($f);
            $this->fail('Projection failure accepted');
        } catch (QueryException $error) {
            $this->assertDatabaseCount('module_records', 0);
            $this->assertDatabaseCount('module_record_index', 0);
            $this->assertDatabaseCount('module_record_options', 0);
        }
    }

    public function test_staff_and_revoked_admin_cannot_read_or_write_records(): void
    {
        $f = $this->fixture();
        $record = $this->save($f);
        $staff = User::factory()->create()->refresh();
        foreach ([$staff, $f['actor']] as $actor) {
            if ($actor->id === $f['actor']->id) {
                DB::table('users')->where('id', $actor->id)->update(['is_system_admin' => false]);
            }
            foreach (['save', 'detail', 'page'] as $action) {
                try {
                    $service = app(ModuleRecords::class);
                    match ($action) {
                        'save' => $service->save($actor, $f['module'], ['version_id' => $f['version'], 'values' => $f['values']]),
                        'detail' => $service->detail($actor, $f['module'], $record['id']),
                        'page' => $service->page($actor, $f['module']),
                    };
                    $this->fail('Unauthorized actor accepted');
                } catch (AuthorizationException $error) {
                    $this->assertTrue(true);
                }
            }
        }
        $this->assertDatabaseCount('module_records', 1);
    }

    public function test_record_scope_and_version_change_require_explicit_migration(): void
    {
        $f = $this->fixture();
        $other = $this->fixture('other');
        $record = $this->save($f);
        try {
            app(ModuleRecords::class)->detail($f['actor'], $other['module'], $record['id']);
            $this->fail('Cross-module detail accepted');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        try {
            $this->save($other, $record);
            $this->fail('Cross-module update accepted');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $f['version'] = $other['version'];
        $this->expectException(DefinitionConflict::class);
        $this->save($f, $record);
    }

    public function test_database_foreign_keys_reject_cross_module_records_fields_and_option_sources(): void
    {
        $f = $this->fixture();
        $other = $this->fixture('other');
        $record = $this->save($f);
        $writes = [fn () => DB::table('module_records')->insert(['module_id' => $f['module'], 'version_id' => $other['version'], 'values' => '{}']),
            fn () => DB::table('module_record_index')->insert(['record_id' => $record['id'], 'module_id' => $f['module'], 'field_id' => $other['ids']['text']]),
            fn () => DB::table('module_record_options')->insert(['record_id' => $record['id'], 'module_id' => $f['module'], 'field_id' => $f['ids']['multiselect'],
                'option_list_id' => $f['list'], 'option_id' => $other['options'][0], 'label_snapshot' => 'SENTETİK'])];
        foreach ($writes as $write) {
            try {
                $write();
                $this->fail('Invalid foreign key accepted');
            } catch (QueryException $error) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('module_records', 1);
        $this->assertDatabaseCount('module_record_index', 7);
        $this->assertDatabaseCount('module_record_options', 3);
    }

    public function test_bounded_pagination_batches_historical_option_labels_and_has_no_n_plus_one(): void
    {
        $f = $this->fixture();
        for ($index = 0; $index < 26; $index++) {
            $this->save($f);
        }
        DB::enableQueryLog();
        $page = app(ModuleRecords::class)->page($f['actor'], $f['module'], ['page' => 1, 'per_page' => 25]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(25, $page['rows']);
        $this->assertSame(['page' => 1, 'per_page' => 25, 'total' => 26, 'last_page' => 2], $page['pagination']);
        $this->assertSame('SENTETİK ESKİ ETİKET', $page['rows'][0]['option_labels'][0]['label']);
        $this->assertCount(5, $queries);
        $next = app(ModuleRecords::class)->page($f['actor'], $f['module'], ['page' => 2, 'per_page' => 25]);
        $this->assertCount(1, $next['rows']);
        $this->assertGreaterThan($page['rows'][24]['id'], $next['rows'][0]['id']);
        $this->expectException(ValidationException::class);
        app(ModuleRecords::class)->page($f['actor'], $f['module'], ['per_page' => 100000]);
    }

    public function test_system_fields_overpost_is_ignored_and_requests_do_not_issue_ddl(): void
    {
        $f = $this->fixture();
        DB::enableQueryLog();
        $record = app(ModuleRecords::class)->save($f['actor'], $f['module'], ['version_id' => $f['version'], 'values' => $f['values'], 'created_by' => 999, 'module_id' => 999, 'revision' => 999, 'id' => 999]);
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(create|alter|drop)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertSame(1, $record['revision']);
        $this->assertDatabaseHas('module_records', ['created_by' => $f['actor']->id, 'module_id' => $f['module']]);
    }

    public function test_invalid_update_leaves_the_saved_record_and_indices_unchanged(): void
    {
        $f = $this->fixture();
        $record = $this->save($f);
        $values = $f['values'];
        $values[$f['ids']['date']] = '2023-02-29';
        try {
            $this->save($f, $record, $values);
            $this->fail('Invalid update accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('values.'.$f['ids']['date'], $error->errors());
        }
        $this->assertDatabaseHas('module_records', ['id' => $record['id'], 'revision' => 1]);
        $this->assertDatabaseHas('module_record_index', ['record_id' => $record['id'], 'date_value' => '2024-02-29']);
    }

    public function test_hidden_values_are_preserved_from_database_and_cannot_be_overposted(): void
    {
        $f = $this->fixture();
        $record = $this->save($f);
        $definition = json_decode(DB::table('module_versions')->where('id', $f['version'])->value('definition'), true);
        // Simulate an already validated migration; actual published JSON is immutable.
        $definition['fields'][0]['required'] = false;
        $definition['fields'][0]['show_in_form'] = false;
        DB::table('module_versions')->where('id', $f['version'])->update(['definition' => json_encode($definition)]);
        $values = $f['values'];
        unset($values[$f['ids']['text']]);
        $values[$f['ids']['boolean']] = true;
        $record = $this->save($f, $record, $values);
        $detail = app(ModuleRecords::class)->detail($f['actor'], $f['module'], $record['id']);
        $this->assertSame('SENTETİK İğdır', $detail['values'][$f['ids']['text']]);
        $values[$f['ids']['text']] = 'SENTETİK DEĞİŞTİRME';
        $this->expectException(ValidationException::class);
        $this->save($f, $record, $values);
    }

    public function test_new_publication_blocks_updates_without_silent_record_version_migration(): void
    {
        $f = $this->fixture();
        $record = $this->save($f);
        $definition = DB::table('module_versions')->where('id', $f['version'])->value('definition');
        DB::table('module_versions')->insert(['module_id' => $f['module'], 'number' => 2, 'status' => 'published', 'draft_slot' => null, 'definition' => $definition]);
        $this->expectException(DefinitionConflict::class);
        $this->save($f, $record);
    }

    public function test_empty_definition_is_stored_as_json_object_without_derived_rows(): void
    {
        $f = $this->fixture();
        $definition = json_decode(DB::table('module_versions')->where('id', $f['version'])->value('definition'), true);
        $definition['fields'] = [];
        DB::table('module_versions')->where('id', $f['version'])->update(['definition' => json_encode($definition)]);
        $record = $this->save($f, null, []);
        $this->assertDatabaseHas('module_records', ['id' => $record['id'], 'values' => '{}']);
        $this->assertDatabaseCount('module_record_index', 0);
        $this->assertDatabaseCount('module_record_options', 0);
    }
}
