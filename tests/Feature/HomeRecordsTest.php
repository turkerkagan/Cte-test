<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\People\HomeRecords;
use App\Services\People\PersonList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(bool $admin = true): User
    {
        $a = User::factory()->create(['is_system_admin' => $admin])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);

        return $a;
    }

    private function payload(): array
    {
        return ['identity' => ['tc_identity' => '00000000001', 'full_name' => 'SENTETİK ANA KAYIT', 'registered_on' => now()->toDateString(), 'admitted_on' => null], 'placement' => ['status_id' => 1, 'block_id' => 1, 'room_id' => 1, 'bed_id' => DB::table('housing_beds')->where('room_id', 1)->where('number', 1)->value('id'), 'revision' => 0, 'status_revision' => 0], 'caseRevision' => 0, 'values' => ['huk_calis' => null, 'huk_karar' => null, 'huk_saglik' => 'SAĞLIKLI', 'huk_notlar' => '=SENTETİK NOT']];
    }

    public function test_index_form_saves_all_fields_atomically_and_reopens_current_revisions(): void
    {
        $a = $this->actor();
        $data = $this->payload();
        $options = app(HomeRecords::class)->options($a);
        $data['values']['huk_calis'] = collect($options['caseOptions']['huk_calis'])->firstWhere('is_active', true)['id'];
        $id = $this->postJson('/ana-kayitlar', $data)->assertCreated()->json('id');
        $this->assertDatabaseHas('person_case_data', ['person_id' => $id, 'huk_saglik' => 'SAĞLIKLI', 'huk_notlar' => '=SENTETİK NOT', 'ilkgorus_revision' => 1]);
        $this->assertDatabaseHas('housing_occupancies', ['person_id' => $id, 'bed_id' => $data['placement']['bed_id']]);
        $detail = $this->getJson('/ana-kayitlar/'.$id)->assertOk()->assertJsonPath('identity.full_name', 'SENTETİK ANA KAYIT')->assertJsonMissingPath('values.egt_domainsifre')->json();
        $bed = collect($detail['placement']['beds'])->firstWhere('id', $data['placement']['bed_id']);
        $this->assertTrue($bed['available']);
        $this->assertFalse($bed['is_empty']);
        $room = collect($detail['placement']['rooms'])->firstWhere('id', 1);
        $this->assertSame(1, $room['present']);
        $this->assertGreaterThan(0, $room['capacity']);
        $update = ['identity' => $detail['identity'], 'values' => $detail['values'], 'caseRevision' => $detail['caseRevision'], 'placement' => $detail['placement']['record']];
        unset($update['identity']['id']);
        $update['identity']['full_name'] = 'SENTETİK GÜNCEL';
        $update['values']['huk_notlar'] = 'SENTETİK YENİ';
        $this->putJson('/ana-kayitlar/'.$id, $update)->assertOk();
        $this->putJson('/ana-kayitlar/'.$id, $update)->assertConflict();
        $this->get('/portal')->assertInertia(fn (Assert $p) => $p->where('rows.0.full_name', 'SENTETİK GÜNCEL')->where('rows.0.notes', 'SENTETİK YENİ')->where('homeSummary.today', 1));
    }

    public function test_invalid_options_and_occupied_beds_roll_back_identity_and_audit(): void
    {
        $this->actor();
        $data = $this->payload();
        $this->postJson('/ana-kayitlar', $data)->assertCreated();
        $audit = DB::table('audit_events')->count();
        $this->postJson('/ana-kayitlar', $data)->assertUnprocessable()->assertJsonValidationErrors('bed_id');
        $this->assertDatabaseCount('people', 1);
        $this->assertSame($audit, DB::table('audit_events')->count());
        $data['placement']['bed_id'] = null;
        $data['values']['huk_calis'] = 999999;
        $this->postJson('/ana-kayitlar', $data)->assertUnprocessable();
        $this->assertDatabaseCount('people', 1);
        $data['values'] = ['egt_domainsifre' => 'SYNTHETIC_SECRET'];
        $this->postJson('/ana-kayitlar', $data)->assertUnprocessable();
        $this->assertDatabaseCount('people', 1);
    }

    public function test_home_write_grant_does_not_grant_risk_and_private_fields(): void
    {
        $admin = $this->actor();
        $staff = $this->actor(false);
        app(PageCatalog::class)->save($admin, ['code' => 'hukumlu_kayit', 'name' => 'Ana sayfa', 'path' => '/portal']);
        $page = DB::table('portal_pages')->where('code', 'hukumlu_kayit')->first();
        app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'yazma']);
        $data = $this->payload();
        $data['placement']['bed_id'] = null;
        $id = $this->postJson('/ana-kayitlar', $data)->assertCreated()->json('id');
        $detail = $this->getJson('/ana-kayitlar/'.$id)->assertOk()->assertJsonMissingPath('values.huk_risk')->assertJsonPath('dates', null)->assertJsonPath('leaves', null)->json();
        $update = ['identity' => $detail['identity'], 'values' => $detail['values'] + ['huk_risk' => 1], 'caseRevision' => $detail['caseRevision'], 'placement' => $detail['placement']['record']];
        unset($update['identity']['id']);
        $this->putJson('/ana-kayitlar/'.$id, $update)->assertForbidden();
        $this->getJson('/portal?filters[risk]=1')->assertForbidden();
        $this->get('/portal')->assertInertia(fn (Assert $p) => $p->missing('homeOptions.caseOptions.huk_risk')->where('homeOptions.riskWritable', false)->where('homeSummary.leaves', null));
    }

    public function test_column_filters_and_export_use_whole_filtered_list_and_safe_cells(): void
    {
        $this->actor();
        $data = $this->payload();
        $data['placement']['bed_id'] = null;
        $this->postJson('/ana-kayitlar', $data)->assertCreated();
        $data['identity']['full_name'] = 'SENTETİK BAŞKA';
        $data['values']['huk_saglik'] = 'SENTETİK ÖZEL';
        $this->postJson('/ana-kayitlar', $data)->assertCreated();
        $this->assertCount(1, app(PersonList::class)->page(User::first(), ['filters' => ['health' => 'SAĞLIKLI'], 'sort_field' => 'block'])['rows']);
        $this->get('/portal?'.http_build_query(['filters' => ['health' => 'SAĞLIKLI'], 'sort_field' => 'block']))->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 1)->where('rows.0.full_name', 'SENTETİK ANA KAYIT'));
        $this->get('/ana-kayitlar/cikti?'.http_build_query(['filters' => ['health' => 'SAĞLIKLI']]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee("'=SENTETİK NOT", false)->assertDontSee('SENTETİK BAŞKA');
    }

    public function test_unknown_fields_and_revoked_writer_cannot_create_records(): void
    {
        $a = $this->actor();
        $this->postJson('/ana-kayitlar', $this->payload() + ['is_system_admin' => true])->assertUnprocessable();
        DB::table('users')->where('id', $a->id)->update(['is_system_admin' => false]);
        $this->postJson('/ana-kayitlar', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('people', 0);
    }

    public function test_column_value_checkboxes_filter_before_paging_and_empty_selection_matches_nothing(): void
    {
        $this->actor();
        for ($i = 0; $i < 60; $i++) {
            $id = DB::table('people')->insertGetId(['full_name' => 'SENTETİK DEĞER '.$i]);
            DB::table('person_case_data')->insert(['person_id' => $id, 'huk_saglik' => $i % 2 ? 'SAĞLIKLI' : 'SENTETİK ÖZEL']);
        }
        $url = '/portal?'.http_build_query(['scope' => 'all', 'per_page' => 25, 'selections' => ['health' => ['SAĞLIKLI']]]);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $p) => $p->has('rows', 25)->where('pagination.total', 30));
        $this->getJson('/ana-kayitlar/degerler?'.http_build_query(['field' => 'health', 'scope' => 'all', 'selections' => ['health' => ['SAĞLIKLI']]]))->assertOk()->assertJsonCount(2, 'values');
        $this->get('/portal?'.http_build_query(['scope' => 'all', 'selection_none' => ['health']]))->assertInertia(fn (Assert $p) => $p->has('rows', 0)->where('pagination.total', 0));
        $this->getJson('/ana-kayitlar/degerler?field=health&filters=invalid')->assertUnprocessable();
    }

    public function test_private_note_markers_and_release_dates_are_projected_only_for_authorized_users(): void
    {
        $a = $this->actor();
        $data = $this->payload();
        $data['placement']['bed_id'] = null;
        $id = $this->postJson('/ana-kayitlar', $data)->assertCreated()->json('id');
        DB::table('person_case_data')->where('person_id', $id)->update(['infaz_ds_tarihi' => '2030-01-01', 'revir_bulasici' => 'SENTETİK UYARI', 'egt_domainsifre' => 'SYNTHETIC_SECRET']);
        DB::table('person_notes')->insert(['person_id' => $id, 'author_id' => $a->id, 'author_name' => 'SENTETİK', 'is_private' => true, 'body' => 'SYNTHETIC_ENCRYPTED', 'request_id' => '00000000-0000-4000-8000-000000000001']);
        $this->get('/portal')->assertInertia(fn (Assert $p) => $p->where('rows.0.privateNoteCount', 1)->where('rows.0.release.ds', '2030-01-01')->where('rows.0.healthWarning', 'SENTETİK UYARI')->missing('rows.0.egt_domainsifre'));
        $this->actor(false);
        $this->get('/portal')->assertInertia(fn (Assert $p) => $p->missing('rows.0.privateNoteCount')->missing('rows.0.release')->missing('rows.0.risk'));
    }

    public function test_birth_field_uses_sentence_write_permission_and_optimistic_revision(): void
    {
        $this->actor();
        $data = $this->payload();
        $data['sentenceRevision'] = 0;
        $data['values']['infaz_dogum_tarihi'] = '2000-01-01';
        $id = $this->postJson('/ana-kayitlar', $data)->assertCreated()->json('id');
        $this->assertDatabaseHas('person_case_data', ['person_id' => $id, 'infaz_revision' => 1, 'infaz_dogum_tarihi' => '2000-01-01']);
        $detail = $this->getJson('/ana-kayitlar/'.$id)->json();
        $update = ['identity' => $detail['identity'], 'values' => $detail['values'], 'caseRevision' => $detail['caseRevision'], 'sentenceRevision' => 0, 'placement' => $detail['placement']['record']];
        unset($update['identity']['id']);
        $this->putJson('/ana-kayitlar/'.$id, $update)->assertConflict();
    }

    public function test_read_only_home_staff_can_open_the_old_detail_popup_without_write_or_sensitive_access(): void
    {
        $admin = $this->actor();
        $id = $this->postJson('/ana-kayitlar', $this->payload())->assertCreated()->json('id');
        $staff = $this->actor(false);
        app(PageCatalog::class)->save($admin, ['code' => 'hukumlu_kayit', 'name' => 'Ana sayfa', 'path' => '/portal']);
        $page = DB::table('portal_pages')->where('code', 'hukumlu_kayit')->first();
        app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris']);
        $this->get('/portal')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canWrite', false)->has('homeOptions')->missing('homeOptions.caseOptions.huk_risk'));
        $this->getJson('/ana-kayitlar/'.$id)->assertOk()->assertJsonPath('identity.full_name', 'SENTETİK ANA KAYIT')->assertJsonMissingPath('values.huk_risk')->assertJsonMissingPath('values.infaz_dogum_tarihi')->assertJsonPath('dates', null)->assertJsonPath('leaves', null);
        $this->putJson('/ana-kayitlar/'.$id, $this->payload())->assertForbidden();
        $this->postJson('/ana-kayitlar', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('people', 1);
    }

    public function test_edit_popup_projects_leave_hours_only_with_leave_access(): void
    {
        $admin = $this->actor();
        $id = $this->postJson('/ana-kayitlar', $this->payload())->assertCreated()->json('id');
        DB::table('person_case_data')->where('person_id', $id)->update(['izin_cikis_tarihi' => '2026-10-07', 'izin_cikis_saati' => '14:00:00', 'izin_donus_tarihi' => '2026-10-18', 'izin_donus_saati' => '16:30:00']);
        $this->getJson('/ana-kayitlar/'.$id)->assertOk()->assertJsonPath('leaves.izin_cikis_saati', '14:00:00')->assertJsonPath('leaves.izin_donus_saati', '16:30:00');
        $staff = $this->actor(false);
        app(PageCatalog::class)->save($admin, ['code' => 'hukumlu_kayit', 'name' => 'Ana sayfa', 'path' => '/portal']);
        $page = DB::table('portal_pages')->where('code', 'hukumlu_kayit')->first();
        app(PagePermissions::class)->apply($admin, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $staff->id, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'giris']);
        $this->getJson('/ana-kayitlar/'.$id)->assertOk()->assertJsonPath('leaves', null)->assertJsonMissingPath('leaves.izin_cikis_saati');
    }
}
