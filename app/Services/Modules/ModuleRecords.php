<?php

namespace App\Services\Modules;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Queries\NaturalOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/** Atomic storage shared by administration and policy-guarded runtime routes. */
final class ModuleRecords
{
    public function save(User $actor, int $moduleId, array $input, ?int $recordId = null): array
    {
        Gate::forUser($actor)->authorize('manage-modules');

        return $this->persist($actor, $moduleId, $input, $recordId, false);
    }

    public function runtimeSave(User $actor, int $moduleId, array $input, ?int $recordId = null): array
    {
        app(ModuleAccess::class)->authorize($actor, $moduleId, 'yazma');

        return $this->persist($actor, $moduleId, $input, $recordId, true);
    }

    private function persist(User $actor, int $moduleId, array $input, ?int $recordId, bool $runtime): array
    {
        $data = Validator::make($input, ['version_id' => ['required', 'integer', 'min:1'], 'values' => ['present', 'array', 'max:100'],
            'person_id' => ['nullable', 'integer', 'min:1'], 'single_person_slot' => ['prohibited'],
            'revision' => [$recordId === null ? 'nullable' : 'required', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($actor, $moduleId, $recordId, $data, $runtime): array {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $module = DB::table('modules')->where('id', $moduleId)->lockForUpdate()->first();
            abort_if($module === null, 404);
            $record = $recordId === null ? null : DB::table('module_records')->where('module_id', $moduleId)->where('id', $recordId)->lockForUpdate()->first();
            abort_if($recordId !== null && $record === null, 404);
            if ($runtime) {
                $current = User::whereKey($actor->id)->lockForUpdate()->first();
                abort_if($current === null, 403);
                $published = app(ModuleAccess::class)->authorize($current, $moduleId, 'yazma');
                if ((int) $published->id !== (int) $data['version_id']) {
                    throw new DefinitionConflict;
                }
            } else {
                $current = $this->actor($actor, true);
            }
            if ($record !== null && (int) $record->revision !== (int) $data['revision']) {
                throw new DefinitionConflict;
            }
            $binding = app(ModulePersonLinks::class)->binding($module, $record, $data);
            $validator = app(ModuleValueValidator::class);
            $values = $record === null ? $validator->validateForCreate($moduleId, (int) $data['version_id'], $data['values'])
                : $validator->validateForUpdate($moduleId, $recordId, (int) $data['version_id'], $data['values']);
            $encoded = $this->encode($values);
            if ($record !== null && $values === json_decode($record->values, true, 64, JSON_THROW_ON_ERROR)) {
                return ['id' => (int) $record->id, 'revision' => (int) $record->revision];
            }
            $version = DB::table('module_versions')->where('id', $data['version_id'])->first(['definition']);
            $fields = json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR)['fields'];
            $oldSnapshots = $record === null ? collect() : DB::table('module_record_options')->where('record_id', $recordId)->get()->keyBy(fn ($item) => $item->field_id.':'.$item->option_id);
            $revision = $record === null ? 1 : (int) $record->revision + 1;
            if ($record === null) {
                $recordId = DB::table('module_records')->insertGetId(['module_id' => $moduleId, ...$binding, 'version_id' => $data['version_id'], 'values' => $encoded,
                    'revision' => $revision, 'created_by' => $current->id, 'updated_by' => $current->id, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('module_records')->where('id', $recordId)->update(['values' => $encoded, 'revision' => $revision, 'updated_by' => $current->id, 'updated_at' => now()]);
                DB::table('module_record_index')->where('record_id', $recordId)->delete();
                DB::table('module_record_options')->where('record_id', $recordId)->delete();
            }
            $optionIds = [];
            foreach ($fields as $field) {
                $value = $values[$field['id']];
                if ($field['type'] === 'select' && $value !== null) {
                    $optionIds[] = $value;
                } elseif ($field['type'] === 'multiselect') {
                    $optionIds = array_merge($optionIds, $value);
                }
            }
            $labels = $optionIds === [] ? collect() : DB::table('option_items')->whereIn('id', array_unique($optionIds))->pluck('label', 'id');
            $index = [];
            $usages = [];
            foreach ($fields as $field) {
                $value = $values[$field['id']];
                $base = ['record_id' => $recordId, 'module_id' => $moduleId, 'field_id' => $field['id']];
                if (in_array($field['type'], ['select', 'multiselect'], true)) {
                    foreach ($field['type'] === 'select' ? ($value === null ? [] : [$value]) : $value as $optionId) {
                        $usages[] = $base + ['option_list_id' => $field['option_list_id'], 'option_id' => $optionId,
                            'label_snapshot' => $oldSnapshots->get($field['id'].':'.$optionId)->label_snapshot ?? $labels[$optionId]];
                    }
                } else {
                    $index[] = $base + [$field['type'].'_value' => $value];
                }
            }
            // Different scalar types share a table: supply every nullable column.
            $empty = array_fill_keys(['text_value', 'longtext_value', 'integer_value', 'decimal_value', 'boolean_value', 'date_value', 'datetime_value'], null);
            if ($index !== []) {
                DB::table('module_record_index')->insert(array_map(fn ($row) => $row + $empty, $index));
            }
            foreach (array_chunk($usages, 200) as $chunk) {
                DB::table('module_record_options')->insert($chunk);
            }
            app(AuditLog::class)->record($current, 'module_records', $recordId, $record === null ? 'created' : 'updated', ['module_id' => $moduleId,
                'version_id' => (int) $data['version_id'], 'revision' => $revision, 'values_hash' => hash('sha256', $encoded)]);

            return ['id' => $recordId, 'revision' => $revision];
        }, 3);
    }

    public function detail(User $actor, int $moduleId, int $recordId): array
    {
        $this->actor($actor);

        return $this->detailData($moduleId, $recordId);
    }

    private function detailData(int $moduleId, int $recordId): array
    {
        $record = DB::table('module_records')->where('module_id', $moduleId)->where('id', $recordId)->first(['id', 'version_id', 'revision', 'values', 'person_id']);
        abort_if($record === null, 404);

        return ['id' => (int) $record->id, 'version_id' => (int) $record->version_id, 'revision' => (int) $record->revision,
            'person' => $record->person_id === null ? null : (app(ModulePersonLinks::class)->identities([(int) $record->person_id])[(int) $record->person_id] ?? null),
            'values' => json_decode($record->values, true, 64, JSON_THROW_ON_ERROR),
            'option_labels' => DB::table('module_record_options')->where('record_id', $recordId)->orderBy('field_id')->orderBy('option_id')->get(['field_id', 'option_id', 'label_snapshot'])->map(fn ($item) => [
                'field_id' => (int) $item->field_id, 'option_id' => (int) $item->option_id, 'label' => $item->label_snapshot])->all()];
    }

    public function runtimeForm(User $actor, int $moduleId, ?int $recordId = null): array
    {
        $version = app(ModuleAccess::class)->authorize($actor, $moduleId, 'yazma');
        $definition = json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR);
        $fields = collect(app(ModuleValueValidator::class)->checkedFields($moduleId, $definition))->where('show_in_form', true)->keyBy('id');
        $record = $recordId === null ? null : $this->detailData($moduleId, $recordId);
        if ($record !== null) {
            if ($record['version_id'] !== (int) $version->id) {
                throw new DefinitionConflict;
            }
            $record['values'] = array_intersect_key($record['values'], $fields->all());
            $record['option_labels'] = array_values(array_filter($record['option_labels'], fn ($item) => $fields->has($item['field_id'])));
        }
        $items = DB::table('option_items')->join('option_lists', 'option_lists.id', '=', 'option_items.option_list_id')
            ->whereIn('option_items.option_list_id', $fields->pluck('option_list_id')->filter())->where('option_lists.is_active', true)->where('option_items.is_active', true)
            ->orderBy('option_items.position')->orderBy('option_items.id')->get(['option_items.id', 'option_items.option_list_id', 'option_items.label']);
        $formFields = $fields->map(function ($field) use ($items, $record): array {
            $options = NaturalOrder::numericChoices($items->where('option_list_id', $field['option_list_id'] ?? null))->map(fn ($item) => ['id' => (int) $item->id, 'label' => $item->label, 'archived' => false])->values()->all();
            foreach ($record['option_labels'] ?? [] as $old) {
                if ($old['field_id'] === $field['id'] && ! in_array($old['option_id'], array_column($options, 'id'), true)) {
                    $options[] = ['id' => $old['option_id'], 'label' => $old['label'], 'archived' => true];
                }
            }

            return array_intersect_key($field, array_flip(['id', 'type', 'label', 'helper', 'required'])) + ['options' => NaturalOrder::numericChoices(collect($options))->values()->all()];
        })->values()->all();

        return ['title' => $definition['name'], 'recordMode' => $definition['record_mode'], 'versionId' => (int) $version->id, 'definitionVersion' => (int) $version->number, 'fields' => $formFields, 'record' => $record];
    }

    public function page(User $actor, int $moduleId, array $input = []): array
    {
        $this->actor($actor);

        return $this->pageData($moduleId, $input);
    }

    public function runtimePage(User $actor, int $moduleId, array $input = []): array
    {
        $version = app(ModuleAccess::class)->authorize($actor, $moduleId);
        $fields = collect(json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR)['fields'])->where('show_in_table', true)->keyBy('id');
        $result = $this->pageData($moduleId, $input, $fields->all());
        foreach ($result['rows'] as &$row) {
            $row['values'] = array_intersect_key($row['values'], $fields->all());
            $row['option_labels'] = array_values(array_filter($row['option_labels'], fn ($item) => $fields->has($item['field_id'])));
        }
        unset($row);

        $choices = DB::table('option_items')->whereIn('option_list_id', $fields->pluck('option_list_id')->filter())->orderBy('position')->orderBy('id')->get(['id', 'option_list_id', 'label', 'is_active']);
        $result['filterOptions'] = $fields->whereIn('type', ['select', 'multiselect'])->map(fn ($field) => NaturalOrder::numericChoices($choices->where('option_list_id', $field['option_list_id']))->map(fn ($item) => ['id' => (int) $item->id, 'label' => $item->label.($item->is_active ? '' : ' (arşiv)')])->values()->all())->all();

        return $result;
    }

    private function pageData(int $moduleId, array $input, ?array $fields = null): array
    {
        $data = Validator::make($input, ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'in:25,50,100']])->validate();
        $mode = DB::table('modules')->where('id', $moduleId)->value('record_mode');
        abort_if($mode === null, 404);
        $linked = $mode !== 'standalone';
        $query = DB::table('module_records')->where('module_id', $moduleId);
        $state = $fields === null ? null : app(ModuleListQuery::class)->apply($query, $moduleId, $fields, $input, $linked);
        if ($fields === null) {
            $query->orderBy('id');
        }
        $narrowSort = $query->columns !== null;
        $result = $query->paginate((int) ($data['per_page'] ?? 50), ['id', 'version_id', 'revision', 'values', 'person_id'], 'page', (int) ($data['page'] ?? 1));

        $rows = collect($result->items());
        if ($narrowSort && $rows->isNotEmpty()) {
            $records = DB::table('module_records')->where('module_id', $moduleId)->whereIn('id', $rows->pluck('id'))->get(['id', 'version_id', 'revision', 'values', 'person_id'])->keyBy('id');
            $rows = $rows->map(fn ($row) => $records[$row->id]);
        }
        $people = app(ModulePersonLinks::class)->identities($rows->pluck('person_id')->filter()->map(fn ($id) => (int) $id)->all());
        $labels = $rows->isEmpty() ? collect() : DB::table('module_record_options')->whereIn('record_id', $rows->pluck('id'))->orderBy('record_id')->orderBy('field_id')->orderBy('option_id')->get(['record_id', 'field_id', 'option_id', 'label_snapshot'])->groupBy('record_id');

        return ['rows' => $rows->map(fn ($row) => ['id' => (int) $row->id, 'version_id' => (int) $row->version_id, 'revision' => (int) $row->revision,
            'person' => $row->person_id === null ? null : ($people[(int) $row->person_id] ?? null),
            'values' => json_decode($row->values, true, 64, JSON_THROW_ON_ERROR), 'option_labels' => $labels->get($row->id, collect())->map(fn ($item) => [
                'field_id' => (int) $item->field_id, 'option_id' => (int) $item->option_id, 'label' => $item->label_snapshot])->all()])->all(),
            'queryState' => $state, 'pagination' => ['page' => $result->currentPage(), 'per_page' => $result->perPage(), 'total' => $result->total(), 'last_page' => $result->lastPage()]];
    }

    private function actor(User $actor, bool $lock = false): User
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $query = User::whereKey($actor->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($current === null, 403);
        Gate::forUser($current)->authorize('manage-modules');

        return $current;
    }

    private function encode(array $values): string
    {
        return json_encode((object) $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
