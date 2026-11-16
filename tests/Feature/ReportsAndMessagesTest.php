<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\Housing\PersonPlacement;
use App\Services\Institution\CountReport;
use App\Services\Institution\PersonStatistics;
use App\Services\People\PersonRecords;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\CaseReports;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportsAndMessagesTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        $a = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($a)->withSession(['auth_session_version' => 0]);

        return $a;
    }

    private function person(User $a, string $name = 'SENTETİK IŞIK', string $tc = '00000000001'): int
    {
        $id = app(PersonRecords::class)->save($a, ['full_name' => $name, 'tc_identity' => $tc, 'registered_on' => '2026-10-04', 'admitted_on' => null]);
        app(PersonPlacement::class)->save($a, $id, ['status_id' => 1, 'block_id' => null, 'room_id' => null, 'bed_id' => null, 'revision' => 0, 'status_revision' => 0]);

        return $id;
    }

    private function grant(User $a, User $u, string $code, string $path, string $permission = 'giris'): void
    {
        if (! DB::table('portal_pages')->where('code', $code)->exists()) {
            app(PageCatalog::class)->save($a, ['code' => $code, 'name' => $code, 'legacy_file' => null, 'path' => $path]);
        }$page = DB::table('portal_pages')->where('code', $code)->first();
        app(PagePermissions::class)->apply($a, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'kisi', 'target_id' => $u->id, 'reason' => 'Sentetik erişim gerekçesi', 'permission' => $permission, 'operation' => 'grant']);
    }

    public function test_documents_scopes_selection_photos_and_unassigned_count(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $inactive = $this->person($a, 'SENTETİK PASİF', '00000000002');
        DB::table('person_status_assignments')->where('person_id', $inactive)->delete();
        foreach (['teblig', 'bakanlik_sevk', 'kimlik', 'fotograf'] as $kind) {
            $this->get('/belgeler/'.$kind)->assertOk()->assertInertia(fn (Assert $p) => $p->component('PersonDocuments')->has('people', in_array($kind, ['kimlik', 'fotograf']) ? 2 : 1));
        }$this->get('/belgeler/kimlik?preview=1&ids[0]='.$id)->assertInertia(fn (Assert $p) => $p->has('people', 1)->where('people.0.id', $id));
        Storage::fake('local');
        $this->post('/belgeler/fotograf/kisiler/'.$id.'/foto', ['revision' => 0, 'photo' => UploadedFile::fake()->image('foto.png')])->assertOk();
        $this->get('/belgeler/kimlik/kisiler/'.$id.'/foto')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $u = User::factory()->create()->refresh();
        $this->grant($a, $u, 'kimlik', '/belgeler/kimlik');
        $this->actingAs($u)->get('/belgeler/kimlik/kisiler/'.$id.'/foto')->assertOk();
        $this->get('/belgeler/fotograf/kisiler/'.$id.'/foto')->assertForbidden();
        $count = app(CountReport::class)->read();
        $this->assertSame(1, $count['systemTotals']['registered']);
        $this->assertSame(1, collect($count['rows'])->firstWhere('key', 'YERLEŞİMİ ATANMAMIŞ')['system'][0]['registered']);
    }

    public function test_report_tables_use_legacy_column_order_and_existing_leave_values(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        DB::table('person_case_data')->updateOrInsert(['person_id' => $id], [
            'infaz_uyap_no' => 'SENTETIK-DOSYA', 'izin_cikis_tarihi' => now()->toDateString(),
        ]);
        $leave = app(CaseReports::class)->leave($a, ['section' => 'departure']);
        $this->assertSame(['TC Kimlik', 'Hükümlü Adı Soyadı', 'UYAP No', 'İş Kolu', 'Koğuş', 'Ranza', 'Çıkış Tarih', 'Saat', 'Dönüş Tarih', 'Saat'], array_column($leave['fields'], 'label'));
        $this->assertSame('SENTETIK-DOSYA', $leave['rows'][0]['values']['infaz_uyap_no']);
        $this->assertArrayNotHasKey('izin_turu', $leave['fields']);
        $transfer = app(CaseReports::class)->transfer($a, []);
        $this->assertSame(['TC Kimlik', 'Hükümlü Adı Soyadı', 'UYAP No', 'Tahliye Tarihi', 'Kalan Gün', 'D.S. Tarihi', 'DS Kalan Gün'], array_column($transfer['fields'], 'label'));
        $this->assertArrayNotHasKey('infaz_dogum_tarihi', $transfer['fields']);
        $this->get('/izin-raporu/yazdir?section=departure')->assertOk();
    }

    public function test_count_collisions_do_not_overwrite_and_missing_side_not_zero(): void
    {
        $a = $this->actor();
        $this->person($a);
        $result = app(CountReport::class)->read(['rows' => [['blok' => 'A', 'kogus' => '1', 'kayitli' => 4, 'gayri_mevcut' => 1, 'hazir' => 3], ['blok' => 'A', 'kogus' => '01', 'kayitli' => 7, 'gayri_mevcut' => 0, 'hazir' => 7]]]);
        $collision = collect($result['rows'])->firstWhere('status', 'Çakışan anahtar');
        $this->assertNotNull($collision);
        $this->assertCount(2, $collision['file']);
        $this->assertSame([], $collision['difference']);
        $this->assertSame(11, $result['fileTotals']['registered']);
        $unassigned = collect($result['rows'])->firstWhere('key', 'YERLEŞİMİ ATANMAMIŞ');
        $this->assertSame('Yalnız sistemde', $unassigned['status']);
        $this->assertSame([], $unassigned['file']);
        $this->get('/sayim')->assertOk();
        $this->postJson('/sayim', ['file' => UploadedFile::fake()->createWithContent('bad.xlsx', '<!DOCTYPE xml>')])->assertUnprocessable();
    }

    public function test_statistics_mesem_predicates_risk_and_custom_report_scope(): void
    {
        $a = $this->actor();
        $this->travelTo(Carbon::parse('2026-10-04 10:00:00'));
        $id = $this->person($a);
        $inactive = $this->person($a, 'SENTETİK PASİF', '00000000002');
        DB::table('person_status_assignments')->where('person_id', $inactive)->delete();
        $this->postJson('/mesem/kisiler/'.$id.'/hizli', ['action' => 'basvuru', 'revision' => 0])->assertOk();
        $decision = collect(app(CaseRecords::class)->options()['huk_karar'])->firstWhere('semantic', 'İŞYURTLARI');
        $isgOption = collect(app(CaseRecords::class)->options()['hijyen_is'])->firstWhere('semantic', 'ALDI');
        $educationOption = collect(app(CaseRecords::class)->options()['mesem_ogrenim_belgesi'])->firstWhere('semantic', 'MEVCUT');
        DB::table('person_case_data')->where('person_id', $id)->update(['huk_karar' => $decision['id'], 'mesem_gorusme' => null, 'hijyen_is' => $isgOption['id'] ?? null, 'mesem_ogrenim_belgesi' => $educationOption['id'] ?? null]);
        $stats = app(PersonStatistics::class)->read($a, 'mesemstat');
        $this->assertSame(1, $stats['summary']['total']);
        $this->assertSame(1, $stats['summary']['today']);
        $this->assertCount(32, $stats['metrics']);
        $metrics = collect($stats['metrics'])->pluck('count', 'key');
        $this->assertSame(1, $metrics['isg_aldi']);
        $this->assertSame(1, $metrics['ogrenim_alindi']);
        $this->assertSame('00000000001', $stats['waiting']['isyurtlari'][0]['tc_identity']);
        foreach (['/istatistik', '/mesem-istatistik', '/ozel-rapor', '/ozel-rapor/cikti'] as $url) {
            $this->get($url)->assertOk();
        }$u = User::factory()->create()->refresh();
        $this->grant($a, $u, 'istatistik', '/istatistik');
        $stats = app(PersonStatistics::class)->read($u, 'istatistik');
        $this->assertNotContains('huk_risk', array_column($stats['sections'], 'key'));
        $this->grant($a, $u, 'custom_rapor', '/ozel-rapor');
        $fields = app(CaseList::class)->fields('custom_rapor', $u);
        $this->assertArrayNotHasKey('egt_okulsifre', $fields);
        $this->assertArrayNotHasKey('revir_saglik_rapor', $fields);
        $this->actingAs($u)->get('/ozel-rapor?columns[0]=egt_domainsifre')->assertForbidden();
    }

    public function test_custom_report_exports_only_selected_fields_without_reintroducing_identity(): void
    {
        $actor = $this->actor();
        $this->person($actor);
        $list = app(CaseList::class);
        $snapshot = $list->page($actor, 'custom_rapor', ['columns' => ['registered_on']], true);
        $this->assertSame(['registered_on'], array_column($snapshot['fields'], 'id'));
        $this->assertSame([['2026-10-04']], $snapshot['rows']);
        $this->get('/ozel-rapor/cikti?columns[0]=registered_on')->assertOk()->assertDontSee('TC Kimlik')->assertDontSee('SENTETİK IŞIK')->assertSee('2026-10-04');
        $standard = $list->page($actor, 'infaz', ['columns' => ['infaz_ds_tarihi']], true);
        $this->assertSame(['tc_identity', 'full_name', 'infaz_ds_tarihi'], array_column($standard['fields'], 'id'));
    }

    public function test_room_rename_and_bulk_status_preview_stale_atomic_rules(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $this->get('/koguslar')->assertOk();
        $this->get('/koguslar/cikti')->assertOk()->assertSee('SENTETİK IŞIK');
        $r = DB::table('housing_rooms')->first();
        $this->putJson('/koguslar/'.$r->id, ['name' => 'SENTETİK KOĞUŞ', 'revision' => $r->revision])->assertOk();
        $this->putJson('/koguslar/'.$r->id, ['name' => 'ESKİ', 'revision' => $r->revision])->assertConflict();
        $target = DB::table('person_statuses')->where('code', 'present')->value('id') ?? 2;
        $preview = $this->postJson('/toplu-durum', ['action' => 'preview', 'from' => 1, 'to' => $target])->assertOk()->assertJsonPath('count', 1)->json();
        $this->person($a, 'SENTETİK İKİ', '00000000002');
        $this->postJson('/toplu-durum', ['action' => 'apply', 'from' => 1, 'to' => $target, 'token' => $preview['token']])->assertConflict();
        $preview = $this->postJson('/toplu-durum', ['action' => 'preview', 'from' => 1, 'to' => $target])->assertOk()->json();
        $this->postJson('/toplu-durum', ['action' => 'apply', 'from' => 1, 'to' => $target, 'token' => $preview['token']])->assertOk()->assertJsonPath('count', 2);
        $this->assertSame(2, DB::table('person_status_assignments')->where('status_id', $target)->count());
    }

    public function test_personal_message_ownership_receipt_side_deletion_expiry_and_download(): void
    {
        $a = $this->actor();
        $u = User::factory()->create()->refresh();
        $other = User::factory()->create()->refresh();
        $this->grant($a, $u, 'mesajlar', '/mesajlar');
        $this->grant($a, $other, 'mesajlar', '/mesajlar');
        Storage::fake('local');
        $payload = ['action' => 'send', 'recipient_id' => $u->id, 'subject' => 'SENTETİK KONU', 'body' => 'SENTETİK GİZLİ METİN', 'request_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->createWithContent('ek.txt', 'SENTETİK EK')];
        $id = $this->post('/mesajlar', $payload)->assertOk()->json('id');
        $this->assertStringNotContainsString('SENTETİK GİZLİ METİN', DB::table('personal_messages')->value('body'));
        $file = DB::table('message_attachments')->first();
        $this->get('/mesaj-ekleri/'.$file->id)->assertOk()->assertSee('SENTETİK EK');
        Storage::disk('local')->assertExists($file->path);
        $this->actingAs($other)->get('/mesajlar/'.$id)->assertNotFound();
        $this->get('/mesaj-ekleri/'.$file->id)->assertNotFound();
        $this->actingAs($u)->get('/mesajlar')->assertOk();
        $this->get('/mesajlar/'.$id)->assertOk()->assertJsonPath('message.body', 'SENTETİK GİZLİ METİN');
        $this->assertNull(DB::table('personal_messages')->value('read_at'));
        $this->postJson('/mesajlar', ['action' => 'read', 'message' => $id])->assertOk();
        $this->assertNotNull(DB::table('personal_messages')->value('read_at'));
        $this->get('/mesaj-ekleri/'.$file->id)->assertOk()->assertSee('SENTETİK EK');
        Storage::disk('local')->assertExists($file->path);
        $deadline = DB::table('message_attachments')->where('id', $file->id)->value('expires_at');
        $started = DB::table('message_attachments')->where('id', $file->id)->value('recipient_downloaded_at');
        $this->assertNotNull($started);
        $this->travel(2)->minutes();
        $this->get('/mesaj-ekleri/'.$file->id)->assertOk();
        $this->assertSame($deadline, DB::table('message_attachments')->where('id', $file->id)->value('expires_at'));
        $this->assertSame($started, DB::table('message_attachments')->where('id', $file->id)->value('recipient_downloaded_at'));
        $this->travel(6)->minutes();
        $this->get('/mesaj-ekleri/'.$file->id)->assertNotFound();
        $this->travelBack();
        $this->postJson('/mesajlar', ['action' => 'delete', 'message' => $id])->assertOk();
        $this->get('/mesajlar/'.$id)->assertNotFound();
        $this->actingAs($a)->get('/mesajlar/'.$id)->assertOk();
        $this->actingAs($u)->postJson('/mesajlar', ['action' => 'send', 'recipient_id' => $a->id, 'subject' => 'YOK', 'body' => 'YOK', 'request_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_xml_parser_import_duplicates_forged_selection_and_text_review(): void
    {
        $a = $this->actor();
        $xml = '<jasperPrint><page><frame>';
        foreach ([[1, '00000000001', 'SENTETİK XML'], [2, '00000000002', 'DUP ONE'], [3, '00000000002', 'DUP TWO']] as [$seq,$tc,$name]) {
            foreach ([0 => (string) $seq, 30 => $tc, 100 => $name] as $x => $value) {
                $xml .= '<text><reportElement x="'.$x.'" y="'.($seq * 20).'"/><textContent>'.$value.'</textContent></text>';
            }
        }
        $xml .= '</frame></page></jasperPrint>';
        $this->post('/uyap-karsilastirma', ['file' => UploadedFile::fake()->createWithContent('uyap.xml', $xml)])->assertOk()->assertJsonPath('result.summary.uyap_gecerli_satir', 3)->assertJsonPath('filename', 'uyap.xml');
        $this->getJson('/uyap-karsilastirma')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('filename', 'uyap.xml')->assertJsonPath('canImport', true)->assertJsonCount(2, 'result.uyap_only');
        $this->get('/uyap-karsilastirma')->assertInertia(fn (Assert $p) => $p->where('result.summary.uyap_gecerli_satir', 3));
        $this->postJson('/uyap-karsilastirma', ['action' => 'add', 'selected' => ['00000000002']])->assertUnprocessable();
        $this->postJson('/uyap-karsilastirma', ['action' => 'add', 'selected' => ['00000000009']])->assertUnprocessable();
        $this->postJson('/uyap-karsilastirma', ['action' => 'add', 'selected' => ['00000000001']])->assertOk()->assertJsonPath('created', 1);
        $this->postJson('/uyap-karsilastirma', ['action' => 'add', 'selected' => ['00000000001']])->assertOk()->assertJsonPath('created', 0)->assertJsonPath('skipped', 1);
        $this->assertDatabaseHas('people', ['tc_identity' => '00000000001', 'full_name' => 'SENTETİK XML']);
        $this->postJson('/uyap-karsilastirma', ['action' => 'text-preview', 'text' => "00000000003 SENTETİK ÜÇ\nYALNIZ AD"])->assertOk()->assertJsonCount(2, 'records')->assertJsonCount(1, 'review');
        $this->postJson('/uyap-karsilastirma', ['action' => 'text-add', 'text' => '00000000003 SENTETİK ÜÇ', 'selected' => ['00000000003']])->assertOk()->assertJsonPath('created', 1);
        $this->post('/uyap-karsilastirma', ['file' => UploadedFile::fake()->createWithContent('uyap.xml', '<!DOCTYPE jasperPrint [<!ENTITY ext SYSTEM "file:///etc/passwd">]><jasperPrint><page>&ext;</page></jasperPrint>')])->assertUnprocessable();
    }

    public function test_all_121_fields_have_explicit_destinations_and_legacy_navigation_is_owned(): void
    {
        $a = $this->actor();
        $map = json_decode(file_get_contents(resource_path('defaults/legacy-person-field-map.json')), true);
        $this->assertSame(121, $map['sourceFieldCount']);
        $this->assertFalse($map['automaticImport']);
        $this->assertSame(array_keys(config('legacy_core.fields')), array_keys($map['fields']));
        foreach ($map['fields'] as $field => $contract) {
            $this->assertNotEmpty($contract['destination']);
            if (str_starts_with($contract['destination'], 'person_case_data.')) {
                $this->assertTrue(Schema::hasColumn('person_case_data', $field));
            }
        }
        $this->get('/birimler/servisler/ilkgorus.php')->assertRedirect(route('interviews.index'));
        $this->get('/index.php?sayfa=birimler/infaz/teblig.php')->assertRedirect(route('documents.teblig.index'));
        $this->get('/index.php?sayfa=https://example.com')->assertNotFound();
        $this->post('/birimler/servisler/ilkgorus.php')->assertStatus(405);
        $id = $this->person($a);
        app(CaseRecords::class)->save($a, 'infaz', $id, ['revision' => 0, 'values' => ['infaz_uyap_no' => '2026/1', 'infaz_ds_tarihi' => '2027-01-01']]);
        $this->get('/belgeler/kimlik')->assertInertia(fn (Assert $p) => $p->missing('people.0.ds')->missing('people.0.uyap'));
        $this->get('/belgeler/teblig')->assertInertia(fn (Assert $p) => $p->where('people.0.uyap', '2026/1')->missing('people.0.ds'));
        $this->get('/ozel-rapor?scope=all')->assertOk();
    }

    public function test_popup_xml_snapshot_rejects_another_tabs_replacement_before_mutation(): void
    {
        $this->actor();
        $source = ['records' => [['tc' => '00000000001', 'ad_soyad' => 'SENTETİK İLK DOSYA', 'sira_no' => 1]]];
        $snapshot = hash('sha256', json_encode($source, JSON_THROW_ON_ERROR));
        $this->withSession(['comparison.uyap_karsilastirma' => ['source' => $source, 'parsed' => [], 'filename' => 'ilk.xml']]);
        $this->getJson('/uyap-karsilastirma')->assertOk()->assertJsonPath('snapshot', $snapshot);
        $replacement = ['records' => [['tc' => '00000000001', 'ad_soyad' => 'SENTETİK BAŞKA DOSYA', 'sira_no' => 1]]];
        $this->withSession(['comparison.uyap_karsilastirma' => ['source' => $replacement, 'parsed' => [], 'filename' => 'baska.xml']]);
        $this->postJson('/uyap-karsilastirma', ['action' => 'add', 'selected' => ['00000000001'], 'snapshot' => $snapshot])->assertConflict();
        $this->postJson('/uyap-karsilastirma', ['action' => 'delete-preview', 'ids' => [1], 'xml_only' => true, 'snapshot' => $snapshot])->assertConflict();
        $this->assertDatabaseCount('people', 0);
        $this->postJson('/uyap-karsilastirma', ['action' => 'add', 'selected' => ['00000000001'], 'snapshot' => hash('sha256', json_encode($replacement, JSON_THROW_ON_ERROR))])->assertOk()->assertJsonPath('created', 1);
        $this->assertDatabaseHas('people', ['full_name' => 'SENTETİK BAŞKA DOSYA']);
        $this->getJson('/uyap-karsilastirma')->assertOk()->assertJsonPath('result.summary.uygulama_kayit', 1)->assertJsonPath('result.summary.eslesen', 1)->assertJsonPath('result.summary.uyapta_var_uygulamada_yok', 0);
    }

    public function test_excel_merge_matches_names_without_conflating_other_tc_and_private_output(): void
    {
        $a = $this->actor();
        Storage::fake('local');
        $one = '<table><tr><th>TC Kimlik No</th><th>Adı Soyadı</th><th>Not</th></tr><tr><td>00000000001</td><td>İŞIK KİŞİ</td><td>=SUM(1,2)</td></tr><tr><td>00000000002</td><td>AYNI AD</td><td>iki</td></tr></table>';
        $two = '<table><tr><th>TC Kimlik No</th><th>Adı Soyadı</th><th>Alan</th></tr><tr><td></td><td>IŞIK KİŞİ</td><td>eşleşti</td></tr><tr><td>00000000003</td><td>AYNI AD</td><td>ayrı</td></tr></table>';
        $this->post('/excel-birlestir', ['files' => [UploadedFile::fake()->createWithContent('bir.xls', $one), UploadedFile::fake()->createWithContent('iki.html', $two)]])->assertOk();
        $this->get('/excel-birlestir')->assertInertia(fn (Assert $p) => $p->where('result.total_records', 3));
        $download = $this->get('/excel-birlestir/indir')->assertOk();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($download->baseResponse->getFile()->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('=SUM(1,2)', $sheet);
        $this->assertStringContainsString('00000000001', $sheet);
        $zip->close();
        $u = User::factory()->create()->refresh();
        $this->grant($a, $u, 'excel_merge', '/excel-birlestir');
        $this->actingAs($u)->get('/excel-birlestir/indir')->assertNotFound();
    }

    public function test_selected_status_and_deletion_require_current_preview_and_are_atomic(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $other = $this->person($a, 'SENTETİK İKİ', '00000000002');
        $target = DB::table('person_statuses')->where('code', 'present')->value('id') ?? 2;
        $payload = ['action' => 'status-preview', 'ids' => [$id], 'status' => $target];
        $preview = $this->postJson('/uyap-karsilastirma', $payload)->assertOk()->assertJsonPath('count', 1)->json();
        DB::table('people')->where('id', $id)->increment('revision');
        $this->postJson('/uyap-karsilastirma', array_replace($payload, ['action' => 'status-apply', 'token' => $preview['token']]))->assertConflict();
        $preview = $this->postJson('/uyap-karsilastirma', $payload)->assertOk()->json();
        $this->postJson('/uyap-karsilastirma', array_replace($payload, ['action' => 'status-apply', 'token' => $preview['token']]))->assertOk();
        $this->assertDatabaseHas('person_status_assignments', ['person_id' => $other, 'status_id' => 1]);
        $this->assertDatabaseHas('person_status_assignments', ['person_id' => $id, 'status_id' => $target]);
        $payload = ['action' => 'delete-preview', 'ids' => [$id]];
        $preview = $this->postJson('/uyap-karsilastirma', $payload)->assertOk()->json();
        $apply = ['action' => 'delete-apply', 'ids' => [$id], 'token' => $preview['token']];
        $this->postJson('/uyap-karsilastirma', $apply)->assertUnprocessable();
        DB::statement("CREATE TRIGGER synthetic_batch_audit_failure BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        $this->postJson('/uyap-karsilastirma', $apply + ['confirmation' => 'KALICI SİL'])->assertStatus(500);
        $this->assertDatabaseHas('people', ['id' => $id]);
        $this->assertDatabaseHas('person_status_assignments', ['person_id' => $id, 'status_id' => $target]);
        DB::statement('DROP TRIGGER synthetic_batch_audit_failure');
        $this->postJson('/uyap-karsilastirma', $apply + ['confirmation' => 'KALICI SİL'])->assertOk();
        $this->assertDatabaseMissing('people', ['id' => $id]);
        $this->assertDatabaseHas('people', ['id' => $other]);
    }

    public function test_source_page_permissions_allow_owned_routes_without_granting_writes(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $u = User::factory()->create()->refresh();
        $this->grant($a, $u, 'hukumlu_kayit', '/portal');
        $this->grant($a, $u, 'kogus', '/koguslar');
        $this->actingAs($u)->get('/uyap-karsilastirma')->assertOk();
        $this->get('/koguslar')->assertOk();
        $this->postJson('/uyap-karsilastirma', ['action' => 'delete-preview', 'ids' => [$id]])->assertForbidden();
        $this->postJson('/uyap-karsilastirma', ['action' => 'search', 'text' => '00000000001'])->assertOk()->assertJsonPath('people.0.id', $id);
        $this->getJson('/uyap-karsilastirma')->assertOk()->assertJsonPath('canImport', false)->assertJsonPath('result', null);
        $this->postJson('/uyap-karsilastirma', ['action' => 'text-preview', 'text' => '00000000003 SENTETİK'])->assertForbidden();
        DB::table('portal_pages')->where('code', 'kogus')->update(['is_active' => false]);
        $this->get('/koguslar')->assertForbidden();
    }

    public function test_name_only_import_preserves_sensitive_policy_and_xml_deletion_scope(): void
    {
        $a = $this->actor();
        $id = $this->person($a);
        $u = User::factory()->create()->refresh();
        $this->grant($a, $u, 'hukumlu_kayit', '/portal', 'yazma');
        $this->actingAs($u)->postJson('/uyap-karsilastirma', ['action' => 'text-add', 'text' => 'yalnız kişi', 'selected' => ['name:1']])->assertOk()->assertJsonPath('created', 1);
        $person = DB::table('people')->where('full_name', 'YALNIZ KİŞİ')->first();
        $this->assertNotNull($person);
        $this->assertNull($person->tc_identity);
        $this->assertDatabaseHas('person_case_data', ['person_id' => $person->id, 'huk_risk' => null, 'huk_saglik' => 'SAĞLIKLI']);
        $this->postJson('/uyap-karsilastirma', ['action' => 'delete-preview', 'ids' => [$id], 'xml_only' => true])->assertUnprocessable();
        $this->withSession(['comparison.uyap_karsilastirma' => ['source' => ['records' => [['tc' => '00000000001']]]]])->postJson('/uyap-karsilastirma', ['action' => 'delete-preview', 'ids' => [$id], 'xml_only' => true])->assertUnprocessable();
        $this->assertDatabaseHas('people', ['id' => $id]);
    }
}
