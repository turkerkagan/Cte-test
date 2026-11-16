<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Administration\ManagedDatabase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagementRestoreTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        // The existing SQLite migrations have no reversible FK-name drop; dispose this isolated memory DB instead.
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    private string $workspace;

    public static function identityBackupVersions(): array
    {
        return ['current backup' => [false], 'legacy backup' => [true]];
    }

    #[DataProvider('identityBackupVersions')]
    public function test_restore_uses_backup_identity_without_inheriting_live_user_flags(bool $legacy): void
    {
        $actor = User::factory()->create(['is_system_admin' => true])->refresh();
        $master = User::factory()->create(['username' => $legacy ? 'türker' : 'renamed-identity', 'is_system_admin' => true, 'is_master_admin' => true]);
        $aliases = [];
        foreach (['admin', 'turker'] as $name) {
            $aliases[] = User::factory()->create(['username' => $name, 'is_system_admin' => true])->id;
        }
        $database = app(ManagedDatabase::class);
        $backup = $database->create($actor);
        $path = $database->backup($actor, $backup['id'])['path'];
        $payload = json_decode(Crypt::decryptString(file_get_contents($path)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, (int) collect($payload['tables']['users'])->firstWhere('id', $master->id)['is_master_admin']);
        if ($legacy) {
            $payload['schema']['users'] = array_values(array_filter($payload['schema']['users'], fn ($column) => $column['name'] !== 'is_master_admin'));
            foreach ($payload['tables']['users'] as &$row) {
                unset($row['is_master_admin']);
            }
            unset($row);
            file_put_contents($path, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)));
            DB::table('administration_backups')->where('id', $backup['id'])->update(['sha256' => hash_file('sha256', $path)]);
        }
        // Matching numeric IDs in the live database must never transfer master status into a backup.
        DB::table('users')->whereIn('id', [$actor->id, ...$aliases])->update(['is_master_admin' => true]);
        DB::table('users')->where('id', $master->id)->update(['is_master_admin' => false]);
        $verified = $database->verify($actor, $backup['id']);
        $this->assertTrue($verified['schemaMatches']);
        $database->restore($actor, $backup['id'], ['confirmed' => true, 'confirmation' => 'GERİ YÜKLE', 'checksum' => $verified['checksum']]);
        $this->assertTrue($master->fresh()->is_master_admin);
        foreach ([$actor->id, ...$aliases] as $id) {
            $this->assertFalse(User::findOrFail($id)->is_master_admin);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/cte-restore-tests-'.bin2hex(random_bytes(6));
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

    public function test_confirmed_restore_recovers_database_and_files_preserves_prebackup_and_expires_sessions(): void
    {
        $actor = User::factory()->create(['is_system_admin' => true, 'full_name' => 'SENTETİK İLK'])->refresh();
        $this->actingAs($actor)->withSession(['auth_session_version' => 0]);
        $file = storage_path('app/private/person-photos/test.txt');
        mkdir(dirname($file), 0700, true);
        file_put_contents($file, 'FIRST');
        $document = storage_path('app/private/administration/files/belge.txt');
        mkdir(dirname($document), 0700, true);
        file_put_contents($document, 'BELGE İLK');
        $extra = dirname($document).'/sonradan.txt';
        $originalCounts = [];
        foreach (app(ManagedDatabase::class)->tables() as $table) {
            $originalCounts[$table] = DB::table($table)->count();
        }
        $backup = $this->postJson('/yonetim/veritabani', ['action' => 'backup'])->assertOk()->json();
        $verify = $this->postJson('/yonetim/veritabani', ['action' => 'verify', 'id' => $backup['id']])->assertOk()->assertJsonPath('schemaMatches', true)->json();
        DB::table('users')->where('id', $actor->id)->update(['full_name' => 'SENTETİK SON']);
        User::factory()->create(['username' => 'synthetic.after.backup']);
        file_put_contents($file, 'SECOND');
        file_put_contents($document, 'BELGE SON');
        file_put_contents($extra, 'YEDEKTEN SONRA');
        $this->postJson('/yonetim/veritabani', ['action' => 'restore', 'id' => $backup['id'], 'checksum' => $verify['checksum'], 'confirmation' => 'GERİ YÜKLE', 'confirmed' => true])->assertOk()->assertJsonPath('restored', true)->assertJsonPath('redirect', route('login'));
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('SENTETİK İLK', $actor->fresh()->full_name);
        $this->assertSame(1, $actor->fresh()->session_version);
        $this->assertSame('FIRST', file_get_contents($file));
        $this->assertDatabaseCount('administration_backups', 2);
        $this->assertSame('BELGE İLK', file_get_contents($document));
        $this->assertFileDoesNotExist($extra);
        $this->assertFileDoesNotExist(storage_path('framework/down'));
        foreach ($originalCounts as $table => $count) {
            if (! in_array($table, ['audit_events', 'administration_backups'])) {
                $this->assertSame($count, DB::table($table)->count(), $table);
            }
        }
    }
}
