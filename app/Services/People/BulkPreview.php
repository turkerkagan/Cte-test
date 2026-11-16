<?php

namespace App\Services\People;

use Illuminate\Support\Facades\DB;

final class BulkPreview
{
    public function statuses(array $rows, int $target): array
    {
        $labels = DB::table('person_statuses')->whereIn('id', array_unique([$target, ...array_filter(array_column($rows, 'status_id'))]))->pluck('label', 'id');
        $changes = $excluded = [];
        foreach ($rows as $row) {
            $current = isset($row['status_id']) ? (int) $row['status_id'] : null;
            $name = $row['full_name'] ?? '';
            if ($current === $target) {
                $excluded[] = ['id' => $row['id'], 'name' => $name, 'reason' => 'Zaten hedef durumda; değişiklik yapılmayacak.'];
            } else {
                $changes[] = ['id' => $row['id'], 'name' => $name, 'field' => 'Durum', 'before' => $labels[$current] ?? 'Atanmamış', 'after' => $labels[$target]];
            }
        }

        return ['changedCount' => count($changes), 'changes' => $changes, 'excluded' => $excluded,
            'effect' => 'Blok, koğuş ve ranza seçimleri korunur. Doluluk ve ranza rezervasyonu hedef durumun kurallarına göre yeniden hesaplanır. Kapasite ve yetki uygulama sırasında tekrar kontrol edilir.'];
    }
}
