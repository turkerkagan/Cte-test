<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Services\Modules\ModuleExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CaseReports
{
    private function tableFields(string $workflow, array $fields): array
    {
        $columns = json_decode(file_get_contents(resource_path('js/lib/legacyWorkflowColumns.json')), true, flags: JSON_THROW_ON_ERROR)[$workflow];
        $table = [];
        foreach ($columns as $column) {
            if (isset($fields[$column['key']])) {
                $table[$column['key']] = [...$fields[$column['key']], 'label' => $column['label']];
            }
        }

        return $table;
    }

    public function transfer(User $actor, array $input, bool $export = true): array
    {
        app(WorkflowAccess::class)->authorize($actor, 'infaz_sevk_raporu');
        Validator::make($input, ['type' => ['nullable', 'in:il_disi,il_ici'], 'days' => ['nullable', 'integer'], 'age' => ['nullable', 'integer'],
            'excluded' => ['nullable', 'array', 'list', 'max:100'], 'excluded.*' => ['string', 'max:150'], 'q' => ['nullable', 'string', 'max:255']])->validate();
        $state = ['type' => $input['type'] ?? 'il_disi', 'days' => max(1, min(365, (int) ($input['days'] ?? (($input['type'] ?? 'il_disi') === 'il_ici' ? 40 : 100)))),
            'age' => max(18, min(100, (int) ($input['age'] ?? 60))), 'excluded' => array_key_exists('excluded', $input) ? ($input['excluded'] ?? []) : CaseCalculations::DEFAULT_EXCLUSIONS, 'q' => $input['q'] ?? ''];

        return DB::transaction(function () use ($state, $export): array {
            $now = now()->toDateTimeImmutable();
            $list = app(CaseList::class);
            $calc = app(CaseCalculations::class);
            $options = app(CaseRecords::class)->options();
            $reasons = collect($options['infaz_gelis_nedeni'])->keyBy('id');
            $fields = array_intersect_key($list->fields('infaz'), array_flip(['tc_identity', 'full_name', 'infaz_uyap_no', 'infaz_dogum_tarihi', 'infaz_gelis_nedeni', 'infaz_ds_tarihi', 'infaz_tahliye_tarihi', 'infaz_not']));
            $fields += ['ds_days' => ['label' => 'DS Kalan Gün', 'type' => 'integer'], 'release_days' => ['label' => 'Kalan Gün', 'type' => 'integer']];
            $fields = $this->tableFields('infaz_sevk_raporu', $fields);
            $query = $list->query()->where('s.included_in_active_list', true)->orderBy('p.full_name')->orderBy('p.id');
            if ($state['q'] !== '') {
                $query->where(fn ($q) => $q->where('p.full_name', 'like', '%'.$state['q'].'%')->orWhere('p.tc_identity', 'like', '%'.$state['q'].'%'));
            }
            $rows = [];
            $oversized = false;
            // Chunk by offset in one read transaction; no query per person.
            for ($offset = 0; ; $offset += 250) {
                $batch = (clone $query)->offset($offset)->limit(250)->get(['p.id as person_id', 'p.tc_identity', 'p.full_name',
                    'c.infaz_uyap_no', 'c.infaz_dogum_tarihi', 'c.infaz_gelis_nedeni', 'c.infaz_ds_tarihi', 'c.infaz_tahliye_tarihi']);
                if ($batch->isEmpty()) {
                    break;
                }
                foreach ($batch as $record) {
                    $reason = $reasons[(int) $record->infaz_gelis_nedeni]['semantic'] ?? $reasons[(int) $record->infaz_gelis_nedeni]['label'] ?? null;
                    if (! $calc->transfer($record, $state, $now, $reason)) {
                        continue;
                    }
                    $rows[] = $record;
                    if (count($rows) > ModuleExport::ROW_LIMIT) {
                        if ($export) {
                            $list->tooLarge();
                        }
                        $oversized = true;
                        break 2;
                    }
                }
            }
            $displayRows = $oversized ? [] : array_map(function ($record) use ($calc, $list, $fields, $options, $now): array {
                $record->ds_days = $calc->days($record->infaz_ds_tarihi, $now);
                $record->release_days = $calc->days($record->infaz_tahliye_tarihi, $now);

                return ['id' => (int) $record->person_id, 'values' => $list->display($record, $fields, $options, $now)];
            }, $rows);
            $result = ['title' => 'Sevk raporu', 'fields' => $fields, 'rows' => $displayRows, 'queryState' => $state, 'generatedAt' => $now->format('Y-m-d H:i:s'), 'reasons' => $options['infaz_gelis_nedeni'], 'canWrite' => false];
            if ($oversized) {
                $result['reportError'] = 'Sonuç 5.000 kayıt sınırını aşıyor. Arama veya sevk kriterlerini daraltıp raporu yeniden oluşturun.';

                return $result;
            }
            $list->snapshot($result);

            return $result;
        });
    }

    public function leave(User $actor, array $input, bool $export = true): array
    {
        app(WorkflowAccess::class)->authorize($actor, 'izin_rapor');
        Validator::make($input, ['date' => ['nullable', 'date_format:Y-m-d'], 'section' => ['nullable', 'in:departure,return,control'], 'q' => ['nullable', 'string', 'max:255']])->validate();

        return DB::transaction(function () use ($input, $export): array {
            $now = now()->toDateTimeImmutable();
            // The original rule uses current database time, even for a selected historical date.
            $clock = DB::getDriverName() === 'mysql' ? (string) DB::selectOne('SELECT CURRENT_TIME AS clock')->clock : $now->format('H:i:s');
            $state = ['date' => $input['date'] ?? $now->format('Y-m-d'), 'section' => $input['section'] ?? 'control', 'q' => $input['q'] ?? ''];
            $list = app(CaseList::class);
            $queries = [];
            $base = $list->query();
            if ($state['q'] !== '') {
                $base->where(fn ($q) => $q->where('p.full_name', 'like', '%'.$state['q'].'%')->orWhere('p.tc_identity', 'like', '%'.$state['q'].'%'));
            }
            $queries['departure'] = (clone $base)->where('s.included_in_active_list', true)->where('c.izin_cikis_tarihi', $state['date']);
            $queries['return'] = (clone $base)->where('s.included_in_active_list', true)->where('c.izin_donus_tarihi', $state['date']);
            $queries['control'] = (clone $base)->where('s.code', 'special_leave')->whereNotNull('c.izin_donus_tarihi')
                ->where(fn ($q) => $q->where('c.izin_donus_tarihi', '<', $state['date'])->orWhere(fn ($q) => $q->where('c.izin_donus_tarihi', $state['date'])->whereRaw('? >= ?', [$clock, '14:00:00'])));
            $counts = [];
            foreach ($queries as $key => $query) {
                $counts[$key] = (clone $query)->count();
            }
            $oversized = $counts[$state['section']] > ModuleExport::ROW_LIMIT;
            if ($oversized && $export) {
                $list->tooLarge();
            }
            $fields = $list->fields('izin') + array_intersect_key($list->fields('infaz'), array_flip(['infaz_uyap_no'])) + array_intersect_key($list->fields('calisma'), array_flip(['huk_calis']));
            $fields = $this->tableFields('izin_rapor', $fields);
            $query = $queries[$state['section']];
            if ($state['section'] === 'control') {
                $query->orderBy('c.izin_donus_tarihi');
            }
            $options = app(CaseRecords::class)->options();
            $rows = $oversized ? [] : $query->orderBy('p.full_name')->orderBy('p.id')->get($list->columns())->map(fn ($r) => ['id' => (int) $r->person_id, 'values' => $list->display($r, $fields, $options, $now)])->all();
            $result = ['title' => 'İzin raporu · '.['departure' => 'Çıkış', 'return' => 'Dönüş', 'control' => 'Kontrol'][$state['section']], 'fields' => $fields, 'rows' => $rows, 'queryState' => $state, 'counts' => $counts, 'generatedAt' => $now->format('Y-m-d').' '.$clock, 'canWrite' => false];
            if ($oversized) {
                $result['reportError'] = 'Sonuç 5.000 kayıt sınırını aşıyor. Aramayı daraltıp raporu yeniden oluşturun.';

                return $result;
            }
            $list->snapshot($result);

            return $result;
        });
    }
}
