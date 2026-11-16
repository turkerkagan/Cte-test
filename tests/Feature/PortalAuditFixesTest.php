<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Administration\BackupPlans;
use App\Services\Administration\ManagedDatabase;
use App\Services\Administration\UserAdministration;
use App\Services\Operations\PortalHealth;
use App\Services\Operations\PrivateFileCleanup;
use App\Services\Security\PortalLogin;
use App\Services\Security\SessionGeneration;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Regression checks for the audit fixes, using only disposable data and private files. */
final class PortalAuditFixesTest extends TestCase
{
    use DatabaseMigrations;

    private string $probeStorage;

    public function runDatabaseMigrations(): void
    {
        if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \LogicException('Audit probes require the isolated in-memory SQLite test database.');
        }
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->probeStorage = sys_get_temp_dir().'/cte-audit-probe-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->probeStorage);
        File::makeDirectory($this->probeStorage.'/framework/views', 0700, true);
        File::makeDirectory($this->probeStorage.'/app/private', 0700, true);
        config(['view.compiled' => $this->probeStorage.'/framework/views', 'filesystems.disks.local.root' => $this->probeStorage.'/app/private']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->probeStorage);
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_system_admin' => true])->refresh();
    }

    private function grantRead(User $user, string $code, string $path): void
    {
        $id = DB::table('portal_pages')->insertGetId(['code' => $code, 'name' => $code, 'path' => $path]);
        DB::table('page_permissions')->insert(['page_id' => $id, 'target_type' => 'kisi', 'target_id' => $user->id, 'user_id' => $user->id, 'permission' => 'giris']);
    }

    public function test_chat_read_permission_cannot_create_or_send(): void
    {
        $user = User::factory()->create()->refresh();
        $this->grantRead($user, 'sohbet', '/kurum/sohbet');
        $this->assertFalse(app(PageAccess::class)->allows($user, 'sohbet', 'yazma'));
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version]);
        $room = DB::table('chat_rooms')->insertGetId(['name' => 'SENTETIK AUDIT', 'is_general' => true]);
        $this->postJson('/kurum/sohbet', ['action' => 'create', 'name' => 'SENTETIK AUDIT'])->assertForbidden();
        $this->postJson('/kurum/sohbet', ['action' => 'send', 'room' => $room, 'body' => 'SENTETIK AUDIT'])->assertForbidden();
        $this->postJson('/kurum/sohbet', ['action' => 'invite', 'room' => $room, 'members' => []])->assertForbidden();
        $this->getJson('/kurum/sohbet/mesajlar?room='.$room)->assertOk()->assertJsonPath('canWrite', false);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_restore_invalidates_old_sessions_and_advances_live_versions(): void
    {
        $admin = $this->admin();
        $staff = User::factory()->create()->refresh();
        $service = app(ManagedDatabase::class);
        $backup = $service->create($admin);
        $verified = $service->verify($admin, $backup['id']);
        DB::table('users')->where('id', $staff->id)->update(['session_version' => 1]);
        $preRestoreSessionVersion = 1;
        $service->restore($admin, $backup['id'], ['confirmed' => true, 'confirmation' => 'GERİ YÜKLE', 'checksum' => $verified['checksum']]);
        $restored = $staff->fresh();
        $this->assertSame(2, $restored->session_version);
        $response = $this->actingAs($restored)->withSession(['auth_session_version' => $preRestoreSessionVersion])->get('/portal');
        $response->assertRedirect('/giris');
        // Even an old session whose numeric version happens to match cannot cross a restore.
        $this->actingAs($restored)->withSession(['auth_session_version' => $restored->session_version, 'auth_generation' => ''])->get('/portal')->assertRedirect('/giris');
        $generation = SessionGeneration::current(storage_path());
        $this->actingAs($restored)->withSession(['auth_session_version' => $restored->session_version, 'auth_generation' => $generation])->get('/portal')->assertOk();
        $service->restore($admin->fresh(), $backup['id'], ['confirmed' => true, 'confirmation' => 'GERİ YÜKLE', 'checksum' => $verified['checksum']]);
        $restored = $staff->fresh();
        $this->assertSame(3, $restored->session_version);
        $this->assertNotSame($generation, SessionGeneration::current(storage_path()));
        $this->actingAs($restored)->withSession(['auth_session_version' => $restored->session_version, 'auth_generation' => $generation])->get('/portal')->assertRedirect('/giris');
    }

    public function test_panel_backup_copies_files_before_releasing_the_database_snapshot(): void
    {
        $admin = $this->admin();
        $person = DB::table('people')->insertGetId(['full_name' => 'SENTETIK AUDIT']);
        $root = storage_path('app/private');
        File::makeDirectory($root.'/person-photos', 0700, true);
        file_put_contents($root.'/person-photos/old.jpg', 'OLD');
        DB::table('person_photos')->insert(['person_id' => $person, 'path' => 'person-photos/old.jpg', 'mime' => 'image/jpeg', 'revision' => 1]);
        $hooked = false;
        DB::listen(function ($event) use (&$hooked, $person, $root) {
            if (! $hooked && str_starts_with(strtolower($event->sql), 'select * from') && str_contains($event->sql, 'person_photos')) {
                $hooked = true;
                DB::afterCommit(function () use ($person, $root) {
                    file_put_contents($root.'/person-photos/new.jpg', 'NEW');
                    DB::table('person_photos')->where('person_id', $person)->update(['path' => 'person-photos/new.jpg', 'revision' => 2]);
                    unlink($root.'/person-photos/old.jpg');
                });
            }
        });
        $service = app(ManagedDatabase::class);
        $backup = $service->create($admin);
        $path = $service->backup($admin, $backup['id'])['path'];
        $payload = json_decode(Crypt::decryptString(file_get_contents($path)), true);
        $photoPath = $payload['tables']['person_photos'][0]['path'];
        $this->assertTrue($hooked);
        $this->assertSame('person-photos/old.jpg', $photoPath);
        $this->assertArrayHasKey($photoPath, $payload['files']);
        $this->assertSame('OLD', base64_decode($payload['files'][$photoPath]));
        $this->assertArrayNotHasKey('person-photos/new.jpg', $payload['files']);
    }

    public function test_login_accepts_a_separate_registry_number(): void
    {
        User::factory()->create(['username' => 'audit.user', 'registry_number' => '987654321', 'password' => Hash::make('AuditPassword!2026')]);
        $this->assertNotNull(app(PortalLogin::class)->verify('audit.user', 'AuditPassword!2026'));
        $this->assertNotNull(app(PortalLogin::class)->verify('987654321', 'AuditPassword!2026'));
        User::factory()->create(['registry_number' => '987654321']);
        $this->assertNull(app(PortalLogin::class)->verify('987654321', 'AuditPassword!2026'));
    }

    public function test_panel_backup_updates_health_status(): void
    {
        $backup = app(ManagedDatabase::class)->create($this->admin());
        $health = app(PortalHealth::class)->snapshot();
        $this->assertGreaterThan(0, $backup['id']);
        $this->assertTrue($health['backup']['success']);
    }

    public function test_account_deletion_cleans_attachment_files_after_commit(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['username' => 'audit.deleted'])->refresh();
        $message = DB::table('personal_messages')->insertGetId([
            'sender_id' => $user->id, 'recipient_id' => $admin->id,
            'sender_name' => 'SENTETIK', 'recipient_name' => 'SENTETIK',
            'subject' => 'SENTETIK', 'body' => Crypt::encryptString('SENTETIK'),
            'request_id' => (string) Str::uuid(),
        ]);
        $path = 'message-attachments/audit.txt';
        Storage::disk('local')->put($path, 'SENTETIK');
        DB::table('message_attachments')->insert([
            'message_id' => $message, 'path' => $path, 'name' => 'audit.txt',
            'mime' => 'text/plain', 'size' => 8, 'expires_at' => now()->subDay(),
        ]);
        app(UserAdministration::class)->delete($admin, $user->id, [
            'revision' => $user->revision, 'confirmed' => true, 'confirmation' => 'KULLANICIYI SİL',
        ]);
        $this->artisan('portal:clean-private-documents')->assertExitCode(0);
        $this->assertDatabaseCount('message_attachments', 0);
        $fileRemains = Storage::disk('local')->exists($path);
        $this->assertFalse($fileRemains);
        $this->assertDatabaseCount('private_file_deletions', 0);
    }

    public function test_numeric_backup_plan_values_are_normalized_before_scheduling(): void
    {
        $plans = app(BackupPlans::class);
        $plans->save($this->admin(), ['revision' => 0, 'items' => [[
            'id' => 'audit_weekly', 'name' => 'SENTETIK', 'target' => 'local',
            'frequency' => 'weekly', 'time' => '02:00', 'weekday' => '1',
            'monthday' => '1', 'keep' => '14', 'enabled' => true,
        ]]]);
        $plan = $plans->read()['values']['items'][0];
        $this->assertSame(1, $plan['weekday']);
        $this->assertSame(1, $plan['monthday']);
        $this->assertSame(14, $plan['keep']);
        $this->assertNotEmpty($plan['next_run']);
    }

    public function test_backup_rejects_missing_referenced_files_without_creating_a_false_success(): void
    {
        $admin = $this->admin();
        $person = DB::table('people')->insertGetId(['full_name' => 'SENTETIK']);
        DB::table('person_photos')->insert(['person_id' => $person, 'path' => 'person-photos/missing.jpg', 'mime' => 'image/jpeg', 'revision' => 1]);
        try {
            app(ManagedDatabase::class)->create($admin);
            $this->fail('A backup with a missing referenced file was accepted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('özel dosya eksik', $error->getMessage());
        }
        $this->assertDatabaseCount('administration_backups', 0);
        $this->assertFalse(app(PortalHealth::class)->snapshot()['backup']['success']);
    }

    public function test_backups_from_before_the_cleanup_migration_can_still_be_restored(): void
    {
        $admin = $this->admin();
        $service = app(ManagedDatabase::class);
        $backup = $service->create($admin);
        $path = $service->backup($admin, $backup['id'])['path'];
        $payload = json_decode(Crypt::decryptString(file_get_contents($path)), true);
        unset($payload['tables']['private_file_deletions'], $payload['schema']['private_file_deletions']);
        file_put_contents($path, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)));
        $checksum = hash_file('sha256', $path);
        DB::table('administration_backups')->where('id', $backup['id'])->update(['sha256' => $checksum, 'bytes' => filesize($path)]);
        $this->assertTrue($service->verify($admin, $backup['id'])['schemaMatches']);
        DB::table('private_file_deletions')->insert(['path' => 'message-attachments/old.txt', 'created_at' => now(), 'updated_at' => now()]);
        $service->restore($admin, $backup['id'], ['confirmed' => true, 'confirmation' => 'GERİ YÜKLE', 'checksum' => $checksum]);
        $this->assertDatabaseCount('private_file_deletions', 0);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_failed_file_deletion_remains_queued_and_is_retried(): void
    {
        $disk = Storage::disk('local');
        $disk->put('message-attachments/retry.txt', 'SENTETIK');
        $cleanup = app(PrivateFileCleanup::class);
        $cleanup->enqueue(['message-attachments/retry.txt']);
        $failing = \Mockery::mock($disk);
        $failing->shouldReceive('exists')->with('message-attachments/retry.txt')->andReturn(true);
        $failing->shouldReceive('delete')->with('message-attachments/retry.txt')->once()->andReturn(false);
        $this->app['filesystem']->set('local', $failing);
        $this->assertSame(0, $cleanup->drain(50));
        $this->assertDatabaseCount('private_file_deletions', 1);
        $this->app['filesystem']->set('local', $disk);
        $this->assertSame(1, $cleanup->drain(50));
        $this->assertFalse($disk->exists('message-attachments/retry.txt'));
        $this->assertDatabaseCount('private_file_deletions', 0);
    }

    public function test_display_cache_never_caches_write_authorization(): void
    {
        $user = User::factory()->create()->refresh();
        $this->grantRead($user, 'sohbet', '/kurum/sohbet');
        DB::table('page_permissions')->update(['permission' => 'yazma']);
        $access = app(PageAccess::class);
        $this->assertTrue($access->displayMatrix($user)['sohbet']['yazma']);
        DB::table('page_permissions')->delete();
        $this->assertFalse($access->allows($user, 'sohbet', 'yazma'));
    }

    public function test_username_takes_precedence_over_another_users_registry_number(): void
    {
        $owner = User::factory()->create(['username' => 'audit.owner', 'password' => Hash::make('OwnerPassword!2026')]);
        User::factory()->create(['registry_number' => 'audit.owner', 'password' => Hash::make('OtherPassword!2026')]);
        $this->assertSame($owner->id, app(PortalLogin::class)->verify('audit.owner', 'OwnerPassword!2026')->id);
        $this->assertNull(app(PortalLogin::class)->verify('audit.owner', 'OtherPassword!2026'));
    }

    public function test_login_records_the_current_generation_and_accepts_a_new_session(): void
    {
        SessionGeneration::rotate(storage_path());
        $user = User::factory()->create(['password' => Hash::make('LoginPassword!2026')])->refresh();
        $this->post('/giris', ['username' => $user->username, 'password' => 'LoginPassword!2026'])
            ->assertRedirect('/portal')->assertSessionHas('auth_generation', SessionGeneration::current(storage_path()));
        $this->get('/portal')->assertOk();
    }

    public function test_measure_query_count_for_normal_staff_navigation(): void
    {
        $user = User::factory()->create()->refresh();
        foreach (WorkflowAccess::PAGES as $code => $page) {
            $this->grantRead($user, $code, '/audit/'.$code);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($user)->withSession(['auth_session_version' => $user->session_version])->get('/portal')->assertOk();
        $queries = DB::getQueryLog();
        $groups = [];
        foreach ($queries as $query) {
            if (str_contains($query['query'], 'page_permissions')) {
                $groups['permissionQueries'] = ($groups['permissionQueries'] ?? 0) + 1;
            }
            if (str_contains($query['query'], 'portal_pages')) {
                $groups['pageQueries'] = ($groups['pageQueries'] ?? 0) + 1;
            }
        }
        DB::disableQueryLog();
        $this->assertLessThan(65, count($queries), 'Permission projections must not recompute a matrix for every UI field.');
    }

    public function test_username_retains_the_current_one_hundred_character_contract(): void
    {
        $admin = $this->admin();
        $input = ['username' => str_repeat('u', 100), 'full_name' => 'SENTETİK KULLANICI',
            'level' => 'personnel', 'is_active' => true, 'password' => 'SyntheticUser!2026', 'password_confirmation' => 'SyntheticUser!2026'];
        $user = app(UserAdministration::class)->save($admin, $input);
        $this->assertSame($input['username'], $user->username);
        $this->expectException(ValidationException::class);
        app(UserAdministration::class)->save($admin, [...$input, 'username' => str_repeat('u', 101)]);
    }
}
