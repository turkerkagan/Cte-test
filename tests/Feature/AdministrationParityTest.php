<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\GuestAccess;
use App\Services\Access\PageAccess;
use App\Services\Access\PagePermissions;
use App\Services\Administration\BackupPlans;
use App\Services\Administration\DatabaseTools;
use App\Services\Administration\FieldDefinitions;
use App\Services\Administration\LegacySqlConversion;
use App\Services\Administration\ManagedDatabase;
use App\Services\Administration\ManagedFiles;
use App\Services\Administration\ManagementReports;
use App\Services\Administration\RecoveryAccess;
use App\Services\Administration\SqlDump;
use App\Services\Administration\SqlExports;
use App\Services\Administration\StandaloneRecovery;
use App\Services\Institution\InstitutionRecords;
use App\Services\People\PortalIndicators;
use App\Services\Workflows\CaseRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdministrationParityTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateRoot = sys_get_temp_dir().'/cte-parity-'.bin2hex(random_bytes(8));
        mkdir($this->privateRoot, 0700);
        $this->app->useStoragePath($this->privateRoot.'/storage');
        mkdir(storage_path('framework/views'), 0700, true);
        config(['view.compiled' => storage_path('framework/views')]);
        config(['filesystems.disks.private.root' => $this->privateRoot]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->privateRoot)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->privateRoot, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }rmdir($this->privateRoot);
        }parent::tearDown();
    }

    private function actor(): User
    {
        $u = User::factory()->create(['is_system_admin' => true])->refresh();
        $this->actingAs($u)->withSession(['auth_session_version' => 0]);

        return $u;
    }

    private function field(string $key, string $group, string $type = 'text'): array
    {
        return ['key' => $key, 'group' => $group, 'label' => 'SENTETİK ALAN', 'type' => $type, 'table_visible' => true, 'form_visible' => true, 'required' => false, 'readonly' => false, 'position' => 0, 'filter_visible' => false, 'sortable' => false, 'max_length' => 20, 'default' => null, 'option_list' => null];
    }

    public function test_missing_password_cannot_reset_an_account(): void
    {
        $this->actor();
        $u = User::factory()->create()->refresh();
        $this->postJson('/yonetim/kullanicilar/'.$u->id.'/parola-sifirla', ['revision' => 1, 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($u->password, $u->fresh()->password);
    }

    public function test_read_applications_still_count_as_pending_and_requests_paginate_reopen_delete(): void
    {
        $a = $this->actor();
        $id = DB::table('account_requests')->insertGetId(['kind' => 'application', 'username' => 'synthetic.request', 'status' => 'read']);
        $this->assertSame(1, app(ManagementReports::class)->overview($a)['summary']['applications']);
        for ($i = 0; $i < 51; $i++) {
            DB::table('account_requests')->insert(['kind' => 'password', 'username' => 'synthetic.'.$i]);
        }
        $this->getJson('/yonetim/talepler?page=2')->assertOk()->assertJsonPath('pagination.total', 52)->assertJsonCount(2, 'requests');
        foreach (['completed', 'new', 'delete'] as $n => $action) {
            $this->postJson('/yonetim/talepler', ['id' => $id, 'revision' => $n + 1, 'action' => $action, 'confirmed' => true])->assertOk();
        }$this->assertDatabaseMissing('account_requests', ['id' => $id]);
    }

    public function test_guest_has_separate_read_rules_and_cannot_post_or_see_admin(): void
    {
        $a = $this->actor();
        $id = DB::table('portal_pages')->insertGetId(['code' => 'telefon', 'name' => 'Telefon', 'path' => '/kurum/telefon']);
        app(PagePermissions::class)->apply($a, ['pages' => [['id' => $id, 'revision' => 1]], 'target_type' => 'misafir', 'target_id' => 0, 'operation' => 'grant', 'permission' => 'giris']);
        $g = app(GuestAccess::class)->account();
        $this->assertTrue(app(PageAccess::class)->allows($g, 'telefon'));
        $this->assertFalse(app(PageAccess::class)->allows($g, 'telefon', 'yazma'));
        $this->assertFalse(app(PageAccess::class)->allows($g, 'hukumlu_kayit'));
        $this->actingAs($g)->withSession(['auth_session_version' => 0]);
        $this->get('/kurum/telefon')->assertOk();
        $this->postJson('/kurum/telefon', [])->assertForbidden();
        $this->get('/yonetim')->assertForbidden();
    }

    public function test_user_deletion_preserves_institution_records_and_protects_master(): void
    {
        $this->actor();
        $u = User::factory()->create()->refresh();
        $record = DB::table('institution_records')->insertGetId(['kind' => 'telefon', 'values' => '{"name":"SENTETİK","phone":"123"}', 'created_by' => $u->id]);
        $this->deleteJson('/yonetim/kullanicilar/'.$u->id, ['revision' => 1, 'confirmed' => true, 'confirmation' => 'KULLANICIYI SİL'])->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $u->id]);
        $this->assertDatabaseHas('institution_records', ['id' => $record, 'created_by' => null]);
        $master = User::factory()->create(['username' => 'türker', 'is_system_admin' => true, 'is_master_admin' => true])->refresh();
        $this->deleteJson('/yonetim/kullanicilar/'.$master->id, ['revision' => 1, 'confirmed' => true, 'confirmation' => 'KULLANICIYI SİL'])->assertUnprocessable();
    }

    public function test_personal_reason_is_required_persisted_and_returned(): void
    {
        $this->actor();
        $u = User::factory()->create();
        $id = DB::table('portal_pages')->insertGetId(['code' => 'reason', 'name' => 'SENTETİK', 'path' => '/portal']);
        $url = '/yonetim/kullanicilar/'.$u->id.'/sayfa-erisimleri';
        $data = ['pages' => [['id' => $id, 'revision' => 1]], 'operation' => 'grant', 'permission' => 'giris'];
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url, $data + ['reason' => 'SENTETİK GEREKÇE'])->assertOk();
        $this->getJson('/yonetim/kullanicilar/'.$u->id)->assertOk()->assertJsonPath('accessRows.0.personal_reason', 'SENTETİK GEREKÇE');
    }

    public function test_institution_custom_fields_are_used_by_the_correct_form_and_table(): void
    {
        $a = $this->actor();
        $field = $this->field('telefon__custom_note', 'telefon');
        $field['default'] = 'SENTETİK';
        app(FieldDefinitions::class)->save($a, ['revision' => 0, 'items' => [$field]]);
        $this->assertFalse(Schema::hasColumn('person_case_data', 'telefon__custom_note'));
        $this->postJson('/kurum/telefon', ['values' => ['name' => 'SENTETİK', 'phone' => '123', 'custom_note' => 'DOĞRU ALAN']])->assertOk();
        $this->getJson('/kurum/telefon')->assertOk()->assertJsonPath('fields.custom_note.default', 'SENTETİK')->assertJsonPath('rows.0.display.custom_note', 'DOĞRU ALAN');
        $this->assertArrayNotHasKey('mesajlar', app(FieldDefinitions::class)->state()['groups']);
    }

    public function test_case_decimal_boolean_datetime_and_bound_option_are_validated(): void
    {
        $a = $this->actor();
        $f = $this->field('custom_decimal', 'infaz', 'decimal');
        $g = $this->field('custom_boolean', 'infaz', 'boolean');
        $h = $this->field('custom_datetime', 'infaz', 'datetime');
        $o = $this->field('custom_option', 'infaz', 'option');
        $o['option_list'] = 'huk-karar';
        app(FieldDefinitions::class)->save($a, ['revision' => 0, 'items' => [$f, $g, $h, $o]]);
        $person = DB::table('people')->insertGetId(['full_name' => 'SENTETİK']);
        $choice = DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')->where('l.code', 'huk-karar')->value('i.id');
        app(CaseRecords::class)->save($a, 'infaz', $person, ['revision' => 0, 'values' => ['custom_decimal' => '1.25', 'custom_boolean' => 1, 'custom_datetime' => '2026-10-09T12:30', 'custom_option' => $choice]]);
        $detail = app(CaseRecords::class)->detail($a, 'infaz', $person);
        $this->assertSame('2026-10-09T12:30', $detail['values']['custom_datetime']);
        $this->assertNotEmpty($detail['options']['custom_option']);
        $this->assertDatabaseHas('person_case_data', ['person_id' => $person, 'custom_decimal' => 1.25, 'custom_boolean' => 1]);
    }

    public function test_multiple_backup_plans_weekly_monthly_and_once_per_day(): void
    {
        $a = $this->actor();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(3, 0));
        $plans = app(BackupPlans::class);
        $weekly = ['id' => 'weekly', 'name' => 'Hafta', 'target' => 'local', 'frequency' => 'weekly', 'time' => '02:00', 'weekday' => 5, 'monthday' => 9, 'keep' => 2, 'enabled' => true];
        $monthly = $weekly;
        $monthly['id'] = 'monthly';
        $monthly['name'] = 'Ay';
        $monthly['target'] = 'archive';
        $monthly['frequency'] = 'monthly';
        $plans->save($a, ['revision' => 0, 'items' => [$weekly, $monthly]]);
        $this->assertNotNull($plans->run($a, 'weekly', true));
        $this->assertNull($plans->run($a, 'weekly', true));
        $b = $plans->run($a, 'monthly', true);
        $this->assertFileExists($plans->targets()['archive']['path'].'/'.$b['name']);
        $this->assertSame('Başarılı', $plans->read()['values']['items'][0]['status']);
        $count = DB::table('administration_backups')->count();
        $plans->save($a, ['revision' => 1, 'items' => []]);
        $this->assertSame(0, Artisan::call('cte:administration-backup', ['--scheduled' => true]));
        $this->assertSame($count, DB::table('administration_backups')->count());
        $this->travelBack();
    }

    public function test_sql_dump_literals_conversion_and_no_expression_execution(): void
    {
        $a = $this->actor();
        $sql = "CREATE TABLE `tablo` (`id` INT,`huk_tc` VARCHAR(11),`huk_adi` TEXT,`huk_meslek` TEXT); INSERT INTO `tablo` VALUES (1,'12345678901','SENTETİK; KİŞİ','O''Reilly');";
        $service = app(LegacySqlConversion::class);
        $preview = $service->preview($sql);
        $this->assertSame(1, $preview['counts']['people']);
        $this->assertStringContainsString('INSERT INTO `people`', $preview['download_sql']);
        $result = $service->apply($a, ['sql' => $sql, 'checksum' => $preview['checksum'], 'target_checksum' => $preview['target_checksum'], 'confirmed' => true]);
        $this->assertDatabaseHas('people', ['tc_identity' => '12345678901', 'full_name' => 'SENTETİK; KİŞİ']);
        $this->assertDatabaseHas('person_case_data', ['huk_meslek' => "O'Reilly"]);
        $this->assertGreaterThan(0, $result['backup_id']);
        $this->expectException(\RuntimeException::class);
        app(SqlDump::class)->parse('INSERT INTO tablo (huk_adi) VALUES (LOAD_FILE("/etc/passwd"));');
    }

    public function test_sql_tool_previews_before_write_and_table_columns_have_prebackups(): void
    {
        $a = $this->actor();
        $service = app(DatabaseTools::class);
        $created = $service->structure($a, ['operation' => 'create', 'table' => 'custom_synthetic', 'confirmed' => true, 'confirmation' => 'TABLOYU DEĞİŞTİR']);
        $this->assertTrue(Schema::hasTable('custom_synthetic'));
        $this->assertNotEmpty($created['backup']);
        $sql = 'INSERT INTO custom_synthetic (id) VALUES (1);';
        $preview = $service->execute($a, ['sql' => $sql]);
        $this->assertDatabaseCount('custom_synthetic', 0);
        $service->execute($a, ['sql' => $sql, 'checksum' => $preview['checksum'], 'confirmed' => true, 'confirmation' => 'SQL ÇALIŞTIR']);
        $this->assertDatabaseCount('custom_synthetic', 1);
        $this->assertSame(1, $service->execute($a, ['sql' => 'SELECT id FROM custom_synthetic;'])['rows'][0]['id']);
        try {
            $service->execute($a, ['sql' => 'SELECT * FROM mysql.user;']);
            $this->fail('Foreign database accepted.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        Schema::drop('custom_synthetic');
    }

    public function test_documents_are_included_in_signed_backup_and_multi_upload_zip_keeps_paths(): void
    {
        $a = $this->actor();
        $files = app(ManagedFiles::class);
        $dir = 'parity-'.bin2hex(random_bytes(4));
        $files->mutate($a, ['action' => 'folder', 'path' => $dir]);
        $files->uploadMany($a, $dir, [UploadedFile::fake()->createWithContent('one.txt', 'BİR'), UploadedFile::fake()->createWithContent('two.txt', 'İKİ')], ['alt/one.txt', 'alt/two.txt']);
        $zipPath = $files->zip($a, $dir);
        $zip = new \ZipArchive;
        $zip->open($zipPath);
        $this->assertSame('BİR', $zip->getFromName('alt/one.txt'));
        $zip->close();
        unlink($zipPath);
        $backup = app(ManagedDatabase::class)->create($a);
        $path = app(ManagedDatabase::class)->backup($a, $backup['id'])['path'];
        $payload = json_decode(Crypt::decryptString(file_get_contents($path)), true);
        $this->assertSame('BİR', base64_decode($payload['files']['administration/files/'.$dir.'/alt/one.txt']));
    }

    public function test_activity_works_for_file_sessions_and_old_logs_are_pruned(): void
    {
        $a = $this->actor();
        $this->get('/yonetim')->assertOk();
        $this->assertNotNull($a->fresh()->last_activity_at);
        $this->getJson('/yonetim/girisler')->assertJsonPath('rows.0.online', true);
        $this->assertDatabaseHas('audit_events', ['resource_type' => 'page_visit', 'action' => 'opened']);
        DB::table('audit_events')->insert(['resource_type' => 'old', 'resource_id' => 0, 'action' => 'old', 'details' => '{}', 'created_at' => now()->subDays(21)]);
        Artisan::call('cte:prune-logs');
        $this->assertDatabaseMissing('audit_events', ['resource_type' => 'old']);
    }

    public function test_independent_recovery_key_is_single_use_and_credentials_paths_are_denied(): void
    {
        $a = $this->actor();
        $data = app(RecoveryAccess::class)->issue($a);
        $runtime = new StandaloneRecovery($this->privateRoot, ['DB_DATABASE' => 'cte_next_test']);
        $this->assertTrue($runtime->authenticate($data['recovery_key']));
        $this->assertFalse($runtime->authenticate($data['recovery_key']));
        $this->expectException(\RuntimeException::class);
        $runtime->path('.env');
    }

    public function test_conversion_preserves_board_option_and_organization_ids_in_download_and_apply(): void
    {
        $a = $this->actor();
        $sql = "INSERT INTO birimler (id,kod,ad,aktif) VALUES (1,'synthetic-department','SENTETİK BİRİM',1); INSERT INTO unvanlar (id,kod,ad,aktif) VALUES (1,'synthetic-title','SENTETİK UNVAN',1);";
        $hash = Hash::make('SyntheticPass!2026');
        $sql .= "INSERT INTO kullanicilar (id,kullanici_adi,ad_soyad,sifre,birim_id,unvan_id,sistem_yonetici,aktif,sifre_degistir,oturum_surumu,hatali_giris,kilitli_baslangic,son_giris) VALUES (1,'synthetic.old','SENTETİK ESKİ','$hash',1,1,0,1,1,0,0,NULL,NULL); INSERT INTO kurul_komisyon (id,sira_no,disiplin_kurulu_ust,disiplin_kurulu_alt) VALUES (1,3,'SENTETİK BAŞKAN','SENTETİK UNVAN'); INSERT INTO calisma_yerleri (id,ad,aktif,sira) VALUES (1,'SENTETİK İŞ',1,4);";
        $service = app(LegacySqlConversion::class);
        $p = $service->preview($sql);
        $this->assertStringContainsString('INSERT INTO `users`', $p['download_sql']);
        $this->assertSame([], $p['unsupported']);
        $service->apply($a, ['sql' => $sql, 'checksum' => $p['checksum'], 'target_checksum' => $p['target_checksum'], 'confirmed' => true]);
        $u = DB::table('users')->where('username', 'synthetic.old')->first();
        $this->assertNotSame($a->id, $u->id);
        $this->assertSame('synthetic-department', DB::table('departments')->find($u->department_id)->code);
        $v = json_decode(DB::table('institution_records')->where('kind', 'kurul')->where('natural_key', '3')->value('values'), true);
        $this->assertSame('SENTETİK BAŞKAN', $v['discipline_top']);
        $this->assertSame(3, $v['position']);
        $this->assertDatabaseHas('case_option_items', ['label' => 'SENTETİK İŞ']);
    }

    public function test_conversion_preview_detects_same_count_target_edits(): void
    {
        $a = $this->actor();
        $service = app(LegacySqlConversion::class);
        $sql = "INSERT INTO telefon_rehberi (id,isim,telefon) VALUES (1,'SENTETİK','123');";
        $p = $service->preview($sql);
        DB::table('users')->where('id', $a->id)->update(['full_name' => 'DEĞİŞTİ']);
        $this->expectException(HttpException::class);
        $service->apply($a, ['sql' => $sql, 'checksum' => $p['checksum'], 'target_checksum' => $p['target_checksum'], 'confirmed' => true]);
    }

    public function test_bound_announcement_table_is_used_for_reads_and_indicators(): void
    {
        $a = $this->actor();
        app(DatabaseTools::class)->structure($a, ['operation' => 'create', 'table' => 'custom_bulletin', 'layout' => 'institution', 'confirmed' => true, 'confirmation' => 'TABLOYU DEĞİŞTİR']);
        DB::table('portal_pages')->insert(['code' => 'duyurular', 'name' => 'Duyurular', 'path' => '/kurum/duyurular', 'data_table' => 'custom_bulletin']);
        $r = app(InstitutionRecords::class)->save($a, 'duyurular', null, ['values' => ['title' => 'SENTETİK', 'body' => 'BİLDİRİ']]);
        $this->assertSame(1, app(PortalIndicators::class)->read($a)['announcements']);
        app(InstitutionRecords::class)->receipt($a, $r['id'], true);
        $this->assertSame(0, app(PortalIndicators::class)->read($a)['announcements']);
        $this->assertDatabaseHas('announcement_reads', ['record_table' => 'custom_bulletin', 'record_id' => $r['id']]);
        Schema::drop('custom_bulletin');
    }

    public function test_converter_update_creates_an_offline_executable_archive(): void
    {
        $a = $this->actor();
        $result = app(SqlExports::class)->update($a);
        $dir = $this->privateRoot.'/offline';
        mkdir($dir);
        $zip = new \ZipArchive;
        $zip->open(app(ManagedFiles::class)->root().'/'.$result['file']);
        $zip->extractTo($dir);
        $zip->close();
        file_put_contents($dir.'/input.sql', "INSERT INTO telefon_rehberi (id,isim,telefon) VALUES (1,'SENTETİK','123');");
        $process = new Process([PHP_BINARY, $dir.'/donusturucu.php', $dir.'/input.sql', $dir.'/output.sql'], $dir);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $output = file_get_contents($dir.'/output.sql');
        $this->assertStringContainsString('SENTETİK', $output);
        $dataSql = substr($output, strpos($output, 'INSERT INTO `migrations`'));
        $parsed = app(SqlDump::class)->parse($dataSql);
        $this->assertSame('telefon', $parsed['tables']['institution_records'][0]['kind']);
        $this->assertArrayNotHasKey('APP_KEY', json_decode(file_get_contents($dir.'/schema.json'), true));
    }

    public function test_schema_diagnosis_finds_missing_custom_fields_and_repairs_their_type(): void
    {
        $a = $this->actor();
        app(FieldDefinitions::class)->save($a, ['revision' => 0, 'items' => [$this->field('custom_missing', 'infaz', 'decimal')]]);
        Schema::table('person_case_data', fn ($t) => $t->dropColumn('custom_missing'));
        $d = app(DatabaseTools::class);
        $this->assertContains('custom_missing', $d->diagnose()['missing_fields']);
        $d->repair($a, ['confirmed' => true]);
        $this->assertTrue(Schema::hasColumn('person_case_data', 'custom_missing'));
        $this->assertNotContains('custom_missing', $d->diagnose()['missing_fields']);
    }

    public function test_backup_targets_validate_usage_and_retention_removes_only_expired_plan_files(): void
    {
        $a = $this->actor();
        $plans = app(BackupPlans::class);
        $target = ['id' => 'target_synthetic', 'name' => 'SENTETİK HEDEF', 'directory' => 'synthetic', 'is_active' => true];
        $plans->saveTargets($a, ['revision' => 0, 'items' => [$target]]);
        $plan = ['id' => 'synthetic', 'name' => 'SENTETİK PLAN', 'target' => 'target_synthetic', 'frequency' => 'daily', 'time' => '02:00', 'weekday' => 1, 'monthday' => 1, 'keep' => 1, 'enabled' => true];
        $plans->save($a, ['revision' => 0, 'items' => [$plan]]);
        $first = $plans->run($a, 'synthetic');
        $second = $plans->run($a, 'synthetic');
        $this->assertFileDoesNotExist(app(ManagedDatabase::class)->root().'/'.$first['name']);
        $this->assertFileExists(app(ManagedDatabase::class)->root().'/'.$second['name']);
        $this->assertFileExists($plans->targets()['target_synthetic']['path'].'/'.$second['name']);
        $this->expectException(ValidationException::class);
        $plans->saveTargets($a, ['revision' => 1, 'items' => []]);
    }

    public function test_record_reset_clears_bound_institution_tables_and_keeps_users(): void
    {
        $a = $this->actor();
        $tools = app(DatabaseTools::class);
        $tools->structure($a, ['operation' => 'create', 'table' => 'custom_reset', 'layout' => 'institution', 'confirmed' => true, 'confirmation' => 'TABLOYU DEĞİŞTİR']);
        DB::table('portal_pages')->insert(['code' => 'reset-bound', 'name' => 'SENTETİK', 'path' => '/kurum/telefon', 'data_table' => 'custom_reset']);
        DB::table('custom_reset')->insert(['kind' => 'telefon', 'values' => '{}']);
        $tools->reset($a, ['kind' => 'records', 'confirmed' => true, 'confirmation' => 'VERİLERİ SIFIRLA']);
        $this->assertDatabaseCount('custom_reset', 0);
        $this->assertDatabaseHas('users', ['id' => $a->id]);
        Schema::drop('custom_reset');
    }

    public function test_phone_removal_archives_only_the_selected_row(): void
    {
        $a = $this->actor();
        $id = DB::table('institution_records')->insertGetId(['kind' => 'telefon', 'values' => '{"name":"SENTETİK","phone":"123"}']);
        $other = DB::table('institution_records')->insertGetId(['kind' => 'telefon', 'values' => '{}']);
        app(InstitutionRecords::class)->archive($a, 'telefon', $id, 1);
        $this->assertDatabaseHas('institution_records', ['id' => $id, 'is_active' => false, 'revision' => 2]);
        $this->assertDatabaseHas('institution_records', ['id' => $other]);
    }

    public function test_master_account_cannot_be_updated_or_reset_from_the_panel(): void
    {
        $this->actor();
        $u = User::factory()->create(['username' => 'türker', 'is_system_admin' => true, 'is_master_admin' => true])->refresh();
        $this->putJson('/yonetim/kullanicilar/'.$u->id, ['username' => 'renamed', 'full_name' => 'SENTETİK', 'level' => 'personnel', 'is_active' => false, 'revision' => 1])->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/yonetim/kullanicilar/'.$u->id.'/parola-sifirla', ['revision' => 1, 'confirmed' => true, 'password' => 'SyntheticPass!2026', 'password_confirmation' => 'SyntheticPass!2026'])->assertUnprocessable();
        $this->assertSame($u->password, $u->fresh()->password);
        $this->assertSame('türker', $u->fresh()->username);
    }
}
