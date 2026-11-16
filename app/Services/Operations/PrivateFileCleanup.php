<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/** Transactional deletion requests survive disk failures and cascaded message deletion. */
final class PrivateFileCleanup
{
    public function enqueue(array $paths): void
    {
        $rows = [];
        foreach (array_unique($paths) as $path) {
            if (! preg_match('~^message-attachments/[a-z0-9][a-z0-9_.-]*$~iD', $path)) {
                throw new InvalidArgumentException('Özel dosya temizleme yolu geçersiz.');
            }
            $rows[] = ['path' => $path, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('private_file_deletions')->insertOrIgnore($chunk);
        }
    }

    public function afterCommit(): void
    {
        DB::afterCommit(function () {
            try {
                $this->drain(50);
            } catch (Throwable $error) {
                // The committed queue remains available to the scheduled cleanup command.
                report($error);
            }
        });
    }

    public function drain(int $limit): int
    {
        $deleted = 0;
        $disk = Storage::disk('local');
        $ids = DB::table('private_file_deletions')->orderBy('updated_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $deleted += DB::transaction(function () use ($id, $disk): int {
                DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
                $pending = DB::table('private_file_deletions')->where('id', $id)->lockForUpdate()->first();
                if ($pending === null) {
                    return 0;
                }
                if (DB::table('message_attachments')->where('path', $pending->path)->whereNull('deleted_at')->exists()
                    || ($disk->exists($pending->path) && ! $disk->delete($pending->path))) {
                    DB::table('private_file_deletions')->where('id', $id)->update(['updated_at' => now()]);

                    return 0;
                }
                DB::table('private_file_deletions')->where('id', $id)->delete();

                return 1;
            });
        }

        return $deleted;
    }
}
