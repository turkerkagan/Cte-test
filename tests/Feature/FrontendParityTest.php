<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Housing\PersonPlacement;
use App\Services\Institution\InstitutionRecords;
use App\Services\People\PersonRecords;
use App\Services\Workflows\CaseRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FrontendParityTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);

        return $actor;
    }

    private function person(User $actor, int $number, ?string $registered, ?string $admitted = null): int
    {
        $id = app(PersonRecords::class)->save($actor, ['full_name' => sprintf('SENTETİK %04d', $number), 'tc_identity' => sprintf('%011d', $number), 'registered_on' => $registered, 'admitted_on' => $admitted]);
        app(PersonPlacement::class)->save($actor, $id, ['status_id' => 1, 'block_id' => null, 'room_id' => null, 'bed_id' => null, 'revision' => 0, 'status_revision' => 0]);

        return $id;
    }

    public function test_phone_popup_reads_beyond_first_page_and_enforces_page_access(): void
    {
        $actor = $this->actor();
        for ($i = 1; $i <= 61; $i++) {
            app(InstitutionRecords::class)->save($actor, 'telefon', null, ['values' => ['name' => sprintf('SENTETİK TELEFON %03d', $i), 'phone' => sprintf('%07d', $i), 'position' => $i]]);
        }
        $this->getJson('/kurum/telefon')->assertOk()->assertJsonCount(50, 'rows');
        $response = $this->getJson('/kurum/telefon/rehber')->assertOk()->assertJsonCount(61, 'rows');
        $this->assertContains('0000061', array_column(array_column($response->json('rows'), 'values'), 'phone'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0])->getJson('/kurum/telefon/rehber')->assertForbidden();
    }

    public function test_leave_popup_json_preserves_date_section_search_and_authorization(): void
    {
        $actor = $this->actor();
        $id = $this->person($actor, 1, '2026-01-01');
        app(CaseRecords::class)->save($actor, 'izin', $id, ['revision' => 0, 'values' => ['izin_cikis_tarihi' => '2026-10-05', 'izin_donus_tarihi' => '2026-10-07']]);
        $this->getJson('/izin-raporu?date=2026-10-05&section=departure&q=SENTETİK')->assertOk()->assertJsonPath('rows.0.id', $id)->assertJsonPath('queryState.date', '2026-10-05')->assertJsonPath('queryState.section', 'departure');
        $this->getJson('/izin-raporu?date=2026-10-05&section=return')->assertOk()->assertJsonCount(0, 'rows');
        $this->get('/izin-raporu/yazdir?date=2026-10-05&section=departure')->assertInertia(fn (Assert $v) => $v->has('rows', 1)->where('queryState.section', 'departure'));
        $this->getJson('/izin-raporu?section=forged')->assertUnprocessable();
        $this->actingAs(User::factory()->create()->refresh())->withSession(['auth_session_version' => 0])->getJson('/izin-raporu?section=departure')->assertForbidden();
    }

    public function test_identity_dates_fallback_single_bound_and_preview_keep_selected_scope(): void
    {
        $actor = $this->actor();
        $first = $this->person($actor, 1, '2026-10-04');
        $fallback = $this->person($actor, 2, null, '2026-10-05');
        $this->person($actor, 3, '2026-10-06');
        $this->get('/belgeler/kimlik?from=2026-10-04&to=2026-10-05')->assertInertia(fn (Assert $v) => $v->has('people', 2)->where('from', '2026-10-04')->where('to', '2026-10-05')->where('previewReady', false));
        $this->get('/belgeler/kimlik?to=2026-10-04')->assertInertia(fn (Assert $v) => $v->has('people', 1)->where('people.0.id', $first));
        $this->get('/belgeler/kimlik?preview=1&from=2026-10-05&ids[0]='.$first.'&ids[1]='.$fallback)->assertInertia(fn (Assert $v) => $v->has('people', 1)->where('people.0.id', $fallback)->where('previewReady', true));
        $this->getJson('/belgeler/kimlik?from=2026-10-06&to=2026-10-05')->assertUnprocessable();
        $this->getJson('/belgeler/kimlik?from=2026-02-30')->assertUnprocessable();
    }

    public function test_photo_status_filter_applies_before_pagination_and_counts_tombstones_as_missing(): void
    {
        $actor = $this->actor();
        for ($i = 1; $i <= 55; $i++) {
            $last = $this->person($actor, $i, '2026-01-01');
        }
        DB::table('person_photos')->insert(['person_id' => $last, 'path' => 'synthetic/not-delivered.png', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/belgeler/fotograf?photo=with')->assertInertia(fn (Assert $v) => $v->has('people', 1)->where('people.0.id', $last)->where('total', 1)->where('photoFilter', 'with'));
        $this->get('/belgeler/fotograf?photo=without')->assertInertia(fn (Assert $v) => $v->has('people', 50)->where('total', 54));
        DB::table('person_photos')->where('person_id', $last)->update(['path' => null, 'revision' => 2]);
        $this->get('/belgeler/fotograf?photo=with')->assertInertia(fn (Assert $v) => $v->has('people', 0)->where('total', 0));
        $this->get('/belgeler/fotograf?photo=without')->assertInertia(fn (Assert $v) => $v->where('total', 55));
        $this->getJson('/belgeler/fotograf?photo=forged')->assertUnprocessable();
    }

    public function test_selected_export_columns_retain_screen_order_and_matching_values(): void
    {
        $actor = $this->actor();
        $id = $this->person($actor, 1, '2026-01-01');
        app(CaseRecords::class)->save($actor, 'ilkgorus', $id, ['revision' => 0, 'values' => ['huk_yas' => 36, 'huk_meslek' => 'SENTETİK MESLEK']]);
        $this->get('/ilk-gorus/yazdir?columns[0]=huk_meslek&columns[1]=huk_yas')->assertInertia(fn (Assert $v) => $v->where('fields.0.id', 'tc_identity')->where('fields.1.id', 'full_name')->where('fields.2.id', 'huk_meslek')->where('fields.3.id', 'huk_yas')->where('rows.0.2', 'SENTETİK MESLEK')->where('rows.0.3', '36'));
    }

    public function test_column_checkbox_values_cover_all_pages_keep_blank_and_enforce_visible_fields(): void
    {
        $actor = $this->actor();
        for ($i = 1; $i <= 31; $i++) {
            $id = $this->person($actor, $i, '2026-01-01');
            if ($i === 31) {
                app(CaseRecords::class)->save($actor, 'ilkgorus', $id, ['revision' => 0, 'values' => ['huk_meslek' => 'SENTETİK SON SAYFA']]);
            }
        }
        $values = $this->getJson('/ilk-gorus/sutun-degerleri?field=huk_meslek')->assertOk()->assertJsonCount(2, 'values');
        $this->assertSame(30, collect($values->json('values'))->firstWhere('value', '')['count']);
        $this->assertSame(1, collect($values->json('values'))->firstWhere('value', 'SENTETİK SON SAYFA')['count']);
        $this->assertStringContainsString('no-store', $values->headers->get('Cache-Control'));
        $selected = 'selections[huk_meslek][0]=SENTET%C4%B0K%20SON%20SAYFA';
        $this->getJson('/ilk-gorus?'.$selected)->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('rows.0.id', $id);
        $this->getJson('/ilk-gorus/sutun-degerleri?field=huk_meslek&'.$selected)->assertOk()->assertJsonCount(2, 'values');
        $this->getJson('/ilk-gorus?selections[huk_meslek][0]=')->assertOk()->assertJsonPath('pagination.total', 30);
        $this->getJson('/ilk-gorus?selection_none[0]=huk_meslek')->assertOk()->assertJsonPath('pagination.total', 0);
        $this->get('/ilk-gorus/yazdir?'.$selected)->assertInertia(fn (Assert $v) => $v->has('rows', 1));
        $this->getJson('/ilk-gorus/sutun-degerleri?field=huk_ds_durum')->assertOk()->assertJsonPath('values.0.label', 'NORMAL')->assertJsonPath('values.0.count', 31);
        $this->getJson('/ilk-gorus?selections[huk_ds_durum][0]=0')->assertOk()->assertJsonPath('pagination.total', 31);
        $this->getJson('/ilk-gorus?selections[block][0]=')->assertOk()->assertJsonPath('pagination.total', 31);
        $option = collect(app(CaseRecords::class)->options()['huk_infaz'])->first(fn ($item) => $item['is_active']);
        app(CaseRecords::class)->save($actor, 'ilkgorus', $id, ['revision' => 1, 'values' => ['huk_infaz' => $option['id']]]);
        $optionValues = $this->getJson('/ilk-gorus/sutun-degerleri?field=huk_infaz')->assertOk();
        $this->assertSame($option['label'], collect($optionValues->json('values'))->firstWhere('value', (string) $option['id'])['label']);
        $this->getJson('/ilk-gorus?selections[huk_infaz][0]='.$option['id'])->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('rows.0.id', $id);
        $this->getJson('/ilk-gorus/sutun-degerleri?field=forged')->assertUnprocessable();
        $this->getJson('/ilk-gorus?selections[forged][0]=x')->assertUnprocessable();
        $this->getJson('/ziyaret/sutun-degerleri?field=egit_okulsifre')->assertUnprocessable();
        $this->actingAs(User::factory()->create()->refresh())->withSession(['auth_session_version' => 0])->getJson('/ilk-gorus/sutun-degerleri?field=full_name')->assertForbidden();
    }
}
