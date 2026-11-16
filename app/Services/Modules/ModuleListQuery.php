<?php

namespace App\Services\Modules;

use App\Services\Queries\TurkishTextSearch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Only server-selected visible fields may affect query results. */
final class ModuleListQuery
{
    public function apply(Builder $query, int $moduleId, array $fields, array $input, bool $withPeople = false): array
    {
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:150'], 'filter_field' => ['nullable', 'integer', 'min:1'],
            'filter_op' => ['nullable', 'in:eq,gte,lte,contains,empty'], 'filter_value' => ['nullable', 'string', 'max:255'],
            'filters' => ['nullable', 'array', 'list', 'max:30'], 'filters.*' => ['array:field,op,value'],
            'filters.*.field' => ['required', 'integer', 'min:1'], 'filters.*.op' => ['required', 'in:eq,gte,lte,contains,empty'], 'filters.*.value' => ['nullable', 'string', 'max:255'],
            'sort_field' => ['nullable', 'integer', 'min:1'], 'sort_direction' => ['nullable', 'in:asc,desc']])->validate();
        $fields = collect($fields)->keyBy('id');
        $search = trim($data['q'] ?? '');
        $state = ['filters' => [], 'q' => $search, 'filter_field' => null, 'filter_op' => 'eq', 'filter_value' => '', 'sort_field' => null, 'sort_direction' => 'asc'];
        if ($search !== '') {
            $textIds = $fields->whereIn('type', ['text', 'longtext'])->keys()->all();
            $choiceIds = $fields->whereIn('type', ['select', 'multiselect'])->keys()->all();
            $query->where(function ($group) use ($moduleId, $textIds, $choiceIds, $search, $withPeople): void {
                $group->whereRaw('1 = 0');
                if ($withPeople) {
                    $group->orWhereExists(function ($person) use ($search): void {
                        $person->selectRaw('1')->from('people')->whereColumn('people.id', 'module_records.person_id')
                            ->where(function ($identity) use ($search): void {
                                app(TurkishTextSearch::class)->contains($identity, 'full_name', $search);
                                app(TurkishTextSearch::class)->contains($identity, 'tc_identity', $search, 'or');
                            });
                    });
                }
                foreach (['module_record_index' => $textIds, 'module_record_options' => $choiceIds] as $table => $ids) {
                    if ($ids === []) {
                        continue;
                    }
                    $group->orWhereExists(function ($sub) use ($moduleId, $ids, $search, $table): void {
                        $sub->selectRaw('1')->from($table)->whereColumn($table.'.record_id', 'module_records.id')->where($table.'.module_id', $moduleId)->whereIn($table.'.field_id', $ids);
                        $sub->where(function ($texts) use ($table, $search): void {
                            foreach ($table === 'module_record_index' ? ['text_value', 'longtext_value'] : ['label_snapshot'] as $column) {
                                app(TurkishTextSearch::class)->contains($texts, $column, $search, 'or');
                            }
                        });
                    });
                }
            });
        }
        if (! empty($data['filter_field'])) {
            $this->filter($query, $moduleId, $fields, ['field' => $data['filter_field'], 'op' => $data['filter_op'] ?? 'eq', 'value' => $data['filter_value'] ?? null]);
            $state = array_replace($state, ['filter_field' => (int) $data['filter_field'], 'filter_op' => $data['filter_op'] ?? 'eq', 'filter_value' => $data['filter_value'] ?? '']);
        } elseif (! empty($data['filter_value']) || ! empty($data['filter_op'])) {
            $this->invalid('filter_field');
        }
        foreach ($data['filters'] ?? [] as $index => $filter) {
            $this->filter($query, $moduleId, $fields, $filter, 'filters.'.$index);
            $state['filters'][] = array_replace($filter, ['field' => (int) $filter['field']]);
        }
        if (! empty($data['sort_field'])) {
            $field = $fields->get((int) $data['sort_field']);
            if ($field === null || in_array($field['type'], ['select', 'multiselect', 'longtext'], true)) {
                $this->invalid('sort_field');
            }
            $column = $field['type'].'_value';
            // Sort the narrow typed projection, then hydrate only the requested page.
            // LEFT JOIN retains old versions with no index row; nulls remain last.
            $projection = DB::table('module_record_index')->select('record_id', $column)->where('module_id', $moduleId)->where('field_id', $field['id']);
            $query->leftJoinSub($projection, 'sort_index', 'sort_index.record_id', '=', 'module_records.id')
                ->select('module_records.id')->addSelect('sort_index.'.$column.' as sort_value')
                ->orderByRaw('sort_value IS NULL')->orderBy('sort_value', $data['sort_direction'] ?? 'asc');
            $state['sort_field'] = $field['id'];
            $state['sort_direction'] = $data['sort_direction'] ?? 'asc';
        }
        $query->orderBy('module_records.id');

        return $state;
    }

    private function filter(Builder $query, int $moduleId, Collection $fields, array $filter, string $prefix = ''): void
    {
        $field = $fields->get((int) $filter['field']);
        if ($field === null) {
            $this->invalid($prefix === '' ? 'filter_field' : $prefix.'.field');
        }
        $type = $field['type'];
        $op = $filter['op'] ?? 'eq';
        $choice = in_array($type, ['select', 'multiselect'], true);
        if (($op === 'contains' && ! in_array($type, ['text', 'longtext'], true)) || (in_array($op, ['gte', 'lte'], true) && ! in_array($type, ['integer', 'decimal', 'date', 'datetime'], true))) {
            $this->invalid($prefix === '' ? 'filter_op' : $prefix.'.op');
        }
        $value = $op === 'empty' ? null : app(ModuleValueValidator::class)->normalizeFilter($type, $filter['value'] ?? null);
        if ($op !== 'empty' && $value === null) {
            $this->invalid($prefix === '' ? 'filter_value' : $prefix.'.value');
        }
        if ($choice && $value !== null && ! DB::table('option_items')->where('id', $value)->where('option_list_id', $field['option_list_id'])->exists()) {
            $this->invalid($prefix === '' ? 'filter_value' : $prefix.'.value');
        }
        $table = $choice ? 'module_record_options' : 'module_record_index';
        $sub = DB::table($table)->selectRaw('1')->whereColumn($table.'.record_id', 'module_records.id')->where($table.'.module_id', $moduleId)->where($table.'.field_id', $field['id']);
        if ($choice) {
            if ($op !== 'empty') {
                $sub->where('option_id', $value);
            }
            $op === 'empty' ? $query->whereNotExists($sub) : $query->whereExists($sub);
        } else {
            $column = $type.'_value';
            if ($op === 'empty') {
                $sub->whereNull($column);
            } elseif ($op === 'contains') {
                app(TurkishTextSearch::class)->contains($sub, $column, $value);
            } else {
                $sub->where($column, match ($op) {
                    'gte' => '>=', 'lte' => '<=', default => '='
                }, $value);
            }
            $query->whereExists($sub);
        }
    }

    private function invalid(string $key): never
    {
        throw ValidationException::withMessages([$key => 'Görünür alan için geçerli bir filtre veya sıralama seçin.']);
    }
}
