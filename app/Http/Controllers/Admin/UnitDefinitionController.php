<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UnitRecordController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\UnitDefinition;
use App\Models\UnitRecord;
use App\Support\PermissionCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnitDefinitionController extends Controller
{
    public function index()
    {
        return view('admin.unit-definitions.index', ['definitions' => UnitDefinition::orderBy('sort_order')->orderBy('title')->get()]);
    }

    public function create()
    {
        return $this->form(new UnitDefinition(['is_active' => true, 'is_visible' => true, 'sort_order' => 0, 'types' => ['Genel'], 'fields' => []]));
    }

    public function edit(UnitDefinition $unitDefinition)
    {
        return $this->form($unitDefinition);
    }

    private function form(UnitDefinition $definition)
    {
        $grants = [];
        foreach (['view', 'update'] as $action) {
            $permission = Permission::where('key', 'custom_'.$definition->code.'.'.$action)->first();
            $grants[$action] = $permission ? DB::table('permission_role')->where('permission_id', $permission->id)->pluck('role_id')->all() : [];
        }

        return view('admin.unit-definitions.form', ['definition' => $definition, 'roles' => Role::orderBy('name')->get(), 'grants' => $grants]);
    }

    public function store(Request $request)
    {
        return $this->persist($request, new UnitDefinition);
    }

    public function update(Request $request, UnitDefinition $unitDefinition)
    {
        return $this->persist($request, $unitDefinition);
    }

    private function persist(Request $request, UnitDefinition $definition)
    {
        $data = $request->validate([
            'code' => [$definition->exists ? 'prohibited' : 'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9-]*$/', Rule::notIn(array_keys(UnitRecordController::MODULES)), Rule::unique('unit_definitions', 'code')->ignore($definition->id)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'types_text' => ['required', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'], 'is_visible' => ['nullable', 'boolean'], 'is_sensitive' => ['nullable', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'fields' => ['required', 'array', 'min:1', 'max:60'],
            'fields.*' => ['array:key,label,type,required,in_table,filterable,options_text'],
            'fields.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:100'],
            'fields.*.type' => ['required', Rule::in(['text', 'textarea', 'number', 'date', 'select'])],
            'fields.*.required' => ['nullable', 'boolean'], 'fields.*.in_table' => ['nullable', 'boolean'], 'fields.*.filterable' => ['nullable', 'boolean'],
            'fields.*.options_text' => ['nullable', 'string', 'max:4000'],
            'view_roles' => ['nullable', 'array'], 'view_roles.*' => ['integer', 'exists:roles,id'],
            'update_roles' => ['nullable', 'array'], 'update_roles.*' => ['integer', 'exists:roles,id'],
        ]);
        $lines = fn ($value) => array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', $value ?? '')), fn ($v) => $v !== '')));
        $types = $lines($data['types_text']);
        if (! $types || collect($types)->contains(fn ($type) => mb_strlen($type) > 100)) {
            throw ValidationException::withMessages(['types_text' => 'Her satıra en fazla 100 karakterlik bir kayıt türü yazın.']);
        }
        $fields = [];
        foreach ($data['fields'] as $index => $field) {
            $options = $lines($field['options_text'] ?? '');
            if ($field['type'] === 'select' && (! $options || collect($options)->contains(fn ($v) => mb_strlen($v) > 1000))) {
                throw ValidationException::withMessages(["fields.$index.options_text" => 'Seçim alanı için her satıra bir seçenek yazın (en fazla 1000 karakter).']);
            }
            $fields[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'], 'options' => $field['type'] === 'select' ? $options : [],
                'required' => (bool) ($field['required'] ?? false), 'in_table' => (bool) ($field['in_table'] ?? false), 'filterable' => (bool) ($field['filterable'] ?? false)];
        }
        DB::transaction(function () use ($definition, $data, $fields, $types): void {
            if ($definition->exists) {
                $definition = UnitDefinition::whereKey($definition->id)->lockForUpdate()->firstOrFail();
                if (UnitRecord::module($definition->code)->exists()) {
                    $old = collect($definition->fields)->keyBy('key');
                    $new = collect($fields)->keyBy('key');
                    if ($old->keys()->diff($new->keys())->isNotEmpty() || $old->contains(fn ($field, $key) => isset($new[$key]) && $new[$key]['type'] !== $field['type'])) {
                        throw ValidationException::withMessages(['fields' => 'Kayıt bulunan birimde alan anahtarı ve türü korunmalıdır. Alanı listeden gizleyebilir, başlığını veya sırasını değiştirebilirsiniz.']);
                    }
                }
            }
            $definition->fill(['title' => $data['title'], 'description' => $data['description'] ?? null, 'types' => $types, 'fields' => $fields, 'sort_order' => $data['sort_order'],
                'is_active' => (bool) ($data['is_active'] ?? false), 'is_visible' => (bool) ($data['is_visible'] ?? false), 'is_sensitive' => (bool) ($data['is_sensitive'] ?? false)]);
            if (! $definition->exists) {
                $definition->code = $data['code'];
            }
            $definition->save();
            foreach (['view', 'update'] as $action) {
                $permission = Permission::updateOrCreate(['key' => 'custom_'.$definition->code.'.'.$action], ['module' => 'custom_'.$definition->code, 'action' => $action, 'label' => mb_substr($definition->title, 0, 230).' '.($action === 'view' ? 'görüntüleme' : 'düzenleme')]);
                $ids = $data[$action.'_roles'] ?? [];
                if ($action === 'view') {
                    $ids = array_unique(array_merge($ids, $data['update_roles'] ?? []));
                }
                DB::table('permission_role')->where('permission_id', $permission->id)->delete();
                foreach ($ids as $id) {
                    DB::table('permission_role')->insert(['permission_id' => $permission->id, 'role_id' => $id]);
                }
            }
            PermissionCache::flushAll();
        });

        return redirect()->route('admin.unit-definitions.index')->with('status', 'Birim, alanlar ve tablo ayarları kaydedildi.');
    }
}
