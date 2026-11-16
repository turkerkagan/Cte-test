<?php

namespace App\Services\Housing;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\People\PersonAccess;
use App\Services\Queries\NaturalOrder;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PersonPlacement
{
    private ?User $batchActor = null;

    public function writeBatch(User $actor, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $previous = $this->batchActor;
            $this->batchActor = app(PersonAccess::class)->authorize($actor, 'yazma', true);
            try {
                return $operation();
            } finally {
                $this->batchActor = $previous;
            }
        }, 3);
    }

    private function writeTransaction(\Closure $operation): mixed
    {
        return $this->batchActor === null ? DB::transaction($operation, 3) : $operation();
    }

    private const FIELDS = ['status_id', 'block_id', 'room_id', 'bed_id'];

    public function detail(User $actor, int $person): array
    {
        return $this->inspect($actor, $person, false);
    }

    public function detailForInterview(User $actor, int $person): array
    {
        return $this->inspect($actor, $person, true);
    }

    private function authorizeContext(User $actor, bool $interview, bool $lock = false): User
    {
        if (! $interview && $this->batchActor?->id === $actor->id && $this->batchActor->session_version === $actor->session_version) {
            return $this->batchActor;
        }

        return $interview ? app(WorkflowAccess::class)->authorize($actor, 'ilkgorus', 'yazma', $lock) : app(PersonAccess::class)->authorize($actor, 'yazma', $lock);
    }

    public function detailForHomeRead(User $actor, int $person): array
    {
        return $this->inspect($actor, $person, false, true);
    }

    private function inspect(User $actor, int $person, bool $interview, bool $readOnly = false): array
    {
        if ($readOnly) {
            app(PersonAccess::class)->authorize($actor);
        } else {
            $this->authorizeContext($actor, $interview);
        }
        $p = DB::table('people')->where('id', $person)->first(['id', 'full_name', 'tc_identity']);
        abort_if($p === null, 404);
        $l = DB::table('person_locations')->where('person_id', $person)->first();
        $a = DB::table('person_status_assignments')->where('person_id', $person)->first();
        $record = ['status_id' => $a === null ? null : (int) $a->status_id, 'block_id' => $l?->block_id === null ? null : (int) $l->block_id, 'room_id' => $l?->room_id === null ? null : (int) $l->room_id, 'bed_id' => $l?->bed_id === null ? null : (int) $l->bed_id, 'revision' => (int) ($l->revision ?? 0), 'status_revision' => (int) ($a->revision ?? 0)];
        $statuses = DB::table('person_statuses')->where('is_active', true)->orWhere('id', $record['status_id'])->orderBy('position')->orderBy('id')->get(['id', 'label', 'is_active']);
        $blocks = DB::table('housing_blocks')->where('is_active', true)->orWhere('id', $record['block_id'])->get(['id', 'name', 'is_active'])->pipe(fn ($rows) => NaturalOrder::rows($rows, ['name', 'id']));
        $rooms = DB::table('housing_rooms as r')->join('housing_blocks as b', 'b.id', '=', 'r.block_id')->where(fn ($q) => $q->where('r.is_active', true)->where('b.is_active', true))->orWhere('r.id', $record['room_id'])->get(['r.id', 'r.block_id', 'r.name', 'r.code', 'r.is_active', 'r.capacity', 'r.bed_count'])->pipe(fn ($rows) => NaturalOrder::rows($rows, ['name', 'id']));
        $occupied = DB::table('housing_occupancies')->pluck('person_id', 'bed_id');
        $present = DB::table('person_locations as l')->join('person_status_assignments as a', 'a.person_id', '=', 'l.person_id')->join('person_statuses as s', 's.id', '=', 'a.status_id')->where('s.counts_room', true)->whereIn('l.room_id', $rooms->pluck('id'))->groupBy('l.room_id')->select('l.room_id')->selectRaw('COUNT(*) as total')->pluck('total', 'room_id');
        $beds = DB::table('housing_beds')->whereIn('room_id', $rooms->pluck('id'))->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $record['bed_id']))->orderBy('number')->get(['id', 'room_id', 'number', 'is_active'])->map(fn ($b) => ['id' => (int) $b->id, 'room_id' => (int) $b->room_id, 'label' => (int) $b->number === 0 ? 'Ranzasız' : (string) $b->number, 'is_active' => (bool) $b->is_active, 'available' => ! isset($occupied[(int) $b->id]) || (int) $occupied[(int) $b->id] === $person, 'is_empty' => ! isset($occupied[(int) $b->id])])->all();

        return ['person' => ['id' => (int) $p->id, 'full_name' => $p->full_name, 'tc_identity' => $p->tc_identity], 'record' => $record, 'statuses' => $statuses->map(fn ($s) => ['id' => (int) $s->id, 'label' => $s->label, 'is_active' => (bool) $s->is_active])->all(), 'blocks' => $blocks->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name, 'is_active' => (bool) $b->is_active])->all(), 'rooms' => $rooms->map(fn ($r) => ['id' => (int) $r->id, 'block_id' => (int) $r->block_id, 'name' => $r->name, 'is_active' => (bool) $r->is_active, 'capacity' => (int) $r->capacity, 'bed_count' => (int) $r->bed_count, 'present' => (int) ($present[$r->id] ?? 0)])->all(), 'beds' => $beds];
    }

    public function summaries(array $ids): array
    {
        return DB::table('people as p')->leftJoin('person_locations as l', 'l.person_id', '=', 'p.id')->leftJoin('person_status_assignments as a', 'a.person_id', '=', 'p.id')->leftJoin('person_statuses as s', 's.id', '=', 'a.status_id')->leftJoin('housing_blocks as b', 'b.id', '=', 'l.block_id')->leftJoin('housing_rooms as r', 'r.id', '=', 'l.room_id')->leftJoin('housing_beds as d', 'd.id', '=', 'l.bed_id')->whereIn('p.id', $ids)->get(['p.id', 's.label as status', 'b.name as block', 'r.name as room', 'd.number'])->mapWithKeys(fn ($r) => [(int) $r->id => ['status' => $r->status, 'block' => $r->block, 'room' => $r->room, 'bed' => $r->number === null ? null : ((int) $r->number === 0 ? 'Ranzasız' : (string) $r->number)]])->all();
    }

    public function save(User $actor, int $person, array $input): array
    {
        return $this->persist($actor, $person, $input, false);
    }

    public function saveForInterview(User $actor, int $person, array $input): array
    {
        return $this->persist($actor, $person, $input, true);
    }

    private function persist(User $actor, int $person, array $input, bool $interview): array
    {
        $this->authorizeContext($actor, $interview);
        $rules = ['record' => ['required', 'array:status_id,block_id,room_id,bed_id,revision,status_revision'], 'record.revision' => ['required', 'integer', 'min:0'], 'record.status_revision' => ['required', 'integer', 'min:0']];
        foreach (self::FIELDS as $field) {
            $rules['record.'.$field] = ['present', 'nullable', 'integer', 'min:1'];
        }
        try {
            $data = Validator::make(['record' => $input], $rules)->validate()['record'];
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($v, $k) => [str_starts_with($k, 'record.') ? substr($k, 7) : $k => $v])->all());
        }
        foreach (self::FIELDS as $field) {
            $data[$field] = $data[$field] === null ? null : (int) $data[$field];
        }

        return $this->writeTransaction(function () use ($actor, $person, $data, $interview) {
            if ($this->batchActor === null) {
                DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            }
            $p = DB::table('people')->where('id', $person)->lockForUpdate()->first();
            $actor = $this->authorizeContext($actor, $interview, true);
            abort_if($p === null, 404);
            $before = DB::table('person_locations')->where('person_id', $person)->lockForUpdate()->first();
            $assigned = DB::table('person_status_assignments')->where('person_id', $person)->lockForUpdate()->first();
            if ((int) ($before->revision ?? 0) !== (int) $data['revision'] || (int) ($assigned->revision ?? 0) !== (int) $data['status_revision']) {
                throw new HousingConflict;
            }
            $old = ['status_id' => $assigned === null ? null : (int) $assigned->status_id, 'block_id' => $before?->block_id === null ? null : (int) $before->block_id, 'room_id' => $before?->room_id === null ? null : (int) $before->room_id, 'bed_id' => $before?->bed_id === null ? null : (int) $before->bed_id];
            $values = array_intersect_key($data, array_flip(self::FIELDS));
            $changed = array_keys(array_filter($values, fn ($v, $k) => $v !== $old[$k], ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return ['revision' => (int) $data['revision'], 'status_revision' => (int) $data['status_revision']];
            }
            $status = $data['status_id'] === null ? null : DB::table('person_statuses')->where('id', $data['status_id'])->lockForUpdate()->first();
            if ($data['status_id'] !== null && ($status === null || (! $status->is_active && $data['status_id'] !== $old['status_id']))) {
                throw ValidationException::withMessages(['status_id' => 'Aktif bir durum seçin.']);
            }
            $block = $data['block_id'] === null ? null : DB::table('housing_blocks')->where('id', $data['block_id'])->lockForUpdate()->first();
            $room = $data['room_id'] === null ? null : DB::table('housing_rooms')->where('id', $data['room_id'])->lockForUpdate()->first();
            $bed = $data['bed_id'] === null ? null : DB::table('housing_beds')->where('id', $data['bed_id'])->lockForUpdate()->first();
            $sameLocation = $data['block_id'] === $old['block_id'] && $data['room_id'] === $old['room_id'] && $data['bed_id'] === $old['bed_id'];
            $oldStatus = $assigned === null ? null : DB::table('person_statuses')->where('id', $assigned->status_id)->first();
            $retained = $sameLocation && (! ($status->counts_room ?? false) || ($oldStatus->counts_room ?? false)) && (! ($status->reserves_bed ?? false) || ($oldStatus->reserves_bed ?? false));
            if ($data['block_id'] !== null && ($block === null || (! $block->is_active && ! $retained))) {
                throw ValidationException::withMessages(['block_id' => 'Aktif bir blok seçin.']);
            }
            if ($data['room_id'] !== null && ($room === null || $block === null || (int) $room->block_id !== $data['block_id'] || (! $room->is_active && ! $retained))) {
                throw ValidationException::withMessages(['room_id' => 'Seçilen bloğa bağlı aktif bir koğuş seçin.']);
            }
            if ($data['bed_id'] !== null && ($bed === null || $room === null || (int) $bed->room_id !== $data['room_id'] || (! $bed->is_active && ! $retained) || ((int) $bed->number === 0 && (int) $room->bed_count !== 0 && ! $retained) || ((int) $bed->number > 0 && (int) $bed->number > (int) $room->bed_count && ! $retained))) {
                throw ValidationException::withMessages(['bed_id' => 'Seçilen koğuşa bağlı uygun bir ranza seçin.']);
            }
            $reserve = $status !== null && $status->reserves_bed && $bed !== null;
            if ($reserve && DB::table('housing_occupancies')->where('bed_id', $data['bed_id'])->where('person_id', '<>', $person)->exists()) {
                throw ValidationException::withMessages(['bed_id' => 'Seçilen ranza başka bir kişiye atanmış.']);
            }
            if ($room !== null && $status?->counts_room && (int) $room->capacity > 0) {
                $count = DB::table('person_locations as l')->join('person_status_assignments as a', 'a.person_id', '=', 'l.person_id')->join('person_statuses as s', 's.id', '=', 'a.status_id')->where('l.room_id', $data['room_id'])->where('l.person_id', '<>', $person)->where('s.counts_room', true)->count();
                if ($count >= (int) $room->capacity) {
                    throw ValidationException::withMessages(['room_id' => 'Koğuş kişi kapasitesi dolu.']);
                }
            }
            DB::table('housing_occupancies')->where('person_id', $person)->delete();
            if ($reserve) {
                DB::table('housing_occupancies')->insert(['person_id' => $person, 'bed_id' => $data['bed_id'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $statusRevision = (int) ($assigned->revision ?? 0);
            if ($data['status_id'] !== $old['status_id']) {
                if ($data['status_id'] === null) {
                    DB::table('person_status_assignments')->where('person_id', $person)->delete();
                    $statusRevision = 0;
                } else {
                    $statusRevision++;
                    DB::table('person_status_assignments')->updateOrInsert(['person_id' => $person], ['status_id' => $data['status_id'], 'revision' => $statusRevision, 'created_at' => $assigned->created_at ?? now(), 'updated_at' => now()]);
                }
            }
            $revision = (int) ($before->revision ?? 0) + 1;
            DB::table('person_locations')->updateOrInsert(['person_id' => $person], ['block_id' => $data['block_id'], 'room_id' => $data['room_id'], 'bed_id' => $data['bed_id'], 'revision' => $revision, 'created_at' => $before->created_at ?? now(), 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'person_placement', $person, 'updated', ['revision' => $revision, 'changed_fields' => $changed]);

            return ['revision' => $revision, 'status_revision' => $statusRevision];
        });
    }
}
