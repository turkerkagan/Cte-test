<?php

namespace App\Services\People;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Queries\NaturalOrder;
use App\Services\Queries\TurkishTextSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PersonList
{
    private const COLUMNS = ['tc_identity' => 'people.tc_identity', 'full_name' => 'people.full_name', 'registered_on' => 'people.registered_on', 'block' => 'b.name', 'room' => 'r.name', 'bed' => 'd.number', 'status' => 's.label', 'work_place' => 'w.label', 'work_decision' => 'v.label', 'health' => 'c.huk_saglik', 'notes' => 'c.huk_notlar', 'risk' => 'risk.label'];

    private function prepared(User $actor, array $input = []): array
    {
        app(PersonAccess::class)->authorize($actor);
        $data = Validator::make($input, ['scope' => ['sometimes', 'required', 'in:active,all'], 'q' => ['nullable', 'string', 'max:150'], 'tc' => ['nullable', 'string', 'regex:/^[0-9]{11}$/D'],
            'selection_none' => ['sometimes', 'array', 'list', 'max:13'], 'selection_none.*' => ['string', 'in:'.implode(',', array_keys(self::COLUMNS))],
            'selections' => ['sometimes', 'array:'.implode(',', array_keys(self::COLUMNS))], 'selections.*' => ['array', 'list', 'max:5000'], 'selections.*.*' => ['string', 'max:10000'],
            'filters' => ['sometimes', 'array:block,room,bed,status,work_place,work_decision,health,notes,risk,registered_on'], 'filters.*' => ['nullable', 'string', 'max:255'],
            'sort_field' => ['nullable', 'in:tc_identity,full_name,admitted_on,registered_on,block,room,bed,status,work_place,work_decision,health,notes,risk'], 'sort_direction' => ['nullable', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'min:0', 'max:100000']],
            ['regex' => 'TC Kimlik filtresi 11 rakam içermeli.', 'in' => 'Geçerli bir sıralama seçin.', 'q.max' => 'Arama metni en fazla 150 karakter olabilir.', 'page.max' => 'Geçerli bir sayfa numarası seçin.'])->validate();
        $state = ['scope' => $data['scope'] ?? 'active', 'q' => trim($data['q'] ?? ''), 'tc' => $data['tc'] ?? '', 'sort_field' => $data['sort_field'] ?? null, 'sort_direction' => $data['sort_direction'] ?? 'asc', 'filters' => $data['filters'] ?? [], 'selections' => $data['selections'] ?? [], 'selection_none' => $data['selection_none'] ?? []];
        $columns = ['block' => 'b.name', 'room' => 'r.name', 'bed' => 'd.number', 'status' => 's.label', 'work_place' => 'w.label', 'work_decision' => 'v.label', 'health' => 'c.huk_saglik', 'notes' => 'c.huk_notlar', 'risk' => 'risk.label', 'registered_on' => 'people.registered_on'];
        $selections = $state['selections'];
        $filters = array_filter($state['filters'], fn ($v) => $v !== null && $v !== '');
        if (isset($filters['risk']) || isset($selections['risk']) || $state['sort_field'] === 'risk') {
            abort_unless($actor->is_system_admin || app(PageAccess::class)->allows($actor, 'hassas_risk'), 403);
        }
        $query = DB::table('people');
        if ($filters !== [] || $selections !== [] || isset($columns[$state['sort_field'] ?? ''])) {
            $query->leftJoin('person_locations as l', 'l.person_id', '=', 'people.id')->leftJoin('housing_blocks as b', 'b.id', '=', 'l.block_id')->leftJoin('housing_rooms as r', 'r.id', '=', 'l.room_id')->leftJoin('housing_beds as d', 'd.id', '=', 'l.bed_id')->leftJoin('person_status_assignments as a', 'a.person_id', '=', 'people.id')->leftJoin('person_statuses as s', 's.id', '=', 'a.status_id')->leftJoin('person_case_data as c', 'c.person_id', '=', 'people.id')->leftJoin('case_option_items as w', 'w.id', '=', 'c.huk_calis')->leftJoin('case_option_items as v', 'v.id', '=', 'c.huk_karar');
            if (isset($filters['risk']) || isset($selections['risk']) || $state['sort_field'] === 'risk') {
                $query->leftJoin('case_option_items as risk', 'risk.id', '=', 'c.huk_risk');
            }
        }
        foreach ($filters as $key => $value) {
            if (in_array($key, ['bed', 'registered_on'])) {
                $query->where($columns[$key], $value);
            } else {
                app(TurkishTextSearch::class)->contains($query, $columns[$key], $value);
            }
        }
        if ($state['selection_none'] !== []) {
            $query->whereRaw('1=0');
        }
        foreach ($selections as $key => $selected) {
            $column = self::COLUMNS[$key];
            $query->where(function ($q) use ($column, $key, $selected) {
                if ($selected === []) {
                    $q->whereRaw('1=0');

                    return;
                }
                $nonempty = array_filter($selected, fn ($v) => $v !== '');
                $nonempty = array_map(fn ($v) => $key === 'bed' && $v === 'Ranzasız' ? '0' : $v, $nonempty);
                if ($nonempty !== []) {
                    $q->whereIn($column, $nonempty);
                }
                if (in_array('', $selected, true)) {
                    $q->orWhereNull($column)->orWhere($column, '');
                }
            });
        }
        if ($state['scope'] === 'active') {
            $query->whereIn('people.id', DB::table('person_status_assignments as a')->join('person_statuses as s', 's.id', '=', 'a.status_id')->where('s.included_in_active_list', true)->select('a.person_id'));
        }
        if ($state['q'] !== '') {
            app(TurkishTextSearch::class)->contains($query, 'people.full_name', $state['q']);
        }
        if ($state['tc'] !== '') {
            $query->where('tc_identity', $state['tc']);
        }
        if ($state['sort_field'] !== null) {
            $sortColumn = $columns[$state['sort_field']] ?? 'people.'.$state['sort_field'];
            $column = DB::connection()->getQueryGrammar()->wrap($sortColumn);
            if (in_array($state['sort_field'], ['block', 'room', 'risk'], true)) {
                $table = match ($state['sort_field']) {
                    'block' => 'housing_blocks', 'room' => 'housing_rooms', default => 'case_option_items'
                };
                NaturalOrder::catalogue($query, $column, DB::table($table)->pluck($state['sort_field'] === 'risk' ? 'label' : 'name'), $state['sort_direction']);
            } else {
                $query->orderByRaw($column.' IS NULL')->orderBy($sortColumn, $state['sort_direction']);
            }
        }

        return ['query' => $query->orderBy('people.id'), 'state' => $state, 'per_page' => (int) ($data['per_page'] ?? 0), 'page' => (int) ($data['page'] ?? 1)];
    }

    public function page(User $actor, array $input = []): array
    {
        $prepared = $this->prepared($actor, $input);
        $query = $prepared['query'];
        $state = $prepared['state'];
        // Zero means all matching records, including installations above 10,000 people.
        $total = $prepared['per_page'] === 0 ? $query->count() : null;
        $page = $query->paginate($prepared['per_page'] ?: max(1, $total), ['people.id', 'people.tc_identity', 'people.full_name', 'people.admitted_on', 'people.registered_on'], 'page', $prepared['per_page'] === 0 ? 1 : $prepared['page'], $total);

        return ['rows' => collect($page->items())->map(fn ($row) => ['id' => (int) $row->id, 'tc_identity' => $row->tc_identity, 'full_name' => $row->full_name,
            'admitted_on' => $row->admitted_on, 'registered_on' => $row->registered_on])->all(), 'queryState' => $state,
            'pagination' => ['page' => $page->currentPage(), 'per_page' => $prepared['per_page'], 'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }

    public function values(User $actor, mixed $field, array $input): array
    {
        Validator::make(['field' => $field], ['field' => ['required', 'string', 'in:'.implode(',', array_keys(self::COLUMNS))]])->validate();
        if ($field === 'risk') {
            abort_unless($actor->is_system_admin || app(PageAccess::class)->allows($actor, 'hassas_risk'), 403);
        }
        $this->prepared($actor, $input);
        $input['selection_none'] = array_values(array_diff($input['selection_none'] ?? [], [$field]));
        unset($input['filters'][$field],$input['selections'][$field],$input['sort_field'],$input['sort_direction']);
        if ($field === 'full_name') {
            unset($input['q']);
        }
        if ($field === 'tc_identity') {
            unset($input['tc']);
        }
        // Joining is required even when this column has no applied filter.
        $input['sort_field'] = $field;
        $query = $this->prepared($actor, $input)['query']->reorder();
        $column = self::COLUMNS[$field];
        $rows = $query->select($column.' as value')->selectRaw('COUNT(*) AS total')->groupBy($column)->limit(5001)->get();
        $rows = NaturalOrder::rows($rows, ['value']);
        if ($rows->count() > 5000) {
            throw ValidationException::withMessages(['values' => 'Değer listesi en fazla 5000 seçenek olabilir. Diğer sütunlardan filtre ekleyin.']);
        }

        return ['values' => $rows->map(fn ($r) => ['value' => $r->value === null ? '' : ($field === 'bed' && (string) $r->value === '0' ? 'Ranzasız' : (string) $r->value), 'label' => $r->value === null || $r->value === '' ? '(Boş)' : ($field === 'bed' && (string) $r->value === '0' ? 'Ranzasız' : (string) $r->value), 'count' => (int) $r->total])->groupBy('value')->map(fn ($items) => ['value' => $items->first()['value'], 'label' => $items->first()['label'], 'count' => $items->sum('count')])->values()->all()];
    }
}
