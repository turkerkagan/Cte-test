<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Administration\FieldDefinitions;

final class CaseFields
{
    public function defaults(): array
    {
        return json_decode(file_get_contents(resource_path('defaults/person-workflows.json')), true, 64, JSON_THROW_ON_ERROR)['fields'] + $this->services()['fields'];
    }

    public function all(): array
    {
        return app(FieldDefinitions::class)->all($this->defaults());
    }

    public function group(string $group, ?array $definitions = null): array
    {
        $members = $this->services()['groups'][$group] ?? [];
        $fields = array_filter($definitions ?? $this->all(), fn ($field, $key) => $field['group'] === $group || in_array($key, $members, true), ARRAY_FILTER_USE_BOTH);
        foreach ($fields as $key => &$field) {
            $field['readonly'] = ($field['admin_readonly'] ?? false) || in_array($key, $this->services()['readonly'][$group] ?? [], true);
        }
        unset($field);

        return $fields;
    }

    public function services(): array
    {
        return json_decode(file_get_contents(resource_path('defaults/service-workflows.json')), true, 64, JSON_THROW_ON_ERROR);
    }

    public function visible(User $actor, string $group, bool $form = false): array
    {
        $fresh = User::find($actor->id);
        $definitions = $this->all();
        $fields = $this->group($group, $definitions);
        if ($group === 'custom_rapor') {
            foreach (array_keys(WorkflowAccess::PAGES) as $source) {
                if ($source === 'custom_rapor') {
                    continue;
                }
                if ($fresh?->is_system_admin || app(PageAccess::class)->allows($actor, $source)) {
                    $fields += $this->group($source, $definitions);
                }
            }
            foreach ($fields as &$field) {
                $field['readonly'] = true;
            } unset($field);
        }

        return array_filter($fields, fn ($f) => ($form ? ($f['form_visible'] ?? true) : ($f['table_visible'] ?? true)) && (($form && $group !== 'custom_rapor') || ! ($f['secret'] ?? false)) && (! isset($f['permission']) || ($fresh !== null && $fresh->is_active && ! $fresh->must_change_password && ($fresh->is_system_admin || app(PageAccess::class)->allows($fresh, $f['permission'])))));
    }

    public function writable(User $actor, string $group): array
    {
        $fresh = User::find($actor->id);

        return array_filter($this->visible($actor, $group, true), fn ($f) => ! $f['readonly'] && (! isset($f['permission']) || $fresh->is_system_admin || app(PageAccess::class)->allows($fresh, $f['permission'], 'yazma')));
    }
}
