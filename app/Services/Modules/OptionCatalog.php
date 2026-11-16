<?php

namespace App\Services\Modules;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Queries\NaturalOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OptionCatalog
{
    public function __construct(private readonly string $namespace = '')
    {
        if (! in_array($namespace, ['', 'case_'], true)) {
            throw new \LogicException('Unknown option namespace');
        }
    }

    private function table(string $name): string
    {
        return $this->namespace.$name;
    }

    public function all(): array
    {
        $items = DB::table($this->table('option_items'))->orderBy('position')->orderBy('id')->get()->groupBy('option_list_id');

        return DB::table($this->table('option_lists'))->orderBy('name')->get()->map(fn ($list) => [
            'id' => (int) $list->id, 'code' => $list->code, 'name' => $list->name, 'is_active' => (bool) $list->is_active, 'revision' => (int) $list->revision,
            'items' => NaturalOrder::numericChoices($items->get($list->id, collect()))->map(fn ($item) => ['id' => (int) $item->id, 'label' => $item->label, 'is_active' => (bool) $item->is_active])->all(),
        ])->all();
    }

    public function create(User $actor, array $input): int
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $data = Validator::make($input, ['code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', Rule::unique($this->table('option_lists'))], 'name' => ['required', 'string', 'max:150']], $this->messages())->validate();
        $data['name'] = trim($data['name']);

        return DB::transaction(function () use ($actor, $data): int {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = $this->actor($actor);
            $id = DB::table($this->table('option_lists'))->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, $this->table('option_lists'), $id, 'created');

            return $id;
        }, 3);
    }

    public function save(User $actor, int $id, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-modules');
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:150'], 'is_active' => ['required', 'boolean'],
            'items' => ['present', 'array', 'list', 'max:500'], 'items.*' => ['array:id,label,is_active'], 'items.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'items.*.label' => ['required', 'string', 'max:150'], 'items.*.is_active' => ['required', 'boolean']], $this->messages())->validate();
        DB::transaction(function () use ($actor, $id, $data): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $list = DB::table($this->table('option_lists'))->where('id', $id)->lockForUpdate()->first();
            abort_if($list === null, 404);
            $actor = $this->actor($actor);
            if ((int) $list->revision !== (int) $data['revision']) {
                throw new DefinitionConflict;
            }
            $existing = DB::table($this->table('option_items'))->where('option_list_id', $id)->orderBy('position')->orderBy('id')->get()->keyBy('id');
            $received = array_filter(array_column($data['items'], 'id'), fn ($value) => $value !== null);
            if (array_diff($existing->keys()->all(), $received) !== []) {
                throw ValidationException::withMessages(['items' => 'Kaydedilmiş seçenek silinemez; etkin işaretini kaldırarak arşivleyin.']);
            }
            $changed = $list->name !== trim($data['name']) || (bool) $list->is_active !== (bool) $data['is_active'];
            $savedIds = [];
            foreach ($data['items'] as $position => $item) {
                $itemId = $item['id'] ?? null;
                $values = ['label' => trim($item['label']), 'position' => $position, 'is_active' => (bool) $item['is_active']];
                if ($itemId !== null) {
                    if (! $existing->has($itemId)) {
                        throw ValidationException::withMessages(['items.'.$position.'.id' => 'Seçenek bu listeye ait değil.']);
                    }
                    $before = $existing[$itemId];
                    if ($before->label !== $values['label'] || (int) $before->position !== $position || (bool) $before->is_active !== $values['is_active']) {
                        DB::table($this->table('option_items'))->where('id', $itemId)->update($values + ['updated_at' => now()]);
                        $changed = true;
                    }
                } else {
                    $itemId = DB::table($this->table('option_items'))->insertGetId($values + ($this->namespace === 'case_' ? ['legacy_value' => $values['label']] : []) + ['option_list_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                    $changed = true;
                }
                $savedIds[] = (int) $itemId;
            }
            if (! $changed) {
                return;
            }
            DB::table($this->table('option_lists'))->where('id', $id)->update(['name' => trim($data['name']), 'is_active' => (bool) $data['is_active'], 'revision' => $list->revision + 1, 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, $this->table('option_lists'), $id, 'updated', ['before_revision' => (int) $list->revision, 'after_revision' => (int) $list->revision + 1, 'item_ids' => $savedIds,
                'definition_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))]);
        }, 3);
    }

    private function actor(User $actor): User
    {
        $current = User::whereKey($actor->id)->lockForUpdate()->first();
        abort_if($current === null, 403);
        Gate::forUser($current)->authorize('manage-modules');

        return $current;
    }

    private function messages(): array
    {
        return ['required' => ':attribute alanı zorunlu.', 'present' => ':attribute gönderilmeli.', 'string' => ':attribute metin olmalı.', 'max' => ':attribute en fazla :max olabilir.',
            'array' => ':attribute geçerli alanlardan oluşmalı.', 'list' => ':attribute sıralı liste olmalı.', 'distinct' => 'Aynı seçenek kimliği iki kez kullanılamaz.',
            'boolean' => ':attribute evet/hayır olmalı.', 'integer' => ':attribute tam sayı olmalı.', 'unique' => 'Bu liste kodu kullanılıyor.', 'regex' => 'Kod küçük Latin harf, rakam ve tire içermeli.'];
    }
}
