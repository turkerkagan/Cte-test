<?php

namespace App\Models\Concerns;

use App\Models\Bed;
use App\Models\Block;
use App\Models\HousingAssignment;
use App\Models\InmateFile;
use App\Models\LookupGroup;
use App\Models\Ward;
use App\Models\WorkPlace;
use App\Support\StateDefinitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait GuardsAssignmentTransitions
{
    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            $inmate = InmateFile::whereKey($this->inmate_file_id)->lockForUpdate()->firstOrFail();
            $current = $this->exists ? static::whereKey($this->getKey())->lockForUpdate()->firstOrFail() : null;
            $fail = fn ($field, $message) => throw ValidationException::withMessages([$field => $message]);
            if ($current && $current->inmate_file_id !== $inmate->id) {
                $fail('inmate_file_id', 'Atamanın hükümlüsü değiştirilemez.');
            }
            if ($current?->ended_at && ! $this->ended_at) {
                $fail('ended_at', 'Kapatılmış atama yeniden açılamaz. Yeni bir atama oluşturun.');
            }
            if (! $this->started_at || ($this->ended_at && $this->ended_at->lt($this->started_at))) {
                $fail('ended_at', 'Bitiş tarihi başlangıçtan önce olamaz.');
            }
            if ($this->ended_at?->isAfter(today()->endOfDay())) {
                $fail('ended_at', 'Bitiş fiilî kapanma tarihidir; gelecekte olamaz.');
            }
            if (! $this->ended_at && (! $inmate->is_active || $this->started_at->isFuture() && ! $this->started_at->isToday())) {
                $fail('started_at', 'Aktif atama için aktif dosya ve bugün veya geçmiş bir başlangıç gerekir.');
            }
            if ($this instanceof HousingAssignment) {
                $block = Block::whereKey($this->block_id)->lockForUpdate()->firstOrFail();
                $ward = Ward::whereKey($this->ward_id)->lockForUpdate()->firstOrFail();
                $bed = $this->bed_id ? Bed::whereKey($this->bed_id)->lockForUpdate()->firstOrFail() : null;
                if ($block->institution_id !== $inmate->institution_id || $ward->block_id !== $block->id || ($bed && $bed->ward_id !== $ward->id)) {
                    $fail('ward_id', 'Blok, koğuş, ranza ve kurum ilişkisi geçersiz.');
                }
                if (! $this->ended_at && (! $block->is_active || ! $ward->is_active || ($bed && ! $bed->is_active))) {
                    $fail('ward_id', 'Pasif yerleşim birimine aktif atama yapılamaz.');
                }
                if (! $this->ended_at && $bed && static::where('bed_id', $bed->id)->whereNull('ended_at')->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))->exists()) {
                    $fail('bed_id', 'Bu ranza için zaten aktif konum kaydı var.');
                }
                if (! $this->ended_at && $ward->capacity > 0 && static::where('ward_id', $ward->id)->whereNull('ended_at')->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))->count() >= $ward->capacity) {
                    $fail('ward_id', 'Koğuş kapasitesi dolu.');
                }
            } else {
                StateDefinitions::validate($this->getAttributes(), $current?->getAttributes() ?? [], ['decision_code' => LookupGroup::where('code', 'work_decision')->exists() ? 'work_decision' : 'decision']);
                $place = WorkPlace::whereKey($this->work_place_id)->lockForUpdate()->firstOrFail();
                if ($place->institution_id !== $inmate->institution_id || (! $this->ended_at && ! $place->is_active)) {
                    $fail('work_place_id', 'Görev yeri aynı kurumda ve aktif olmalıdır.');
                }
            }
            if (! $this->ended_at || $this->ended_at->gt($this->started_at)) {
                $overlap = static::query()->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))
                    ->when($this->ended_at, fn ($q) => $q->whereDate('started_at', '<', $this->ended_at))
                    ->where(fn ($q) => $q->whereNull('ended_at')->orWhereDate('ended_at', '>', $this->started_at));
                if ((clone $overlap)->where('inmate_file_id', $inmate->id)->exists()) {
                    $fail('inmate_file_id', 'Atama tarihleri mevcut geçmişle çakışıyor.');
                }
                if ($this instanceof HousingAssignment && $this->bed_id && (clone $overlap)->where('bed_id', $this->bed_id)->exists()) {
                    $fail('bed_id', 'Ranza bu tarih aralığında başka bir atamada kullanılıyor.');
                }
            }
            if (! $this->ended_at && static::where('inmate_file_id', $inmate->id)->whereNull('ended_at')->when($this->exists, fn ($q) => $q->whereKeyNot($this->id))->exists()) {
                $fail('inmate_file_id', 'Bu hükümlünün zaten aktif ataması var.');
            }

            return parent::save($options);
        }, 3);
    }
}
