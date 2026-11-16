<?php

namespace App\Services\Housing;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Queries\NaturalOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HousingDefinitions
{
    private ?User $batchActor = null;

    private function writeTransaction(\Closure $operation): mixed
    {
        return $this->batchActor === null ? DB::transaction($operation, 3) : $operation();
    }

    private function actor(User $actor, bool $lock = false): User
    {
        if ($this->batchActor?->id === $actor->id && $this->batchActor->session_version === $actor->session_version) {
            return $this->batchActor;
        }
        $query = User::whereKey($actor->id);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($fresh === null, 403);
        Gate::forUser($fresh)->authorize('manage-housing');

        return $fresh;
    }

    public function snapshot(User $actor): array
    {
        $this->actor($actor);
        $blocks = NaturalOrder::rows(DB::table('housing_blocks')->get(), ['name', 'code', 'id']);
        $blockOrder = array_flip($blocks->pluck('id')->all());
        $rooms = DB::table('housing_rooms')->get()->sort(fn ($a, $b) => ($blockOrder[$a->block_id] <=> $blockOrder[$b->block_id]) ?: NaturalOrder::compare($a->name, $b->name) ?: ($a->id <=> $b->id))->values();
        $occupied = DB::table('housing_occupancies as o')->join('housing_beds as b', 'b.id', '=', 'o.bed_id')->select('b.room_id')->selectRaw('COUNT(*) AS total')->groupBy('b.room_id')->pluck('total', 'room_id');

        return ['blocks' => $blocks->map(fn ($b) => ['id' => (int) $b->id, 'code' => $b->code, 'name' => $b->name, 'is_active' => (bool) $b->is_active, 'revision' => (int) $b->revision])->all(), 'rooms' => $rooms->map(fn ($r) => ['id' => (int) $r->id, 'block_id' => (int) $r->block_id, 'code' => $r->code, 'name' => $r->name, 'bed_count' => (int) $r->bed_count, 'capacity' => (int) $r->capacity, 'kind' => $r->kind, 'is_active' => (bool) $r->is_active, 'revision' => (int) $r->revision, 'occupied_count' => (int) ($occupied[$r->id] ?? 0)])->all()];
    }

    public function bulk(User $actor, array $input): array
    {
        $this->actor($actor);
        $data = Validator::make($input, ['block_code' => ['required', 'string', 'max:40'], 'block_name' => ['required', 'string', 'max:100'], 'first' => ['required', 'integer', 'between:1,9999'], 'count' => ['required', 'integer', 'between:1,500'], 'beds' => ['required', 'integer', 'between:0,500'], 'capacity' => ['required', 'integer', 'between:0,500'], 'kind' => ['required', Rule::in(['normal', 'gecici', 'ozel'])]])->validate();

        return DB::transaction(function () use ($actor, $data) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = $this->actor($actor, true);
            $this->batchActor = $actor;
            try {
                $block = $this->save($actor, 'blocks', ['code' => $data['block_code'], 'name' => $data['block_name'], 'is_active' => true]);
                $rooms = [];
                for ($number = $data['first']; $number < $data['first'] + $data['count']; $number++) {
                    $rooms[] = $this->save($actor, 'rooms', ['code' => (string) $number, 'name' => (string) $number, 'block_id' => $block, 'bed_count' => $data['beds'], 'capacity' => $data['capacity'], 'kind' => $data['kind'], 'is_active' => true]);
                }

                return ['block_id' => $block, 'rooms' => $rooms];
            } finally {
                $this->batchActor = null;
            }
        }, 3);
    }

    public function save(User $actor, string $kind, array $input, ?int $id = null): int
    {
        $this->actor($actor);
        abort_unless(in_array($kind, ['blocks', 'rooms'], true), 404);
        $room = $kind === 'rooms';
        $table = $room ? 'housing_rooms' : 'housing_blocks';
        $allowed = $room ? 'code,name,is_active,revision,block_id,bed_count,capacity,kind' : 'code,name,is_active,revision';
        $rules = ['record' => ['required', 'array:'.$allowed], 'record.code' => ['required', 'string', 'max:'.($room ? 100 : 50)], 'record.name' => ['required', 'string', 'max:120'], 'record.is_active' => ['required', 'boolean'], 'record.revision' => $id === null ? ['prohibited'] : ['required', 'integer', 'min:1']];
        if ($room) {
            $rules += ['record.block_id' => ['required', 'integer', 'min:1'], 'record.bed_count' => ['required', 'integer', 'min:0', 'max:500'], 'record.capacity' => ['required', 'integer', 'min:0', 'max:500'], 'record.kind' => ['required', Rule::in(['normal', 'gecici', 'ozel'])]];
        }
        try {
            $data = Validator::make(['record' => $input], $rules, ['required' => 'Alan zorunludur.', 'array' => 'Yalnız izin verilen ayarları gönderin.', 'max' => 'İzin verilen uzunluk veya sayı sınırı aşıldı.', 'min' => 'Geçerli bir sayı girin.', 'in' => 'Geçerli bir koğuş türü seçin.'])->validate()['record'];
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($v, $k) => [str_starts_with($k, 'record.') ? substr($k, 7) : $k => $v])->all());
        }
        $data['code'] = str_replace(['i', 'ı'], ['İ', 'I'], trim($data['code']));
        $data['code'] = mb_strtoupper($data['code'], 'UTF-8');
        $data['name'] = trim($data['name']);
        if ($data['code'] === '' || $data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Kod ve ad boş olamaz.']);
        }

        return $this->writeTransaction(function () use ($actor, $data, $table, $room, $id) {
            if ($this->batchActor === null) {
                DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            }
            $actor = $this->actor($actor, true);
            $before = $id === null ? null : DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_if($id !== null && $before === null, 404);
            if ($before !== null && (int) $before->revision !== (int) $data['revision']) {
                throw new HousingConflict;
            }
            $block = $room ? DB::table('housing_blocks')->where('id', $data['block_id'])->lockForUpdate()->first() : null;
            if ($room && ($block === null || (! $block->is_active && ($before === null || (int) $before->block_id !== (int) $block->id || $data['is_active'])))) {
                throw ValidationException::withMessages(['block_id' => 'Yeni veya aktif koğuş için aktif bir blok seçin.']);
            }
            $duplicates = DB::table($table)->where('code', $data['code']);
            if ($room) {
                $duplicates->where('block_id', $data['block_id']);
            } if ($id !== null) {
                $duplicates->where('id', '<>', $id);
            }
            if ($duplicates->exists()) {
                throw ValidationException::withMessages(['code' => 'Bu kod zaten kullanılıyor; arşivli tanımları da kontrol edin.']);
            }
            $values = array_intersect_key($data, array_flip($room ? ['code', 'name', 'is_active', 'block_id', 'bed_count', 'capacity', 'kind'] : ['code', 'name', 'is_active']));
            $values['is_active'] = (bool) $values['is_active'];
            if ($room) {
                foreach (['block_id', 'bed_count', 'capacity'] as $key) {
                    $values[$key] = (int) $values[$key];
                }
            }
            $changed = array_keys(array_filter($values, fn ($v, $k) => $before === null || (string) $v !== (string) $before->$k, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $id;
            }
            if ($before !== null) {
                // Lock referenced beds before checking occupants; future assignment writes use the same installation lock.
                $beds = DB::table('housing_beds as b')->join('housing_rooms as r', 'r.id', '=', 'b.room_id');
                $room ? $beds->where('b.room_id', $id) : $beds->where('r.block_id', $id);
                $beds->orderBy('b.id')->lockForUpdate()->get(['b.id']);
                $occupants = DB::table('housing_occupancies as o')->join('housing_beds as b', 'b.id', '=', 'o.bed_id');
                if ($room) {
                    $occupants->where('b.room_id', $id);
                } else {
                    $occupants->join('housing_rooms as r', 'r.id', '=', 'b.room_id')->where('r.block_id', $id);
                }
                $numbers = $occupants->pluck('b.number');
                $locations = DB::table('person_locations as l')->leftJoin('person_status_assignments as a', 'a.person_id', '=', 'l.person_id')->leftJoin('person_statuses as s', 's.id', '=', 'a.status_id');
                $room ? $locations->where('l.room_id', $id) : $locations->where('l.block_id', $id);
                $hasLinked = (clone $locations)->exists();
                $activeLocations = $locations->where('s.counts_room', true)->count();
                if (($room && (int) $before->block_id !== $values['block_id'] && $hasLinked) || (! $values['is_active'] && $activeLocations > 0) || ($room && (($values['capacity'] > 0 && $values['capacity'] < $activeLocations) || ($values['capacity'] === 0 && $values['bed_count'] > 0 && $activeLocations > 0)))) {
                    throw ValidationException::withMessages(['operation' => 'Kişi konumları etkileniyor. Önce uygun yerleşim aktarımı yapılmalıdır.']);
                }
                if ($numbers->isNotEmpty() && (! $values['is_active'] || ($room && ((int) $before->block_id !== $values['block_id'] || ($values['capacity'] > 0 && $numbers->count() > $values['capacity']) || ($values['capacity'] === 0 && $values['bed_count'] > 0) || ($numbers->contains(0) && $values['bed_count'] > 0) || $numbers->max() > $values['bed_count'])))) {
                    throw ValidationException::withMessages(['operation' => 'Dolu yerleşim etkileniyor. Önce kişiler için uygun yerleşim aktarımı yapılmalıdır.']);
                }
            }
            $revision = $before === null ? 1 : (int) $before->revision + 1;
            if ($id === null) {
                $id = DB::table($table)->insertGetId($values + ['revision' => $revision, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table($table)->where('id', $id)->update($values + ['revision' => $revision, 'updated_at' => now()]);
            }
            if ($room) {
                // Never delete/re-number bed IDs. Shrinking archives only the unoccupied upper numbers.
                DB::table('housing_beds')->where('room_id', $id)->update(['is_active' => false]);
                $existing = DB::table('housing_beds')->where('room_id', $id)->pluck('number')->map(fn ($number) => (int) $number)->all();
                $new = [];
                for ($i = $values['bed_count'] === 0 ? 0 : 1; $i <= $values['bed_count']; $i++) {
                    if (! in_array($i, $existing, true)) {
                        $new[] = ['room_id' => $id, 'number' => $i, 'is_active' => true];
                    }
                }
                foreach (array_chunk($new, 200) as $chunk) {
                    DB::table('housing_beds')->insert($chunk);
                }
                DB::table('housing_beds')->where('room_id', $id)->where('number', '<=', $values['bed_count'])->where('number', '>=', $values['bed_count'] === 0 ? 0 : 1)->update(['is_active' => true]);
            }
            app(AuditLog::class)->record($actor, $table, $id, $before === null ? 'created' : 'updated', ['revision' => $revision, 'changed_fields' => $changed]);

            return (int) $id;
        });
    }
}
