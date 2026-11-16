<?php

namespace App\Services\Modules;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ModuleDefinitions
{
    public const RECORD_MODES = ['standalone', 'person_single', 'person_multiple'];

    public const TYPES = ['text', 'longtext', 'integer', 'decimal', 'date', 'datetime', 'boolean', 'select', 'multiselect'];

    public function create(User $actor, array $input): int
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $data = Validator::make($input, ['code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', Rule::unique('modules')],
            'name' => ['required', 'string', 'max:150'], 'record_mode' => ['required', Rule::in(self::RECORD_MODES)]], $this->messages(), ['code' => 'Modül kodu', 'name' => 'Modül adı', 'record_mode' => 'Kayıt modu'])->validate();

        $data['name'] = trim($data['name']);

        return DB::transaction(function () use ($actor, $data): int {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = $this->actor($actor);
            $id = DB::table('modules')->insertGetId($data + ['revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('module_versions')->insert(['module_id' => $id, 'number' => 1, 'status' => 'draft', 'draft_slot' => 1, 'revision' => 1,
                'definition' => $this->encode(['schema_version' => 1, 'name' => $data['name'], 'record_mode' => $data['record_mode'], 'fields' => []]), 'created_at' => now(), 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'modules', $id, 'draft_created', ['number' => 1]);

            return $id;
        }, 3);
    }

    public function save(User $actor, int $id, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'version_id' => ['required', 'integer', 'min:1'],
            'version_revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:150'],
            'fields' => ['present', 'array', 'list', 'max:100'], 'fields.*' => ['array:id,type,label,helper,required,show_in_form,show_in_table,option_list_id'],
            'fields.*.option_list_id' => ['nullable', 'integer', 'min:1'],
            'fields.*.id' => ['nullable', 'integer', 'min:1', 'distinct'], 'fields.*.type' => ['required', Rule::in(self::TYPES)],
            'fields.*.label' => ['required', 'string', 'max:150'], 'fields.*.helper' => ['present', 'nullable', 'string', 'max:255'],
            'fields.*.required' => ['required', 'boolean'], 'fields.*.show_in_form' => ['required', 'boolean'], 'fields.*.show_in_table' => ['required', 'boolean']], $this->messages(),
            ['name' => 'Modül adı', 'fields' => 'Alanlar', 'fields.*.label' => 'Alan başlığı', 'fields.*.type' => 'Alan türü', 'fields.*.helper' => 'Alan açıklaması'])->validate();
        foreach ($data['fields'] as $index => $field) {
            if ($field['required'] && ! $field['show_in_form']) {
                throw ValidationException::withMessages(['fields.'.$index.'.show_in_form' => 'Zorunlu alan formda görünür olmalı.']);
            }
        }
        DB::transaction(function () use ($actor, $id, $data): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $module = DB::table('modules')->where('id', $id)->lockForUpdate()->first();
            abort_if($module === null, 404);
            $version = DB::table('module_versions')->where('module_id', $id)->where('id', $data['version_id'])->lockForUpdate()->first();
            $actor = $this->actor($actor);
            if ($version === null || $version->status !== 'draft' || (int) $version->draft_slot !== 1 || (int) $module->revision !== (int) $data['revision'] || (int) $version->revision !== (int) $data['version_revision']) {
                throw new DefinitionConflict;
            }
            $before = json_decode($version->definition, true, 64, JSON_THROW_ON_ERROR);
            $previous = collect($before['fields'])->keyBy('id');
            $identities = DB::table('module_fields')->where('module_id', $id)->get(['id', 'type', 'option_list_id'])->keyBy('id');
            $lists = DB::table('option_lists')->get(['id', 'is_active'])->keyBy('id');
            $fields = [];
            foreach ($data['fields'] as $index => $field) {
                $fieldId = $field['id'] ?? null;
                $choice = in_array($field['type'], ['select', 'multiselect'], true);
                $listId = $field['option_list_id'] ?? null;
                if (($choice && ($listId === null || ! $lists->has($listId))) || (! $choice && $listId !== null)) {
                    throw ValidationException::withMessages(['fields.'.$index.'.option_list_id' => 'Seçenek alanı mevcut bir listeye bağlı olmalı; diğer alanlar listeye bağlanamaz.']);
                }
                if ($choice && ! $lists[$listId]->is_active && ($fieldId === null || ! $identities->has($fieldId) || (int) $identities[$fieldId]->option_list_id !== (int) $listId)) {
                    throw ValidationException::withMessages(['fields.'.$index.'.option_list_id' => 'Arşivlenmiş liste yeni alana bağlanamaz.']);
                }
                if ($fieldId !== null) {
                    if (! $previous->has($fieldId) || ! $identities->has($fieldId)) {
                        throw ValidationException::withMessages(['fields.'.$index.'.id' => 'Alan bu taslağa ait değil veya kaldırılmış.']);
                    }
                    if ($identities[$fieldId]->type !== $field['type']) {
                        throw ValidationException::withMessages(['fields.'.$index.'.type' => 'Kaydedilmiş alanın türü değişmez. Farklı tür için yeni alan ekleyin.']);
                    }
                    if ($choice && (int) $identities[$fieldId]->option_list_id !== (int) $listId) {
                        throw ValidationException::withMessages(['fields.'.$index.'.option_list_id' => 'Kaydedilmiş alanın seçenek kaynağı değişmez; yeni alan ekleyin.']);
                    }
                } else {
                    $fieldId = DB::table('module_fields')->insertGetId(['module_id' => $id, 'type' => $field['type'], 'option_list_id' => $listId, 'created_at' => now(), 'updated_at' => now()]);
                }
                $fields[] = ['id' => (int) $fieldId, 'type' => $field['type'], 'label' => trim($field['label']), 'helper' => $field['helper'] ?? '',
                    'required' => (bool) $field['required'], 'show_in_form' => (bool) $field['show_in_form'], 'show_in_table' => (bool) $field['show_in_table']] + ($choice ? ['option_list_id' => (int) $listId] : []);
            }
            $definition = ['schema_version' => 1, 'name' => trim($data['name']), 'record_mode' => $module->record_mode, 'fields' => $fields];
            if ($definition === $before) {
                return;
            }
            DB::table('module_versions')->where('id', $version->id)->update(['definition' => $this->encode($definition), 'revision' => $version->revision + 1, 'updated_at' => now()]);
            DB::table('modules')->where('id', $id)->update(['name' => $definition['name'], 'revision' => $module->revision + 1, 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'modules', $id, 'draft_updated', ['version_id' => (int) $version->id, 'number' => (int) $version->number, 'field_ids' => array_column($fields, 'id'),
                'before_revision' => (int) $version->revision, 'after_revision' => (int) $version->revision + 1, 'definition_hash' => hash('sha256', $this->encode($definition))]);
        }, 3);
    }

    public function fork(User $actor, int $id, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1']])->validate();
        DB::transaction(function () use ($actor, $id, $data): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $module = DB::table('modules')->where('id', $id)->lockForUpdate()->first();
            abort_if($module === null, 404);
            $actor = $this->actor($actor);
            if ((int) $module->revision !== (int) $data['revision']) {
                throw new DefinitionConflict;
            }
            if (DB::table('module_versions')->where('module_id', $id)->where('draft_slot', 1)->exists()) {
                return;
            }
            $source = DB::table('module_versions')->where('module_id', $id)->where('status', 'published')->orderByDesc('number')->first();
            abort_if($source === null, 404);
            $number = (int) DB::table('module_versions')->where('module_id', $id)->max('number') + 1;
            DB::table('module_versions')->insert(['module_id' => $id, 'number' => $number, 'status' => 'draft', 'draft_slot' => 1, 'revision' => 1,
                'definition' => $source->definition, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('modules')->where('id', $id)->update(['revision' => $module->revision + 1, 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'modules', $id, 'draft_created', ['number' => $number, 'source_version_id' => (int) $source->id]);
        }, 3);
    }

    private function actor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        abort_if($current === null, 403);
        Gate::forUser($current)->authorize('manage-modules');

        return $current;
    }

    private function encode(array $definition): string
    {
        return json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function messages(): array
    {
        return ['required' => ':attribute alanı zorunlu.', 'present' => ':attribute alanı gönderilmeli.',
            'in' => ':attribute için desteklenen bir değer seçin.', 'max' => ':attribute en fazla :max olabilir.',
            'integer' => ':attribute tam sayı olmalı.', 'boolean' => ':attribute geçerli bir evet/hayır değeri olmalı.',
            'array' => ':attribute geçerli bir tanım listesi olmalı; ek kod veya betik alanı kabul edilmez.',
            'list' => ':attribute sıralı bir liste olmalı.', 'distinct' => 'Bir alan kimliği birden fazla kullanılamaz.',
            'unique' => 'Bu modül kodu zaten kullanılıyor.', 'regex' => 'Kod küçük Latin harf, rakam ve tire içermeli.'];
    }
}
