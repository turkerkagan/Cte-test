<?php

namespace App\Services\Institution;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Queries\NaturalOrder;
use App\Services\Workflows\CaseFields;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\WorkflowAccess;

final class PersonStatistics
{
    private function matches(array $p, array $v): bool
    {
        if ($p[0] === 'true') {
            return true;
        }if ($p[0] === 'and') {
            return collect(array_slice($p, 1))->every(fn ($x) => $this->matches($x, $v));
        }if ($p[0] === 'or') {
            return collect(array_slice($p, 1))->contains(fn ($x) => $this->matches($x, $v));
        }$value = $v[$p[1]] ?? null;
        if ($p[3] && $value !== null) {
            $value = mb_strtoupper($value, 'UTF-8');
        }

        return match ($p[0]) {
            'IS NULL' => $value === null,'IS NOT NULL' => $value !== null,'=' => $value !== null && $value === $p[2],'!=' => $value !== null && $value !== $p[2],default => false
        };
    }

    public function read(User $a, string $kind): array
    {
        app(WorkflowAccess::class)->authorize($a, $kind);
        $labels = app(CaseFields::class)->all();
        $options = app(CaseRecords::class)->options();
        $semantics = [];
        foreach ($options as $field => $items) {
            $semantics[$field] = array_column($items, 'semantic', 'id');
        }
        $risk = User::find($a->id)?->is_system_admin || app(PageAccess::class)->allows($a, 'hassas_risk');
        $categories = $kind === 'mesemstat' ? ['huk_karar', 'huk_calis', 'mesem_dali', 'mesem_mevcut_belge', 'mesem_belge_turu', 'mesem_sinif'] : ['huk_calis', 'egt_ogrenim', 'egt_okul', 'kurs_adi', 'huk_saglik', 'huk_kronik', 'block', 'room', 'status', 'huk_karar', 'huk_meslek', ...($risk ? ['huk_risk'] : [])];
        $groups = array_fill_keys($categories, []);
        $definitions = json_decode(file_get_contents(resource_path('defaults/mesem-statistics.json')), true, 64, JSON_THROW_ON_ERROR);
        $calculatedDefinitions = $kind === 'mesemstat' ? $definitions : array_intersect_key($definitions, array_flip(['toplam', 'toplam_gorusuldu']));
        $caseKeys = array_values(array_unique([...array_intersect($categories, array_keys($labels)), 'huk_yas', 'huk_karar', 'huk_calis',
            'mesem_gorusme', 'mesem_durumu', 'mesem_dilekce', 'mesem_ogrenim_belgesi', 'hijyen_is', 'izin_cikis_tarihi', 'izin_donus_tarihi']));
        $semantics = array_intersect_key($semantics, array_flip($caseKeys));
        $counts = array_fill_keys(array_keys($definitions), 0);
        $summary = ['total' => 0, 'today' => 0, 'month' => 0, 'year' => 0, 'specialLeave' => 0, 'departures' => 0, 'returns' => 0];
        $trend = [];
        $ages = [];
        $work = [];
        $waiting = ['isyurtlari' => [], 'genel' => []];
        $now = now();
        $trendStart = $now->copy()->subMonths(12)->format('Y-m-d');
        $query = app(CaseList::class)->query()->where('s.included_in_active_list', true)->orderBy('p.id');
        foreach ($query->select(['p.id as person_id', 'p.tc_identity', 'p.full_name', 'p.registered_on', 's.label as status', 's.code as status_code',
            'b.name as block', 'r.name as room', ...array_map(fn ($key) => 'c.'.$key, $caseKeys)])->cursor() as $row) {
            $v = (array) $row;
            foreach ($semantics as $field => $map) {
                $v[$field] = isset($v[$field]) ? ($map[$v[$field]] ?? null) : null;
            }$summary['total']++;
            foreach ($categories as $field) {
                $value = $v[$field] ?? null;
                if ($value !== null && $value !== '') {
                    $groups[$field][(string) $value] = ($groups[$field][(string) $value] ?? 0) + 1;
                }
            }
            foreach ($calculatedDefinitions as $key => $d) {
                if ($this->matches($d['predicate'], $v)) {
                    $counts[$key]++;
                }
            }
            $registered = $row->registered_on;
            if ($registered !== null) {
                if ($registered === $now->format('Y-m-d')) {
                    $summary['today']++;
                }if (substr($registered, 0, 7) === $now->format('Y-m')) {
                    $summary['month']++;
                }if (substr($registered, 0, 4) === $now->format('Y')) {
                    $summary['year']++;
                }if ($registered >= $trendStart) {
                    $month = substr($registered, 0, 7);
                    $trend[$month] = ($trend[$month] ?? 0) + 1;
                }
            }
            if ($row->status_code === 'special_leave') {
                $summary['specialLeave']++;
            }if ($row->izin_cikis_tarihi === $now->format('Y-m-d')) {
                $summary['departures']++;
            }if ($row->izin_donus_tarihi === $now->format('Y-m-d')) {
                $summary['returns']++;
            }
            if (($row->huk_yas ?? 0) > 0) {
                $age = match (true) {
                    $row->huk_yas < 25 => '18-24',$row->huk_yas < 35 => '25-34',$row->huk_yas < 45 => '35-44',$row->huk_yas < 55 => '45-54',default => '55+'
                };
                $ages[$age] = ($ages[$age] ?? 0) + 1;
            }
            $place = $v['huk_calis'] ?? '';
            $decision = $v['huk_karar'] ?? '';
            if ($place !== '') {
                $work[$place] ??= ['place' => $place, 'total' => 0, 'isyurtlari' => 0, 'genel' => 0, 'muhtelif' => 0, 'kararsiz' => 0, 'interviewed' => 0, 'applications' => 0, 'petitions' => 0, 'decisions' => []];
                $work[$place]['total']++;
                $work[$place]['decisions'][$decision] = ($work[$place]['decisions'][$decision] ?? 0) + 1;
                $decisionKey = match ($decision) {
                    'İŞYURTLARI' => 'isyurtlari','GENEL BÜTÇE' => 'genel','MUHTELİF' => 'muhtelif','KARARSIZ','' => 'kararsiz',default => null
                };
                if ($decisionKey) {
                    $work[$place][$decisionKey]++;
                }if (($v['mesem_gorusme'] ?? null) === 'GÖRÜŞÜLDÜ') {
                    $work[$place]['interviewed']++;
                }if (($v['mesem_durumu'] ?? null) === 'BAŞVURU SÜRECİNDE') {
                    $work[$place]['applications']++;
                }if (($v['mesem_dilekce'] ?? null) === 'DİLEKÇE ALINDI') {
                    $work[$place]['petitions']++;
                }
            }
            if (($v['mesem_gorusme'] ?? '') === '') {
                $key = match ($decision) {
                    'İŞYURTLARI' => 'isyurtlari','GENEL BÜTÇE' => 'genel',default => null
                };
                if ($key) {
                    $waiting[$key][] = ['id' => (int) $row->person_id, 'tc_identity' => $row->tc_identity, 'name' => $row->full_name, 'place' => $place];
                }
            }
        }
        $sections = [];
        foreach ($groups as $key => $items) {
            if (in_array($key, ['block', 'room', 'huk_risk'], true) || (count($items) > 0 && count(array_filter(array_keys($items), 'is_numeric')) === count($items))) {
                uksort($items, NaturalOrder::compare(...));
            } else {
                arsort($items);
            }
            $limit = match ($key) {
                'egt_okul' => 10,'huk_meslek','mesem_dali' => 15,default => null
            };
            if ($limit) {
                $items = array_slice($items, 0, $limit, true);
            }$sections[] = ['key' => $key, 'label' => $labels[$key]['label'] ?? (['block' => 'Blok', 'room' => 'Koğuş', 'status' => 'Durum'][$key] ?? $key), 'rows' => array_map(fn ($label, $count) => ['label' => (string) $label, 'count' => $count], array_keys($items), array_values($items))];
        }
        ksort($trend);
        ksort($ages);
        uasort($work, fn ($a, $b) => $b['total'] <=> $a['total']);
        foreach ($waiting as $key => $rows) {
            usort($rows, fn ($a, $b) => $key === 'isyurtlari' ? [$a['place'], $a['name']] <=> [$b['place'], $b['name']] : $a['name'] <=> $b['name']);
            $waiting[$key] = array_slice($rows, 0, 100);
        }

        return ['title' => WorkflowAccess::PAGES[$kind][0], 'kind' => $kind, 'summary' => $summary, 'sections' => $sections, 'metrics' => $kind === 'mesemstat' ? array_map(fn ($key) => ['key' => $key, 'label' => $definitions[$key]['label'], 'count' => $counts[$key]], array_keys($counts)) : [], 'interviewRate' => $counts['toplam'] > 0 ? round($counts['toplam_gorusuldu'] / $counts['toplam'] * 100, 1) : 0, 'trend' => $trend, 'ages' => $ages, 'work' => array_values($work), 'waiting' => $kind === 'mesemstat' ? $waiting : []];
    }
}
