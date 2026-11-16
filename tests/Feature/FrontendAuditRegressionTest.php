<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\People\PersonList;
use App\Services\Workflows\CaseFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FrontendAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(int $count): User
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        foreach (array_chunk(range(1, $count), 150) as $ids) {
            DB::table('people')->insert(array_map(fn ($id) => ['id' => $id, 'full_name' => sprintf('SENTETİK KİŞİ %05d', $id), 'tc_identity' => sprintf('%011d', $id)], $ids));
            DB::table('person_status_assignments')->insert(array_map(fn ($id) => ['person_id' => $id, 'status_id' => 1], $ids));
        }

        return $actor;
    }

    public function test_custom_report_reads_shared_field_configuration_once_per_collection(): void
    {
        $actor = $this->fixture(1);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $fields = app(CaseFields::class)->visible($actor, 'custom_rapor');
        $reads = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'from "administration_settings"'));
        DB::disableQueryLog();
        $this->assertNotEmpty($fields);
        $this->assertCount(1, $reads);
    }

    public function test_home_defaults_to_all_records_even_above_ten_thousand_and_paging_remains_optional(): void
    {
        $actor = $this->fixture(10001);
        $all = app(PersonList::class)->page($actor);
        $this->assertCount(10001, $all['rows']);
        $this->assertSame(['page' => 1, 'per_page' => 0, 'total' => 10001, 'last_page' => 1], $all['pagination']);
        $paged = app(PersonList::class)->page($actor, ['page' => 2, 'per_page' => 25]);
        $this->assertCount(25, $paged['rows']);
        $this->assertSame(26, $paged['rows'][0]['id']);
        $this->assertCount(10001, app(PersonList::class)->page($actor, ['page' => 2, 'per_page' => 0])['rows']);
    }

    public function test_oversized_transfer_renders_criteria_and_clear_error_instead_of_redirecting_to_previous_page(): void
    {
        $this->fixture(5001);
        foreach (array_chunk(range(1, 5001), 150) as $ids) {
            DB::table('person_case_data')->insert(array_map(fn ($id) => ['person_id' => $id, 'infaz_dogum_tarihi' => now()->subYears(30)->toDateString(), 'infaz_ds_tarihi' => now()->addYear()->toDateString()], $ids));
        }
        $this->get('/sevk-raporu')->assertOk()->assertInertia(fn (Assert $page) => $page->component('WorkflowList')->where('workflow', 'infaz_sevk_raporu')->has('rows', 0)->where('queryState.days', 100)->where('reportError', 'Sonuç 5.000 kayıt sınırını aşıyor. Arama veya sevk kriterlerini daraltıp raporu yeniden oluşturun.'));
        $this->getJson('/sevk-raporu/cikti')->assertUnprocessable()->assertJsonValidationErrors('export');
        $this->get('/sevk-raporu?q='.urlencode('SENTETİK KİŞİ 00001'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('rows', 1)->missing('reportError'));
    }

    public function test_oversized_leave_keeps_counts_and_explains_how_to_reduce_results(): void
    {
        $this->fixture(5001);
        foreach (array_chunk(range(1, 5001), 150) as $ids) {
            DB::table('person_case_data')->insert(array_map(fn ($id) => ['person_id' => $id, 'izin_cikis_tarihi' => now()->toDateString()], $ids));
        }
        $this->getJson('/izin-raporu?section=departure')->assertOk()->assertJsonPath('counts.departure', 5001)->assertJsonPath('rows', [])->assertJsonPath('reportError', 'Sonuç 5.000 kayıt sınırını aşıyor. Aramayı daraltıp raporu yeniden oluşturun.');
        $this->getJson('/izin-raporu/cikti?section=departure')->assertUnprocessable()->assertJsonValidationErrors('export');
    }
}
