<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PagePermissions;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModuleExport;
use App\Services\Modules\ModulePublication;
use App\Services\Modules\ModuleRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModuleExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_numeric_and_date_ranges_are_identical_in_list_csv_and_print(): void
    {
        [$actor, $module, $fields] = $this->fixture();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $params = ['filters' => [['field' => $fields[1], 'op' => 'gte', 'value' => '1'], ['field' => $fields[1], 'op' => 'lte', 'value' => '3'], ['field' => $fields[2], 'op' => 'lte', 'value' => '2026-10-02']]];
        $this->assertSame(1, app(ModuleRecords::class)->runtimePage($actor, $module, $params)['pagination']['total']);
        $csv = $this->get('/moduller/synthetic-export/cikti?'.http_build_query($params))->assertOk()->getContent();
        $this->assertStringContainsString('İPEK;2;2026-10-02', $csv);
        $this->assertStringNotContainsString('IŞIK', $csv);
        $this->get('/moduller/synthetic-export/yazdir?'.http_build_query($params))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows', 1)->where('rows.0.0', 'İPEK')->where('queryState.filters', $params['filters']));
    }

    private function fixture(): array
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $designer = app(ModuleDefinitions::class);
        $id = $designer->create($actor, ['code' => 'synthetic-export', 'name' => 'SENTETİK', 'record_mode' => 'standalone']);
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

    public function test_filtered_csv_and_print_have_identical_visible_rows_sort_and_no_database_ids(): void
    {
        [$actor, $module, $fields] = $this->fixture();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $params = ['filter_field' => $fields[1], 'filter_op' => 'gte', 'filter_value' => '0', 'sort_field' => $fields[1], 'sort_direction' => 'desc', 'page' => 999];
        $response = $this->get('/moduller/synthetic-export/cikti?'.http_build_query($params))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFID;", $csv);
        $this->assertStringNotContainsString('SENTETİK GİZLİ', $csv);
        $this->assertStringContainsString('1;IŞIK;10;2026-10-01;Evet', $csv);
        $this->assertStringContainsString('2;İPEK;2;2026-10-02;Hayır', $csv);
        $this->assertStringContainsString('3;100%_tam!;0;2026-10-03;Evet', $csv);
        $this->get('/moduller/synthetic-export/yazdir?'.http_build_query($params))->assertInertia(fn (Assert $view) => $view->component('ModulePrint')->has('fields', 4)->has('rows', 3)->where('rows.0.1', '10')->where('rows.2.1', '0')->missing('rows.0.id'));
        $snapshot = app(ModuleExport::class)->snapshot($actor, $module, ['q' => 'ipek']);
        $this->assertCount(1, $snapshot['rows']);
        $this->assertSame('İPEK', $snapshot['rows'][0][0]);
    }

    public function test_export_and_print_enforce_fresh_read_permissions_and_hidden_filter_scope(): void
    {
        [$admin, , $fields] = $this->fixture();
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        foreach (['cikti', 'yazdir'] as $path) {
            $this->getJson('/moduller/synthetic-export/'.$path)->assertForbidden();
        }
        $page = DB::table('portal_pages')->first();
        app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris']);
        $this->get('/moduller/synthetic-export/cikti')->assertOk();
        $this->get('/moduller/synthetic-export/yazdir')->assertOk();
        $this->getJson('/moduller/synthetic-export/cikti?sort_field='.$fields[4])->assertUnprocessable();
        DB::table('page_permissions')->delete();
        $this->getJson('/moduller/synthetic-export/cikti')->assertForbidden();
        $this->actingAs($admin)->withSession(['auth_session_version' => 0]);
        DB::table('portal_pages')->update(['is_active' => false]);
        $this->get('/moduller/synthetic-export/yazdir')->assertNotFound();
        auth()->logout();
        $this->get('/moduller/synthetic-export/cikti')->assertRedirect(route('login'));
    }

    public function test_csv_safely_escapes_formulas_quotes_newlines_and_preserves_numeric_precision(): void
    {
        $csv = app(ModuleExport::class)->csv(['fields' => [['label' => '=HEAD', 'type' => 'text'], ['label' => 'Ondalık', 'type' => 'decimal']], 'rows' => [
            ['=SUM(1;2)', '123456789012.123456'], ['  @danger', '-12.5'], ["satır\n\"alıntı\"", '0']]]);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $header = fgetcsv($stream, 0, ';', '"', '');
        $first = fgetcsv($stream, 0, ';', '"', '');
        $second = fgetcsv($stream, 0, ';', '"', '');
        $third = fgetcsv($stream, 0, ';', '"', '');
        fclose($stream);
        $this->assertSame("'=HEAD", $header[1]);
        $this->assertSame(['1', "'=SUM(1;2)", '123456789012.123456'], $first);
        $this->assertSame(['2', "'  @danger", '-12.5'], $second);
        $this->assertSame("satır\n\"alıntı\"", $third[1]);
    }

    public function test_row_and_source_byte_limits_reject_before_partial_download(): void
    {
        [$actor, $module] = $this->fixture();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $version = DB::table('module_versions')->where('module_id', $module)->value('id');
        $row = ['module_id' => $module, 'version_id' => $version, 'values' => '{}'];
        foreach (array_chunk(array_fill(0, 5001, $row), 500) as $chunk) {
            DB::table('module_records')->insert($chunk);
        }
        $this->getJson('/moduller/synthetic-export/cikti')->assertUnprocessable()->assertJsonValidationErrors('export');
        DB::table('module_records')->where('module_id', $module)->delete();
        $row['values'] = json_encode(['synthetic' => str_repeat('x', 6 * 1024 * 1024)]);
        DB::table('module_records')->insert([$row, $row]);
        $this->getJson('/moduller/synthetic-export/yazdir')->assertUnprocessable()->assertJsonValidationErrors('export');
    }
}
