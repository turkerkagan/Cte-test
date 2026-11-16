<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\Housing\PersonPlacement;
use App\Services\Modules\ModuleExport;
use App\Services\Modules\OptionCatalog;
use App\Services\People\PersonRecords;
use App\Services\Workflows\CaseCalculations;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\CaseReports;
use App\Services\Workflows\WorkflowAccess;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $a = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);

        return $a;
    }

    private function person(User $a, string $name = 'SENTETİK IŞIK'): int
    {
        $id = app(PersonRecords::class)->save($a, ['full_name' => $name, 'tc_identity' => '00000000001', 'admitted_on' => null, 'registered_on' => '2026-01-01']);
        app(PersonPlacement::class)->save($a, $id, ['status_id' => 1, 'block_id' => null, 'room_id' => null, 'bed_id' => null, 'revision' => 0, 'status_revision' => 0]);

        return $id;
    }

    private function save(User $a, string $group, int $id, array $values): array
    {
        return app(CaseRecords::class)->save($a, $group, $id, ['revision' => app(CaseRecords::class)->detail($a, $group, $id)['revision'], 'values' => $values]);
    }

    private function option(string $field, string $value): int
    {
        return (int) DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')->where('l.code', str_replace('_', '-', $field))->where('i.legacy_value', $value)->value('i.id');
    }

    public function test_infaz_hover_projects_authorized_summary_and_uses_protected_photo_route(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $this->save($a, 'infaz', $id, ['infaz_uyap_no' => '2026/123', 'infaz_ds_tarihi' => '2027-01-02', 'infaz_tahliye_tarihi' => '2028-03-04', 'infaz_ceza_yil' => 2]);
        DB::table('person_photos')->insert(['person_id' => $id, 'path' => 'private/test.jpg', 'mime' => 'image/jpeg', 'revision' => 3]);
        $staff = User::factory()->create()->refresh();
        app(PageCatalog::class)->save($a, ['code' => 'infaz', 'name' => 'İnfaz', 'path' => '/infaz']);
        $page = DB::table('portal_pages')->where('code', 'infaz')->first();
        app(PagePermissions::class)->apply($a, ['pages' => [['id' => $page->id, 'revision' => 1]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris']);
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->getJson('/infaz')->assertOk()->assertJsonPath('canWrite', false)
            ->assertJsonPath('rows.0.hover.full_name', 'SENTETİK IŞIK')
            ->assertJsonPath('rows.0.hover.uyap', '2026/123')
            ->assertJsonPath('rows.0.hover.release.ds', '2027-01-02')
            ->assertJsonPath('rows.0.hover.release.release', '2028-03-04')
            ->assertJsonPath('rows.0.hover.sentence', '2 Yıl, 0 Ay, 0 Gün')
            ->assertJsonPath('rows.0.hover.photoUrl', url('/infaz/kisiler/'.$id.'/foto').'?v=3')
            ->assertJsonMissing(['path' => 'private/test.jpg']);
        $this->getJson('/revir')->assertForbidden();
        $this->putJson('/infaz/kisiler/'.$id, ['revision' => 1, 'values' => ['infaz_not' => 'NO WRITE']])->assertForbidden();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);
        $this->getJson('/ilk-gorus')->assertOk()->assertJsonMissingPath('rows.0.hover');
        $this->get('/infaz/yazdir')->assertInertia(fn (Assert $v) => $v->missing('rows.0.hover'));
    }

    public function test_three_lists_editors_csv_print_and_option_management_are_connected(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        foreach (['ilk-gorus' => 'ilkgorus', 'infaz' => 'infaz', 'izin' => 'izin'] as $path => $group) {
            $this->get('/'.$path)->assertInertia(fn (Assert $v) => $v->component('WorkflowList')->where('workflow', $group)->where('rows.0.id', $id));
            $this->get('/'.$path.'/kisiler/'.$id)->assertInertia(fn (Assert $v) => $v->component('WorkflowEditor')->where('person.id', $id)->missing('person.created_by')->where('submitUrl', url('/'.$path.'/kisiler/'.$id)));
            $this->putJson('/'.$path.'/kisiler/'.$id, ['revision' => 0, 'values' => []])->assertOk();
            $this->get('/'.$path.'/cikti')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            $this->get('/'.$path.'/yazdir')->assertInertia(fn (Assert $v) => $v->component('WorkflowPrint')->has('rows', 1));
        }
        $this->get('/izin-raporu')->assertOk();
        $this->get('/sevk-raporu')->assertOk();
        $this->get('/yonetim/is-akisi-secenekleri')->assertInertia(fn (Assert $v) => $v->where('allowCreate', true)->has('lists', 39));
    }

    public function test_group_isolation_shared_identity_noop_stale_and_redacted_audit(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $this->save($a, 'ilkgorus', $id, ['huk_saglik' => 'iyi ışık', 'huk_engelli' => null, 'huk_notlar' => null]);
        $this->save($a, 'infaz', $id, ['infaz_ceza_yil' => 1, 'infaz_ceza_ay' => 13, 'infaz_ceza_gun' => 40]);
        $this->save($a, 'izin', $id, ['izin_gun_sayisi' => 11, 'izin_donus_saati' => '14:00']);
        $this->assertDatabaseHas('person_case_data', ['person_id' => $id, 'huk_saglik' => 'İYİ IŞIK', 'huk_engelli' => 'YOK', 'huk_notlar' => '', 'infaz_ceza_ay' => 13, 'izin_donus_saati' => '14:00:00']);
        $count = DB::table('audit_events')->count();
        $this->save($a, 'infaz', $id, ['infaz_ceza_yil' => 1, 'infaz_ceza_ay' => 13, 'infaz_ceza_gun' => 40]);
        $this->assertSame($count, DB::table('audit_events')->count());
        $this->putJson('/infaz/kisiler/'.$id, ['revision' => 0, 'values' => ['infaz_not' => 'STALE']])->assertConflict();
        $this->putJson('/infaz/kisiler/'.$id, ['revision' => 1, 'values' => ['huk_saglik' => 'SECRET']])->assertUnprocessable();
        $this->putJson('/izin/kisiler/'.$id, ['revision' => 1, 'values' => [], 'identity' => ['revision' => 1, 'full_name' => 'BAD']])->assertUnprocessable();
        $this->putJson('/ilk-gorus/kisiler/'.$id, ['revision' => 1, 'values' => [], 'identity' => ['revision' => 1, 'full_name' => 'SENTETİK YENİ', 'tc_identity' => null]])->assertOk();
        $this->assertDatabaseHas('people', ['id' => $id, 'full_name' => 'SENTETİK YENİ', 'tc_identity' => null, 'registered_on' => '2026-01-01', 'revision' => 2]);
        $this->putJson('/infaz/kisiler/'.$id, ['revision' => 1, 'values' => ['infaz_not' => 'ROLLBACK'], 'identity' => ['revision' => 1, 'admitted_on' => '2026-01-01']])->assertConflict();
        $this->assertDatabaseHas('person_case_data', ['person_id' => $id, 'infaz_not' => null]);
        $audit = DB::table('audit_events')->pluck('details')->implode('');
        $this->assertStringNotContainsString('İYİ IŞIK', $audit);
        $this->assertStringNotContainsString('SENTETİK YENİ', $audit);
    }

    public function test_interview_write_grant_allows_own_placement_without_home_or_other_scopes(): void
    {
        $admin = $this->actor();
        $id = $this->person($admin);
        $staff = User::factory()->create()->refresh();
        app(PageCatalog::class)->save($admin, ['code' => 'ilkgorus', 'name' => 'İlk görüş', 'path' => '/ilk-gorus']);
        $page = DB::table('portal_pages')->where('code', 'ilkgorus')->first();
        app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $page->id, 'revision' => 1]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'yazma']);
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->get('/ilk-gorus/kisiler/'.$id)->assertOk();
        $placement = app(PersonPlacement::class)->detailForInterview($staff, $id)['record'];
        $placement['status_id'] = null;
        $placement['block_id'] = 1;
        $this->putJson('/ilk-gorus/kisiler/'.$id, ['revision' => 0, 'values' => ['huk_yas' => 30], 'placement' => $placement])->assertOk();
        $this->assertDatabaseHas('person_status_assignments', ['person_id' => $id, 'status_id' => DB::table('person_statuses')->where('code', 'data_change')->value('id')]);
        $this->get('/kisiler/'.$id.'/yerlesim')->assertForbidden();
        foreach (['infaz', 'izin', 'izin-raporu', 'sevk-raporu'] as $path) {
            $this->get('/'.$path)->assertForbidden();
            $this->get('/'.$path.'/cikti')->assertForbidden();
        }
        DB::table('page_permissions')->delete();
        $this->putJson('/ilk-gorus/kisiler/'.$id, ['revision' => 1, 'values' => ['huk_yas' => 31]])->assertForbidden();
        $this->assertDatabaseHas('person_case_data', ['person_id' => $id, 'huk_yas' => 30]);
    }

    public function test_invalid_calendar_time_options_fields_and_ds_values_never_mutate(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        foreach ([['infaz', ['infaz_ds_tarihi' => '2026-02-29']], ['infaz', ['infaz_ds_tarihi' => '0000-00-00']], ['izin', ['izin_donus_saati' => '24:00']], ['ilkgorus', ['huk_ds_durum' => '9']], ['ilkgorus', ['huk_yas' => 1.5]], ['infaz', ['infaz_durumu' => $this->option('izin_turu', 'ÖZEL İZİN')]], ['infaz', ['id' => 100]], ['ilkgorus', ['huk_notlar' => str_repeat('A', 10001)]]] as [$group, $values]) {
            try {
                $this->save($a, $group, $id, $values);
                $this->fail('Invalid input accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('person_case_data', 0);
            }
        }
        $this->save($a, 'izin', $id, ['izin_cikis_tarihi' => '2026-03-05', 'izin_donus_tarihi' => '2026-03-01']);
        $this->assertDatabaseHas('person_status_assignments', ['person_id' => $id, 'status_id' => 1]);
    }

    public function test_option_renaming_archiving_retention_and_immutable_report_semantics(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $option = $this->option('infaz_gelis_nedeni', 'EĞİTİM');
        $this->save($a, 'infaz', $id, ['infaz_gelis_nedeni' => $option, 'infaz_ds_tarihi' => '2028-01-01']);
        $catalog = new OptionCatalog('case_');
        $list = collect($catalog->all())->firstWhere('code', 'infaz-gelis-nedeni');
        foreach ($list['items'] as &$item) {
            if ($item['id'] === $option) {
                $item['label'] = 'YENİ ETİKET';
                $item['is_active'] = false;
            }
        } unset($item);
        $catalog->save($a, $list['id'], array_intersect_key($list, array_flip(['name', 'revision', 'is_active', 'items'])));
        $this->save($a, 'infaz', $id, ['infaz_gelis_nedeni' => $option, 'infaz_not' => 'RETAINED']);
        $this->assertDatabaseHas('case_option_items', ['id' => $option, 'legacy_value' => 'EĞİTİM', 'label' => 'YENİ ETİKET']);
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(12, 0));
        $this->assertCount(0, app(CaseReports::class)->transfer($a, [])['rows']);
        $this->assertCount(1, app(CaseReports::class)->transfer($a, ['excluded' => []])['rows']);
        $second = $this->person($a, 'SENTETİK İKİ');
        try {
            $this->save($a, 'infaz', $second, ['infaz_gelis_nedeni' => $option]);
            $this->fail('Archived option accepted');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('person_case_data', ['person_id' => $second]);
        }
    }

    public function test_late_failure_rolls_back_identity_case_and_placement(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $before = app(PersonPlacement::class)->detail($a, $id)['record'];
        DB::statement("CREATE TRIGGER synthetic_case_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            app(CaseRecords::class)->save($a, 'ilkgorus', $id, ['revision' => 0, 'values' => ['huk_meslek' => 'DEĞİŞİM'], 'identity' => ['revision' => 1, 'full_name' => 'SENTETİK DEĞİŞİM'], 'placement' => array_replace($before, ['block_id' => 1])]);
            $this->fail('Audit failure accepted');
        } catch (QueryException) {
            $this->assertDatabaseHas('people', ['id' => $id, 'full_name' => 'SENTETİK IŞIK', 'revision' => 1]);
            $this->assertDatabaseCount('person_case_data', 0);
            $this->assertSame($before, app(PersonPlacement::class)->detail($a, $id)['record']);
        }
    }

    public function test_ds_reset_is_all_people_and_stale_preview_is_rejected(): void
    {
        $a = $this->actor();
        $one = $this->person($a);
        $two = $this->person($a, 'SENTETİK İKİ');
        $this->save($a, 'ilkgorus', $one, ['huk_ds_durum' => '1']);
        $preview = app(CaseRecords::class)->dsPreview($a);
        $this->save($a, 'ilkgorus', $two, ['huk_ds_durum' => '3']);
        $this->postJson('/ilk-gorus/ds-sifirla', ['token' => $preview['token'], 'confirm' => true])->assertConflict();
        DB::table('person_status_assignments')->where('person_id', $two)->delete();
        $preview = app(CaseRecords::class)->dsPreview($a);
        $this->assertSame(2, $preview['count']);
        $this->postJson('/ilk-gorus/ds-sifirla', ['token' => $preview['token'], 'confirm' => true])->assertOk();
        $this->assertSame(['0', '0'], DB::table('person_case_data')->orderBy('person_id')->pluck('huk_ds_durum')->all());
        $this->assertSame([2, 2], DB::table('person_case_data')->orderBy('person_id')->pluck('ilkgorus_revision')->all());
    }

    public function test_natural_sort_filters_derived_values_pagination_and_full_export_agree(): void
    {
        $a = $this->actor();
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(12, 0));
        foreach (['2026/10', '2026/2', '2025/100'] as $i => $uyap) {
            $id = $this->person($a, 'SENTETİK '.$i);
            $this->save($a, 'infaz', $id, ['infaz_uyap_no' => $uyap, 'infaz_ceza_yil' => 1, 'infaz_ceza_ay' => 6, 'infaz_dogum_tarihi' => '1996-10-04', 'infaz_not' => '=FORMULA']);
        }
        $list = app(CaseList::class);
        $this->assertSame(['2025/100', '2026/2', '2026/10'], array_column(array_column($list->page($a, 'infaz', [])['rows'], 'values'), 'infaz_uyap_no'));
        $this->assertSame(['2026/2', '2026/10', '2025/100'], array_column(array_column($list->page($a, 'infaz', ['direction' => 'desc'])['rows'], 'values'), 'infaz_uyap_no'));
        $input = ['filters' => [['field' => 'infaz_uyap_no', 'op' => 'contains', 'value' => '2026/'], ['field' => 'mean', 'op' => 'gte', 'value' => 1.49], ['field' => 'age', 'op' => 'gte', 'value' => 29]]];
        $result = $list->page($a, 'infaz', $input);
        $snapshot = $list->page($a, 'infaz', $input, true);
        $this->assertCount(2, $result['rows']);
        $this->assertSame(array_map(fn ($r) => array_values($r['values']), $result['rows']), $snapshot['rows']);
        $this->assertSame('1 Yıl, 6 Ay, 0 Gün', $result['rows'][0]['values']['sentence']);
        $this->assertStringContainsString("'=FORMULA", app(ModuleExport::class)->csv($snapshot));
        $this->get('/infaz/yazdir?'.http_build_query($input))->assertInertia(fn (Assert $v) => $v->has('rows', 2)->where('queryState.filters.0.value', '2026/'));
        for ($i = 0; $i < 24; $i++) {
            $this->person($a, 'SENTETİK SAYFA '.$i);
        }
        $this->assertCount(27, $list->page($a, 'infaz', [])['rows']);
        $this->assertCount(27, $list->page($a, 'infaz', ['page' => 2])['rows']);
        $this->assertCount(27, $list->page($a, 'infaz', [], true)['rows']);
        $this->assertSame(1, $list->page($a, 'infaz', ['page' => 999])['pagination']['page']);
    }

    public function test_date_calculations_preserve_minimum_vs_ds_priority_whole_days_and_exclusive_age(): void
    {
        $calc = app(CaseCalculations::class);
        $now = new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('Europe/Istanbul'));
        $this->assertSame('9 GÜN', $calc->remaining('2026-11-01', '2026-10-14', $now));
        $this->assertSame('GEÇMİŞ TARİH', $calc->remaining('2026-10-03', '2028-01-01', $now));
        $this->assertSame('BELİRSİZ', $calc->remaining(null, null, $now));
        $row = (object) ['infaz_ds_tarihi' => '2027-01-12', 'infaz_tahliye_tarihi' => '2028-01-01', 'infaz_dogum_tarihi' => null];
        $state = ['type' => 'il_disi', 'days' => 100, 'age' => 60, 'excluded' => CaseCalculations::DEFAULT_EXCLUSIONS];
        $this->assertFalse($calc->transfer($row, $state, $now, null));
        $row->infaz_ds_tarihi = '2027-01-13';
        $this->assertTrue($calc->transfer($row, $state, $now, 'KENDİ İSTEĞİYLE'));
        $this->assertFalse($calc->transfer($row, $state, $now, 'KENDİ İSTEĞİ İLE'));
        $row->infaz_dogum_tarihi = '1966-10-04';
        $this->assertFalse($calc->transfer($row, $state, $now, null));
        $row->infaz_dogum_tarihi = '1966-10-05';
        $this->assertTrue($calc->transfer($row, $state, $now, null));
        $row->infaz_ds_tarihi = '2026-10-03';
        $this->assertFalse($calc->transfer($row, $state, $now, null));
        $row->infaz_ds_tarihi = null;
        $this->assertTrue($calc->transfer($row, $state, $now, null));
        $row->infaz_dogum_tarihi = '1940-01-01';
        $state['type'] = 'il_ici';
        $this->assertTrue($calc->transfer($row, $state, $now, null));
    }

    public function test_leave_report_counts_lists_prints_and_fixed_1400_ignore_saved_return_time(): void
    {
        $a = $this->actor();
        $one = $this->person($a);
        $two = $this->person($a, 'SENTETİK İKİ');
        $special = DB::table('person_statuses')->where('code', 'special_leave')->value('id');
        DB::table('person_status_assignments')->whereIn('person_id', [$one, $two])->update(['status_id' => $special]);
        $this->save($a, 'izin', $one, ['izin_cikis_tarihi' => '2026-10-04', 'izin_donus_tarihi' => '2026-10-04', 'izin_donus_saati' => '23:59']);
        $this->save($a, 'izin', $two, ['izin_donus_tarihi' => '2026-10-03', 'izin_donus_saati' => '23:59']);
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(13, 59, 59));
        $report = app(CaseReports::class)->leave($a, []);
        $this->assertSame(['departure' => 1, 'return' => 1, 'control' => 1], $report['counts']);
        $this->assertCount(1, $report['rows']);
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(14, 0, 0));
        $report = app(CaseReports::class)->leave($a, []);
        $this->assertSame(2, $report['counts']['control']);
        $this->assertCount(2, $report['rows']);
        $this->get('/izin-raporu/yazdir')->assertInertia(fn (Assert $v) => $v->has('rows', 2));
        $this->get('/izin-raporu/cikti')->assertOk();
        $this->assertCount(0, app(CaseReports::class)->leave($a, ['date' => '2026-10-02'])['rows']);
        DB::table('person_statuses')->where('id', $special)->update(['included_in_active_list' => false, 'label' => 'YENİ İZİN']);
        $this->assertSame(['departure' => 0, 'return' => 0, 'control' => 2], app(CaseReports::class)->leave($a, [])['counts']);
    }

    public function test_private_photos_use_person_ids_revisions_tombstones_and_failure_cleanup(): void
    {
        Storage::fake('local');
        $a = $this->actor();
        $id = $this->person($a);
        $second = $this->person($a, 'SENTETİK DUPLICATE TC');
        $url = '/ilk-gorus/kisiler/'.$id.'/foto';
        $this->post($url, ['revision' => 0, 'photo' => UploadedFile::fake()->image('synthetic.png')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('revision', 1);
        $path = DB::table('person_photos')->where('person_id', $id)->value('path');
        Storage::disk('local')->assertExists($path);
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/ilk-gorus/kisiler/'.$second.'/foto')->assertNotFound();
        $this->post($url, ['revision' => 0, 'photo' => UploadedFile::fake()->image('stale.png')], ['Accept' => 'application/json'])->assertConflict();
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->post($url, ['revision' => 1, 'photo' => UploadedFile::fake()->create('synthetic.svg', 10, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->deleteJson($url, ['revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        Storage::disk('local')->assertMissing($path);
        $this->get($url)->assertNotFound();
        $this->post($url, ['revision' => 0, 'photo' => UploadedFile::fake()->image('aba.png')], ['Accept' => 'application/json'])->assertConflict();
        DB::statement("CREATE TRIGGER synthetic_photo_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->withoutExceptionHandling();
        try {
            $this->post($url, ['revision' => 2, 'photo' => UploadedFile::fake()->image('rollback.png')], ['Accept' => 'application/json']);
            $this->fail('Audit failure accepted');
        } catch (QueryException) {
            $this->assertCount(0, Storage::disk('local')->allFiles());
            $this->assertDatabaseHas('person_photos', ['person_id' => $id, 'path' => null, 'revision' => 2]);
        }
    }

    public function test_read_only_grants_and_archived_pages_do_not_allow_writes_or_reappear_for_admin(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $staff = User::factory()->create()->refresh();
        app(PageCatalog::class)->save($a, ['code' => 'ilkgorus', 'name' => 'İlk görüş', 'path' => '/ilk-gorus']);
        $page = DB::table('portal_pages')->where('code', 'ilkgorus')->first();
        app(PagePermissions::class)->apply($a, ['pages' => [['id' => $page->id, 'revision' => 1]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris']);
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        $this->get('/ilk-gorus')->assertOk();
        $this->get('/ilk-gorus/kisiler/'.$id)->assertInertia(fn (Assert $v) => $v->where('canWrite', false)->where('placement', null));
        $this->putJson('/ilk-gorus/kisiler/'.$id, ['revision' => 0, 'values' => ['huk_yas' => 30]])->assertForbidden();
        $this->postJson('/ilk-gorus/ds-sifirla', ['token' => str_repeat('a', 64), 'confirm' => true])->assertForbidden();
        Storage::fake('local');
        $this->post('/ilk-gorus/kisiler/'.$id.'/foto', ['revision' => 0, 'photo' => UploadedFile::fake()->image('denied.png')], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertDatabaseCount('person_case_data', 0);
        DB::table('portal_pages')->where('id', $page->id)->update(['is_active' => false]);
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);
        $this->get('/ilk-gorus')->assertForbidden();
        $this->get('/ilk-gorus/cikti')->assertForbidden();
        $this->assertNotContains(route('interviews.index'), array_column(app(WorkflowAccess::class)->navigation($a), 'url'));
    }

    public function test_multi_option_date_empty_bounds_columns_and_sql_injection_allowlists(): void
    {
        $a = $this->actor();
        $one = $this->person($a);
        $two = $this->person($a, 'SENTETİK FİLTRE');
        $option = $this->option('infaz_durumu', '7550 SK');
        $this->save($a, 'infaz', $one, ['infaz_durumu' => $option, 'infaz_ds_tarihi' => '2027-01-02']);
        $this->save($a, 'infaz', $two, ['infaz_ds_tarihi' => '2027-01-01']);
        $list = app(CaseList::class);
        $filters = [['field' => 'infaz_durumu', 'op' => 'in', 'value' => [$option]], ['field' => 'infaz_ds_tarihi', 'op' => 'year', 'value' => '2027']];
        $this->assertCount(1, $list->page($a, 'infaz', ['filters' => $filters])['rows']);
        $this->assertCount(1, $list->page($a, 'infaz', ['filters' => [['field' => 'infaz_durumu', 'op' => 'empty']]])['rows']);
        $this->assertCount(1, $list->page($a, 'infaz', ['filters' => [['field' => 'infaz_ds_tarihi', 'op' => 'after', 'value' => '2027-01']]])['rows']);
        $snapshot = $list->page($a, 'infaz', ['columns' => ['infaz_ds_tarihi']], true);
        $this->assertSame(['tc_identity', 'full_name', 'infaz_ds_tarihi'], array_column($snapshot['fields'], 'id'));
        $this->assertCount(3, $snapshot['rows'][0]);
        foreach ([['sort' => 'p.id; DROP TABLE people'], ['filters' => [['field' => 'private_secret', 'op' => 'eq', 'value' => '1']]], ['columns' => ['huk_saglik']], ['per_page' => 5000], ['filters' => [['field' => 'infaz_ds_tarihi', 'op' => 'after', 'value' => '2027-13']]]] as $invalid) {
            try {
                $list->page($a, 'infaz', $invalid);
                $this->fail('Invalid query accepted');
            } catch (ValidationException) {
                $this->assertDatabaseCount('people', 2);
            }
        }
        // No silently truncated exports.
        $now = now();
        $rows = [];
        for ($i = 0; $i < 4999; $i++) {
            $rows[] = ['full_name' => 'SENTETİK SINIR '.$i, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('people')->insert($rows);
        $ids = DB::table('people')->where('id', '>', $two)->pluck('id');
        foreach ($ids->chunk(400) as $batch) {
            DB::table('person_status_assignments')->insert($batch->map(fn ($id) => ['person_id' => $id, 'status_id' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now])->all());
        }
        try {
            $list->page($a, 'infaz', [], true);
            $this->fail('Oversized export accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('export', $e->errors());
        }
    }
}
