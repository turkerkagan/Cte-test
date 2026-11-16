<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalState;
use App\Services\Operations\PrivateFileCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CleanPrivateDocuments extends Command
{
    protected $signature = 'portal:clean-private-documents {--limit=50 : Her çalıştırmada silinecek en fazla ek} {--reconcile : Önceden silinmiş işaretlenen eklerin kalan dosyalarını kuyruğa al}';

    protected $description = 'Süresi dolmuş mesaj eklerini ve bir saatlik Excel çıktılarını temizler';

    public function handle(): int
    {
        try {
            $result = $this->clean();
            app(OperationalState::class)->result('job', $result === self::SUCCESS);

            return $result;
        } catch (\Throwable) {
            app(OperationalState::class)->result('job', false);
            $this->error('Dosya temizleme görevi başarısız; tekrar çalıştırmadan önce bağlantı ve dosya izinlerini kontrol edin.');

            return self::FAILURE;
        }
    }

    private function clean(): int
    {
        if (app()->isDownForMaintenance()) {
            $this->error('Bakım sırasında dosya temizleme görevi çalıştırılmaz.');

            return self::FAILURE;
        }
        $limit = max(1, min(500, (int) $this->option('limit')));
        $disk = Storage::disk('local');
        $cleanup = app(PrivateFileCleanup::class);
        if ($this->option('reconcile')) {
            DB::table('message_attachments')->whereNotNull('deleted_at')->orderBy('id')->chunkById(200, function ($rows) use ($disk, $cleanup) {
                $paths = $rows->pluck('path')->filter(fn ($path) => $disk->exists($path))->all();
                $cleanup->enqueue($paths);
            });
        }
        $count = 0;
        foreach (DB::table('message_attachments')->whereNull('deleted_at')->where('expires_at', '<', now())->limit($limit)->pluck('id') as $id) {
            DB::transaction(function () use ($id, $cleanup, &$count) {
                DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
                $f = DB::table('message_attachments')->where('id', $id)->whereNull('deleted_at')->where('expires_at', '<', now())->lockForUpdate()->first();
                if (! $f) {
                    return;
                }
                $cleanup->enqueue([$f->path]);
                DB::table('message_attachments')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
                $count++;
            });
        }
        $queued = 0;
        while (($batch = $cleanup->drain($limit)) > 0) {
            $queued += $batch;
        }
        $outputs = 0;
        foreach ($disk->directories('excel-merge') as $dir) {
            if (! preg_match('~^excel-merge/[0-9a-f-]+$~D', $dir)) {
                continue;
            }$file = $dir.'/output.xlsx';
            if ($disk->exists($file) && $disk->lastModified($file) < time() - 3600 && $disk->deleteDirectory($dir)) {
                $outputs++;
            }
        }
        $this->info($count.' ek işaretlendi, '.$queued.' dosya silme işi ve '.$outputs.' Excel çıktısı temizlendi.');
        if (DB::table('private_file_deletions')->exists()) {
            $this->warn('Bekleyen dosya silme işleri var; sonraki çalıştırmada yeniden denenecek.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
