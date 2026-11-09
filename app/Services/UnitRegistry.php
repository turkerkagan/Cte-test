<?php

namespace App\Services;

use App\Http\Controllers\UnitRecordController;
use App\Models\Module;
use App\Models\UnitDefinition;

class UnitRegistry
{
    public function all(): array
    {
        $disabled = Module::where('is_active', false)->pluck('code')->all();
        $builtIn = array_filter(UnitRecordController::MODULES, fn ($config) => ! in_array($config['permission'], $disabled, true));
        foreach (UnitDefinition::where('is_active', true)->orderBy('sort_order')->orderBy('title')->get() as $definition) {
            $builtIn[$definition->code] = $definition->configuration();
        }

        return $builtIn;
    }

    public function visible(): array
    {
        return array_filter($this->all(), fn ($config) => ($config['visible'] ?? true) && auth()->user()?->hasPermission($config['permission'].'.view'));
    }

    public function find(string $code): array
    {
        $all = $this->all();
        abort_unless(isset($all[$code]), 404);

        return $all[$code];
    }
}
