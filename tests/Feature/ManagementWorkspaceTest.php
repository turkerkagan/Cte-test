<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\Administration\AccountRequests;
use App\Services\Administration\BoardDefinitions;
use App\Services\Administration\BuiltinMenu;
use App\Services\Administration\ManagedDatabase;
use App\Services\Administration\ManagedFiles;
use App\Services\Administration\ManagementNavigation;
use App\Services\Administration\ManagementReports;
use App\Services\Administration\ManagementSettings;
use App\Services\Housing\PersonPlacement;
use App\Services\Institution\InstitutionRecords;
use App\Services\People\PersonRecords;
use App\Services\Workflows\CaseRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManagementWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/cte-admin-tests-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->workspace);
        mkdir($this->workspace.'/framework/views', 0700, true);
        config(['view.compiled' => $this->workspace.'/framework/views']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }rmdir($this->workspace);
        }parent::tearDown();
    }

    private function actor(): User
    {
        $user = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($user)->withSession(['auth_session_version' => 0]);

        return $user;
    }

    public function test_management_menu_matches_legacy_groups_and_every_destination_opens(): void
    {
        $this->actor();
        $groups = app(ManagementNavigation::class)->groups();
        $this->assertSame([
            'Genel Bakış' => ['Genel Bakış'],
            'Kullanıcılar ve Erişim' => ['Kullanıcılar ve Erişim', 'Erişim Politikaları'],
            'Kurum Yapısı' => ['Personel Kadrosu', 'Kurum Birimleri', 'Unvanlar', 'Telefon Rehberi', 'Kurul Başlıkları'],
            'İçerik' => ['Giriş & Footer Metinleri'],
            'Raporlama ve Denetim' => ['Kullanıcı Girişleri', 'İşlem Kayıtları'],
            'Sistem ve Güvenlik' => ['Veritabanı & Sistem', 'Kurtarma & Dosya Yöneticisi'],
        ], collect($groups)->mapWithKeys(fn ($group) => [$group['label'] => array_column($group['children'], 'label')])->all());
        $links = collect($groups)->flatMap(fn ($group) => $group['children']);
        $this->assertCount(13, $links);
        $this->assertCount(13, $links->pluck('url')->unique());
        foreach ($links as $link) {
            $this->assertNotEmpty($link['icon']);
            $this->get($link['url'])->assertOk();
        }
        $this->get('/yonetim/personel-kadrosu')->assertInertia(fn (Assert $page) => $page->where('management', true));
    }

    public function test_database_subpages_preserve_selected_tab_and_default_to_tables(): void
    {
        $this->actor();
        $this->getJson('/yonetim/veritabani')->assertOk()->assertJsonPath('queryState.tab', 'tables');
        foreach (['health', 'tables', 'backups', 'repair', 'conversion'] as $tab) {
            $this->getJson('/yonetim/veritabani?tab='.$tab)->assertOk()->assertJsonPath('queryState.tab', $tab);
        }
    }

    public function test_all_admin_screens_are_real_routes_and_staff_and_guests_cannot_open_them(): void
    {
        $paths = ['/yonetim', '/yonetim/metinler', '/yonetim/kurul-basliklari', '/yonetim/girisler', '/yonetim/islem-kayitlari', '/yonetim/talepler', '/yonetim/alanlar', '/yonetim/veritabani', '/yonetim/kurtarma'];
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
        $staff = User::factory()->create()->refresh();
        $this->actingAs($staff)->withSession(['auth_session_version' => 0]);
        foreach ($paths as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->actor();
        foreach ($paths as $path) {
            $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $a) => $a->component('AdminWorkspace')->has('managementNavigation', 6));
        }
        $this->get('/yonetim/personel-kadrosu')->assertInertia(fn (Assert $a) => $a->component('StaffTree')->where('baseUrl', url('/yonetim/personel-kadrosu')));
        $this->get('/yonetim/telefon')->assertInertia(fn (Assert $a) => $a->component('Institution')->where('management', true)->where('baseUrl', url('/yonetim/telefon')));
        $this->get('/yonetim/veritabani?tab=tables&table=users')->assertOk()->assertInertia(fn (Assert $a) => $a->missing('rows.0.password')->missing('rows.0.remember_token'));
    }

    public function test_settings_are_versioned_and_login_and_common_footer_consume_them(): void
    {
        $admin = $this->actor();
        $state = app(ManagementSettings::class)->appearance();
        $values = $state['values'];
        $values['login_title'] = 'SENTETİK KURUM';
        $values['footer_center'] = 'SENTETİK FOOTER';
        $this->postJson('/yonetim/metinler', ['revision' => 0, 'values' => $values])->assertOk();
        $this->postJson('/yonetim/metinler', ['revision' => 0, 'values' => $values])->assertConflict();
        $this->get('/portal')->assertInertia(fn (Assert $a) => $a->where('appearance.footer_center', 'SENTETİK FOOTER'));
        DB::table('users')->where('id', $admin->id)->update(['is_system_admin' => false]);
        $this->postJson('/yonetim/metinler', ['revision' => 1, 'values' => $values])->assertForbidden();
        $this->assertSame(1, DB::table('administration_settings')->where('key', 'appearance')->value('revision'));
    }

    public function test_requests_do_not_create_accounts_until_approved_and_passwords_are_never_logged(): void
    {
        $admin = $this->actor();
        $department = DB::table('departments')->insertGetId(['code' => 'TEST', 'name' => 'TEST', 'is_active' => true]);
        $title = DB::table('job_titles')->insertGetId(['code' => 'TEST', 'name' => 'TEST', 'is_active' => true]);
        $service = app(AccountRequests::class);
        $service->submit('application', ['username' => 'synthetic.application', 'full_name' => 'SENTETİK BAŞVURU', 'department_id' => $department, 'job_title_id' => $title]);
        $service->submit('application', ['username' => 'synthetic.application', 'full_name' => 'SENTETİK BAŞVURU', 'department_id' => $department, 'job_title_id' => $title]);
        $this->assertDatabaseCount('account_requests', 1);
        $this->assertDatabaseCount('users', 1);
        $request = DB::table('account_requests')->first();
        $base = ['id' => $request->id, 'revision' => 1, 'action' => 'approve', 'confirmed' => true];
        $this->postJson('/yonetim/talepler', $base + ['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/yonetim/talepler', $base + ['password' => 'synthetic-strong-password', 'password_confirmation' => 'synthetic-strong-password'])->assertOk();
        $user = User::where('username', 'synthetic.application')->first();
        $this->assertFalse($user->is_system_admin);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('synthetic-strong-password', $user->password));
        $this->postJson('/yonetim/talepler', $base + ['password' => 'synthetic-strong-password', 'password_confirmation' => 'synthetic-strong-password'])->assertConflict();
        $this->assertStringNotContainsString('synthetic-strong-password', DB::table('audit_events')->pluck('details')->implode(' '));
        $service->submit('password', ['username' => 'synthetic.application']);
        $request = DB::table('account_requests')->where('kind', 'password')->first();
        $this->postJson('/yonetim/talepler', ['id' => $request->id, 'revision' => 1, 'action' => 'password', 'confirmed' => true, 'user_revision' => $user->revision, 'password' => 'another-synthetic-password', 'password_confirmation' => 'another-synthetic-password'])->assertOk();
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertDatabaseHas('account_requests', ['id' => $request->id, 'status' => 'completed']);
    }

    public function test_board_titles_reorder_add_and_archive_without_deleting_member_values(): void
    {
        $admin = $this->actor();
        $service = app(BoardDefinitions::class);
        $state = $service->read();
        $rows = app(InstitutionRecords::class)->read($admin, 'kurul')['rows'];
        $row = $rows[0];
        app(InstitutionRecords::class)->save($admin, 'kurul', $row['id'], ['revision' => 1, 'values' => ['discipline_top' => 'SENTETİK ÜYE']]);
        $items = $state['values']['items'];
        $items[0]['is_active'] = false;
        $items = array_reverse($items);
        $items[] = ['code' => 'new_board', 'label' => 'SENTETİK KURUL', 'is_active' => true];
        $service->save($admin, ['revision' => 0, 'items' => $items]);
        $this->assertArrayHasKey('new_board_top', $service->fields());
        $this->assertArrayNotHasKey('discipline_top', $service->fields());
        $stored = json_decode(DB::table('institution_records')->where('id', $row['id'])->value('values'), true);
        $this->assertSame('SENTETİK ÜYE', $stored['discipline_top']);
        app(InstitutionRecords::class)->save($admin, 'kurul', $row['id'], ['revision' => 2, 'values' => ['new_board_top' => 'YENİ ÜYE']]);
        $stored = json_decode(DB::table('institution_records')->where('id', $row['id'])->value('values'), true);
        $this->assertSame('SENTETİK ÜYE', $stored['discipline_top']);
        $this->assertSame('YENİ ÜYE', $stored['new_board_top']);
    }

    public function test_custom_fields_are_saved_filtered_printed_and_archived_in_the_actual_workflow(): void
    {
        $admin = $this->actor();
        $id = app(PersonRecords::class)->save($admin, ['full_name' => 'SENTETİK ALAN', 'tc_identity' => '00000000001', 'admitted_on' => null, 'registered_on' => '2026-01-01']);
        app(PersonPlacement::class)->save($admin, $id, ['status_id' => 1, 'block_id' => null, 'room_id' => null, 'bed_id' => null, 'revision' => 0, 'status_revision' => 0]);
        $item = ['key' => 'custom_admin_note', 'label' => 'Yönetim Notu', 'type' => 'text', 'group' => 'infaz', 'table_visible' => true, 'form_visible' => true, 'required' => false, 'readonly' => false, 'position' => 1];
        $this->postJson('/yonetim/alanlar', ['revision' => 0, 'items' => [$item]])->assertOk();
        $detail = app(CaseRecords::class)->detail($admin, 'infaz', $id);
        $this->assertArrayHasKey('custom_admin_note', $detail['fields']);
        $this->putJson('/infaz/kisiler/'.$id, ['revision' => 0, 'values' => ['custom_admin_note' => 'SENTETİK DEĞER']])->assertOk();
        $this->getJson('/infaz?sort=custom_admin_note&direction=asc')->assertOk()->assertJsonPath('rows.0.values.custom_admin_note', 'SENTETİK DEĞER');
        $this->getJson('/infaz/sutun-degerleri?field=custom_admin_note')->assertOk()->assertJsonPath('values.0.value', 'SENTETİK DEĞER');
        $this->get('/infaz/yazdir')->assertInertia(fn (Assert $a) => $a->where('fields', fn ($f) => collect($f)->contains('id', 'custom_admin_note')));
        $item['table_visible'] = false;
        $item['form_visible'] = false;
        $this->postJson('/yonetim/alanlar', ['revision' => 1, 'items' => [$item]])->assertOk();
        $this->getJson('/infaz')->assertOk()->assertJsonMissingPath('fields.custom_admin_note');
        $this->assertDatabaseHas('person_case_data', ['person_id' => $id, 'custom_admin_note' => 'SENTETİK DEĞER']);
        $this->putJson('/infaz/kisiler/'.$id, ['revision' => 1, 'values' => ['custom_admin_note' => 'FORBIDDEN']])->assertUnprocessable();
    }

    public function test_file_versions_trash_and_path_guards_keep_originals_recoverable(): void
    {
        $this->actor();
        $empty = hash('sha256', '');
        $url = '/yonetim/kurtarma';
        $this->postJson($url, ['action' => 'folder', 'path' => 'documents'])->assertOk();
        $this->postJson($url, ['action' => 'save', 'path' => 'documents/a.txt', 'text' => 'FIRST', 'checksum' => $empty])->assertOk();
        $this->postJson($url, ['action' => 'save', 'path' => 'documents/a.txt', 'text' => 'SECOND', 'checksum' => hash('sha256', 'FIRST')])->assertOk();
        $this->postJson($url, ['action' => 'save', 'path' => 'documents/a.txt', 'text' => 'STALE', 'checksum' => hash('sha256', 'FIRST')])->assertConflict();
        $data = $this->getJson($url.'?path=documents/a.txt')->assertOk()->json();
        $this->assertCount(1, $data['versions']);
        $this->postJson($url, ['action' => 'version', 'path' => 'documents/a.txt', 'version_id' => $data['versions'][0]['id'], 'checksum' => $data['checksum']])->assertOk();
        $this->getJson($url.'?path=documents/a.txt')->assertJsonPath('text', 'FIRST');
        $this->postJson($url, ['action' => 'copy', 'path' => 'documents/a.txt', 'target' => 'documents/b.txt'])->assertOk();
        $this->postJson($url, ['action' => 'delete', 'path' => 'documents/a.txt'])->assertOk();
        $data = $this->getJson($url)->assertOk()->json();
        $this->postJson($url, ['action' => 'restore', 'path' => 'documents/a.txt', 'trash_id' => $data['trash'][0]['id']])->assertOk();
        foreach (['../.env', '/etc/passwd', 'documents/../../secret', 'documents/.hidden', 'documents/a.php', 'documents\\a.txt', 'C:\\secret.txt'] as $bad) {
            $this->postJson($url, ['action' => 'save', 'path' => $bad, 'text' => 'NO', 'checksum' => $empty])->assertUnprocessable();
        }
        $link = app(ManagedFiles::class)->root().'/escape';
        $this->assertTrue(symlink(sys_get_temp_dir(), $link));
        try {
            $this->getJson($url.'?path=escape')->assertUnprocessable();
        } finally {
            PHP_OS_FAMILY === 'Windows' ? rmdir($link) : unlink($link);
        }
    }

    public function test_zip_upload_download_and_extraction_reject_paths_without_partial_writes(): void
    {
        $this->actor();
        $service = app(ManagedFiles::class);
        $root = $service->root();
        $zip = new \ZipArchive;
        $zip->open($root.'/bundle.zip', \ZipArchive::CREATE);
        $zip->addFromString('documents/a.txt', 'SENTETİK ZIP');
        $zip->close();
        $this->getJson('/yonetim/kurtarma?path=bundle.zip')->assertOk()->assertJsonPath('editable', false);
        $this->get('/yonetim/dosya-indir?path=bundle.zip')->assertOk()->assertDownload('bundle.zip');
        $this->postJson('/yonetim/kurtarma', ['action' => 'extract', 'path' => 'bundle.zip', 'target' => 'extracted'])->assertOk();
        $this->assertSame('SENTETİK ZIP', file_get_contents($root.'/extracted/documents/a.txt'));
        $this->postJson('/yonetim/kurtarma', ['action' => 'copy', 'path' => 'extracted', 'target' => 'copy'])->assertOk();
        $this->assertFileExists($root.'/copy/documents/a.txt');
        $this->postJson('/yonetim/kurtarma', ['action' => 'move', 'path' => 'copy', 'target' => 'moved'])->assertOk();
        $this->assertFileExists($root.'/moved/documents/a.txt');
        $this->assertDirectoryDoesNotExist($root.'/copy');
        $zip = new \ZipArchive;
        $zip->open($root.'/bad.zip', \ZipArchive::CREATE);
        $zip->addFromString('good.txt', 'OK');
        $zip->addFromString('../escape.txt', 'NO');
        $zip->close();
        $this->postJson('/yonetim/kurtarma', ['action' => 'extract', 'path' => 'bad.zip', 'target' => 'bad'])->assertUnprocessable();
        $this->assertDirectoryDoesNotExist($root.'/bad');
        $this->assertFileDoesNotExist($root.'/escape.txt');
    }

    public function test_audit_reports_redact_nested_credentials_and_login_events_have_real_counts(): void
    {
        $admin = $this->actor();
        DB::table('audit_events')->insert(['actor_id' => $admin->id, 'resource_type' => 'test', 'resource_id' => 0, 'action' => 'test', 'details' => json_encode(['safe' => ['name' => 'SENTETİK', 'password' => 'SECRET'], 'token' => 'SECRET']), 'created_at' => now()]);
        $this->getJson('/yonetim/islem-kayitlari')->assertOk()->assertJsonMissingPath('rows.0.details.token')->assertJsonMissingPath('rows.0.details.safe.password')->assertJsonPath('rows.0.details.safe.name', 'SENTETİK');
        app(ManagementReports::class)->loginEvent($admin, $admin->username, 'login', '127.0.0.1');
        app(ManagementReports::class)->loginEvent($admin, $admin->username, 'failed', '127.0.0.1');
        $this->getJson('/yonetim/girisler')->assertOk()->assertJsonPath('rows.0.today_count', 1)->assertJsonPath('sessionMeasurement', true);
        $this->getJson('/yonetim')->assertJsonPath('summary.logins_today', 1);
    }

    public function test_bulk_housing_and_builtin_menu_change_real_data_and_reject_stale_updates(): void
    {
        $this->actor();
        $payload = ['block_code' => 'ADMIN', 'block_name' => 'SENTETİK BLOK', 'first' => 1, 'count' => 24, 'beds' => 2, 'capacity' => 2, 'kind' => 'normal'];
        $r = $this->postJson('/yonetim/yerlesim/toplu', $payload)->assertCreated()->json();
        $numbers = DB::table('housing_rooms')->where('block_id', $r['block_id'])->orderBy('id')->pluck('name')->all();
        $this->assertSame(array_map('strval', range(1, 24)), $numbers);
        $this->assertSame(48, DB::table('housing_beds')->whereIn('room_id', $r['rooms'])->count());
        $this->postJson('/yonetim/yerlesim/toplu', $payload)->assertUnprocessable();
        $this->assertSame(24, DB::table('housing_rooms')->where('block_id', $r['block_id'])->count());
        $service = app(BuiltinMenu::class);
        $state = $service->state();
        $items = $state['items'];
        foreach ($items as &$item) {
            if ($item['key'] === 'infaz') {
                $item['label'] = 'SENTETİK İNFAZ';
            }
        }unset($item);
        $this->postJson('/yonetim/menu/sabit', ['revision' => 0, 'items' => $items])->assertOk();
        $this->postJson('/yonetim/menu/sabit', ['revision' => 0, 'items' => $items])->assertConflict();
        $this->get('/portal')->assertInertia(fn (Assert $a) => $a->where('navigation.2.children.2.children.0.label', 'SENTETİK İNFAZ'));
    }

    public function test_sensitive_personal_access_can_deny_or_read_only_override_inherited_write(): void
    {
        $admin = $this->actor();
        $department = DB::table('departments')->insertGetId(['code' => 'SENSITIVE', 'name' => 'SENTETİK', 'is_active' => true]);
        $staff = User::factory()->create(['department_id' => $department])->refresh();
        $catalog = app(PageCatalog::class);
        $catalog->save($admin, ['code' => 'hassas_ozel_not', 'name' => 'Özel Not', 'path' => null]);
        $page = DB::table('portal_pages')->where('code', 'hassas_ozel_not')->first();
        $permissions = app(PagePermissions::class);
        $permissions->apply($admin, ['pages' => [['id' => $page->id, 'revision' => $page->revision]], 'target_type' => 'birim', 'target_id' => $department, 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => 'yazma']);
        $access = app(PageAccess::class);
        $this->assertTrue($access->allows($staff, 'hassas_ozel_not', 'yazma'));
        foreach (['giris' => [true, false], 'yok' => [false, false]] as $permission => [$read,$write]) {
            $revision = DB::table('portal_pages')->where('id', $page->id)->value('revision');
            $this->postJson('/yonetim/kullanicilar/'.$staff->id.'/sayfa-erisimleri', ['pages' => [['id' => $page->id, 'revision' => $revision]], 'operation' => 'grant', 'reason' => 'Sentetik erişim gerekçesi', 'permission' => $permission, 'reason' => 'Sentetik hassas erişim'])->assertOk();
            $this->assertSame($read, $access->allows($staff, 'hassas_ozel_not'));
            $this->assertSame($write, $access->allows($staff, 'hassas_ozel_not', 'yazma'));
        }
        $revision = DB::table('portal_pages')->where('id', $page->id)->value('revision');
        $this->postJson('/yonetim/kullanicilar/'.$staff->id.'/sayfa-erisimleri', ['pages' => [['id' => $page->id, 'revision' => $revision]], 'operation' => 'remove'])->assertOk();
        $this->assertTrue($access->allows($staff, 'hassas_ozel_not', 'yazma'));
    }

    public function test_scheduled_backups_run_once_per_day_and_follow_the_saved_plan(): void
    {
        $admin = $this->actor();
        app(ManagedDatabase::class)->plan($admin, ['revision' => 0, 'values' => ['enabled' => true, 'time' => '00:00', 'keep' => 2]]);
        $this->artisan('cte:administration-backup --scheduled')->assertSuccessful();
        $this->artisan('cte:administration-backup --scheduled')->assertSuccessful();
        $this->assertDatabaseCount('administration_backups', 1);
        $this->travel(1)->days();
        $this->artisan('cte:administration-backup --scheduled')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('cte:administration-backup --scheduled')->assertSuccessful();
        $this->travelBack();
        $this->assertDatabaseCount('administration_backups', 3);
        $this->assertSame(2, DB::table('administration_backups')->whereNull('archived_at')->count());
    }

    public function test_encrypted_backup_has_integrity_checks_and_invalid_confirmation_cannot_restore(): void
    {
        $admin = $this->actor();
        $r = $this->postJson('/yonetim/veritabani', ['action' => 'backup'])->assertOk()->json();
        $path = app(ManagedDatabase::class)->backup($admin, $r['id'])['path'];
        $this->assertStringNotContainsString($admin->username, file_get_contents($path));
        $this->get('/yonetim/yedekler/'.$r['id'].'/indir')->assertOk()->assertDownload(basename($path));
        $verify = $this->postJson('/yonetim/veritabani', ['action' => 'verify', 'id' => $r['id']])->assertOk()->assertJsonPath('schemaMatches', true)->json();
        $this->postJson('/yonetim/veritabani', ['action' => 'restore', 'id' => $r['id'], 'checksum' => $verify['checksum'], 'confirmed' => true, 'confirmation' => 'NO'])->assertUnprocessable();
        $this->assertDatabaseCount('users', 1);
        file_put_contents($path, 'CORRUPTED');
        $this->postJson('/yonetim/veritabani', ['action' => 'verify', 'id' => $r['id']])->assertUnprocessable();
    }
}
