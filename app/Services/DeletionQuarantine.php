<?php

namespace App\Services;

use App\Models\InmatePrivateFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DeletionQuarantine
{
    public function move(string $original): array
    {
        $entry = ['original' => $original, 'quarantine' => 'quarantine/permanent-delete/'.Str::uuid().'.bin'];
        $entry['journal'] = $entry['quarantine'].'.json';
        $disk = Storage::disk('local');
        // Persist intent before the filesystem mutation, so process termination is recoverable.
        if (! $disk->put($entry['journal'], json_encode($entry, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Silme işlem günlüğü yazılamadı.');
        }
        if (! $disk->move($original, $entry['quarantine'])) {
            $disk->delete($entry['journal']);
            throw new RuntimeException('Dosya karantinaya taşınamadı.');
        }

        return $entry;
    }

    public function finish(array $entries, bool $rollback = false): void
    {
        $disk = Storage::disk('local');
        foreach (array_reverse($entries) as $entry) {
            if ($disk->exists($entry['quarantine'])) {
                $ok = $rollback
                    ? (! $disk->exists($entry['original']) && $disk->move($entry['quarantine'], $entry['original']))
                    : $disk->delete($entry['quarantine']);
                if (! $ok) {
                    throw new RuntimeException('Karantina işlemi tamamlanamadı; işlem günlüğü korundu.');
                }
            }
            if (isset($entry['journal'])) {
                $disk->delete($entry['journal']);
            }
        }
    }

    public function recover(): int
    {
        $count = 0;
        foreach (Storage::disk('local')->files('quarantine/permanent-delete') as $journal) {
            if (! str_ends_with($journal, '.bin.json')) {
                continue;
            }
            $entry = json_decode(Storage::disk('local')->get($journal), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($entry) || ($entry['journal'] ?? null) !== $journal || ($entry['quarantine'] ?? null) !== substr($journal, 0, -5)
                || ! str_starts_with($entry['original'] ?? '', 'inmate-private-files/') || str_contains($entry['original'], '..') || str_contains($entry['original'], '\\')) {
                throw new RuntimeException('Geçersiz karantina işlem günlüğü.');
            }
            $rollback = InmatePrivateFile::where('storage_disk', 'local')->where('storage_path', $entry['original'])->exists();
            $this->finish([$entry], $rollback);
            $count++;
        }

        return $count;
    }
}
