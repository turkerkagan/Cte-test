<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administration\BackupDelivery;
use App\Services\Administration\BackupPlans;
use App\Services\Administration\ManagedDatabase;
use App\Services\Administration\ManagementSettings;
use App\Services\Operations\OperationalState;
use App\Services\Operations\PortalHealth;
use App\Services\Operations\PrivateFileCleanup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Regression checks for the second audit, isolated from institutional data. */
final class PortalReportFixesTest extends TestCase
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

    private function message(User $sender, User $recipient): array
    {
        $id = DB::table('personal_messages')->insertGetId([
            'sender_id' => $sender->id, 'recipient_id' => $recipient->id,
            'sender_name' => 'SENTETIK', 'recipient_name' => 'SENTETIK',
            'subject' => 'SENTETIK', 'body' => Crypt::encryptString('SENTETIK'),
            'request_id' => (string) Str::uuid(),
        ]);
        $path = 'message-attachments/'.Str::uuid().'.txt';
        Storage::disk('local')->put($path, 'SENTETIK');
        $attachment = DB::table('message_attachments')->insertGetId([
            'message_id' => $id, 'path' => $path, 'name' => 'audit.txt',
            'mime' => 'text/plain', 'size' => 8, 'expires_at' => now()->addDay(),
        ]);

        return [$id, $attachment, $path];
    }

    public function test_failed_download_deletion_is_queued_and_retried_by_cleanup(): void
    {
        config(['document_tools.attachment_retry_minutes' => 0]);
        $recipient = $this->admin();
        [$message, $attachment, $path] = $this->message($this->admin(), $recipient);
        $disk = Storage::disk('local');
        $failedDisk = \Mockery::mock($disk)->makePartial();
        $failedDisk->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::set('local', $failedDisk);
        $this->actingAs($recipient)->withSession(['auth_session_version' => $recipient->session_version])
            ->get('/mesaj-ekleri/'.$attachment)->assertOk();
        Storage::set('local', $disk);
        $this->assertNotNull(DB::table('message_attachments')->where('id', $attachment)->value('deleted_at'));
        DB::table('message_attachments')->where('id', $attachment)->update(['expires_at' => now()->subDay()]);
        $this->assertDatabaseCount('private_file_deletions', 1);
        $this->assertTrue($disk->exists($path));
        $this->artisan('portal:clean-private-documents')->assertExitCode(0);
        $this->assertFalse($disk->exists($path));
        $this->assertDatabaseCount('private_file_deletions', 0);
        $this->get('/mesaj-ekleri/'.$attachment)->assertNotFound();
    }

    public function test_hidden_message_attachment_is_hidden_only_from_that_participant(): void
    {
        $sender = $this->admin();
        $recipient = $this->admin();
        [$message, $attachment] = $this->message($sender, $recipient);
        $this->actingAs($sender)->withSession(['auth_session_version' => $sender->session_version]);
        $this->postJson('/mesajlar', ['action' => 'delete', 'message' => $message])->assertOk();
        $this->get('/mesajlar/'.$message)->assertNotFound();
        $this->get('/mesaj-ekleri/'.$attachment)->assertNotFound();
        $this->actingAs($recipient)->withSession(['auth_session_version' => $recipient->session_version]);
        $this->get('/mesajlar/'.$message)->assertOk();
        $this->get('/mesaj-ekleri/'.$attachment)->assertOk();
    }

    public function test_eighty_messages_use_one_attachment_count_query(): void
    {
        $recipient = $this->admin();
        $sender = $this->admin();
        for ($i = 0; $i < 80; $i++) {
            $this->message($sender, $recipient);
        }
        $this->actingAs($recipient)->withSession(['auth_session_version' => $recipient->session_version]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/mesajlar')->assertOk();
        $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'message_attachments') && str_contains(strtolower($q['query']), 'count('));
        $this->assertCount(1, $queries);
        DB::disableQueryLog();
    }

    public function test_archive_failure_reuses_local_copy_waits_and_recovers(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $actor = $this->admin();
        $service = app(BackupPlans::class);
        $service->save($actor, ['revision' => 0, 'items' => [[
            'id' => 'probe', 'name' => 'SENTETIK', 'target' => 'archive',
            'frequency' => 'daily', 'time' => '00:00', 'weekday' => 1,
            'monthday' => 1, 'keep' => 1, 'enabled' => true,
        ]]]);
        app(ManagedDatabase::class)->root();
        $target = $service->targets()['archive']['path'];
        file_put_contents($target, 'SYNTHETIC INVALID TARGET');
        for ($i = 0; $i < 3; $i++) {
            try {
                $service->run($actor, 'probe', true);
                $this->fail('Unsafe target must be rejected.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
            $this->assertNull($service->run($actor, 'probe', true));
            $this->assertDatabaseCount('administration_backups', 1);
            $plan = $service->read()['values']['items'][0];
            $this->assertSame($i + 1, $plan['attempts']);
            $this->assertSame('Başarısız', $plan['status']);
            $this->assertNull($plan['last_target_success']);
            $this->travelTo(CarbonImmutable::parse($plan['retry_at']));
        }
        $health = app(PortalHealth::class)->snapshot();
        $this->assertTrue($health['backup']['success']); // Local and target status are deliberately separate.
        $this->assertSame('Başarısız', $health['backupPlans'][0]['status']);
        unlink($target);
        $backup = $service->run($actor, 'probe', true);
        $this->assertNotNull($backup);
        $this->assertDatabaseCount('administration_backups', 1);
        $this->assertFileExists($target.'/'.$backup['name']);
        $this->assertSame(hash_file('sha256', app(ManagedDatabase::class)->root().'/'.$backup['name']), hash_file('sha256', $target.'/'.$backup['name']));
        $this->assertSame('Başarılı', $service->read()['values']['items'][0]['status']);
        $this->assertNotNull($service->read()['values']['items'][0]['last_target_success']);
        $this->assertNull($service->run($actor, 'probe', true));
        $this->travelBack();
    }

    public function test_cleanup_never_deletes_a_file_still_referenced_by_a_visible_attachment(): void
    {
        [$message, $attachment, $path] = $this->message($this->admin(), $this->admin());
        app(PrivateFileCleanup::class)->enqueue([$path]);
        $this->assertSame(0, app(PrivateFileCleanup::class)->drain(50));
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertDatabaseCount('private_file_deletions', 1);
    }

    public function test_recipient_hiding_and_unrelated_actor_cannot_download_but_sender_can(): void
    {
        config(['document_tools.delete_attachment_after_recipient_download' => false]);
        $sender = $this->admin();
        $recipient = $this->admin();
        [$message, $attachment] = $this->message($sender, $recipient);
        $this->actingAs($recipient)->withSession(['auth_session_version' => $recipient->session_version]);
        $this->postJson('/mesajlar', ['action' => 'delete', 'message' => $message])->assertOk();
        $this->get('/mesaj-ekleri/'.$attachment)->assertNotFound();
        $this->actingAs($this->admin())->get('/mesaj-ekleri/'.$attachment)->assertNotFound();
        $this->actingAs($sender)->withSession(['auth_session_version' => $sender->session_version]);
        $this->get('/mesaj-ekleri/'.$attachment)->assertOk();
    }

    public function test_cleanup_reconciles_legacy_orphans_and_reports_disk_failure(): void
    {
        [$message, $attachment, $path] = $this->message($this->admin(), $this->admin());
        DB::table('message_attachments')->where('id', $attachment)->update(['deleted_at' => now()]);
        $disk = Storage::disk('local');
        $failedDisk = \Mockery::mock($disk)->makePartial();
        $failedDisk->shouldReceive('delete')->once()->with($path)->andReturn(false);
        Storage::set('local', $failedDisk);
        $this->artisan('portal:clean-private-documents', ['--reconcile' => true])->assertExitCode(1);
        $this->assertDatabaseCount('private_file_deletions', 1);
        $health = app(PortalHealth::class)->snapshot();
        $this->assertSame(1, $health['cleanup']['pending']);
        $this->assertFalse($health['job']['success']);
        Storage::set('local', $disk);
        $this->artisan('portal:clean-private-documents')->assertExitCode(0);
        $this->assertFalse($disk->exists($path));
        $this->assertDatabaseCount('private_file_deletions', 0);
        $this->artisan('portal:clean-private-documents', ['--reconcile' => true])->assertExitCode(0);
    }

    public function test_health_retains_last_success_and_rejects_invalid_scheduler_times(): void
    {
        $this->admin();
        $state = app(OperationalState::class);
        $state->result('backup', true);
        $success = $state->read()['backup']['last_success_at'];
        $this->travel(5)->minutes();
        $state->result('backup', false);
        $this->assertSame($success, $state->read()['backup']['last_success_at']);
        foreach (['not a date', now()->addHour()->toIso8601String()] as $at) {
            $state->update(fn ($data) => array_replace($data, ['scheduler' => ['at' => $at, 'success' => true]]));
            $this->assertFalse(app(PortalHealth::class)->snapshot()['schedulerHealthy']);
        }
        $state->result('scheduler', false);
        $this->assertFalse(app(PortalHealth::class)->snapshot()['schedulerHealthy']);
        $state->result('scheduler', true);
        $this->assertTrue(app(PortalHealth::class)->snapshot()['schedulerHealthy']);
        $this->assertSame([], app(PortalHealth::class)->snapshot()['pendingMigrations']);
        $this->travelBack();
    }

    public function test_plan_recovers_a_snapshot_created_before_a_process_interruption(): void
    {
        $actor = $this->admin();
        $plans = app(BackupPlans::class);
        $plans->save($actor, ['revision' => 0, 'items' => [[
            'id' => 'interrupted', 'name' => 'SENTETIK', 'target' => 'archive',
            'frequency' => 'daily', 'time' => '00:00', 'weekday' => 1,
            'monthday' => 1, 'keep' => 1, 'enabled' => true,
        ]]]);
        $local = app(ManagedDatabase::class)->create($actor, 'plan:interrupted');
        $result = $plans->run($actor, 'interrupted');
        $this->assertSame($local['id'], $result['id']);
        $this->assertDatabaseCount('administration_backups', 1);
        $this->assertFileExists($plans->targets()['archive']['path'].'/'.$local['name']);
    }

    public function test_delivery_replaces_a_corrupt_target_and_publishes_no_partial_file(): void
    {
        $actor = $this->admin();
        $database = app(ManagedDatabase::class);
        $backup = $database->create($actor);
        $source = $database->backup($actor, $backup['id'])['path'];
        $target = storage_path('delivery-target');
        File::makeDirectory($target, 0700, true);
        file_put_contents($target.'/'.$backup['name'], 'INCOMPLETE SYNTHETIC COPY');
        $delivery = app(BackupDelivery::class);
        $delivery->deliver($source, $target, $backup['name']);
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $target.'/'.$backup['name']));
        $this->assertSame([], glob($target.'/.delivery-*'));
        $delivery->deliver($source, $target, $backup['name']);
        $this->assertSame([], glob($target.'/.delivery-*'));
    }

    public function test_attachment_counts_exclude_expired_and_deleted_files(): void
    {
        $recipient = $this->admin();
        $sender = $this->admin();
        [$message] = $this->message($sender, $recipient);
        [$expiredMessage, $expired] = $this->message($sender, $recipient);
        [$deletedMessage, $deleted] = $this->message($sender, $recipient);
        DB::table('message_attachments')->where('id', $expired)->update(['expires_at' => now()->subDay()]);
        DB::table('message_attachments')->where('id', $deleted)->update(['deleted_at' => now()]);
        $this->actingAs($recipient)->withSession(['auth_session_version' => $recipient->session_version])
            ->get('/mesajlar')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.0.id', $deletedMessage)->where('messages.0.attachments', 0)
            ->where('messages.1.id', $expiredMessage)->where('messages.1.attachments', 0)
            ->where('messages.2.id', $message)->where('messages.2.attachments', 1));
    }

    public function test_interrupted_manual_rerun_resumes_after_an_earlier_success_that_day(): void
    {
        $actor = $this->admin();
        $plans = app(BackupPlans::class);
        $plans->save($actor, ['revision' => 0, 'items' => [[
            'id' => 'rerun', 'name' => 'SENTETIK', 'target' => 'archive', 'frequency' => 'daily',
            'time' => '00:00', 'weekday' => 1, 'monthday' => 1, 'keep' => 1, 'enabled' => true,
        ]]]);
        $first = $plans->run($actor, 'rerun');
        $settings = app(ManagementSettings::class);
        $state = $settings->read('backup_plan_runs');
        $state['values']['items']['rerun']['status'] = 'Bekliyor';
        $state['values']['items']['rerun']['backup_id'] = null;
        $settings->save($actor, 'backup_plan_runs', $state['values'], $state['revision']);
        // Simulate interruption after the local snapshot, before its id could be persisted.
        $pending = app(ManagedDatabase::class)->create($actor, 'plan:rerun');
        $resumed = $plans->run($actor, 'rerun', true);
        $this->assertSame($pending['id'], $resumed['id']);
        $this->assertSame(1, DB::table('administration_backups')->whereNull('archived_at')->count());
        $this->assertNotNull(DB::table('administration_backups')->where('id', $first['id'])->value('archived_at'));
        $this->assertFileExists($plans->targets()['archive']['path'].'/'.$pending['name']);
    }
}
