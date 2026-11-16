<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Modules\DefinitionConflict;
use App\Services\Workflows\CaseFields;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class FieldDefinitions
{
    public function institutionDefaults(): array
    {
        return json_decode(file_get_contents(resource_path('defaults/institution-workflows.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function state(): array
    {
        $state = app(ManagementSettings::class)->read('workflow_fields', ['items' => []]);
        $defaults = app(CaseFields::class)->defaults();
        $columns = json_decode(file_get_contents(resource_path('js/lib/legacyWorkflowColumns.json')), true, flags: JSON_THROW_ON_ERROR);
        $groups = [];
        $sources = [];
        foreach ($defaults as $key => &$f) {
            $f['table_visible'] = in_array($key, array_column($columns[$f['group']] ?? [], 'key'), true);
            $groups[$f['group']] = WorkflowAccess::PAGES[$f['group']][0] ?? $f['group'];
            $sources[$f['group']] = 'person_case_data';
        }unset($f);
        foreach ($this->institutionDefaults() as $group => $definition) {
            $groups[$group] = $definition['label'];
            $sources[$group] = 'institution_records';
            $fields = $group === 'kurul' ? app(BoardDefinitions::class)->fields() : $definition['fields'];
            foreach ($fields as $key => $field) {
                $defaults[$group.'__'.$key] = $field + ['group' => $group, 'storage_key' => $key, 'table_visible' => true];
            }
        }

        return $state + ['defaults' => $defaults, 'groups' => $groups, 'sources' => $sources, 'optionLists' => DB::table('case_option_lists')->where('is_active', true)->orderBy('name')->get(['code', 'name as label'])->all()];
    }

    public function all(array $defaults, ?string $institution = null, bool $includeMissing = false): array
    {
        foreach (app(ManagementSettings::class)->read('workflow_fields', ['items' => []])['values']['items'] as $item) {
            $institutionField = str_contains($item['key'], '__');
            if ($institution === null && $institutionField) {
                continue;
            }
            if ($institution !== null && (! $institutionField || $item['group'] !== $institution)) {
                continue;
            }
            $key = $institution === null ? $item['key'] : explode('__', $item['key'], 2)[1];
            if (! $includeMissing && $institution === null && ! isset($defaults[$key]) && ! Schema::hasColumn('person_case_data', $key)) {
                continue;
            }
            $defaults[$key] = array_replace($defaults[$key] ?? ['type' => $item['type'], 'group' => $item['group']], [
                'label' => $item['label'], 'table_visible' => $item['table_visible'], 'form_visible' => $item['form_visible'], 'required' => $item['required'],
                'admin_readonly' => $item['readonly'], 'admin_position' => $item['position'], 'admin_configured' => true,
                'filter_visible' => $item['filter_visible'] ?? true, 'sortable' => $item['sortable'] ?? true, 'max_length' => $item['max_length'] ?? ($item['type'] === 'longtext' ? 10000 : 255),
                'default' => $item['default'] ?? null, 'option_list' => $item['option_list'] ?? str_replace('_', '-', $key),
            ]);
            if ($institution !== null) {
                $defaults[$key]['readonly'] = ($defaults[$key]['readonly'] ?? false) || $item['readonly'];
            }
        }
        uasort($defaults, fn ($a, $b) => ($a['admin_position'] ?? 500) <=> ($b['admin_position'] ?? 500));

        return $defaults;
    }

    public function save(User $actor, array $input): void
    {
        $state = $this->state();
        $defaults = $state['defaults'];
        $groups = array_keys($state['groups']);
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:0'], 'items' => ['required', 'array', 'list', 'max:300'],
            'items.*' => ['array:key,label,type,group,table_visible,form_visible,required,readonly,position,filter_visible,sortable,max_length,default,option_list'],
            'items.*.key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/D', 'distinct'], 'items.*.label' => ['required', 'string', 'max:150'],
            'items.*.type' => ['required', 'in:text,longtext,integer,decimal,date,datetime,time,boolean,option,ds,user,room'], 'items.*.group' => ['required', 'in:'.implode(',', $groups)],
            'items.*.table_visible' => ['required', 'boolean'], 'items.*.form_visible' => ['required', 'boolean'], 'items.*.required' => ['required', 'boolean'], 'items.*.readonly' => ['required', 'boolean'],
            'items.*.position' => ['required', 'integer', 'between:0,9999'], 'items.*.filter_visible' => ['sometimes', 'boolean'], 'items.*.sortable' => ['sometimes', 'boolean'],
            'items.*.max_length' => ['sometimes', 'integer', 'between:1,10000'], 'items.*.default' => ['nullable', 'string', 'max:1000'], 'items.*.option_list' => ['nullable', 'string', 'max:80']])->validate();
        $root = storage_path('app/private/administration');
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }$lock = fopen($root.'/fields.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            app(ManagementAccess::class)->actor($actor);
            $state = $this->state();
            if ($state['revision'] !== $data['revision']) {
                throw new DefinitionConflict;
            }
            $prior = collect($state['values']['items'])->keyBy('key');
            if (array_diff($prior->keys()->all(), array_column($data['items'], 'key'))) {
                throw ValidationException::withMessages(['items' => 'Veriyi korumak için alanı görünümden kaldırın. Fiziksel kolon silme, veritabanı araçlarında ön yedekle yapılır.']);
            }
            foreach ($data['items'] as $item) {
                $old = $defaults[$item['key']] ?? $prior->get($item['key']);
                if ($old && ($old['type'] !== $item['type'] || $old['group'] !== $item['group'])) {
                    throw ValidationException::withMessages(['items' => 'Kayıtlı alanın türü ve birimi değiştirilemez.']);
                }
                $institution = $state['sources'][$item['group']] === 'institution_records';
                $key = $institution ? explode('__', $item['key'], 2)[1] ?? '' : $item['key'];
                if ($institution && ! str_starts_with($item['key'], $item['group'].'__')) {
                    throw ValidationException::withMessages(['items' => 'Kurum alanının kodu birim__alan biçiminde olmalı.']);
                }
                if (! $old && (! str_starts_with($key, 'custom_') || in_array($item['type'], ['ds', 'user', 'room']))) {
                    throw ValidationException::withMessages(['items' => 'Yeni alan custom_ koduyla başlamalı ve standart veri türünde olmalı.']);
                }
                if ($item['type'] === 'option' && (! empty($item['option_list']) || ! $old)) {
                    $code = $item['option_list'] ?? '';
                    if (! DB::table('case_option_lists')->where('code', $code)->where('is_active', true)->exists()) {
                        throw ValidationException::withMessages(['items' => 'Etkin bir seçenek listesi bağlayın.']);
                    }
                }
                if (($item['default'] ?? null) !== null && $item['default'] !== '') {
                    $rule = match ($item['type']) {
                        'integer','option' => ['integer'],'decimal' => ['numeric'],'boolean' => ['in:0,1'],'date' => ['date_format:Y-m-d'],'datetime' => ['date_format:Y-m-d\TH:i'],'time' => ['date_format:H:i'],default => ['string', 'max:'.($item['max_length'] ?? 255)]
                    };
                    Validator::make(['default' => $item['default']], ['default' => $rule])->validate();
                }
                if (! $old && ! $institution && ! Schema::hasColumn('person_case_data', $key)) {
                    Schema::table('person_case_data', function (Blueprint $t) use ($item, $key) {
                        match ($item['type']) {
                            'integer','option' => $t->integer($key)->nullable(),'decimal' => $t->decimal($key, 18, 4)->nullable(),'boolean' => $t->boolean($key)->nullable(),
                            'date' => $t->date($key)->nullable(),'datetime' => $t->dateTime($key)->nullable(),'time' => $t->time($key)->nullable(),default => $t->text($key)->nullable(),
                        };
                    });
                }
            }
            app(ManagementSettings::class)->save($actor, 'workflow_fields', ['items' => $data['items']], $data['revision']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
