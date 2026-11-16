<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModuleListQuery;
use App\Services\Modules\ModulePublication;
use App\Services\Modules\ModuleRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModuleListQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_bounds_and_fields_intersect_before_pagination_and_reject_hidden_fields(): void
    {
        [$actor, $module, $fields] = $this->fixture();
        $filters = [['field' => $fields[1], 'op' => 'gte', 'value' => '1'], ['field' => $fields[1], 'op' => 'lte', 'value' => '3'], ['field' => $fields[2], 'op' => 'lte', 'value' => '2026-10-02']];
        $result = app(ModuleRecords::class)->runtimePage($actor, $module, ['filters' => $filters]);
        $this->assertSame(1, $result['pagination']['total']);
        $this->assertSame('2', (string) $result['rows'][0]['values'][$fields[1]]);
        $this->assertSame($filters, $result['queryState']['filters']);
        $this->expectException(ValidationException::class);
        app(ModuleRecords::class)->runtimePage($actor, $module, ['filters' => [['field' => $fields[4], 'op' => 'eq', 'value' => 'SENTETİK GİZLİ']]]);
    }

    private function fixture(): array
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $designer = app(ModuleDefinitions::class);
        $id = $designer->create($actor, ['code' => 'synthetic-query', 'name' => 'SENTETİK', 'record_mode' => 'standalone']);
        $version = DB::table('module_versions')->where('module_id', $id)->first();
        $fields = [];
        foreach (['text', 'decimal', 'date', 'boolean', 'text'] as $i => $type) {
            $fields[] = ['id' => null, 'type' => $type, 'label' => 'SENTETİK '.$i, 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => $i !== 4];
        }
        $designer->save($actor, $id, ['revision' => 1, 'version_id' => $version->id, 'version_revision' => 1, 'name' => 'SENTETİK', 'fields' => $fields]);
        $publication = app(ModulePublication::class);
        $publication->publish($actor, $id, $publication->inspect($actor, $id) + ['confirmed' => true]);
        $ids = DB::table('module_fields')->where('module_id', $id)->orderBy('id')->pluck('id')->all();
        $records = app(ModuleRecords::class);
        $names = ['IŞIK', 'İPEK', '100%_tam!', 'SENTETİK NORMAL'];
        $nums = ['10', '2', '0', null];
        for ($i = 0; $i < 4; $i++) {
            $records->save($actor, $id, ['version_id' => $version->id, 'values' => [$ids[0] => $names[$i], $ids[1] => $nums[$i], $ids[2] => $i === 3 ? null : '2026-10-0'.($i + 1), $ids[3] => $i % 2 === 0, $ids[4] => 'SENTETİK GİZLİ']]);
        }

        return [$actor, $id, $ids];
    }

    public function test_turkish_case_search_literal_wildcards_and_hidden_text_are_scoped(): void
    {
        [$actor,$id,$fields] = $this->fixture();
        $service = app(ModuleRecords::class);
        $this->assertSame('IŞIK', $service->runtimePage($actor, $id, ['q' => 'ışık'])['rows'][0]['values'][$fields[0]]);
        $this->assertSame('İPEK', $service->runtimePage($actor, $id, ['q' => 'ipek'])['rows'][0]['values'][$fields[0]]);
        $this->assertSame(1, $service->runtimePage($actor, $id, ['q' => '%_tam!'])['pagination']['total']);
        $this->assertSame(0, $service->runtimePage($actor, $id, ['q' => 'GİZLİ'])['pagination']['total']);
        $this->assertSame(4, $service->runtimePage($actor, $id, ['q' => '   '])['pagination']['total']);
    }

    public function test_numeric_sort_nulls_last_and_typed_filters_apply_before_pagination(): void
    {
        [$actor,$id,$fields] = $this->fixture();
        $service = app(ModuleRecords::class);
        $page = $service->runtimePage($actor, $id, ['sort_field' => $fields[1], 'sort_direction' => 'asc']);
        $this->assertSame(['0', '2', '10', null], array_map(fn ($row) => $row['values'][$fields[1]], $page['rows']));
        $page = $service->runtimePage($actor, $id, ['sort_field' => $fields[1], 'sort_direction' => 'desc']);
        $this->assertSame(['10', '2', '0', null], array_map(fn ($row) => $row['values'][$fields[1]], $page['rows']));
        foreach ([[$fields[1], 'gte', '2', 2], [$fields[1], 'eq', '0', 1], [$fields[1], 'empty', '', 1], [$fields[2], 'lte', '2026-10-02', 2], [$fields[3], 'eq', '0', 2], [$fields[0], 'contains', 'İP', 1]] as [$field,$op,$value,$count]) {
            $this->assertSame($count, $service->runtimePage($actor, $id, ['filter_field' => $field, 'filter_op' => $op, 'filter_value' => $value])['pagination']['total']);
        }
    }

    public function test_hidden_fields_invalid_types_operators_and_permission_bypass_are_rejected(): void
    {
        [$actor,$id,$fields] = $this->fixture();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        foreach ([['sort_field' => $fields[4]], ['filter_field' => $fields[4], 'filter_op' => 'eq', 'filter_value' => 'SENTETİK GİZLİ'], ['filter_field' => $fields[1], 'filter_op' => 'contains', 'filter_value' => '2'], ['filter_field' => $fields[2], 'filter_op' => 'eq', 'filter_value' => '2026-02-30'], ['filter_field' => $fields[1], 'filter_value' => '1e3'], ['sort_field' => 'id desc;--'], ['q' => ['invalid']]] as $query) {
            $this->getJson('/moduller/synthetic-query?'.http_build_query($query))->assertUnprocessable();
        }
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->getJson('/moduller/synthetic-query?q=GİZLİ')->assertForbidden();
    }

    public function test_filtered_total_sort_and_second_page_use_all_records(): void
    {
        [$actor,$id,$fields] = $this->fixture();
        $version = DB::table('module_versions')->where('module_id', $id)->value('id');
        $records = app(ModuleRecords::class);
        for ($i = 0; $i < 27; $i++) {
            $records->save($actor, $id, ['version_id' => $version, 'values' => [$fields[0] => 'SENTETİK GLOBAL', $fields[1] => (string) $i]]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $records->runtimePage($actor, $id, ['q' => 'GLOBAL', 'sort_field' => $fields[1], 'sort_direction' => 'desc', 'per_page' => 25, 'page' => 2]);
        $this->assertSame(27, $page['pagination']['total']);
        $this->assertCount(2, $page['rows']);
        $this->assertSame(['1', '0'], array_map(fn ($row) => $row['values'][$fields[1]], $page['rows']));
        $this->assertLessThan(15, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_choice_filters_include_archived_membership_without_duplicate_rows_or_label_drift(): void
    {
        [, $module] = $this->fixture();
        $list = DB::table('option_lists')->insertGetId(['code' => 'synthetic-query-choice', 'name' => 'SENTETİK', 'is_active' => true]);
        $option = DB::table('option_items')->insertGetId(['option_list_id' => $list, 'label' => 'SENTETİK YENİ ETİKET', 'position' => 0, 'is_active' => false]);
        $field = DB::table('module_fields')->insertGetId(['module_id' => $module, 'type' => 'multiselect', 'option_list_id' => $list]);
        $record = DB::table('module_records')->where('module_id', $module)->orderBy('id')->value('id');
        DB::table('module_record_options')->insert(['record_id' => $record, 'module_id' => $module, 'field_id' => $field, 'option_list_id' => $list, 'option_id' => $option, 'label_snapshot' => 'SENTETİK TARİHÎ KARAR']);
        $metadata = [$field => ['id' => $field, 'type' => 'multiselect', 'option_list_id' => $list]];
        foreach ([['filter_field' => $field, 'filter_op' => 'eq', 'filter_value' => (string) $option], ['q' => 'TARİHÎ']] as $input) {
            $query = DB::table('module_records')->where('module_id', $module);
            app(ModuleListQuery::class)->apply($query, $module, $metadata, $input);
            $this->assertSame(1, $query->count());
        }
        $query = DB::table('module_records')->where('module_id', $module);
        app(ModuleListQuery::class)->apply($query, $module, $metadata, ['filter_field' => $field, 'filter_op' => 'empty']);
        $this->assertSame(3, $query->count());
    }
}
