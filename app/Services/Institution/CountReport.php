<?php

namespace App\Services\Institution;

use App\Services\Queries\NaturalOrder;
use App\Services\Workflows\CaseList;
use Illuminate\Support\Facades\DB;

final class CountReport
{
    public function read(?array $uploaded = null): array
    {
        require_once __DIR__.'/Parsers/CountParser.php';
        $wrap = fn ($c) => DB::connection()->getQueryGrammar()->wrap($c);
        $counts = app(CaseList::class)->query()->where('s.included_in_active_list', true)->groupBy('l.room_id')->select('l.room_id')->selectRaw('COUNT(*) as total, SUM(CASE WHEN '.$wrap('s.code')." = 'special_leave' THEN 1 ELSE 0 END) as away")->get()->keyBy('room_id');
        $rooms = DB::table('housing_rooms as r')->join('housing_blocks as b', 'b.id', '=', 'r.block_id')->get(['r.id', 'b.code as block', 'r.code as room', 'r.name', 'r.is_active', 'b.is_active as block_active'])->pipe(fn ($rows) => NaturalOrder::rows($rows, ['block', 'name', 'id']));
        $system = [];
        foreach ($rooms as $r) {
            $c = $counts->get($r->id);
            if (! $r->is_active || ! $r->block_active) {
                if ($c === null) {
                    continue;
                }
            }$system[] = ['key' => Parsers\sayim_norm_key($r->block, $r->room), 'room' => $r->block.' · '.$r->name, 'registered' => (int) ($c->total ?? 0), 'away' => (int) ($c->away ?? 0), 'ready' => (int) ($c->total ?? 0) - (int) ($c->away ?? 0)];
        }
        if ($counts->has('')) {
            $c = $counts->get('');
            $system[] = ['key' => 'YERLEŞİMİ ATANMAMIŞ', 'room' => 'Yerleşimi atanmamış', 'registered' => (int) $c->total, 'away' => (int) $c->away, 'ready' => (int) $c->total - (int) $c->away];
        }
        $file = [];
        foreach ($uploaded['rows'] ?? [] as $i => $r) {
            $file[] = ['key' => Parsers\sayim_norm_key($r['blok'] ?? '', $r['kogus'] ?? ''), 'sourceRow' => $i + 1, 'room' => ($r['blok'] ?? '').' · '.($r['kogus'] ?? ''), 'registered' => (int) $r['kayitli'], 'away' => (int) $r['gayri_mevcut'], 'ready' => (int) $r['hazir']];
        }
        $left = collect($system)->groupBy('key');
        $right = collect($file)->groupBy('key');
        $keys = array_unique([...$left->keys()->all(), ...$right->keys()->all()]);
        usort($keys, fn ($a, $b) => Parsers\sayim_sort_priority($a) <=> Parsers\sayim_sort_priority($b));
        $rows = [];
        foreach ($keys as $key) {
            $s = $left->get($key, collect());
            $f = $right->get($key, collect());
            $ambiguous = $s->count() > 1 || $f->count() > 1;
            $status = $ambiguous ? 'Çakışan anahtar' : ($s->isEmpty() ? 'Yalnız dosyada' : ($f->isEmpty() ? ($uploaded === null ? 'Dosya yüklenmedi' : 'Yalnız sistemde') : 'Tam uyum'));
            $difference = [];
            if (! $ambiguous && $s->isNotEmpty() && $f->isNotEmpty()) {
                foreach (['registered', 'away', 'ready'] as $column) {
                    $difference[$column] = $s->first()[$column] - $f->first()[$column];
                }if (array_filter($difference)) {
                    $status = 'Fark var';
                }
            }$rows[] = ['key' => $key, 'status' => $status, 'system' => $s->all(), 'file' => $f->all(), 'difference' => $difference];
        }

        return ['rows' => $rows, 'systemTotals' => ['registered' => array_sum(array_column($system, 'registered')), 'away' => array_sum(array_column($system, 'away')), 'ready' => array_sum(array_column($system, 'ready'))], 'fileTotals' => $uploaded === null ? null : ['registered' => array_sum(array_column($file, 'registered')), 'away' => array_sum(array_column($file, 'away')), 'ready' => array_sum(array_column($file, 'ready'))]];
    }
}
