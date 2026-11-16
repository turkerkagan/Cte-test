<?php

namespace App\Services\Administration;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BuiltinMenu
{
    public function defaults(): array
    {
        return json_decode(file_get_contents(resource_path('defaults/legacy-navigation.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function nodes(): array
    {
        $nodes = [];
        $walk = function ($items, $parent = null) use (&$walk, &$nodes) {
            foreach ($items as $i => $item) {
                $key = $item['page'] ?? $item['key'];
                $nodes[$key] = $item + ['parent' => $parent, 'position' => $i];
                if (isset($item['children'])) {
                    $walk($item['children'], $key);
                }
            }
        };
        $walk($this->defaults());

        return $nodes;
    }

    public function state(): array
    {
        $state = app(ManagementSettings::class)->read('builtin_menu', ['items' => []]);
        $configured = collect($state['values']['items'])
            ->reject(fn ($item) => ($item['key'] ?? null) === 'alan_inceleme')
            ->keyBy('key');
        $items = [];
        foreach ($this->nodes() as $key => $node) {
            $items[] = array_replace(['key' => $key, 'label' => $node['label'], 'parent' => $node['parent'], 'position' => $node['position'], 'is_active' => true, 'icon' => '', 'target' => '_self', 'url' => null, 'visible_for' => []], $configured->get($key, []));
        }
        foreach ($configured as $key => $item) {
            if (! isset($this->nodes()[$key])) {
                $items[] = $item;
            }
        }

        return ['revision' => $state['revision'], 'items' => $items, 'groupKeys' => array_keys(array_filter($this->nodes(), fn ($n) => isset($n['children']))), 'users' => DB::table('users')->where('is_active', true)->where('is_guest', false)->orderBy('full_name')->get(['id', 'full_name', 'username'])->all()];
    }

    public function save(User $actor, array $input): void
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:0'], 'items' => ['required', 'array', 'list', 'max:100'], 'items.*' => ['array:key,label,parent,position,is_active,icon,target,url,visible_for'],
            'items.*.key' => ['required', 'regex:/^[a-z][a-z0-9_-]{0,79}$/D', 'distinct'], 'items.*.label' => ['required', 'string', 'max:150'], 'items.*.parent' => ['present', 'nullable', 'string'],
            'items.*.position' => ['required', 'integer', 'between:0,1000'], 'items.*.is_active' => ['required', 'boolean'], 'items.*.icon' => ['nullable', 'regex:/^[a-z0-9-]{0,50}$/D'], 'items.*.target' => ['sometimes', 'in:_self,_blank'],
            'items.*.url' => ['nullable', 'string', 'max:1000'], 'items.*.visible_for' => ['sometimes', 'array', 'list', 'max:1000'], 'items.*.visible_for.*' => ['integer', 'exists:users,id']])->validate();
        $defaults = $this->nodes();
        $items = collect($data['items'])->keyBy('key');
        if (array_diff(array_keys($defaults), $items->keys()->all())) {
            throw ValidationException::withMessages(['items' => 'Sabit öğeleri kaldırmak için görünürlüğü kapatın.']);
        }
        foreach ($items as $key => $item) {
            if (! isset($defaults[$key]) && ! str_starts_with($key, 'custom-')) {
                throw ValidationException::withMessages(['items' => 'Yeni öğe custom- kodu kullanmalı.']);
            }
            if (! empty($item['url']) && ! preg_match('~^(?:https?://[^\s]+|/(?!/)[^\s]*)$~iD', $item['url'])) {
                throw ValidationException::withMessages(['items' => 'Bağlantı http/https veya uygulama içi yol olmalı.']);
            }
            if (! empty($item['parent'])) {
                $parent = $items->get($item['parent']);
                if (! $parent || isset($defaults[$parent['key']]['page']) || ! empty($parent['url'])) {
                    throw ValidationException::withMessages(['items' => 'Üst öğe bir grup olmalı.']);
                }
            }
            $seen = [$key => true];
            $parent = $item['parent'];
            $depth = 0;
            while ($parent !== null) {
                if (isset($seen[$parent]) || ++$depth > 8) {
                    throw ValidationException::withMessages(['items' => 'Menü döngüsü veya 8 seviyeden fazla grup olamaz.']);
                }$seen[$parent] = true;
                $parent = $items[$parent]['parent'] ?? null;
            }
        }
        app(ManagementSettings::class)->save($actor, 'builtin_menu', ['items' => $data['items']], $data['revision']);
    }

    public function structure(?User $actor = null): array
    {
        $nodes = $this->nodes();
        $items = $this->state()['items'];
        $walk = function ($parent) use (&$walk, $nodes, $items, $actor) {
            $result = [];
            foreach ($items as $setting) {
                if ($setting['parent'] !== $parent || ! $setting['is_active'] || ($actor && ! empty($setting['visible_for']) && ! in_array($actor->id, $setting['visible_for']))) {
                    continue;
                }
                $item = ['key' => $setting['key'], 'label' => $setting['label'], 'position' => $setting['position'], 'icon' => $setting['icon'] ?? '', 'target' => $setting['target'] ?? '_self'];
                $node = $nodes[$setting['key']] ?? null;
                if (isset($node['page'])) {
                    $item['page'] = $node['page'];
                } elseif (! empty($setting['url'])) {
                    $item['url'] = $setting['url'];
                } else {
                    $item['children'] = $walk($setting['key']);
                }
                $result[] = $item;
            }usort($result, fn ($a, $b) => $a['position'] <=> $b['position']);

            return $result;
        };

        return $walk(null);
    }
}
