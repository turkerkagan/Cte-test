<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administration\BackupPlans;
use App\Services\Administration\ManagedDatabase;
use App\Services\Operations\OperationalState;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Diagnostic reproductions: passing assertions confirm existing defects, not fixes. */
final class ReauditEvidenceTest extends TestCase
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

    public function test_failed_download_deletion_is_not_retried_by_cleanup(): void
    {
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
        $this->artisan('portal:clean-private-documents')->assertExitCode(0);
        $this->assertTrue($disk->exists($path));
        $this->assertDatabaseCount('private_file_deletions', 0);
        $this->get('/mesaj-ekleri/'.$attachment)->assertNotFound();
    }

    public function test_hidden_message_attachment_remains_accessible_to_sender(): void
    {
        $sender = $this->admin();
        [$message, $attachment] = $this->message($sender, $this->admin());
        $this->actingAs($sender)->withSession(['auth_session_version' => $sender->session_version]);
        $this->postJson('/mesajlar', ['action' => 'delete', 'message' => $message])->assertOk();
        $this->get('/mesajlar/'.$message)->assertNotFound();
        $this->get('/mesaj-ekleri/'.$attachment)->assertOk();
    }

    public function test_failed_scheduled_archive_retries_exceed_retention_and_leave_green_backup_health(): void
    {
        $actor = $this->admin();
        $service = app(BackupPlans::class);
        $service->save($actor, ['revision' => 0, 'items' => [[
            'id' => 'probe', 'name' => 'SENTETIK', 'target' => 'archive',
            'frequency' => 'daily', 'time' => '00:00', 'weekday' => 1,
            'monthday' => 1, 'keep' => 1, 'enabled' => true,
        ]]]);
        app(ManagedDatabase::class)->root();
        File::makeDirectory(storage_path('archive-destination'), 0700, true);
        symlink(storage_path('archive-destination'), storage_path('app/private/administration/backup-archive'));
        for ($i = 0; $i < 3; $i++) {
            try {
                $service->run($actor, 'probe', true);
                $this->fail('The simulated archive failure must be rejected.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->assertDatabaseCount('administration_backups', 3);
        $this->assertSame(3, DB::table('administration_backups')->whereNull('archived_at')->count());
        $this->assertSame('Başarısız', $service->read()['values']['items'][0]['status']);
        $this->assertTrue(app(OperationalState::class)->read()['backup']['success']);
    }

    public function test_eighty_messages_issue_eighty_attachment_count_queries(): void
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
        $this->assertCount(80, $queries);
        DB::disableQueryLog();
    }
}
