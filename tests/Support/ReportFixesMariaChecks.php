<?php

namespace Tests\Support;

use App\Http\Controllers\PersonalMessagesController;
use App\Models\User;
use App\Services\Administration\BackupPlans;
use App\Services\Operations\PrivateFileCleanup;
use App\Services\Workflows\PersonalMessageAccess;
use App\Services\Workspace\BackupRehearsal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReportFixesMariaChecks
{
    public static function run(callable $check): void
    {
        if (! app()->environment('testing') || ! preg_match('/^cte_test_g03_[a-f0-9]{12}$/D', DB::connection()->getDatabaseName())) {
            throw new \LogicException('Report checks require the disposable integration database.');
        }
        $storage = app()->storagePath();
        $diskRoot = config('filesystems.disks.local.root');
        $directory = sys_get_temp_dir().'/cte-report-maria-'.bin2hex(random_bytes(8));
        app()->useStoragePath($directory);
        config(['filesystems.disks.local.root' => $directory.'/app/private']);
        Storage::forgetDisk('local');
        try {
            File::makeDirectory($directory.'/app/private', 0700, true);
            // Existing records belong to this disposable database; backups need only synthetic placeholder bytes.
            foreach (['person_photos', 'meal_files', 'message_attachments'] as $table) {
                foreach (DB::table($table)->pluck('path') as $path) {
                    if ($path) {
                        Storage::disk('local')->put($path, 'SYNTHETIC FIXTURE');
                    }
                }
            }
            $sender = User::factory()->create(['is_system_admin' => true])->refresh();
            $recipient = User::factory()->create(['is_system_admin' => true])->refresh();
            $message = DB::table('personal_messages')->insertGetId([
                'sender_id' => $sender->id, 'recipient_id' => $recipient->id,
                'sender_name' => 'SENTETIK', 'recipient_name' => 'SENTETIK',
                'subject' => 'SENTETIK', 'body' => Crypt::encryptString('SENTETIK'),
                'request_id' => (string) Str::uuid(),
            ]);
            $path = 'message-attachments/'.Str::uuid().'.txt';
            Storage::disk('local')->put($path, 'SYNTHETIC');
            $attachment = DB::table('message_attachments')->insertGetId([
                'message_id' => $message, 'path' => $path, 'name' => 'synthetic.txt',
                'mime' => 'text/plain', 'size' => 9, 'expires_at' => now()->addDay(),
            ]);
            DB::table('personal_messages')->where('id', $message)->update(['sender_deleted' => true]);
            $access = app(PersonalMessageAccess::class);
            $check(! $access->visibleTo($sender)->where('id', $message)->exists() && $access->visibleTo($recipient)->where('id', $message)->exists(), 'report_message_visibility_preserves_other_participant');
            $cleanup = app(PrivateFileCleanup::class);
            $cleanup->enqueue([$path]);
            $check($cleanup->drain(50) === 0 && Storage::disk('local')->exists($path), 'report_cleanup_protects_live_reference');
            DB::table('message_attachments')->where('id', $attachment)->update(['deleted_at' => now()]);
            $check($cleanup->drain(50) === 1 && ! Storage::disk('local')->exists($path), 'report_cleanup_deletes_soft_deleted_reference');

            DB::enableQueryLog();
            DB::flushQueryLog();
            $request = Request::create('/mesajlar', 'GET');
            $request->setUserResolver(fn () => $recipient);
            app(PersonalMessagesController::class)->index($request);
            $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'message_attachments') && str_contains(strtolower($query['query']), 'count('));
            DB::disableQueryLog();
            $check($queries->count() === 1, 'report_attachment_group_count_uses_one_mariadb_query');

            $plans = app(BackupPlans::class);
            $state = $plans->read();
            $plans->save($sender, ['revision' => $state['revision'], 'items' => [[
                'id' => 'report_maria', 'name' => 'SENTETIK', 'target' => 'archive',
                'frequency' => 'daily', 'time' => '00:00', 'weekday' => 1, 'monthday' => 1, 'keep' => 1, 'enabled' => true,
            ]]]);
            $target = $plans->targets()['archive']['path'];
            file_put_contents($target, 'SYNTHETIC INVALID TARGET');
            $before = DB::table('administration_backups')->count();
            for ($i = 0; $i < 3; $i++) {
                try {
                    $plans->run($sender, 'report_maria');
                    throw new \LogicException('Invalid archive target accepted.');
                } catch (HttpException $error) {
                    $check($error->getStatusCode() === 422, 'report_invalid_target_rejected_'.$i);
                }
            }
            $check(DB::table('administration_backups')->count() === $before + 1, 'report_failed_delivery_reuses_one_local_snapshot');
            unlink($target);
            $backup = $plans->run($sender, 'report_maria');
            $check(is_file($target.'/'.$backup['name']) && $plans->read()['values']['items'][0]['status'] === 'Başarılı', 'report_delivery_recovers_on_mariadb');
            $restoreSchemas = DB::select("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'cte_restore_%' ORDER BY SCHEMA_NAME");
            $peopleBefore = DB::table('people')->orderBy('id')->get()->toJson();
            $rehearsal = app(BackupRehearsal::class)->run($sender, $backup['id']);
            $check($rehearsal['status'] === 'passed' && $rehearsal['tables'] > 30 && $rehearsal['rows'] > 0, 'workspace_rehearsal_restores_and_hash_compares_real_mariadb_rows');
            $check($rehearsal['temporaryResourcesCleaned'] && glob($directory.'/app/private/administration/rehearsals/*') === [], 'workspace_rehearsal_removes_private_files');
            $check(DB::table('people')->orderBy('id')->get()->toJson() === $peopleBefore, 'workspace_rehearsal_does_not_modify_source_people');
            $check(DB::select("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'cte_restore_%' ORDER BY SCHEMA_NAME") == $restoreSchemas, 'workspace_rehearsal_removes_temporary_database');

        } finally {
            DB::disableQueryLog();
            Storage::forgetDisk('local');
            app()->useStoragePath($storage);
            config(['filesystems.disks.local.root' => $diskRoot]);
            File::deleteDirectory($directory);
        }
    }
}
