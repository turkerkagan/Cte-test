<?php

/** Disposable MariaDB integration test. Reads no source database records. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/vendor/autoload.php';

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Access\PageAccessView;
use App\Services\Access\PageCatalog;
use App\Services\Access\PagePermissions;
use App\Services\Administration\StandaloneRecovery;
use App\Services\Administration\UserAdministration;
use App\Services\Exports\LegacyCorePreflight;
use App\Services\Exports\LegacyOrganizationReader;
use App\Services\Exports\LegacyReadOnlyConnection;
use App\Services\Housing\HousingDefinitions;
use App\Services\Housing\HousingSummary;
use App\Services\Housing\PersonPlacement;
use App\Services\Imports\ImportConflict;
use App\Services\Imports\ImportTargetGuard;
use App\Services\Imports\OrganizationImporter;
use App\Services\Imports\OrganizationSnapshot;
use App\Services\Modules\DefinitionConflict;
use App\Services\Modules\ModuleDefinitions;
use App\Services\Modules\ModuleExport;
use App\Services\Modules\ModulePersonLinks;
use App\Services\Modules\ModulePublication;
use App\Services\Modules\ModuleRecords;
use App\Services\Modules\ModuleValueValidator;
use App\Services\Modules\OptionCatalog;
use App\Services\Navigation\MenuConfiguration;
use App\Services\Organization\OrganizationDefinitions;
use App\Services\People\PersonConflict;
use App\Services\People\PersonList;
use App\Services\People\PersonRecords;
use App\Services\Security\InitialAdministrator;
use App\Services\Security\PortalLogin;
use App\Services\Security\SessionGeneration;
use App\Services\Statuses\StatusDefinitions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\LegacyCoreFixture;
use Tests\Support\LegacyOrganizationFixture;
use Tests\Support\MaintenanceMariaChecks;
use Tests\Support\ReportFixesMariaChecks;
use Tests\Support\ServiceInstitutionMariaChecks;
use Tests\Support\WorkflowMariaChecks;
use Tests\Support\WorkspaceMariaChecks;

$host = getenv('CTE_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('CTE_TEST_DB_PORT') ?: '3306';
$socket = getenv('CTE_TEST_DB_SOCKET') ?: '';
$user = getenv('CTE_TEST_DB_USER');
$password = getenv('CTE_TEST_DB_PASSWORD');
if ($user === false || $user === '') {
    fwrite(STDERR, "CTE_TEST_DB_USER ve yerel test bağlantısı gerekli.\n");
    exit(1);
}
$schema = 'cte_test_g03_'.bin2hex(random_bytes(6));
$created = false;
$sourceSchema = 'cte_g03_source_test_'.bin2hex(random_bytes(6));
$sourceCreated = false;
$sourceCleaned = false;
$exportName = 'g03_smoke_'.bin2hex(random_bytes(6));
$cleaned = false;
$server = null;
$passed = [];
$failed = false;
$check = static function (bool $condition, string $label) use (&$passed): void {
    if (! $condition) {
        throw new RuntimeException('Check failed: '.$label);
    }
    $passed[] = $label;
};
try {
    $transport = $socket === '' ? 'host='.$host.';port='.$port : 'unix_socket='.$socket;
    $server = new PDO('mysql:'.$transport.';charset=utf8mb4', $user, $password ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $schema, 'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_SOCKET' => $socket, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $password ?: '', 'DB_URL' => '', 'DB_TABLE_PREFIX' => getenv('CTE_TEST_TABLE_PREFIX') ?: ''] as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $exportDirectory = storage_path('app/private/imports/'.$exportName);
    $server->exec('CREATE DATABASE `'.$sourceSchema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $sourceCreated = true;
    $server->exec('USE `'.$sourceSchema.'`');
    $legacy = LegacyOrganizationFixture::create($server);
    $before = [];
    foreach (['birimler', 'unvanlar', 'kullanicilar'] as $table) {
        $before[$table] = $server->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    $settings = ['host' => $host, 'port' => $port, 'socket' => $socket, 'database' => $sourceSchema, 'username' => $user, 'password' => $password ?: ''];
    $export = app(LegacyOrganizationReader::class)->read('g03_synthetic_export', $settings);
    $check($export['snapshot']['kullanicilar'][0]['sifre'] === $legacy['kullanicilar'][0]['sifre']
        && $export['snapshot']['kullanicilar'][0]['sicil'] === '000012'
        && $export['report']['deferred_access_columns_non_null']['kullanicilar.ozel_not_seviye'] === 1
        && ! array_key_exists('telefon', $export['snapshot']['kullanicilar'][0]), 'legacy_read_preserves_security_and_optional_schema');
    config(['legacy' => $settings]);
    $check(Artisan::call('portal:export-organization', ['source' => 'g03_synthetic_export']) === 0
        && ! str_contains(Artisan::output(), $legacy['kullanicilar'][0]['sifre'])
        && ! str_contains(Artisan::output(), $legacy['kullanicilar'][0]['ad_soyad'])
        && ! is_dir(storage_path('app/private/imports/'.$exportName)), 'export_cli_default_read_only_and_redacted');
    $check(Artisan::call('portal:export-organization', ['source' => 'g03_synthetic_export', '--write' => $exportName]) === 0
        && is_file(storage_path('app/private/imports/'.$exportName.'/report.json'))
        && ! str_contains(Artisan::output(), $legacy['kullanicilar'][0]['sifre']), 'export_cli_private_snapshot');
    $writtenSnapshot = json_decode(file_get_contents(storage_path('app/private/imports/'.$exportName.'/snapshot.json')), true, 64, JSON_THROW_ON_ERROR);
    LegacyCoreFixture::create($server);
    $core = app(LegacyCorePreflight::class)->check($settings);
    $check($core['counts']['tablo'] === 3 && $core['mapped_source_columns'] === 121
        && $core['identity']['empty_tc'] === 1 && $core['identity']['empty_name'] === 1
        && $core['identity']['duplicate_tc_groups'] === 1 && $core['identity']['unexpected_tc_format'] === 0
        && $core['identity']['max_tc_characters'] === 11, 'core_identity_aggregate_preserves_nonunique_tc');
    $check($core['dates']['huk_cezaevine_giris'] === ['zero_dates' => 1, 'invalid_dates' => 1], 'core_zero_and_partial_dates_reported');
    $check($core['placement'] === ['occupied_bed_collision_groups' => 1, 'rooms_without_block' => 1, 'occupied_rows_without_room' => 2]
        && $core['options']['karar']['rows_without_database_option'] === 1, 'core_placement_and_options_anomalies_reported');
    $check($server->query('SELECT izin_donus_saati FROM tablo WHERE id = 1')->fetchColumn() === '14:00:00'
        && ! str_contains(json_encode($core), '00000000001') && ! str_contains(json_encode($core), 'SYNTHETIC_CUSTOM'), 'core_report_redacted_and_return_hour_preserved');
    try {
        app(LegacyReadOnlyConnection::class)->run($settings, function (PDO $source): void {
            $source->exec('UPDATE tablo SET huk_adi = NULL WHERE id = 2');
        });
        throw new LogicException('Read-only transaction allowed writes');
    } catch (PDOException $error) {
        $check((int) ($error->errorInfo[1] ?? 0) === 1792
            && $server->query('SELECT huk_adi FROM tablo WHERE id = 2')->fetchColumn() === 'SYNTHETIC', 'source_transaction_enforces_read_only');
    }
    foreach ($before as $table => $rows) {
        $check($server->query('SELECT * FROM `'.$table.'` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $rows, 'legacy_unchanged_'.$table);
    }
    $server->exec('ALTER TABLE unvanlar ADD COLUMN unexpected_column INT NULL');
    try {
        app(LegacyOrganizationReader::class)->read('g03_synthetic_export', $settings);
        throw new LogicException('Schema drift accepted');
    } catch (RuntimeException $error) {
        $check(! $error instanceof LogicException && str_contains($error->getMessage(), 'şema'), 'legacy_schema_drift_rejected');
    }
    $server->exec('ALTER TABLE unvanlar DROP COLUMN unexpected_column, ENGINE=MyISAM');
    try {
        app(LegacyOrganizationReader::class)->read('g03_synthetic_export', $settings);
        throw new LogicException('Nontransactional source accepted');
    } catch (RuntimeException $error) {
        $check(! $error instanceof LogicException && str_contains($error->getMessage(), 'InnoDB'), 'legacy_nontransactional_source_rejected');
    }
    $sentinels = [];
    if (DB::connection()->getTablePrefix() !== '') {
        $targetPdo = DB::connection()->getPdo();
        foreach (['users', 'departments', 'job_titles', 'sessions', 'audit_events', 'portal_installations', 'import_mappings', 'migrations', 'portal_pages', 'page_permissions', 'modules', 'module_fields', 'module_versions', 'option_lists', 'option_items', 'module_records', 'module_record_index', 'module_record_options', 'menu_configuration', 'menu_items', 'people', 'housing_blocks', 'housing_rooms', 'housing_beds', 'housing_occupancies', 'person_statuses', 'person_status_assignments', 'person_locations', 'case_option_lists', 'case_option_items', 'person_case_data', 'person_photos', 'staff_nodes', 'person_notes', 'institution_records', 'announcement_reads', 'duty_unavailable', 'meal_files', 'chat_rooms', 'chat_members', 'chat_messages', 'personal_messages', 'message_attachments'] as $name) {
            $targetPdo->exec('CREATE TABLE `'.$name.'` (id INT PRIMARY KEY, marker VARCHAR(30)) ENGINE=InnoDB');
            $targetPdo->exec('INSERT INTO `'.$name."` VALUES (1, 'synthetic_legacy_unchanged')");
            $sentinels[] = $name;
        }
    }
    $check(Artisan::call('migrate', ['--force' => true]) === 0, 'migrations');
    $recoveryManifest = json_decode(file_get_contents(resource_path('recovery/native-schema.json')), true, 512, JSON_THROW_ON_ERROR);
    $manifestPrefix = DB::connection()->getTablePrefix().'manifest_';
    DB::unprepared(str_replace('__PREFIX__', $manifestPrefix, $recoveryManifest['tables']['private_file_deletions']));
    $manifestColumns = DB::connection()->getPdo()->query('SHOW COLUMNS FROM `'.$manifestPrefix.'private_file_deletions`')->fetchAll(PDO::FETCH_COLUMN);
    $check($manifestColumns === ['id', 'path', 'created_at', 'updated_at'], 'private_cleanup_recovery_schema');
    DB::statement('DROP TABLE `'.$manifestPrefix.'private_file_deletions`');
    $initialAdmin = app(InitialAdministrator::class)->create('g05_synthetic_admin', 'g05-test-password-123');
    $check($initialAdmin->is_system_admin && $initialAdmin->is_active && $initialAdmin->session_version === 0
        && password_verify('g05-test-password-123', $initialAdmin->password), 'initial_admin_hash_and_security_defaults');
    try {
        app(InitialAdministrator::class)->create('g05_other_admin', 'g05-test-password-123');
        throw new LogicException('Repeated installation accepted');
    } catch (RuntimeException $error) {
        $check(! $error instanceof LogicException && DB::table('users')->count() === 1, 'initial_admin_repeat_rejected');
    }
    $login = app(PortalLogin::class);
    $check($login->verify('g05_synthetic_admin', 'wrong') === null
        && $login->verify('g05_synthetic_admin', 'g05-test-password-123') !== null
        && (int) DB::table('users')->where('id', $initialAdmin->id)->value('failed_login_attempts') === 0, 'mariadb_login_counter_reset');
    $initialAdmin->delete();
    try {
        app(InitialAdministrator::class)->create('g05_again', 'g05-test-password-123');
        throw new LogicException('Installation marker bypassed');
    } catch (RuntimeException $error) {
        $check(! $error instanceof LogicException && DB::table('users')->count() === 0, 'installation_marker_survives_admin_deletion');
    }
    $input = $writtenSnapshot;
    $snapshot = OrganizationSnapshot::fromArray($input);
    $importer = app(OrganizationImporter::class);
    $check($importer->apply($snapshot) === ['inserted' => 3, 'unchanged' => 0], 'exported_snapshot_import');
    $check($importer->apply($snapshot) === ['inserted' => 0, 'unchanged' => 3], 'repeat_import');
    $imported = DB::table('users')->where('id', 11)->first();
    $check($imported->password === $input['kullanicilar'][0]['sifre'] && $imported->registry_number === '000012'
        && (int) $imported->department_id === 7 && (int) $imported->job_title_id === 9
        && (int) $imported->session_version === 3 && (int) $imported->must_change_password === 1
        && $imported->locked_at === '2026-10-03 12:00:00'
        && $imported->created_at === '2020-02-29 08:09:10' && $imported->updated_at === null, 'identity_hash_security_state');

    DB::table('users')->insert(['id' => 99, 'username' => 'g03_collision', 'password' => $input['kullanicilar'][0]['sifre']]);
    $late = $input;
    $late['birimler'][] = ['id' => 15, 'kod' => 'g03_new', 'ad' => 'SENTETİK YENİ BİRİM', 'aktif' => 1];
    $late['unvanlar'][] = ['id' => 16, 'kod' => 'g03_new', 'ad' => 'SENTETİK YENİ UNVAN', 'aktif' => 1];
    $extra = $input['kullanicilar'][0];
    $extra['id'] = 17;
    $extra['kullanici_adi'] = 'g03_collision';
    $extra['birim_id'] = 15;
    $extra['unvan_id'] = 16;
    $late['kullanicilar'][] = $extra;
    try {
        $importer->apply(OrganizationSnapshot::fromArray($late));
        throw new RuntimeException('Late conflict not rejected');
    } catch (QueryException $error) {
        $check(DB::table('departments')->count() === 1 && DB::table('job_titles')->count() === 1
            && DB::table('users')->count() === 2 && DB::table('import_mappings')->count() === 3, 'late_failure_atomic_rollback');
    }
    try {
        DB::table('departments')->where('id', 7)->delete();
        throw new RuntimeException('Foreign key deletion not rejected');
    } catch (QueryException $error) {
        $check(DB::table('departments')->where('id', 7)->exists(), 'assigned_department_delete_restricted');
    }
    DB::table('users')->where('id', 11)->update(['full_name' => 'SENTETİK YENİ HEDEF ADI', 'revision' => 2]);
    try {
        $importer->apply($snapshot);
        throw new RuntimeException('Edited target not rejected');
    } catch (ImportConflict $error) {
        $check((int) DB::table('users')->where('id', 11)->value('revision') === 2, 'edited_target_preserved');
    }
    $actor = User::factory()->create(['username' => 'g05_management_admin', 'is_system_admin' => true])->refresh();
    $management = app(OrganizationDefinitions::class);
    foreach (['birimler' => ['departments', 'department_id'], 'unvanlar' => ['job_titles', 'job_title_id']] as $kind => [$table, $foreignKey]) {
        $management->save($actor, $kind, ['code' => 'g05_source', 'name' => 'SENTETİK KAYNAK', 'is_active' => true]);
        $management->save($actor, $kind, ['code' => 'g05_target', 'name' => 'SENTETİK HEDEF', 'is_active' => true]);
        $source = (int) DB::table($table)->where('code', 'g05_source')->value('id');
        $target = (int) DB::table($table)->where('code', 'g05_target')->value('id');
        $person = User::factory()->create(['username' => 'g05_person_'.$kind, $foreignKey => $source])->refresh();
        $permissionPage = DB::table('portal_pages')->insertGetId(['code' => 'synthetic_transfer_'.$kind, 'name' => 'SENTETİK AKTARIM']);
        $type = $kind === 'birimler' ? 'birim' : 'unvan';
        $grant = function (string $targetType, int $targetId, string $permission) use ($actor, $permissionPage): void {
            app(PagePermissions::class)->apply($actor, ['pages' => [['id' => $permissionPage, 'revision' => DB::table('portal_pages')->where('id', $permissionPage)->value('revision')]],
                'target_type' => $targetType, 'target_id' => $targetId, 'operation' => 'grant', 'permission' => $permission, 'reason' => 'SENTETİK TEST ERİŞİMİ']);
        };
        $grant($type, $source, 'giris');
        $grant($type, $target, 'giris');
        $grant('kisi', $person->id, 'giris');
        $sourceImpact = $management->permissionImpact($kind, $source);
        $targetImpact = $management->permissionImpact($kind, $target);
        $deleteInput = ['revision' => 1, 'assigned_count' => 1, 'target_id' => $target,
            'permission_count' => $sourceImpact['count'], 'permission_snapshot' => $sourceImpact['snapshot'],
            'target_permission_count' => $targetImpact['count'], 'target_permission_snapshot' => $targetImpact['snapshot']];
        $grant($type, $target, 'yazma');
        try {
            $management->delete($actor, $kind, $source, $deleteInput);
            throw new LogicException('Stale transfer target permission accepted');
        } catch (ValidationException $error) {
            $check(isset($error->errors()['target_permission_snapshot']) && $person->fresh()->$foreignKey === $source, 'management_same_count_permission_change_rejected_'.$kind);
        }
        $deleteInput['target_permission_snapshot'] = $management->permissionImpact($kind, $target)['snapshot'];
        $pageRevision = (int) DB::table('portal_pages')->where('id', $permissionPage)->value('revision');
        DB::statement('CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'");
        try {
            $management->delete($actor, $kind, $source, $deleteInput);
            throw new LogicException('Audit failure did not roll back');
        } catch (QueryException $error) {
            $check(DB::table($table)->where('id', $source)->exists() && $person->fresh()->$foreignKey === $source
                && $person->fresh()->session_version === 0 && DB::table('page_permissions')->where($foreignKey, $source)->exists()
                && (int) DB::table('portal_pages')->where('id', $permissionPage)->value('revision') === $pageRevision, 'management_atomic_audit_failure_'.$kind);
        } finally {
            DB::statement('DROP TRIGGER synthetic_audit_failure');
        }
        $management->delete($actor, $kind, $source, $deleteInput);
        $check(! DB::table($table)->where('id', $source)->exists() && $person->fresh()->$foreignKey === $target
            && $person->fresh()->password === $person->password && $person->fresh()->session_version === 1,
            'management_transfer_preserves_person_'.$kind);
        $check(! DB::table('page_permissions')->where($foreignKey, $source)->exists() && DB::table('page_permissions')->where($foreignKey, $target)->exists()
            && DB::table('page_permissions')->where('user_id', $person->id)->exists() && app(PageAccess::class)->allows($person, 'synthetic_transfer_'.$kind, 'yazma')
            && (int) DB::table('portal_pages')->where('id', $permissionPage)->value('revision') === $pageRevision + 1, 'management_transfer_permission_scope_and_revision_'.$kind);
        DB::table('portal_pages')->where('id', $permissionPage)->delete();
    }
    $check(DB::table('audit_events')->whereIn('resource_type', ['birimler', 'unvanlar'])->count() === 6, 'management_audit_committed_only_for_success');
    $userAdmin = app(UserAdministration::class);
    $managedUser = $userAdmin->save($actor, ['username' => str_repeat('u', 100), 'full_name' => 'SENTETİK YENİ PERSONEL',
        'level' => 'personnel', 'is_active' => true, 'password' => 'synthetic-new-password', 'password_confirmation' => 'synthetic-new-password']);
    $check($managedUser->must_change_password && ! $managedUser->is_system_admin && password_verify('synthetic-new-password', $managedUser->password), 'user_creation_security_defaults');
    $check(strlen($managedUser->fresh()->username) === 100, 'username_current_schema_accepts_one_hundred_characters');
    DB::statement('CREATE TRIGGER synthetic_user_audit_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'");
    try {
        $userAdmin->resetPassword($actor, $managedUser->id, ['revision' => 1, 'confirmed' => true, 'password' => 'SyntheticReset!2026', 'password_confirmation' => 'SyntheticReset!2026']);
        throw new LogicException('Reset audit rollback failed');
    } catch (QueryException $error) {
        $check($managedUser->fresh()->password === $managedUser->password && $managedUser->fresh()->session_version === 0, 'reset_audit_failure_rolls_back_hash_and_session');
    } finally {
        DB::statement('DROP TRIGGER synthetic_user_audit_failure');
    }
    $managedUser = $userAdmin->resetPassword($actor, $managedUser->id, ['revision' => 1, 'confirmed' => true, 'password' => 'SyntheticReset!2026', 'password_confirmation' => 'SyntheticReset!2026']);
    $check(password_verify('SyntheticReset!2026', $managedUser->password) && $managedUser->must_change_password && $managedUser->session_version === 1
        && ! str_contains(DB::table('audit_events')->where('resource_type', 'users')->where('resource_id', $managedUser->id)->where('action', 'password_reset')->value('details'), 'SyntheticReset!2026'), 'reset_hash_force_change_and_no_secret_audit');
    // Imported synthetic fixture also contains an active admin: isolate the last-admin scenario.
    DB::table('users')->where('is_system_admin', true)->where('id', '!=', $actor->id)->update(['is_active' => false]);
    $check(DB::table('users')->where('is_active', true)->where('is_system_admin', true)->count() === 1, 'last_admin_fixture_is_singleton');
    try {
        $userAdmin->save($actor, ['username' => $actor->username, 'full_name' => 'SENTETİK YÖNETİCİ', 'email' => $actor->email,
            'level' => 'personnel', 'is_active' => true, 'revision' => $actor->revision], $actor->id);
        throw new LogicException('Last admin demoted');
    } catch (ValidationException $error) {
        $check($actor->fresh()->is_system_admin, 'last_active_admin_preserved');
    }
    $pageIds = [];
    foreach (['synthetic_access_one', 'synthetic_access_two'] as $code) {
        $pageIds[] = DB::table('portal_pages')->insertGetId(['code' => $code, 'name' => 'SENTETİK SAYFA']);
    }
    $accessDepartment = DB::table('departments')->insertGetId(['code' => 'synthetic_access', 'name' => 'SENTETİK BİRİM']);
    $accessPerson = User::factory()->create(['username' => 'synthetic_access', 'department_id' => $accessDepartment])->refresh();
    $permissionInput = ['pages' => array_map(fn ($id) => ['id' => $id, 'revision' => 1], $pageIds),
        'target_type' => 'birim', 'target_id' => $accessDepartment, 'operation' => 'grant', 'permission' => 'yazma'];
    DB::statement('CREATE TRIGGER synthetic_access_audit_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW BEGIN IF NEW.resource_type = 'page_access' AND NEW.resource_id = ".$pageIds[1]." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");
    try {
        app(PagePermissions::class)->apply($actor, $permissionInput);
        throw new LogicException('Access batch audit rollback failed');
    } catch (QueryException $error) {
        $check(DB::table('page_permissions')->count() === 0 && DB::table('portal_pages')->where('revision', '!=', 1)->count() === 0,
            'access_batch_late_audit_failure_rolls_back_all_pages');
    } finally {
        DB::statement('DROP TRIGGER synthetic_access_audit_failure');
    }
    app(PagePermissions::class)->apply($actor, $permissionInput);
    $matrix = app(PageAccess::class)->matrix($accessPerson);
    $check(count($matrix) === 2 && $matrix['synthetic_access_one']['giris'] && $matrix['synthetic_access_two']['yazma']
        && DB::table('page_permissions')->count() === 2, 'access_batch_write_implies_read_and_commits_all_pages');
    $personalInput = ['pages' => [['id' => $pageIds[0], 'revision' => 2]], 'target_type' => 'kisi', 'target_id' => $accessPerson->id, 'operation' => 'grant', 'permission' => 'giris', 'reason' => 'SENTETİK TEST ERİŞİMİ'];
    app(PagePermissions::class)->apply($actor, $personalInput);
    $personalInput['pages'][0]['revision'] = 3;
    $personalInput['operation'] = 'remove';
    app(PagePermissions::class)->apply($actor, $personalInput);
    $check(app(PageAccess::class)->allows($accessPerson, 'synthetic_access_one', 'yazma'), 'access_personal_removal_preserves_department_inheritance');
    DB::table('departments')->where('id', $accessDepartment)->update(['is_active' => false]);
    $check(! app(PageAccess::class)->allows($accessPerson, 'synthetic_access_one'), 'access_inactive_department_does_not_grant');
    DB::table('users')->where('id', $accessPerson->id)->update(['department_id' => null]);
    DB::table('departments')->where('id', $accessDepartment)->delete();
    $check(DB::table('page_permissions')->count() === 0, 'access_target_delete_cascades_prefixed_foreign_keys');
    $catalog = app(PageCatalog::class);
    $pageRevision = (int) DB::table('portal_pages')->where('id', $pageIds[0])->value('revision');
    $catalog->save($actor, ['code' => 'synthetic_access_one', 'name' => 'SENTETİK DÜZENLENEN SAYFA', 'path' => '/portal', 'legacy_file' => null, 'revision' => $pageRevision], $pageIds[0]);
    $viewRows = app(PageAccessView::class)->rows($accessPerson);
    $check(count($viewRows) === 2 && collect($viewRows)->contains(fn ($row) => $row['name'] === 'SENTETİK DÜZENLENEN SAYFA' && $row['open_url'] !== null), 'catalog_prefixed_join_and_personal_view');
    $pageRevision++;
    DB::statement('CREATE TRIGGER synthetic_catalog_audit_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'");
    try {
        $catalog->setActive($actor, $pageIds[0], ['revision' => $pageRevision, 'permission_count' => 0, 'confirmed' => true], false);
        throw new LogicException('Catalog archive rollback failed');
    } catch (QueryException $error) {
        $check((bool) DB::table('portal_pages')->where('id', $pageIds[0])->value('is_active'), 'catalog_archive_audit_failure_rolls_back');
    } finally {
        DB::statement('DROP TRIGGER synthetic_catalog_audit_failure');
    }
    $catalog->setActive($actor, $pageIds[0], ['revision' => $pageRevision, 'permission_count' => 0, 'confirmed' => true], false);
    $check(collect(app(PageAccessView::class)->rows())->contains(fn ($row) => $row['id'] === $pageIds[0] && ! $row['is_active'] && $row['open_url'] === null), 'catalog_archived_link_is_closed');
    $catalog->setActive($actor, $pageIds[0], ['revision' => $pageRevision + 1, 'permission_count' => 0, 'confirmed' => true], true);
    $check((bool) DB::table('portal_pages')->where('id', $pageIds[0])->value('is_active') && DB::table('page_permissions')->count() === 0, 'catalog_restore_keeps_permissions_empty');
    $designer = app(ModuleDefinitions::class);
    $moduleId = $designer->create($actor, ['code' => 'synthetic-module', 'name' => 'SENTETİK MODÜL', 'record_mode' => 'standalone']);
    $moduleVersion = DB::table('module_versions')->where('module_id', $moduleId)->first();
    $modulePayload = ['revision' => 1, 'version_id' => $moduleVersion->id, 'version_revision' => 1, 'name' => 'SENTETİK MODÜL',
        'fields' => [['id' => null, 'type' => 'text', 'label' => 'SENTETİK TÜRKÇE BAŞLIK', 'helper' => 'SENTETİK AÇIKLAMA', 'required' => true, 'show_in_form' => true, 'show_in_table' => true]]];
    DB::statement('CREATE TRIGGER synthetic_module_audit_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'");
    try {
        $designer->save($actor, $moduleId, $modulePayload);
        throw new LogicException('Module draft rollback failed');
    } catch (QueryException $error) {
        $check(DB::table('module_fields')->count() === 0 && (int) DB::table('modules')->where('id', $moduleId)->value('revision') === 1, 'module_draft_audit_failure_rolls_back_fields_and_revision');
    } finally {
        DB::statement('DROP TRIGGER synthetic_module_audit_failure');
    }
    $designer->save($actor, $moduleId, $modulePayload);
    $definition = json_decode(DB::table('module_versions')->where('id', $moduleVersion->id)->value('definition'), true, 64, JSON_THROW_ON_ERROR);
    $fieldId = $definition['fields'][0]['id'];
    $check(is_int($fieldId) && $definition['fields'][0]['label'] === 'SENTETİK TÜRKÇE BAŞLIK', 'module_json_preserves_utf8_and_stable_identity');
    try {
        $designer->save($actor, $moduleId, $modulePayload);
        throw new LogicException('Stale draft accepted');
    } catch (DefinitionConflict $error) {
        $check(DB::table('module_fields')->count() === 1, 'module_stale_revision_does_not_allocate_new_fields');
    }
    $sourceDefinition = DB::table('module_versions')->where('id', $moduleVersion->id)->value('definition');
    DB::table('module_versions')->where('id', $moduleVersion->id)->update(['status' => 'published', 'draft_slot' => null]);
    $designer->fork($actor, $moduleId, ['revision' => 2]);
    $draft = DB::table('module_versions')->where('module_id', $moduleId)->where('draft_slot', 1)->first();
    $check((int) $draft->number === 2 && json_decode($draft->definition, true)['fields'][0]['id'] === $fieldId, 'module_fork_keeps_field_identity');
    $modulePayload = ['revision' => 3, 'version_id' => $draft->id, 'version_revision' => 1, 'name' => 'SENTETİK MODÜL', 'fields' => $definition['fields']];
    $modulePayload['fields'][0]['label'] = 'SENTETİK YENİ BAŞLIK';
    $designer->save($actor, $moduleId, $modulePayload);
    $check(DB::table('module_versions')->where('id', $moduleVersion->id)->value('definition') === $sourceDefinition, 'module_published_history_immutable_after_draft_edit');
    try {
        DB::table('module_versions')->insert(['module_id' => $moduleId, 'number' => 3, 'status' => 'draft', 'draft_slot' => 1, 'definition' => '{}']);
        throw new LogicException('Multiple drafts accepted');
    } catch (QueryException $error) {
        $check(DB::table('module_versions')->where('module_id', $moduleId)->where('draft_slot', 1)->count() === 1, 'module_unique_current_draft_enforced');
    }
    $options = app(OptionCatalog::class);
    $listId = $options->create($actor, ['code' => 'synthetic-options', 'name' => 'SENTETİK LİSTE']);
    $optionPayload = ['revision' => 1, 'name' => 'SENTETİK LİSTE', 'is_active' => true, 'items' => [['id' => null, 'label' => 'SENTETİK TÜRKÇE SEÇENEK', 'is_active' => true]]];
    DB::statement('CREATE TRIGGER synthetic_option_audit_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'");
    try {
        $options->save($actor, $listId, $optionPayload);
        throw new LogicException('Option audit rollback failed');
    } catch (QueryException $error) {
        $check(DB::table('option_items')->count() === 0 && (int) DB::table('option_lists')->where('id', $listId)->value('revision') === 1, 'option_audit_failure_rolls_back_items_and_revision');
    } finally {
        DB::statement('DROP TRIGGER synthetic_option_audit_failure');
    }
    $options->save($actor, $listId, $optionPayload);
    $item = DB::table('option_items')->where('option_list_id', $listId)->first();
    $check($item->label === 'SENTETİK TÜRKÇE SEÇENEK', 'option_utf8_label_preserved');
    try {
        $options->save($actor, $listId, $optionPayload);
        throw new LogicException('Stale option revision accepted');
    } catch (DefinitionConflict $error) {
        $check(DB::table('option_items')->count() === 1, 'option_stale_revision_does_not_allocate_items');
    }
    $options->save($actor, $listId, ['revision' => 2, 'name' => 'SENTETİK LİSTE', 'is_active' => true, 'items' => [['id' => (int) $item->id, 'label' => 'SENTETİK YENİ ETİKET', 'is_active' => false]]]);
    $check((int) DB::table('option_items')->where('option_list_id', $listId)->value('id') === (int) $item->id && ! DB::table('option_items')->where('id', $item->id)->value('is_active'), 'option_archive_preserves_identity');
    $draft = DB::table('module_versions')->where('module_id', $moduleId)->where('draft_slot', 1)->first();
    $modulePayload = ['revision' => DB::table('modules')->where('id', $moduleId)->value('revision'), 'version_id' => $draft->id, 'version_revision' => $draft->revision, 'name' => 'SENTETİK MODÜL',
        'fields' => [['id' => null, 'type' => 'select', 'label' => 'SENTETİK SEÇİM', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true, 'option_list_id' => $listId]]];
    $designer->save($actor, $moduleId, $modulePayload);
    $choiceField = DB::table('module_fields')->where('module_id', $moduleId)->where('type', 'select')->first();
    $check((int) $choiceField->option_list_id === $listId && json_decode(DB::table('module_versions')->where('id', $draft->id)->value('definition'), true)['fields'][0]['option_list_id'] === $listId, 'option_source_identity_matches_metadata_and_fk');
    try {
        DB::table('option_lists')->where('id', $listId)->delete();
        throw new LogicException('Referenced option list deleted');
    } catch (QueryException $error) {
        $check(DB::table('option_lists')->where('id', $listId)->exists(), 'option_list_fk_prevents_destructive_delete');
    }
    $valueValidator = app(ModuleValueValidator::class);
    try {
        $valueValidator->validateForCreate($moduleId, (int) $draft->id, []);
        throw new LogicException('Draft values accepted for record creation');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 404, 'value_validator_rejects_unpublished_draft');
    }
    $typedFields = json_decode(DB::table('module_versions')->where('id', $draft->id)->value('definition'), true)['fields'];
    foreach (['text', 'longtext', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'multiselect'] as $type) {
        $typedFields[] = ['id' => null, 'type' => $type, 'label' => 'SENTETİK '.$type, 'helper' => '', 'required' => true, 'show_in_form' => true, 'show_in_table' => true]
            + ($type === 'multiselect' ? ['option_list_id' => $listId] : []);
    }
    $designer->save($actor, $moduleId, ['revision' => DB::table('modules')->where('id', $moduleId)->value('revision'), 'version_id' => $draft->id,
        'version_revision' => DB::table('module_versions')->where('id', $draft->id)->value('revision'), 'name' => 'SENTETİK MODÜL', 'fields' => $typedFields]);
    // Synthetic publication state only: no actual publish endpoint exists yet.
    DB::table('module_versions')->where('id', $draft->id)->update(['status' => 'published', 'draft_slot' => null]);
    $typedFields = json_decode(DB::table('module_versions')->where('id', $draft->id)->value('definition'), true)['fields'];
    $samples = ['select' => (string) $item->id, 'text' => 'SENTETİK İğdır', 'longtext' => "SENTETİK\r\nİKİ", 'integer' => '9007199254740991',
        'decimal' => '999999999999.999999', 'boolean' => '0', 'date' => '2024-02-29', 'datetime' => '2026-10-04T14:00', 'multiselect' => [(string) $item->id]];
    $values = [];
    $idsByType = [];
    foreach ($typedFields as $field) {
        $idsByType[$field['type']] = $field['id'];
        $values[$field['id']] = $samples[$field['type']];
    }
    try {
        $valueValidator->validateForCreate($moduleId, (int) $draft->id, $values);
        throw new LogicException('Archived option accepted');
    } catch (ValidationException $error) {
        $check(isset($error->errors()['values.'.$idsByType['select']]) && isset($error->errors()['values.'.$idsByType['multiselect']]), 'value_validator_rejects_archived_options');
    }
    $options->save($actor, $listId, ['revision' => 3, 'name' => 'SENTETİK LİSTE', 'is_active' => true, 'items' => [['id' => (int) $item->id, 'label' => 'SENTETİK YENİ ETİKET', 'is_active' => true]]]);
    DB::enableQueryLog();
    $normalized = $valueValidator->validateForCreate($moduleId, (int) $draft->id, $values);
    $valueQueries = DB::getQueryLog();
    DB::disableQueryLog();
    $check(count($normalized) === 9 && $normalized[$idsByType['integer']] === 9007199254740991 && $normalized[$idsByType['decimal']] === '999999999999.999999'
        && $normalized[$idsByType['boolean']] === false && $normalized[$idsByType['select']] === (int) $item->id, 'value_validator_preserves_exact_typed_values');
    $check($normalized[$idsByType['datetime']] === '2026-10-04 14:00:00' && $normalized[$idsByType['longtext']] === "SENTETİK\nİKİ", 'value_validator_preserves_return_hour_and_utf8');
    $check(count($valueQueries) === 3 && collect($valueQueries)->every(fn ($query) => preg_match('/^\s*select\b/i', $query['query'])), 'value_validator_read_only_bounded_queries');
    try {
        $valueValidator->validateForCreate($moduleId, (int) $draft->id, $values + ['revision' => 999]);
        throw new LogicException('System value key accepted');
    } catch (ValidationException $error) {
        $check(isset($error->errors()['values']), 'value_validator_rejects_system_keys');
    }
    try {
        $valueValidator->validateForCreate($moduleId, (int) $moduleVersion->id, []);
        throw new LogicException('Old published version accepted');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 404, 'value_validator_rejects_old_published_version');
    }
    $records = app(ModuleRecords::class);
    $recordInput = ['version_id' => (int) $draft->id, 'values' => $values];
    DB::statement('CREATE TRIGGER synthetic_record_audit_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'");
    try {
        $records->save($actor, $moduleId, $recordInput);
        throw new LogicException('Record create rollback failed');
    } catch (QueryException $error) {
        $check(DB::table('module_records')->count() === 0 && DB::table('module_record_index')->count() === 0 && DB::table('module_record_options')->count() === 0, 'record_create_audit_failure_rolls_back_all_tables');
    } finally {
        DB::statement('DROP TRIGGER synthetic_record_audit_failure');
    }
    $record = $records->save($actor, $moduleId, $recordInput);
    $decimalIndex = DB::table('module_record_index')->where('record_id', $record['id'])->where('field_id', $idsByType['decimal'])->value('decimal_value');
    $check($decimalIndex === '999999999999.999999' && DB::table('module_record_index')->count() === 7 && DB::table('module_record_options')->count() === 2, 'record_typed_decimal_and_usage_commit_exactly');
    $storedValues = DB::table('module_records')->where('id', $record['id'])->value('values');
    $check(str_starts_with($storedValues, '{') && json_decode($storedValues, true) === $normalized, 'record_json_object_is_canonical_source');
    $options->save($actor, $listId, ['revision' => 4, 'name' => 'SENTETİK LİSTE', 'is_active' => false, 'items' => [['id' => (int) $item->id, 'label' => 'SENTETİK ARŞİVLİ YENİ ETİKET', 'is_active' => false]]]);
    $updatedValues = $values;
    $updatedValues[$idsByType['text']] = 'SENTETİK DÜZENLEME';
    $updatedInput = ['version_id' => (int) $draft->id, 'revision' => 1, 'values' => $updatedValues];
    $updated = $records->save($actor, $moduleId, $updatedInput, $record['id']);
    $detail = $records->detail($actor, $moduleId, $record['id']);
    $check($updated['revision'] === 2 && $detail['option_labels'][0]['label'] === 'SENTETİK YENİ ETİKET', 'record_update_retains_archived_option_and_historical_label');
    try {
        $records->save($actor, $moduleId, $updatedInput, $record['id']);
        throw new LogicException('Stale record overwritten');
    } catch (DefinitionConflict $error) {
        $check((int) DB::table('module_records')->where('id', $record['id'])->value('revision') === 2, 'record_stale_revision_does_not_overwrite');
    }
    $updatedInput['revision'] = 2;
    $updatedInput['values'][$idsByType['integer']] = '123';
    DB::statement('CREATE TRIGGER synthetic_record_update_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'");
    try {
        $records->save($actor, $moduleId, $updatedInput, $record['id']);
        throw new LogicException('Record update rollback failed');
    } catch (QueryException $error) {
        $check((int) DB::table('module_records')->where('id', $record['id'])->value('revision') === 2
            && (int) DB::table('module_record_index')->where('record_id', $record['id'])->where('field_id', $idsByType['integer'])->value('integer_value') === 9007199254740991
            && DB::table('module_record_options')->where('record_id', $record['id'])->count() === 2, 'record_update_audit_failure_restores_json_index_and_usages');
    } finally {
        DB::statement('DROP TRIGGER synthetic_record_update_failure');
    }
    $foreignModule = $designer->create($actor, ['code' => 'synthetic-foreign', 'name' => 'SENTETİK', 'record_mode' => 'standalone']);
    try {
        DB::table('module_records')->insert(['module_id' => $foreignModule, 'version_id' => $draft->id, 'values' => '{}']);
        throw new LogicException('Cross module version reference accepted');
    } catch (QueryException $error) {
        $check(DB::table('module_records')->count() === 1, 'record_composite_fk_rejects_cross_module_version');
    }
    $paged = $records->page($actor, $moduleId, ['per_page' => 25]);
    $check($paged['pagination']['total'] === 1 && $paged['rows'][0]['option_labels'][0]['label'] === 'SENTETİK YENİ ETİKET', 'record_page_returns_historical_labels_and_bounded_pagination');
    $recordMigration = require database_path('migrations/2026_10_04_000004_create_module_records.php');
    $recordMigration->down();
    $check(! Schema::hasTable('module_records'), 'record_migration_can_roll_back_on_synthetic_schema');
    $recordMigration->up();
    $personLinkMigration = require database_path('migrations/2026_10_04_000008_link_module_people.php');
    $personLinkMigration->up();
    $check(Schema::hasTable('module_records') && DB::table('module_fields')->count() > 0, 'record_migration_can_reapply_without_losing_definition_tables');
    $pageMigration = require database_path('migrations/2026_10_04_000005_link_module_pages.php');
    $pageMigration->down();
    $pageMigration->up();
    $check(Schema::hasColumn('portal_pages', 'module_id'), 'module_page_migration_round_trip');
    $publication = app(ModulePublication::class);
    $designer->save($actor, $foreignModule, ['revision' => 1, 'version_id' => DB::table('module_versions')->where('module_id', $foreignModule)->value('id'),
        'version_revision' => 1, 'name' => 'SENTETİK YAYIN', 'fields' => [['id' => null, 'type' => 'text', 'label' => 'SENTETİK ALAN', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true]]]);
    $publicationInput = $publication->inspect($actor, $foreignModule) + ['confirmed' => true];
    DB::statement('CREATE TRIGGER synthetic_publication_failure BEFORE INSERT ON `'.DB::connection()->getTablePrefix()."audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'");
    try {
        $publication->publish($actor, $foreignModule, $publicationInput);
        throw new LogicException('Publication audit rollback failed');
    } catch (QueryException $error) {
        $check(! DB::table('portal_pages')->where('module_id', $foreignModule)->exists()
            && DB::table('module_versions')->where('id', $publicationInput['version_id'])->value('status') === 'draft', 'publication_rolls_back_page_and_version');
    } finally {
        DB::statement('DROP TRIGGER synthetic_publication_failure');
    }
    $publication->publish($actor, $foreignModule, $publicationInput);
    $modulePage = DB::table('portal_pages')->where('module_id', $foreignModule)->first();
    $check($modulePage->code === 'module_'.$foreignModule && app(PageCatalog::class)->destination($modulePage) === route('module-runtime.show', 'synthetic-foreign'), 'publication_links_server_owned_page_and_destination');
    $foreignField = DB::table('module_fields')->where('module_id', $foreignModule)->value('id');
    $foreignRecord = $records->save($actor, $foreignModule, ['version_id' => $publicationInput['version_id'], 'values' => [$foreignField => 'SENTETİK TÜRKÇE']]);
    $designer->fork($actor, $foreignModule, ['revision' => DB::table('modules')->where('id', $foreignModule)->value('revision')]);
    $foreignDraft = DB::table('module_versions')->where('module_id', $foreignModule)->where('draft_slot', 1)->first();
    $foreignDefinition = json_decode($foreignDraft->definition, true);
    $foreignDefinition['fields'][0]['label'] = 'SENTETİK YENİ BAŞLIK';
    $designer->save($actor, $foreignModule, ['revision' => DB::table('modules')->where('id', $foreignModule)->value('revision'), 'version_id' => $foreignDraft->id,
        'version_revision' => 1, 'name' => $foreignDefinition['name'], 'fields' => $foreignDefinition['fields']]);
    $impact = $publication->inspect($actor, $foreignModule);
    $publication->publish($actor, $foreignModule, $impact + ['confirmed' => true]);
    $check((int) DB::table('module_records')->where('id', $foreignRecord['id'])->value('version_id') === (int) $foreignDraft->id
        && (int) DB::table('module_records')->where('id', $foreignRecord['id'])->value('revision') === 2, 'publication_presentation_updates_record_version_and_revision');
    $designer->fork($actor, $foreignModule, ['revision' => DB::table('modules')->where('id', $foreignModule)->value('revision')]);
    $staleImpact = $publication->inspect($actor, $foreignModule);
    $records->save($actor, $foreignModule, ['version_id' => (int) $foreignDraft->id, 'revision' => 2, 'values' => [$foreignField => 'SENTETİK DEĞİŞİKLİK']], $foreignRecord['id']);
    try {
        $publication->publish($actor, $foreignModule, $staleImpact + ['confirmed' => true]);
        throw new LogicException('Stale publication impact accepted');
    } catch (DefinitionConflict $error) {
        $check($staleImpact['record_count'] === 1 && $staleImpact['record_snapshot'] !== $publication->inspect($actor, $foreignModule)['record_snapshot'], 'publication_hash_detects_same_count_record_change');
    }
    $runtimeStaff = User::factory()->create(['username' => 'synthetic_runtime_writer'])->refresh();
    $modulePage = DB::table('portal_pages')->where('module_id', $foreignModule)->first();
    app(PagePermissions::class)->apply($actor, ['pages' => [['id' => $modulePage->id, 'revision' => $modulePage->revision]], 'target_type' => 'kisi', 'target_id' => $runtimeStaff->id, 'operation' => 'grant', 'permission' => 'yazma', 'reason' => 'SENTETİK TEST ERİŞİMİ']);
    $runtimeForm = $records->runtimeForm($runtimeStaff, $foreignModule, $foreignRecord['id']);
    $check($runtimeForm['record']['revision'] === 3 && count($runtimeForm['fields']) === 1, 'runtime_form_uses_latest_version_and_scoped_fields');
    $records->runtimeSave($runtimeStaff, $foreignModule, ['version_id' => $foreignDraft->id, 'values' => [$foreignField => 'IŞIK %_tam!']]);
    $records->runtimeSave($runtimeStaff, $foreignModule, ['version_id' => $foreignDraft->id, 'values' => [$foreignField => 'İPEK']]);
    $check($records->runtimePage($runtimeStaff, $foreignModule, ['q' => 'ışık'])['pagination']['total'] === 1, 'query_turkish_dotless_case_matches');
    $check($records->runtimePage($runtimeStaff, $foreignModule, ['q' => 'ipek'])['pagination']['total'] === 1, 'query_turkish_dotted_case_matches');
    $check($records->runtimePage($runtimeStaff, $foreignModule, ['q' => '%_tam!'])['pagination']['total'] === 1, 'query_wildcards_are_literal');
    $queryPage = $records->runtimePage($runtimeStaff, $foreignModule, ['filter_field' => $foreignField, 'filter_op' => 'contains', 'filter_value' => 'ışık', 'sort_field' => $foreignField, 'sort_direction' => 'desc']);
    $check($queryPage['pagination']['total'] === 1 && $queryPage['queryState']['sort_direction'] === 'desc', 'query_filter_sort_use_prefixed_indices');
    $exporter = app(ModuleExport::class);
    $exportAuditCount = DB::table('audit_events')->count();
    $exportSnapshot = $exporter->snapshot($runtimeStaff, $foreignModule, ['q' => 'ışık', 'sort_field' => $foreignField, 'sort_direction' => 'desc']);
    $check(count($exportSnapshot['rows']) === 1 && $exportSnapshot['rows'][0][0] === 'IŞIK %_tam!', 'export_uses_filtered_sorted_read_scope');
    $exportCsv = $exporter->csv($exportSnapshot);
    $check(str_starts_with($exportCsv, "\xEF\xBB\xBFID;") && str_contains($exportCsv, '1;') && ! str_contains($exportCsv, 'module_id'), 'export_csv_utf8_and_sequence_only');
    $check(DB::table('audit_events')->count() === $exportAuditCount && DB::table('module_records')->where('module_id', $foreignModule)->count() === 3, 'export_snapshot_does_not_mutate_records_or_audit');
    $runtimeSaved = $records->runtimeSave($runtimeStaff, $foreignModule, ['version_id' => $foreignDraft->id, 'revision' => 3, 'values' => [$foreignField => 'SENTETİK PERSONEL KAYDI']], $foreignRecord['id']);
    $check($runtimeSaved['revision'] === 4 && (int) DB::table('module_records')->where('id', $foreignRecord['id'])->value('updated_by') === (int) $runtimeStaff->id, 'runtime_staff_write_commits_with_actor_and_revision');
    $modulePage = DB::table('portal_pages')->where('module_id', $foreignModule)->first();
    app(PagePermissions::class)->apply($actor, ['pages' => [['id' => $modulePage->id, 'revision' => $modulePage->revision]], 'target_type' => 'kisi', 'target_id' => $runtimeStaff->id, 'operation' => 'remove', 'permission' => 'yazma']);
    try {
        $records->runtimeSave($runtimeStaff, $foreignModule, ['version_id' => $foreignDraft->id, 'revision' => 4, 'values' => [$foreignField => 'SENTETİK ENGELLİ']], $foreignRecord['id']);
        throw new LogicException('Revoked runtime write accepted');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 403 && (int) DB::table('module_records')->where('id', $foreignRecord['id'])->value('revision') === 4, 'runtime_revoked_permission_rejected_without_write');
    }
    $menuMigration = require dirname(__DIR__).'/database/migrations/2026_10_04_000006_create_menu_items.php';
    $menuMigration->down();
    $menuMigration->up();
    $check(DB::table('menu_configuration')->where('id', 1)->value('revision') === 1, 'menu_migration_round_trip');
    $menus = app(MenuConfiguration::class);
    $menuInput = ['revision' => 1, 'items' => [
        ['id' => null, 'key' => 'synthetic-group', 'parent_key' => null, 'label' => 'SENTETİK GRUP', 'page_id' => null, 'is_active' => true],
        ['id' => null, 'key' => 'synthetic-page', 'parent_key' => 'synthetic-group', 'label' => 'SENTETİK SAYFA', 'page_id' => $modulePage->id, 'is_active' => true]]];
    $permissionsBeforeMenu = DB::table('page_permissions')->count();
    DB::unprepared('CREATE TRIGGER synthetic_menu_audit_failure BEFORE INSERT ON '.DB::connection()->getTablePrefix()."audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC MENU FAILURE'");
    try {
        $menus->save($actor, $menuInput);
        throw new LogicException('Menu audit failure accepted');
    } catch (QueryException) {
        $check(DB::table('menu_items')->count() === 0 && (int) DB::table('menu_configuration')->value('revision') === 1, 'menu_audit_failure_rolls_back_configuration_and_items');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS synthetic_menu_audit_failure');
    }
    $menus->save($actor, $menuInput);
    $menuState = $menus->state($actor);
    $check(count($menus->navigation($actor)[0]['children']) === 1 && DB::table('page_permissions')->count() === $permissionsBeforeMenu, 'menu_group_navigation_preserves_permissions');
    $check($menus->navigation($runtimeStaff) === [], 'menu_hidden_when_person_permission_revoked');
    $menus->save($actor, ['revision' => $menuState['revision'], 'items' => $menuState['items']]);
    $check($menus->state($actor)['revision'] === $menuState['revision'], 'menu_no_op_keeps_revision');
    $caseMigration = require database_path('migrations/2026_10_04_000012_create_person_case_workflows.php');
    $sortMigration = require database_path('migrations/2026_10_05_000018_index_sentence_sort.php');
    $sortMigration->down();
    $extensions = [$sortMigration];
    foreach (['000017_create_personal_messages', '000016_create_institution_workflows', '000015_create_person_notes', '000014_extend_service_workflows'] as $name) {
        $migration = require database_path('migrations/2026_10_04_'.$name.'.php');
        $migration->down();
        $extensions[] = $migration;
    }
    $caseMigration->down();
    $personMigration = require database_path('migrations/2026_10_04_000007_create_people.php');
    $personLinkMigration->down();
    $housingMigration = require database_path('migrations/2026_10_04_000009_create_housing.php');
    $statusMigration = require database_path('migrations/2026_10_04_000010_create_person_statuses.php');
    $placementMigration = require database_path('migrations/2026_10_04_000011_create_person_locations.php');
    $placementMigration->down();
    $statusMigration->down();
    $housingMigration->down();
    $personMigration->down();
    $personMigration->up();
    $housingMigration->up();
    $statusMigration->up();
    $placementMigration->up();
    $caseMigration->up();
    foreach (array_reverse($extensions) as $migration) {
        $migration->up();
    }
    // Reapply later schema extensions after recreating the earlier workflow tables.
    $administrationToolsMigration = require database_path('migrations/2026_10_09_000020_complete_administration_tools.php');
    $administrationToolsMigration->up();
    $check(DB::table('person_statuses')->count() === 7 && DB::table('person_status_assignments')->count() === 0, 'status_migration_roundtrip_defaults');
    $check(DB::table('housing_blocks')->count() === 7 && DB::table('housing_rooms')->count() === 62 && DB::table('housing_beds')->where('number', '>', 0)->count() === 820, 'housing_migration_roundtrip_defaults');
    $personLinkMigration->up();
    $check(Schema::hasColumn('module_records', 'person_id'), 'module_person_link_migration_round_trip');
    $check(Schema::hasTable('people') && DB::table('people')->count() === 0, 'person_migration_round_trip_without_seed');
    $people = app(PersonRecords::class);
    $personInput = ['tc_identity' => '00000000001', 'full_name' => 'SENTETİK IŞIK İNCİ', 'admitted_on' => '2024-02-29', 'registered_on' => null];
    $personId = $people->save($actor, $personInput);
    $secondPersonId = $people->save($actor, $personInput);
    $blankPersonId = $people->save($actor, array_replace($personInput, ['tc_identity' => null, 'full_name' => str_repeat('İ', 255), 'admitted_on' => null]));
    $check($personId !== $secondPersonId && DB::table('people')->count() === 3 && $people->detail($actor, $blankPersonId)['tc_identity'] === null, 'person_duplicate_tc_and_null_are_distinct_records');
    $check($people->detail($actor, $personId)['tc_identity'] === '00000000001' && $people->detail($actor, $personId)['admitted_on'] === '2024-02-29'
        && mb_strlen($people->detail($actor, $blankPersonId)['full_name']) === 255, 'person_unicode_leading_zero_and_calendar_date_preserved');
    $personAuditCount = DB::table('audit_events')->count();
    $people->save($actor, $personInput + ['revision' => 1], $personId);
    $check(DB::table('audit_events')->count() === $personAuditCount && $people->detail($actor, $personId)['revision'] === 1, 'person_no_op_keeps_revision_and_audit');
    $people->save($actor, array_replace($personInput, ['revision' => 1, 'full_name' => 'SENTETİK SON AD']), $personId);
    try {
        $people->save($actor, $personInput + ['revision' => 1], $personId);
        throw new LogicException('Stale person update accepted');
    } catch (PersonConflict) {
        $check($people->detail($actor, $personId)['revision'] === 2 && $people->detail($actor, $personId)['full_name'] === 'SENTETİK SON AD', 'person_stale_update_preserves_current_identity');
    }
    $personBeforeFailure = $people->detail($actor, $personId);
    $personAuditCount = DB::table('audit_events')->count();
    DB::unprepared('CREATE TRIGGER synthetic_person_audit_failure BEFORE INSERT ON '.DB::connection()->getTablePrefix()."audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC PERSON FAILURE'");
    try {
        foreach ([null, $personId] as $target) {
            try {
                $people->save($actor, $target === null ? $personInput : array_replace($personInput, ['revision' => 2, 'full_name' => 'SENTETİK GERİ AL']), $target);
                throw new LogicException('Person audit failure accepted');
            } catch (QueryException) {
                $check(DB::table('people')->count() === 3 && $people->detail($actor, $personId) === $personBeforeFailure && DB::table('audit_events')->count() === $personAuditCount,
                    $target === null ? 'person_create_audit_failure_rolls_back' : 'person_update_audit_failure_rolls_back');
            }
        }
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS synthetic_person_audit_failure');
    }
    $personAudit = DB::table('audit_events')->where('resource_type', 'person')->pluck('details')->implode('');
    $check(! str_contains($personAudit, '00000000001') && ! str_contains($personAudit, 'SENTETİK') && ! str_contains($personAudit, '2024-02-29'), 'person_audit_does_not_copy_identity_values');
    $personActor = User::factory()->create(['is_system_admin' => true])->refresh();
    $ownedPersonId = $people->save($personActor, $personInput);
    DB::table('users')->where('id', $personActor->id)->update(['is_system_admin' => false]);
    try {
        $people->save($personActor, $personInput);
        throw new LogicException('Revoked person actor accepted');
    } catch (AuthorizationException) {
        $check(DB::table('people')->count() === 4, 'person_write_rechecks_fresh_administrator');
    }
    $personActor->delete();
    $ownerlessPerson = DB::table('people')->where('id', $ownedPersonId)->first();
    $check($ownerlessPerson->created_by === null && $ownerlessPerson->updated_by === null && $ownerlessPerson->tc_identity === '00000000001', 'person_actor_deletion_preserves_business_identity');
    foreach (['person_single', 'person_multiple'] as $mode) {
        $personModule = $designer->create($actor, ['code' => 'synthetic-'.str_replace('_', '-', $mode), 'name' => 'SENTETİK KİŞİ MODÜLÜ', 'record_mode' => $mode]);
        $personVersion = DB::table('module_versions')->where('module_id', $personModule)->first();
        $designer->save($actor, $personModule, ['revision' => 1, 'version_id' => $personVersion->id, 'version_revision' => 1, 'name' => 'SENTETİK KİŞİ MODÜLÜ', 'fields' => [['id' => null, 'type' => 'text', 'label' => 'İşlem', 'helper' => '', 'required' => false, 'show_in_form' => true, 'show_in_table' => true]]]);
        $publication->publish($actor, $personModule, $publication->inspect($actor, $personModule) + ['confirmed' => true]);
        $personField = (int) DB::table('module_fields')->where('module_id', $personModule)->value('id');
        $linkedInput = ['version_id' => $personVersion->id, 'person_id' => $secondPersonId, 'values' => [$personField => 'SENTETİK İŞLEM']];
        $linkedRecord = $records->runtimeSave($actor, $personModule, $linkedInput);
        $check($records->detail($actor, $personModule, $linkedRecord['id'])['person']['id'] === $secondPersonId, 'module_'.$mode.'_preserves_shared_person');
        if ($mode === 'person_single') {
            try {
                $records->save($actor, $personModule, $linkedInput);
                throw new LogicException('Duplicate single person accepted');
            } catch (ValidationException $error) {
                $check(isset($error->errors()['person_id']), 'module_single_person_duplicate_rejected');
            }
            try {
                DB::table('module_records')->insert(['module_id' => $personModule, 'version_id' => $personVersion->id, 'person_id' => $secondPersonId, 'single_person_slot' => 1, 'values' => '{}']);
                throw new LogicException('Unique person slot accepted duplicate');
            } catch (QueryException) {
                $check(DB::table('module_records')->where('module_id', $personModule)->count() === 1, 'module_single_person_database_unique');
            }
        } else {
            $records->save($actor, $personModule, $linkedInput);
            $check(DB::table('module_records')->where('module_id', $personModule)->count() === 2, 'module_multiple_person_keeps_separate_records');
        }
        $check($records->runtimePage($actor, $personModule, ['q' => 'ışık'])['pagination']['total'] === ($mode === 'person_single' ? 1 : 2), 'module_'.$mode.'_turkish_identity_search');
        $personExport = $exporter->snapshot($actor, $personModule, ['q' => '00000000001', 'sort_field' => $personField]);
        $check($personExport['fields'][0]['kind'] === 'tc' && $personExport['fields'][1]['kind'] === 'name' && $personExport['rows'][0][0] === '00000000001', 'module_'.$mode.'_identity_export');
        $lookup = app(ModulePersonLinks::class)->lookup($actor, $personModule, ['q' => 'ışık']);
        $check(count($lookup) >= 1 && ! array_key_exists('admitted_on', $lookup[0]), 'module_'.$mode.'_identity_only_lookup');
        try {
            DB::table('people')->where('id', $secondPersonId)->delete();
            throw new LogicException('Referenced person deleted');
        } catch (QueryException) {
            $check(DB::table('people')->where('id', $secondPersonId)->exists(), 'module_'.$mode.'_person_fk_restricts_delete');
        }
        $linkedBefore = DB::table('module_records')->where('id', $linkedRecord['id'])->first();
        $linkCount = DB::table('module_records')->where('module_id', $personModule)->count();
        DB::unprepared('CREATE TRIGGER synthetic_person_link_failure BEFORE INSERT ON '.DB::connection()->getTablePrefix()."audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC LINK FAILURE'");
        try {
            $records->save($actor, $personModule, array_replace($linkedInput, ['revision' => 1, 'values' => [$personField => 'SENTETİK GERİ AL']]), $linkedRecord['id']);
            throw new LogicException('Linked audit failure accepted');
        } catch (QueryException) {
            $check(DB::table('module_records')->where('module_id', $personModule)->count() === $linkCount && DB::table('module_records')->where('id', $linkedRecord['id'])->first()->values === $linkedBefore->values, 'module_'.$mode.'_audit_rollback');
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS synthetic_person_link_failure');
        }
    }
    $personList = app(PersonList::class);
    $identityRows = $personList->page($actor, ['scope' => 'all', 'tc' => '00000000001', 'per_page' => 25]);
    $check($identityRows['pagination']['total'] === 3, 'person_runtime_duplicate_tc_filter');
    $literalId = $people->runtimeSave($actor, array_replace($personInput, ['full_name' => 'SENTETİK IŞIK %_!', 'tc_identity' => null]));
    $literalRows = $personList->page($actor, ['scope' => 'all', 'q' => 'ışık %_!']);
    $check(count($literalRows['rows']) === 1 && $literalRows['rows'][0]['id'] === $literalId, 'person_runtime_turkish_literal_search');
    $check(array_keys($literalRows['rows'][0]) === ['id', 'tc_identity', 'full_name', 'admitted_on', 'registered_on'], 'person_runtime_minimal_identity_dto');
    $staffPerson = User::factory()->create(['is_system_admin' => false])->refresh();
    $check($personList->page($staffPerson, ['scope' => 'all'])['pagination']['total'] === 5, 'person_runtime_staff_fixed_home_read');
    try {
        $people->runtimeSave($staffPerson, $personInput);
        throw new LogicException('Read-only staff wrote person');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 403 && DB::table('people')->count() === 5, 'person_runtime_staff_write_denied');
    }
    $home = DB::table('portal_pages')->where('code', 'hukumlu_kayit')->first();
    if ($home === null) {
        app(PageCatalog::class)->save($actor, ['code' => 'hukumlu_kayit', 'name' => 'Ana sayfa', 'path' => '/portal']);
        $home = DB::table('portal_pages')->where('code', 'hukumlu_kayit')->first();
    }
    $homeGrant = ['pages' => [['id' => $home->id, 'revision' => $home->revision]], 'target_type' => 'kisi', 'target_id' => $staffPerson->id, 'operation' => 'grant', 'permission' => 'yazma', 'reason' => 'SENTETİK TEST ERİŞİMİ'];
    app(PagePermissions::class)->apply($actor, $homeGrant);
    $staffPersonId = $people->runtimeSave($staffPerson, array_replace($personInput, ['tc_identity' => null]));
    $check($people->runtimeDetail($staffPerson, $staffPersonId)['revision'] === 1, 'person_runtime_explicit_home_write');
    $homeGrant['pages'][0]['revision'] = DB::table('portal_pages')->where('id', $home->id)->value('revision');
    app(PagePermissions::class)->apply($actor, array_replace($homeGrant, ['operation' => 'remove', 'permission' => null]));
    try {
        $people->runtimeSave($staffPerson, $personInput + ['revision' => 1], $staffPersonId);
        throw new LogicException('Revoked staff wrote person');
    } catch (HttpException $error) {
        $check($error->getStatusCode() === 403 && DB::table('people')->where('id', $staffPersonId)->value('revision') === 1, 'person_runtime_revocation_is_fresh');
    }
    $housing = app(HousingDefinitions::class);
    $housingRoom = static function (array $changes = []): array {
        $row = (array) DB::table('housing_rooms')->where('id', 1)->first();

        return array_replace(array_intersect_key($row, array_flip(['code', 'name', 'is_active', 'revision', 'block_id', 'bed_count', 'capacity', 'kind'])), $changes);
    };
    $bedIds = DB::table('housing_beds')->where('room_id', 1)->pluck('id', 'number')->all();
    $housing->save($actor, 'rooms', $housingRoom(['bed_count' => 8]), 1);
    $check(DB::table('housing_beds')->where('room_id', 1)->where('is_active', true)->count() === 8, 'housing_capacity_shrink_archives_upper_beds');
    $housing->save($actor, 'rooms', $housingRoom(['bed_count' => 12]), 1);
    $check(DB::table('housing_beds')->where('room_id', 1)->pluck('id', 'number')->all() === $bedIds, 'housing_regrowth_preserves_bed_ids');
    $personForHousing = $people->runtimeSave($actor, array_replace($personInput, ['tc_identity' => null]));
    DB::table('housing_occupancies')->insert(['person_id' => $personForHousing, 'bed_id' => $bedIds[12], 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['bed_count' => 11], ['capacity' => 0], ['is_active' => false], ['block_id' => 7]] as $change) {
        try {
            $housing->save($actor, 'rooms', $housingRoom($change), 1);
            throw new LogicException('Occupied housing changed unsafely');
        } catch (ValidationException) {
            $check(DB::table('housing_rooms')->where('id', 1)->value('bed_count') === 12 && DB::table('housing_beds')->where('id', $bedIds[12])->value('is_active') === 1, 'housing_occupied_guard_'.array_key_first($change));
        }
    }
    try {
        DB::table('housing_beds')->where('id', $bedIds[12])->delete();
        throw new LogicException('Occupied bed deleted');
    } catch (QueryException) {
        $check(DB::table('housing_occupancies')->where('bed_id', $bedIds[12])->exists(), 'housing_occupancy_fk_restrict');
    }
    $beforeHousing = (array) DB::table('housing_rooms')->where('id', 1)->first();
    DB::unprepared('CREATE TRIGGER synthetic_housing_failure BEFORE INSERT ON '.DB::connection()->getTablePrefix()."audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC HOUSING FAILURE'");
    try {
        $housing->save($actor, 'rooms', $housingRoom(['bed_count' => 15]), 1);
        throw new LogicException('Housing audit failure accepted');
    } catch (QueryException) {
        $check((array) DB::table('housing_rooms')->where('id', 1)->first() === $beforeHousing && DB::table('housing_beds')->where('room_id', 1)->pluck('id', 'number')->all() === $bedIds, 'housing_audit_rollback_definition_and_beds');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS synthetic_housing_failure');
    }
    $check(count($housing->snapshot($actor)['rooms']) === 62, 'housing_snapshot_uses_current_database');
    $statuses = app(StatusDefinitions::class);
    $statusInput = static function (array $changes = []): array {
        $row = (array) DB::table('person_statuses')->where('id', 1)->first();

        return array_replace(array_intersect_key($row, array_flip(['code', 'label', 'included_in_active_list', 'counts_room', 'reserves_bed', 'is_active', 'revision'])), $changes);
    };
    DB::table('person_status_assignments')->insert(['person_id' => $personForHousing, 'status_id' => 1]);
    try {
        $statuses->save($actor, $statusInput(['reserves_bed' => false]), 1);
        throw new LogicException('Used status behavior changed');
    } catch (ValidationException) {
        $check(DB::table('person_statuses')->where('id', 1)->value('reserves_bed') === 1, 'used_status_behavior_is_protected');
    }
    $statuses->save($actor, $statusInput(['label' => 'SENTETİK YENİ ETİKET']), 1);
    $check($statuses->rows($actor)[0]['usage_count'] === 1 && $statuses->rows($actor)[0]['reserves_bed'], 'status_rename_preserves_behavior_and_identity');
    $statuses->save($actor, $statusInput(['is_active' => false]), 1);
    $check(DB::table('person_status_assignments')->where('person_id', $personForHousing)->value('status_id') === 1, 'status_archival_preserves_person_link');
    try {
        DB::table('person_statuses')->where('id', 1)->delete();
        throw new LogicException('Referenced status deleted');
    } catch (QueryException) {
        $check(DB::table('person_statuses')->where('id', 1)->exists(), 'status_assignment_fk_restrict');
    }
    $custom = $statuses->save($actor, ['code' => 'synthetic_custom', 'label' => 'SENTETİK DURUM', 'included_in_active_list' => false, 'counts_room' => false, 'reserves_bed' => false, 'is_active' => true]);
    $check(DB::table('person_statuses')->where('id', $custom)->value('reserves_bed') === 0, 'status_custom_behavior_is_explicit');
    $beforeStatus = (array) DB::table('person_statuses')->where('id', 1)->first();
    DB::unprepared('CREATE TRIGGER synthetic_status_failure BEFORE INSERT ON '.DB::connection()->getTablePrefix()."audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC STATUS FAILURE'");
    try {
        $statuses->save($actor, $statusInput(['label' => 'SENTETİK GERİ AL']), 1);
        throw new LogicException('Status audit failure accepted');
    } catch (QueryException) {
        $check((array) DB::table('person_statuses')->where('id', 1)->first() === $beforeStatus, 'status_audit_failure_rollback');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS synthetic_status_failure');
    }
    $placement = app(PersonPlacement::class);
    $placementPeople = [];
    for ($i = 0; $i < 3; $i++) {
        $placementPeople[] = app(PersonRecords::class)->save($actor, ['tc_identity' => null, 'full_name' => 'SENTETİK YERLEŞİM', 'admitted_on' => null, 'registered_on' => null]);
    }
    $bed = (int) DB::table('housing_beds')->where('room_id', 1)->where('number', 9)->value('id');
    $barrier = tempnam(sys_get_temp_dir(), 'cte-placement-');
    unlink($barrier);
    $workers = [];
    $workerEnv = getenv();
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $schema, 'DB_HOST' => $host, 'DB_PORT' => $port, 'DB_SOCKET' => $socket, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $password ?: '', 'DB_URL' => '', 'DB_TABLE_PREFIX' => DB::connection()->getTablePrefix()] as $key => $value) {
        $workerEnv[$key] = $value;
    }
    try {
        foreach (array_slice($placementPeople, 0, 2) as $person) {
            $process = proc_open([PHP_BINARY, __DIR__.'/placement_concurrency_worker.php', (string) $actor->id, (string) $person, (string) $bed, $barrier], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $workerEnv);
            if (! is_resource($process)) {
                throw new RuntimeException('Worker unavailable');
            }
            fclose($pipes[0]);
            $workers[] = [$process, $pipes[1], $pipes[2]];
        }
        touch($barrier);
        $results = [];
        foreach ($workers as [$process, $out, $err]) {
            $results[] = stream_get_contents($out);
            $errors = stream_get_contents($err);
            fclose($out);
            fclose($err);
            $check(proc_close($process) === 0 && $errors === '', 'placement_concurrency_worker_completed');
        }
        sort($results);
        $check($results === ['200', '422'], 'placement_two_connections_one_bed_one_winner');
    } finally {
        if (is_file($barrier)) {
            unlink($barrier);
        }
    }
    $check(DB::table('housing_occupancies')->where('bed_id', $bed)->count() === 1 && DB::table('person_locations')->whereIn('person_id', array_slice($placementPeople, 0, 2))->count() === 1, 'placement_concurrent_loser_has_no_partial_location');
    $owner = (int) DB::table('housing_occupancies')->where('bed_id', $bed)->value('person_id');
    $current = $placement->detail($actor, $owner)['record'];
    $placement->save($actor, $owner, array_replace($current, ['status_id' => $custom]));
    $check(! DB::table('housing_occupancies')->where('person_id', $owner)->exists(), 'placement_nonreserving_status_releases_bed');
    $specialBed = (int) DB::table('housing_beds')->where('room_id', 60)->where('number', 0)->value('id');
    $placement->save($actor, $placementPeople[2], ['status_id' => 2, 'block_id' => 6, 'room_id' => 60, 'bed_id' => $specialBed, 'revision' => 0, 'status_revision' => 0]);
    $check($placement->summaries([$placementPeople[2]])[$placementPeople[2]]['bed'] === 'Ranzasız', 'placement_virtual_bed_zero_capacity_allowed');
    $current = $placement->detail($actor, $placementPeople[2])['record'];
    DB::unprepared('CREATE TRIGGER synthetic_placement_failure BEFORE INSERT ON '.DB::connection()->getTablePrefix()."audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC PLACEMENT FAILURE'");
    try {
        $placement->save($actor, $placementPeople[2], array_replace($current, ['status_id' => null, 'bed_id' => null]));
        throw new LogicException('Placement audit failure accepted');
    } catch (QueryException) {
        $check($placement->detail($actor, $placementPeople[2])['record'] === $current && DB::table('housing_occupancies')->where('person_id', $placementPeople[2])->value('bed_id') === $specialBed, 'placement_mariadb_audit_failure_atomic_rollback');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS synthetic_placement_failure');
    }
    $overview = app(HousingSummary::class)->read();
    $check($overview['physical'] === 820 && $overview['bedless_reserved'] === 1, 'overview_physical_excludes_virtual_beds');
    $overviewRooms = collect($overview['rooms'])->keyBy('id');
    $check($overviewRooms[60]['present'] === 1 && $overviewRooms[60]['physical'] === 0, 'overview_bedless_counts_room_without_physical_bed');
    $check($overviewRooms[1]['reserved'] === 1 && $overviewRooms[1]['available'] === 11, 'overview_physical_reservations_and_available');
    $activeRows = $personList->page($actor);
    $check($activeRows['queryState']['scope'] === 'active' && $activeRows['pagination']['total'] === $overview['active'], 'overview_default_active_membership_matches_aggregate');
    $check(in_array($personForHousing, array_column($activeRows['rows'], 'id'), true), 'overview_archived_status_retains_membership');
    $check($personList->page($actor, ['scope' => 'all'])['pagination']['total'] === $overview['total'] && $overview['unassigned'] > 0, 'overview_all_includes_unassigned');
    $personList->page($actor, ['q' => 'ABSENT', 'page' => 2]);
    $check(app(HousingSummary::class)->read() === $overview, 'overview_independent_of_search_and_pagination');
    WorkflowMariaChecks::run($actor, $check);
    ServiceInstitutionMariaChecks::run($actor, $check);
    $recoveryBase = sys_get_temp_dir().'/cte-sql-recovery-'.bin2hex(random_bytes(6));
    $recoveryPrefix = DB::connection()->getTablePrefix().'restore_';
    $recoveryTable = $recoveryPrefix.'probe';
    try {
        DB::statement('CREATE TABLE `'.$recoveryTable.'` (id INT PRIMARY KEY) ENGINE=InnoDB');
        $recovery = new StandaloneRecovery($recoveryBase, ['DB_TABLE_PREFIX' => $recoveryPrefix]);
        $recovery->restore(DB::connection()->getPdo(), 'INSERT INTO `'.$recoveryTable.'` (`id`) VALUES (1);', 'sql');
        $generation = SessionGeneration::current($recoveryBase.'/storage');
        $check(strlen($generation) === 64, 'standalone_sql_restore_rotates_sessions');
        $recovery->restore(DB::connection()->getPdo(), 'INSERT INTO `'.$recoveryTable.'` (`id`) VALUES (2);', 'sql');
        $check($generation !== SessionGeneration::current($recoveryBase.'/storage')
            && (int) DB::connection()->getPdo()->query('SELECT COUNT(*) FROM `'.$recoveryTable.'`')->fetchColumn() === 2, 'standalone_repeated_sql_restore_changes_generation');
    } finally {
        DB::statement('DROP TABLE IF EXISTS `'.$recoveryTable.'`');
        File::deleteDirectory($recoveryBase);
    }
    ReportFixesMariaChecks::run($check);
    WorkspaceMariaChecks::run($actor->fresh(), $check);
    MaintenanceMariaChecks::run($actor->fresh(), $check);
    config(['database.connections.unsafe' => ['driver' => 'mysql', 'database' => 'cte', 'prefix' => 'cte_next_']]);
    try {
        app(ImportTargetGuard::class)->assertSafe('unsafe');
        throw new LogicException('Unsafe source target accepted');
    } catch (RuntimeException $error) {
        $check(! $error instanceof LogicException, 'legacy_target_rejected');
    }
    DB::statement('ALTER TABLE `'.DB::connection()->getTablePrefix().'import_mappings` ENGINE=MyISAM');
    try {
        $importer->apply($snapshot);
        throw new RuntimeException('Nontransactional target accepted');
    } catch (ImportConflict $error) {
        $check(str_contains($error->getMessage(), 'InnoDB') && DB::table('import_mappings')->count() === 3, 'nontransactional_target_rejected');
    }
    if ($sentinels !== []) {
        foreach ($sentinels as $name) {
            $row = DB::connection()->getPdo()->query('SELECT * FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC);
            $check(count($row) === 1 && $row[0]['marker'] === 'synthetic_legacy_unchanged', 'shared_schema_unprefixed_unchanged_'.$name);
        }
    }
    $version = $server->query('SELECT VERSION()')->fetchColumn();
} catch (Throwable $error) {
    $failed = true;
    $driverCode = $error instanceof QueryException || $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
    fwrite(STDERR, get_class($error).'; driver_code='.$driverCode."; integration test failed. No credential/binding output.\n");
    foreach ($error->getTrace() as $frame) {
        $file = $frame['file'] ?? '';
        if (str_starts_with($file, dirname(__DIR__).'/app/') || str_starts_with($file, __DIR__.'/')) {
            fwrite(STDERR, str_replace(dirname(__DIR__).'/', '', $file).':'.($frame['line'] ?? 0).PHP_EOL);
        }
    }
} finally {
    if (isset($exportDirectory) && is_dir($exportDirectory)) {
        try {
            if (! File::deleteDirectory($exportDirectory)) {
                throw new RuntimeException('Export cleanup failed');
            }
        } catch (Throwable $error) {
            $failed = true;
            fwrite(STDERR, "Synthetic export cleanup failed.\n");
        }
    }
    if ($sourceCreated && $server !== null && preg_match('/^cte_g03_source_test_[a-f0-9]{12}$/D', $sourceSchema)) {
        try {
            $server->exec('DROP DATABASE `'.$sourceSchema.'`');
            $sourceCleaned = true;
        } catch (Throwable $error) {
            $failed = true;
            fwrite(STDERR, "Synthetic source cleanup failed.\n");
        }
    }
    if ($created && $server !== null && preg_match('/^cte_test_g03_[a-f0-9]{12}$/D', $schema)) {
        if (isset($app)) {
            try {
                DB::purge('mysql');
            } catch (Throwable $error) {
                $failed = true;
                fwrite(STDERR, "Test connection cleanup failed.\n");
            }
        }
        try {
            $server->exec('DROP DATABASE `'.$schema.'`');
            $cleaned = true;
        } catch (Throwable $error) {
            $failed = true;
            fwrite(STDERR, "Synthetic database cleanup failed.\n");
        }
    }
}
echo json_encode(['passed_count' => count($passed), 'passed' => $passed, 'status' => $failed ? 'failed' : 'passed', 'server_version' => $version ?? null,
    'synthetic_database_cleaned' => $cleaned, 'synthetic_source_cleaned' => $sourceCleaned, 'real_source_rows_copied' => 0], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed ? 1 : 0);
