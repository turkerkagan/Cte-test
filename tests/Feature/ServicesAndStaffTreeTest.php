<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\Housing\PersonPlacement;
use App\Services\Organization\StaffTree;
use App\Services\People\PersonRecords;
use App\Services\Workflows\CaseCalculations;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServicesAndStaffTreeTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $a = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);

        return $a;
    }

    private function person(User $a): int
    {
        $id = app(PersonRecords::class)->save($a, ['full_name' => 'SENTETİK IŞIK', 'tc_identity' => '00000000001', 'admitted_on' => null, 'registered_on' => null]);
        app(PersonPlacement::class)->save($a, $id, ['status_id' => 1, 'block_id' => null, 'room_id' => null, 'bed_id' => null, 'revision' => 0, 'status_revision' => 0]);

        return $id;
    }

    private function grant(User $a, User $u, string $code, string $path, string $permission): void
    {
        if (! DB::table('portal_pages')->where('code', $code)->exists()) {
            app(PageCatalog::class)->save($a, ['code' => $code, 'name' => $code, 'legacy_file' => null, 'path' => $path]);
        }$page = DB::table('portal_pages')->where('code', $code)->first();
        app(PagePermissions::class)->apply($a, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $u->id, 'reason' => 'Sentetik erişim gerekçesi', 'permission' => $permission, 'operation' => 'grant']);
    }

    private function node(array $extra = []): array
    {
        return $extra + ['parent_id' => null, 'user_id' => null, 'full_name' => 'SENTETİK PERSONEL', 'title' => 'Unvan', 'description' => null, 'position' => 0, 'is_active' => true];
    }

    public function test_routes_shared_fields_partial_updates_stale_readonly_and_hygiene_scope(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        foreach (['egitim', 'kurslar', 'revir', 'ziyaret', 'manevi', 'mesem', 'hijyen-isg', 'psikososyal', 'calisma'] as $path) {
            foreach (['', '/kisiler/'.$id, '/cikti', '/yazdir'] as $suffix) {
                $this->get('/'.$path.$suffix)->assertOk();
            }
        }
        app(CaseRecords::class)->save($a, 'revir', $id, ['revision' => 0, 'values' => ['huk_saglik' => 'iyi ışık', 'huk_kronik' => 'KORUNACAK']]);
        $this->assertSame('İYİ IŞIK', app(CaseRecords::class)->detail($a, 'ilkgorus', $id)['values']['huk_saglik']);
        $this->putJson('/ilk-gorus/kisiler/'.$id, ['revision' => 0, 'values' => ['huk_saglik' => 'STALE']])->assertConflict();
        $this->putJson('/revir/kisiler/'.$id, ['revision' => 1, 'values' => ['huk_calis' => 1]])->assertUnprocessable();
        $this->putJson('/revir/kisiler/'.$id, ['revision' => 1, 'values' => ['revir_portor_tarihi_1' => '2026-02-30']])->assertUnprocessable();
        $this->assertDatabaseHas('person_case_data', ['person_id' => $id, 'huk_kronik' => 'KORUNACAK']);
        foreach (['psikososyal', 'calisma'] as $path) {
            $this->putJson('/'.$path.'/kisiler/'.$id, ['revision' => 0, 'values' => []])->assertForbidden();
        }
        DB::table('person_status_assignments')->where('person_id', $id)->delete();
        $this->assertSame(1, app(CaseList::class)->page($a, 'hijyen_isg', [])['pagination']['total']);
        $this->assertSame(0, app(CaseList::class)->page($a, 'revir', [])['pagination']['total']);
    }

    public function test_removed_field_review_page_and_related_endpoints_are_unavailable(): void
    {
        $this->actor();
        foreach (['', '/cikti', '/yazdir', '/sutun-degerleri', '/kisiler/1'] as $suffix) {
            $this->get('/alan-inceleme'.$suffix)->assertNotFound();
        }
        $this->putJson('/alan-inceleme/kisiler/1', ['revision' => 0, 'values' => []])->assertNotFound();
        $this->assertFalse(Route::has('services.alan_inceleme.index'));
    }

    public function test_passwords_encrypted_not_listed_filtered_exported_or_audited(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $this->putJson('/egitim/kisiler/'.$id, ['revision' => 0, 'values' => ['egt_okulsifre' => 'SENTETIK_SECRET', 'egt_domainsifre' => 'DOMAIN', 'egt_okul' => 'ışık okul']])->assertOk();
        $this->assertNotSame('SENTETIK_SECRET', DB::table('person_case_data')->value('egt_okulsifre'));
        $this->assertSame('SENTETIK_SECRET', app(CaseRecords::class)->detail($a, 'egitim', $id)['values']['egt_okulsifre']);
        foreach (['/egitim', '/egitim/cikti', '/egitim/yazdir'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('SENTETIK_SECRET');
        }$this->assertArrayNotHasKey('egt_okulsifre', app(CaseList::class)->page($a, 'egitim', [])['fields']);
        $this->getJson('/egitim?sort=egt_okulsifre')->assertUnprocessable();
        $this->getJson('/egitim?filters[0][field]=egt_okulsifre&filters[0][op]=eq&filters[0][value]=SENTETIK_SECRET')->assertUnprocessable();
        $this->putJson('/egitim/kisiler/'.$id, ['revision' => 1, 'values' => ['egt_okulsifre' => 'SENTETIK_SECRET']])->assertOk()->assertJsonPath('revision', 1);
        $this->assertStringNotContainsString('SENTETIK_SECRET', DB::table('audit_events')->pluck('details')->implode(''));
    }

    public function test_sensitive_risk_and_private_append_notes_permissions_and_idempotency(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $u = User::factory()->create()->refresh();
        $this->grant($a, $u, 'ilkgorus', '/ilk-gorus', 'yazma');
        $risk = DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')->where('l.code', 'huk-risk')->value('i.id');
        app(CaseRecords::class)->save($a, 'ilkgorus', $id, ['revision' => 0, 'values' => ['huk_risk' => $risk]]);
        $this->assertArrayNotHasKey('huk_risk', app(CaseRecords::class)->detail($u, 'ilkgorus', $id)['values']);
        $this->actingAs($u)->putJson('/ilk-gorus/kisiler/'.$id, ['revision' => 1, 'values' => ['huk_risk' => $risk]])->assertForbidden();
        $this->grant($a, $u, 'hassas_risk', '/ilk-gorus', 'giris');
        $this->assertTrue(app(CaseRecords::class)->detail($u, 'ilkgorus', $id)['fields']['huk_risk']['readonly']);
        $this->actingAs($a);
        $payload = ['body' => 'ÖZEL SENTETİK NOT', 'is_private' => true, 'request_id' => (string) Str::uuid()];
        $this->postJson('/kisiler/'.$id.'/notlar', $payload)->assertOk();
        $this->postJson('/kisiler/'.$id.'/notlar', $payload)->assertOk();
        $this->assertSame(1, DB::table('person_notes')->count());
        $this->actingAs($u)->getJson('/kisiler/'.$id.'/notlar')->assertJsonCount(0, 'notes');
        $this->postJson('/kisiler/'.$id.'/notlar', ['body' => 'FORGED', 'is_private' => true, 'request_id' => (string) Str::uuid()])->assertForbidden();
        $this->grant($a, $u, 'hassas_ozel_not', '/ilk-gorus', 'yazma');
        $this->getJson('/kisiler/'.$id.'/notlar')->assertJsonPath('notes.0.body', 'ÖZEL SENTETİK NOT');
        $this->postJson('/kisiler/'.$id.'/notlar', ['body' => 'İKİNCİ NOT', 'is_private' => true, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->assertStringNotContainsString('ÖZEL SENTETİK NOT', DB::table('person_notes')->pluck('body')->implode(''));
        $this->assertStringNotContainsString('ÖZEL SENTETİK NOT', DB::table('audit_events')->pluck('details')->implode(''));
    }

    public function test_private_note_composer_filters_before_pagination_and_rejects_unauthorized_scope(): void
    {
        $admin = $this->actor();
        $id = $this->person($admin);
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ['person_id' => $id, 'author_id' => $admin->id, 'author_name' => 'SENTETİK YAZAR', 'is_private' => true,
                'body' => Crypt::encryptString('ÖZEL SENTETİK '.$i), 'request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        }
        for ($i = 0; $i < 2; $i++) {
            $rows[] = ['person_id' => $id, 'author_id' => $admin->id, 'author_name' => 'SENTETİK YAZAR', 'is_private' => false,
                'body' => Crypt::encryptString('GENEL SENTETİK '.$i), 'request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('person_notes')->insert($rows);
        $private = $this->getJson('/kisiler/'.$id.'/notlar?kind=private')->assertOk()->assertJsonPath('total', 105)->assertJsonCount(100, 'notes')->assertDontSee('GENEL SENTETİK');
        $this->assertSame([1], array_unique(array_column($private->json('notes'), 'is_private')));
        $this->getJson('/kisiler/'.$id.'/notlar?kind=private&page=2')->assertOk()->assertJsonCount(5, 'notes');
        $this->getJson('/kisiler/'.$id.'/notlar?kind=general')->assertOk()->assertJsonPath('total', 2)->assertJsonCount(2, 'notes')->assertDontSee('ÖZEL SENTETİK');
        $this->getJson('/kisiler/'.$id.'/notlar?kind=invalid')->assertUnprocessable();
        $staff = User::factory()->create()->refresh();
        $this->grant($admin, $staff, 'hukumlu_kayit', '/portal', 'giris');
        $this->actingAs($staff)->getJson('/kisiler/'.$id.'/notlar?kind=private')->assertForbidden();
        $this->getJson('/kisiler/'.$id.'/notlar')->assertOk()->assertJsonPath('total', 2)->assertDontSee('ÖZEL SENTETİK');
    }

    public function test_psychosocial_boundaries_ds_priority_sql_filter_and_export(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $now = new \DateTimeImmutable('2026-10-04 00:00:00');
        $calc = app(CaseCalculations::class);
        foreach ([8, 10, 12] as $w) {
            $this->assertSame('UYGUN', $calc->suitability($now->modify('+'.($w * 7).' days')->format('Y-m-d'), null, $now, $w));
            $this->assertSame('UYGUN DEĞİL', $calc->suitability($now->modify('+'.($w * 7 - 1).' days')->format('Y-m-d'), null, $now, $w));
        }$this->assertSame('UYGUN DEĞİL', $calc->suitability('2025-01-01', '2027-01-01', $now, 8));
        $this->assertSame('', $calc->suitability(null, null, $now, 8));
        $this->travelTo(Carbon::parse('2026-10-04 00:00:00'));
        app(CaseRecords::class)->save($a, 'infaz', $id, ['revision' => 0, 'values' => ['infaz_ds_tarihi' => '2026-11-29', 'infaz_tahliye_tarihi' => '2027-01-01']]);
        $input = ['filters' => [['field' => 'samba', 'op' => 'eq', 'value' => 'UYGUN']]];
        $r = app(CaseList::class)->page($a, 'psikososyal', $input);
        $this->assertSame('UYGUN', $r['rows'][0]['values']['samba']);
        $this->assertSame('UYGUN DEĞİL', $r['rows'][0]['values']['ofke']);
        $this->assertCount(1, app(CaseList::class)->page($a, 'psikososyal', $input, true)['rows']);
    }

    public function test_mesem_partial_quick_actions_and_stale_revision(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $this->postJson('/mesem/kisiler/'.$id.'/hizli', ['action' => 'basvuru', 'revision' => 0])->assertOk();
        $this->postJson('/mesem/kisiler/'.$id.'/hizli', ['action' => 'istemiyor', 'revision' => 0])->assertConflict();
        $this->postJson('/mesem/kisiler/'.$id.'/hizli', ['action' => 'istemiyor', 'revision' => 1])->assertOk();
        $r = app(CaseList::class)->page($a, 'mesem', [])['rows'][0]['values'];
        $this->assertSame('DİLEKÇE ALINDI', $r['mesem_dilekce']);
        $this->assertSame('BEKLENİYOR', $r['mesem_ogrenim_belgesi']);
        $this->assertSame('İSTEMİYOR', $r['mesem_durumu']);
        $this->putJson('/mesem/kisiler/'.$id, ['revision' => 2, 'values' => ['hijyen_is' => 1]])->assertUnprocessable();
    }

    public function test_staff_tree_cycles_missing_parents_archival_stale_and_read_write(): void
    {
        $a = $this->actor();
        $service = app(StaffTree::class);
        $root = $service->save($a, null, $this->node());
        $child = $service->save($a, null, $this->node(['parent_id' => $root['id'], 'position' => 2]));
        $leaf = $service->save($a, null, $this->node(['parent_id' => $child['id']]));
        $this->putJson('/personel-kadrosu/'.$root['id'], $this->node(['revision' => 1, 'parent_id' => $leaf['id']]))->assertUnprocessable();
        $this->putJson('/personel-kadrosu/'.$root['id'], $this->node(['revision' => 1, 'is_active' => false]))->assertOk();
        $this->assertNotNull($service->read($a)['nodes'][1]['review_reason']);
        $this->putJson('/personel-kadrosu/'.$root['id'], $this->node(['revision' => 1]))->assertConflict();
        $this->putJson('/personel-kadrosu/'.$child['id'], $this->node(['revision' => 1, 'parent_id' => null]))->assertOk();
        foreach ($service->read($a)['nodes'] as $n) {
            if ($n['id'] === $child['id']) {
                $this->assertNull($n['review_reason']);
            }
        }$this->get('/personel-kadrosu')->assertOk();
        $u = User::factory()->create()->refresh();
        $this->actingAs($u)->get('/personel-kadrosu')->assertForbidden();
        $this->grant($a, $u, 'personel_kadrosu', '/personel-kadrosu', 'giris');
        $this->get('/personel-kadrosu')->assertOk();
        $this->postJson('/personel-kadrosu', $this->node())->assertForbidden();
        $this->grant($a, $u, 'personel_kadrosu', '/personel-kadrosu', 'yazma');
        $this->postJson('/personel-kadrosu', $this->node())->assertOk();
    }
}
