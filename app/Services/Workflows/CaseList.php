<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Services\Modules\ModuleExport;
use App\Services\Queries\NaturalOrder;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CaseList
{
    public function fields(string $group, ?User $actor = null): array
    {
        $fields = ['tc_identity' => ['label' => 'TC Kimlik', 'type' => 'text', 'kind' => 'tc'], 'full_name' => ['label' => 'Adı Soyadı', 'type' => 'text', 'kind' => 'name'],
            'status' => ['label' => 'Durum', 'type' => 'text'], 'block' => ['label' => 'Blok', 'type' => 'text'], 'room' => ['label' => 'Koğuş', 'type' => 'text'], 'bed' => ['label' => 'Ranza', 'type' => 'integer'],
            'admitted_on' => ['label' => 'Kuruma Giriş Tarihi', 'type' => 'date'], 'registered_on' => ['label' => 'Kayıt Tarihi', 'type' => 'date']];
        $fields += $actor ? app(CaseFields::class)->visible($actor, $group) : array_filter(app(CaseFields::class)->group($group), fn ($f) => ! ($f['secret'] ?? false));
        if ($group === 'psikososyal') {
            foreach (['samba' => 'SAMBA', 'ofke' => 'Öfke Kontrolü', 'somgp' => 'SÖMGP'] as $key => $label) {
                $fields[$key] = ['label' => $label, 'type' => 'text'];
            }
        }
        if ($group === 'infaz') {
            $fields += ['age' => ['label' => 'Yaş', 'type' => 'integer'], 'mean' => ['label' => 'Ceza Ortalaması (Yıl)', 'type' => 'decimal'], 'sentence' => ['label' => 'Ceza Süresi', 'type' => 'text']];
        }
        if ($group === 'izin') {
            $fields += ['remaining' => ['label' => 'DS / Tahliye Kalan', 'type' => 'text']];
        }

        $columns = json_decode(file_get_contents(resource_path('js/lib/legacyWorkflowColumns.json')), true, flags: JSON_THROW_ON_ERROR)[$group] ?? [];
        foreach ($columns as $column) {
            if (isset($fields[$column['key']]) && ! ($fields[$column['key']]['admin_configured'] ?? false)) {
                $fields[$column['key']]['label'] = $column['label'];
            }
        }

        return $fields;
    }

    public function query(): Builder
    {
        return DB::table('people as p')->leftJoin('person_case_data as c', 'c.person_id', '=', 'p.id')
            ->leftJoin('person_status_assignments as a', 'a.person_id', '=', 'p.id')->leftJoin('person_statuses as s', 's.id', '=', 'a.status_id')
            ->leftJoin('person_locations as l', 'l.person_id', '=', 'p.id')->leftJoin('housing_blocks as b', 'b.id', '=', 'l.block_id')
            ->leftJoin('housing_rooms as r', 'r.id', '=', 'l.room_id')->leftJoin('housing_beds as d', 'd.id', '=', 'l.bed_id');
    }

    public function columns(): array
    {
        return ['p.id', 'p.tc_identity', 'p.full_name', 'p.admitted_on', 'p.registered_on', 's.label as status', 's.code as status_code', 'b.name as block', 'r.name as room', 'd.number as bed', 'c.*', 'p.id as person_id'];
    }

    private function withoutUnusedHousingJoins(Builder $query, array $state, bool $sorting): Builder
    {
        $used = [...array_column($state['filters'], 'field'), ...array_keys($state['selections'] ?? []), ...($state['selection_none'] ?? [])];
        if ($sorting) {
            $used[] = $state['sort'];
        }
        if (array_intersect($used, ['block', 'room', 'bed']) === []) {
            $query->joins = array_values(array_filter($query->joins, fn ($join) => ! preg_match('/^(?:person_locations|housing_blocks|housing_rooms|housing_beds) as /', $join->table)));
        }

        return $query;
    }

    private function pageColumns(string $group, array $fields): array
    {
        $base = ['p.id', 'p.tc_identity', 'p.full_name', 'p.admitted_on', 'p.registered_on', 's.label as status', 's.code as status_code', 'b.name as block', 'r.name as room', 'd.number as bed', 'p.id as person_id'];
        $keys = array_intersect(array_keys($fields), array_keys(app(CaseFields::class)->all()));
        if ($group === 'infaz') {
            $keys = [...$keys, 'infaz_satir_rengi', 'infaz_dogum_tarihi', 'infaz_ceza_yil', 'infaz_ceza_ay', 'infaz_ceza_gun'];
        }
        if (in_array($group, ['izin', 'psikososyal'], true)) {
            $keys = [...$keys, 'infaz_ds_tarihi', 'infaz_tahliye_tarihi'];
        }
        if ($group !== 'custom_rapor') {
            $keys[] = $group.'_revision';
        }

        return [...$base, ...array_map(fn ($key) => 'c.'.$key, array_unique($keys))];
    }

    private function expressions(string $group, string $today, string $instant): array
    {
        $base = ['tc_identity' => 'p.tc_identity', 'full_name' => 'p.full_name', 'status' => 's.label', 'block' => 'b.name', 'room' => 'r.name', 'bed' => 'd.number', 'id' => 'p.id', 'admitted_on' => 'p.admitted_on', 'registered_on' => 'p.registered_on'];
        foreach (($group === 'custom_rapor' ? app(CaseFields::class)->all() : app(CaseFields::class)->group($group)) as $key => $field) {
            $base[$key] = 'c.'.$key;
        }
        $age = DB::getDriverName() === 'mysql' ? "FLOOR(DATEDIFF('$today', c.infaz_dogum_tarihi)/365.25)" : "FLOOR((julianday('$today') - julianday(c.infaz_dogum_tarihi))/365.25)";

        if ($group === 'psikososyal') {
            $due = 'COALESCE(c.infaz_ds_tarihi,c.infaz_tahliye_tarihi)';
            $days = DB::getDriverName() === 'mysql' ? "TIMESTAMPDIFF(DAY,'$instant',$due)" : "CAST(julianday($due)-julianday('$instant') AS INTEGER)";
            foreach (['samba' => 8, 'ofke' => 10, 'somgp' => 12] as $key => $weeks) {
                $base[$key] = "CASE WHEN $due IS NULL THEN '' WHEN $days >= ".($weeks * 7)." THEN 'UYGUN' ELSE 'UYGUN DEĞİL' END";
            }
        }

        return array_map($this->wrapExpression(...), $base + ['age' => $age, 'mean' => 'ROUND((COALESCE(c.infaz_ceza_yil,0)*365 + COALESCE(c.infaz_ceza_ay,0)*30 + COALESCE(c.infaz_ceza_gun,0))/365.0,2)']);
    }

    private function wrapExpression(string $expression): string
    {
        return preg_replace_callback('/\b[pcsrbd]\.[a-z_]+\b/', fn ($match) => DB::connection()->getQueryGrammar()->wrap($match[0]), $expression);
    }

    public function state(string $group, array $input, ?User $actor = null): array
    {
        $fields = $this->fields($group, $actor);
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1'], 'scope' => ['nullable', 'in:current,all'], 'per_page' => ['nullable', 'integer', 'in:25,50,100'],
            'sort' => ['nullable', 'string', 'in:id,'.implode(',', array_diff(array_keys(array_filter($fields, fn ($f) => $f['sortable'] ?? true)), ['remaining', 'sentence']))], 'direction' => ['nullable', 'in:asc,desc'],
            'columns' => ['nullable', 'array', 'list', 'max:150'], 'columns.*' => ['string', 'distinct', 'in:'.implode(',', array_keys($fields))],
            'selections' => ['nullable', 'array:'.implode(',', array_diff(array_keys(array_filter($fields, fn ($f) => $f['filter_visible'] ?? true)), ['remaining', 'sentence']))],
            'selections.*' => ['array', 'list', 'max:1000'], 'selections.*.*' => ['nullable', 'string', 'max:255'],
            'selection_none' => ['nullable', 'array', 'list', 'max:150'], 'selection_none.*' => ['string', 'distinct', 'in:'.implode(',', array_diff(array_keys(array_filter($fields, fn ($f) => $f['filter_visible'] ?? true)), ['remaining', 'sentence']))],
            'filters' => ['nullable', 'array', 'max:40'], 'filters.*' => ['array:field,op,value'], 'filters.*.field' => ['required', 'in:'.implode(',', array_diff(array_keys(array_filter($fields, fn ($f) => $f['filter_visible'] ?? true)), ['remaining', 'sentence']))],
            'filters.*.op' => ['required', 'in:eq,contains,gte,lte,empty,in,before,after,year,month'], 'filters.*.value' => ['nullable']])->validate();
        $filters = $data['filters'] ?? [];
        foreach ($filters as $i => &$filter) {
            $type = $fields[$filter['field']]['type'];
            $value = $filter['value'] ?? null;
            $op = $filter['op'];
            if ($op === 'empty') {
                $filter['value'] = null;

                continue;
            }
            if ($op === 'in') {
                Validator::make(['value' => $value], ['value' => ['required', 'array', 'list', 'max:100'], 'value.*' => $type === 'option' ? ['integer', 'min:1'] : ['string', 'max:255']])->validate();

                continue;
            }
            if ($type === 'date' && in_array($op, ['before', 'after'], true) && is_string($value) && preg_match('/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D', $value)) {
                $filter['value'] = $value .= '-01';
            }
            $rules = match ($type) {
                'integer', 'option', 'decimal' => ['required', 'numeric'],
                'date' => $op === 'year' ? ['required', 'regex:/^[1-9][0-9]{3}$/D'] : ($op === 'month' ? ['required', 'regex:/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])$/D'] : ['required', 'date_format:Y-m-d']),
                default => ['required', 'string', 'max:255'],
            };
            if (($type !== 'date' && in_array($op, ['before', 'after', 'year', 'month'], true)) || ($type === 'option' && $op !== 'eq')) {
                throw ValidationException::withMessages(['filters.'.$i => 'Bu alan için geçerli bir filtre işlemi seçin.']);
            }
            Validator::make(['value' => $value], ['value' => $rules])->validate();
        }
        unset($filter);

        return ['q' => $data['q'] ?? '', 'page' => (int) ($data['page'] ?? 1), 'per_page' => (int) ($data['per_page'] ?? 25), 'sort' => $data['sort'] ?? ($group === 'infaz' ? 'infaz_uyap_no' : 'id'), 'direction' => $data['direction'] ?? 'asc', 'filters' => $filters, 'columns' => $data['columns'] ?? null, 'selections' => $data['selections'] ?? [], 'selection_none' => $data['selection_none'] ?? []];
    }

    private function selectionExpression(string $expression, array $field): string
    {
        if ($field['type'] === 'ds') {
            $expression = "COALESCE($expression,0)";
        }

        return "COALESCE(CAST($expression AS CHAR),'')";
    }

    private function apply(Builder $query, string $group, array $state, DateTimeImmutable $now): void
    {
        $expressions = $this->expressions($group, $now->format('Y-m-d'), $now->format('Y-m-d H:i:s'));
        $fields = $this->fields($group);
        foreach ($state['selection_none'] ?? [] as $field) {
            $query->whereRaw('1 = 0');
        }
        foreach ($state['selections'] ?? [] as $field => $values) {
            $query->whereIn(DB::raw($this->selectionExpression($expressions[$field], $fields[$field])), array_map(fn ($value) => (string) ($value ?? ''), $values));
        }
        if ($state['q'] !== '') {
            $query->where(fn ($q) => $q->where('p.full_name', 'like', '%'.$state['q'].'%')->orWhere('p.tc_identity', 'like', '%'.$state['q'].'%'));
        }
        foreach ($state['filters'] as $filter) {
            $expr = $expressions[$filter['field']];
            $op = $filter['op'];
            $value = $filter['value'] ?? null;
            if ($op === 'empty') {
                $query->where(fn ($q) => $q->whereNull(DB::raw($expr))->orWhereRaw("$expr = ?", ['']));
            } elseif ($op === 'in') {
                $query->whereIn(DB::raw($expr), $value);
            } elseif ($op === 'year' || $op === 'month') {
                $query->whereRaw("CAST($expr AS CHAR) LIKE ?", [$value.'-%']);
            } else {
                $numeric = in_array($this->fields($group)[$filter['field']]['type'], ['integer', 'decimal', 'option'], true);
                $binding = $numeric ? 'CAST(? AS DECIMAL(20,2))' : '?';
                $query->whereRaw("$expr ".match ($op) {
                    'contains' => 'LIKE', 'gte' => '>=', 'lte' => '<=', 'before' => '<', 'after' => '>', default => '='
                }.' '.$binding, [$op === 'contains' ? '%'.$value.'%' : $value]);
            }
        }
        if ($state['sort'] === 'infaz_uyap_no') {
            $query->orderByRaw($this->wrapExpression('c.infaz_sort_year ').$state['direction'])->orderByRaw($this->wrapExpression('c.infaz_sort_number ASC'));
        } elseif (in_array($state['sort'], ['block', 'room'], true)) {
            NaturalOrder::catalogue($query, $expressions[$state['sort']], DB::table($state['sort'] === 'block' ? 'housing_blocks' : 'housing_rooms')->pluck('name'), $state['direction']);
        } elseif ((($this->fields($group) + app(CaseFields::class)->all())[$state['sort']]['type'] ?? null) === 'option') {
            $choices = app(CaseRecords::class)->options()[$state['sort']] ?? [];
            $choices = NaturalOrder::rows(collect($choices), ['label']);
            // Option IDs reflect import order, not the value shown in the cell.
            $ids = $choices->pluck('id');
            $expression = $expressions[$state['sort']];
            $query->orderByRaw("$expression IS NULL");
            if ($ids->isNotEmpty()) {
                $cases = implode(' ', $ids->map(fn ($id, $index) => 'WHEN ? THEN '.$index)->all());
                $query->orderByRaw("CASE $expression $cases ELSE ".$ids->count().' END '.$state['direction'], $ids->all());
            }
        } else {
            $query->orderByRaw($expressions[$state['sort']].' IS NULL')->orderByRaw($expressions[$state['sort']].' '.$state['direction']);
        }
        $query->orderBy('p.id');
    }

    public function columnValues(User $actor, string $group, array $input): array
    {
        app(WorkflowAccess::class)->authorize($actor, $group);
        $fields = $this->fields($group, $actor);
        $field = Validator::make($input, ['field' => ['required', 'string', 'in:'.implode(',', array_diff(array_keys(array_filter($fields, fn ($f) => $f['filter_visible'] ?? true)), ['remaining', 'sentence']))]])->validate()['field'];
        $state = $this->state($group, $input, $actor);
        unset($state['selections'][$field]);
        $state['selection_none'] = array_values(array_diff($state['selection_none'], [$field]));
        $state['filters'] = array_values(array_filter($state['filters'], fn ($filter) => $filter['field'] !== $field));
        $now = now()->toDateTimeImmutable();
        $query = $this->query();
        if ($group !== 'hijyen_isg' && ! ($group === 'custom_rapor' && ($input['scope'] ?? 'current') === 'all')) {
            $query->where('s.included_in_active_list', true);
        }
        $this->apply($query, $group, $state, $now);
        $expression = $this->selectionExpression($this->expressions($group, $now->format('Y-m-d'), $now->format('Y-m-d H:i:s'))[$field], $fields[$field]);
        $rows = $query->reorder()->selectRaw("$expression AS value, COUNT(DISTINCT p.id) AS count")->groupByRaw($expression)->orderBy('value')->limit(1001)->get();
        if ($rows->count() > 1000) {
            throw ValidationException::withMessages(['field' => 'Bu sütunda 1000’den fazla farklı değer var. Önce ad/TC veya gelişmiş filtre ile kapsamı daraltın.']);
        }
        $options = app(CaseRecords::class)->options()[$field] ?? [];

        return ['values' => $rows->map(function ($row) use ($field, $fields, $options): array {
            $value = (string) $row->value;
            $label = $value;
            if ($fields[$field]['type'] === 'option' && $value !== '') {
                $label = collect($options)->firstWhere('id', (int) $value)['label'] ?? $value;
            } elseif ($fields[$field]['type'] === 'ds') {
                $label = ['0' => 'NORMAL', '1' => 'DS İhlal', '2' => 'TAHLİYE', '3' => "UYAP'A GİRİLDİ"][$value] ?? $value;
            }

            return ['value' => $value, 'label' => $label === '' ? '(Boş)' : $label, 'count' => (int) $row->count];
        })->pipe(fn ($rows) => NaturalOrder::rows($rows, ['label']))->all()];
    }

    public function page(User $actor, string $group, array $input, bool $export = false): array
    {
        app(WorkflowAccess::class)->authorize($actor, $group);

        return DB::transaction(function () use ($actor, $group, $input, $export): array {
            $now = now()->toDateTimeImmutable();
            $state = $this->state($group, $input, $actor);
            if ($group === 'custom_rapor') {
                $state['scope'] = $input['scope'] ?? 'current';
            }
            $fields = $this->fields($group, $actor);
            $query = $this->query();
            if ($group !== 'hijyen_isg' && ! ($group === 'custom_rapor' && ($input['scope'] ?? 'current') === 'all')) {
                $query->where('s.included_in_active_list', true);
            }
            $this->apply($query, $group, $state, $now);
            $count = $this->withoutUnusedHousingJoins(clone $query, $state, false)->count();
            if ($export && $count > ModuleExport::ROW_LIMIT) {
                $this->tooLarge();
            }
            $unitList = in_array($group, WorkflowAccess::UNIT_PAGES, true);
            $page = $unitList ? 1 : min($state['page'], max(1, (int) ceil($count / $state['per_page'])));
            $state['page'] = $page;
            if ($unitList && ! $export) {
                $data = $query->get($this->pageColumns($group, $fields));
            } elseif ($export) {
                $data = $query->limit(ModuleExport::ROW_LIMIT)->get($this->pageColumns($group, $fields));
            } else {
                // Sort IDs first; large case text and housing joins are fetched only for the page.
                $ids = $this->withoutUnusedHousingJoins(clone $query, $state, true)->offset(($page - 1) * $state['per_page'])->limit($state['per_page'])->pluck('p.id');
                $records = $this->query()->whereIn('p.id', $ids)->get($this->pageColumns($group, $fields))->keyBy('person_id');
                $data = $ids->map(fn ($id) => $records[$id]);
            }
            $options = app(CaseRecords::class)->options();
            // The original Infaz name tooltip shows the same authorized record data.
            $photos = $group === 'infaz' && ! $export
                ? DB::table('person_photos')->whereIn('person_id', $data->pluck('person_id'))->whereNotNull('path')->pluck('revision', 'person_id')
                : collect();
            $rows = [];
            foreach ($data as $record) {
                $values = $this->display($record, $fields, $options, $now);
                $row = ['id' => (int) $record->person_id, 'revision' => (int) ($record->{$group.'_revision'} ?? 0), 'color' => $group === 'infaz' ? (collect($options['infaz_satir_rengi'])->firstWhere('id', (int) $record->infaz_satir_rengi)['semantic'] ?? null) : null, 'values' => $values];
                if ($group === 'infaz' && ! $export) {
                    $row['hover'] = ['full_name' => $record->full_name, 'tc_identity' => $record->tc_identity,
                        'placement' => ['block' => $record->block, 'room' => $record->room, 'bed' => $record->bed, 'status' => $values['infaz_durumu'] ?? null],
                        'uyap' => $values['infaz_uyap_no'] ?? '', 'sentence' => $values['sentence'] ?? '',
                        'release' => ['ds' => $record->infaz_ds_tarihi, 'release' => $record->infaz_tahliye_tarihi],
                        'photoUrl' => $photos->has($record->person_id) ? route('sentences.photo', $record->person_id).'?v='.$photos->get($record->person_id) : null];
                }
                $rows[] = $row;
            }
            $result = ['title' => WorkflowAccess::PAGES[$group][0], 'fields' => $fields, 'options' => $options, 'rows' => $rows, 'queryState' => $state, 'pagination' => ['page' => $page, 'pages' => $unitList ? 1 : max(1, (int) ceil($count / $state['per_page'])), 'total' => $count], 'canWrite' => app(WorkflowAccess::class)->canWrite($actor, $group)];
            if ($export) {
                return $this->snapshot($result, $group === 'custom_rapor');
            }

            return $result;
        });
    }

    public function display(object $record, array $fields, array $options, DateTimeImmutable $now): array
    {
        $calc = app(CaseCalculations::class);
        $values = [];
        foreach ($fields as $key => $field) {
            $value = match ($key) {
                'sentence' => ($record->infaz_ceza_yil || $record->infaz_ceza_ay || $record->infaz_ceza_gun) ? ($record->infaz_ceza_yil ?? 0).' Yıl, '.($record->infaz_ceza_ay ?? 0).' Ay, '.($record->infaz_ceza_gun ?? 0).' Gün' : '',
                'age' => $calc->sentenceAge($record->infaz_dogum_tarihi, $now),
                'mean' => $calc->mean($record),
                'samba','ofke','somgp' => $calc->suitability($record->infaz_ds_tarihi, $record->infaz_tahliye_tarihi, $now, ['samba' => 8, 'ofke' => 10, 'somgp' => 12][$key]),
                'remaining' => $calc->remaining($record->infaz_ds_tarihi, $record->infaz_tahliye_tarihi, $now),
                default => $record->$key ?? null,
            };
            if ($field['type'] === 'option') {
                $value = collect($options[str_replace('-', '_', $field['option_list'] ?? $key)] ?? [])->firstWhere('id', (int) $value)['label'] ?? '';
            }
            if ($field['type'] === 'ds') {
                $value = ['0' => 'NORMAL', '1' => 'DS İhlal', '2' => 'TAHLİYE', '3' => "UYAP'A GİRİLDİ"][$value ?? '0'] ?? '';
            }
            $values[$key] = (string) ($value ?? '');
        }

        return $values;
    }

    public function snapshot(array $result, bool $identityOptional = false): array
    {
        if (is_array($result['queryState']['columns'] ?? null)) {
            $keys = array_values(array_unique([...($identityOptional ? [] : ['tc_identity', 'full_name']), ...$result['queryState']['columns']]));
            $result['fields'] = array_replace(array_intersect_key(array_fill_keys($keys, null), $result['fields']), array_intersect_key($result['fields'], array_flip($keys)));
            foreach ($result['rows'] as &$row) {
                $row['values'] = array_map(fn ($key) => $row['values'][$key] ?? null, array_combine(array_keys($result['fields']), array_keys($result['fields'])));
            }
            unset($row);
        }
        $snapshot = ['title' => $result['title'], 'fields' => collect($result['fields'])->map(fn ($f, $k) => ['id' => $k, ...$f])->values()->all(),
            'rows' => array_map(fn ($row) => array_values($row['values']), $result['rows']), 'queryState' => $result['queryState']];
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > ModuleExport::BYTE_LIMIT) {
            $this->tooLarge();
        }

        return $snapshot;
    }

    public function tooLarge(): never
    {
        throw ValidationException::withMessages(['export' => 'Çıktı sınırı 5000 kayıt / 10 MB. Filtreleri daraltın.']);
    }
}
