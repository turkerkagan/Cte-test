<?php

namespace App\Models\Concerns;

use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\Ward;
use App\Models\WorkAssignment;
use App\Models\WorkPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait GuardsResourceTransitions
{
    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            if ($this->exists) {
                static::whereKey($this->id)->lockForUpdate()->firstOrFail();
                $assignments = $this instanceof WorkPlace
                    ? WorkAssignment::where('work_place_id', $this->id)
                    : HousingAssignment::where(match (true) {
                        $this instanceof Block => 'block_id', $this instanceof Ward => 'ward_id', default => 'bed_id'
                    }, $this->id);
                $parent = match (true) {
                    $this instanceof Block, $this instanceof WorkPlace => 'institution_id', $this instanceof Ward => 'block_id', default => 'ward_id'
                };
                if ($this->isDirty($parent) && (clone $assignments)->exists()) {
                    throw ValidationException::withMessages([$parent => 'Geçmişi bulunan kaydın üst birimi değiştirilemez.']);
                }
                $activeCount = (clone $assignments)->whereNull('ended_at')->count();
                if (! $this->is_active && $activeCount > 0) {
                    throw ValidationException::withMessages(['is_active' => 'Önce aktif atamaları kapatın.']);
                }
                if ($this instanceof Ward && $this->isDirty('capacity') && $this->capacity > 0 && $this->capacity < $activeCount) {
                    throw ValidationException::withMessages(['capacity' => 'Kapasite mevcut yerleşim sayısının altına indirilemez.']);
                }
            }

            return parent::save($options);
        }, 3);
    }
}
