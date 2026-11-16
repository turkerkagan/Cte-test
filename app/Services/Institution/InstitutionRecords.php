<?php

namespace App\Services\Institution;

use App\Models\User;
use App\Services\Administration\BoardDefinitions;
use App\Services\Administration\FieldDefinitions;
use App\Services\Audit\AuditLog;
use App\Services\Modules\ModuleExport;
use App\Services\People\PersonConflict;
use App\Services\Queries\NaturalOrder;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\WorkflowAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InstitutionRecords
{
    private ?array $writeContext = null;

    /** Scope caches and authorization to one locked transaction, never to the request lifetime. */
    public function writeBatch(User $actor, string $kind, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $kind, $operation) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $fresh = $this->authorize($actor, $kind, true, true);
            $context = ['actor' => $fresh, 'kind' => $kind, 'table' => $this->table($kind),
                'definition' => $this->definition($kind), 'candidates' => $this->candidates($kind)];
            $previous = $this->writeContext;
            $this->writeContext = $context;
            try {
                return $operation();
            } finally {
                $this->writeContext = $previous;
            }
        }, 3);
    }

    private function writeTransaction(\Closure $operation): mixed
    {
        return $this->writeContext === null ? DB::transaction($operation, 3) : $operation();
    }

    public function table(string $kind): string
    {
        if (($this->writeContext['kind'] ?? null) === $kind) {
            return $this->writeContext['table'];
        }
        $table = DB::table('portal_pages')->where('code', $kind)->value('data_table') ?: 'institution_records';
        abort_unless(preg_match('/^[a-z][a-z0-9_]*$/D', $table), 422);

        return $table;
    }

    public function definitions(): array
    {
        return json_decode(file_get_contents(resource_path('defaults/institution-workflows.json')), true, 64, JSON_THROW_ON_ERROR);
    }

    public function definition(string $kind): array
    {
        if (($this->writeContext['kind'] ?? null) === $kind) {
            return $this->writeContext['definition'];
        }
        abort_unless(isset($this->definitions()[$kind]), 404);

        $definition = $this->definitions()[$kind];
        if ($kind === 'kurul') {
            $definition['fields'] = app(BoardDefinitions::class)->fields();
        }
        $definition['fields'] = app(FieldDefinitions::class)->all($definition['fields'], $kind);

        return $definition;
    }

    public function canWrite(User $a, string $kind): bool
    {
        return app(WorkflowAccess::class)->canWrite($a, $kind) && (! ($this->definition($kind)['adminOnly'] ?? false) || User::find($a->id)?->is_system_admin);
    }

    public function authorize(User $a, string $kind, bool $write = false, bool $lock = false): User
    {
        if ($write && ($this->writeContext['kind'] ?? null) === $kind && $this->writeContext['actor']->id === $a->id && $this->writeContext['actor']->session_version === $a->session_version) {
            return $this->writeContext['actor'];
        }
        $actor = app(WorkflowAccess::class)->authorize($a, $kind, $write ? 'yazma' : 'giris', $lock);
        abort_if($write && ($this->definition($kind)['adminOnly'] ?? false) && ! $actor->is_system_admin, 403);

        return $actor;
    }

    public function candidates(string $kind): array
    {
        if (($this->writeContext['kind'] ?? null) === $kind) {
            return $this->writeContext['candidates'];
        }
        $q = DB::table('users as u')->leftJoin('departments as d', 'd.id', '=', 'u.department_id')->leftJoin('job_titles as j', 'j.id', '=', 'u.job_title_id')->where('u.is_active', true);
        if ($kind === 'mudurnobet') {
            $q->where('j.is_active', true)->whereIn('j.code', ['idareci', 'kurum_muduru', 'kurum_ikinci_muduru']);
        }
        if ($kind === 'vardiyanobet') {
            $q->where('d.is_active', true)->where('d.code', 'vardiya')->where('j.is_active', true)->whereIn('j.code', ['memur', 'infaz_koruma', 'infaz_koruma_basmemuru', 'sorumlu_infaz_koruma_basmemuru']);
        }
        if ($kind === 'infaznobet') {
            $q->where('d.is_active', true)->where('d.code', 'infaz');
        }

        return $q->orderBy('u.full_name')->orderBy('u.id')->get(['u.id', 'u.full_name as name'])->map(fn ($r) => (array) $r)->all();
    }

    public function rooms(): array
    {
        return DB::table('housing_rooms as r')->join('housing_blocks as b', 'b.id', '=', 'r.block_id')->where('r.is_active', true)->where('b.is_active', true)->get(['r.id', 'b.name as block', 'b.code as block_code', 'r.name'])->pipe(fn ($rows) => NaturalOrder::rows($rows, ['block', 'name', 'id']))->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->block.' · '.$r->name, 'block' => $r->block_code])->all();
    }

    private function query(string $kind, array $input)
    {
        if ($kind === 'kismiarama') {
            $input['from'] = ($input['from'] ?? '') ?: now()->startOfYear()->toDateString();
            $input['to'] = ($input['to'] ?? '') ?: now()->toDateString();
        }
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:255'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1'], 'room' => ['nullable', 'integer', 'min:1'], 'block' => ['nullable', 'string', 'max:100']])->validate();
        $state = $input['state'] ?? 'active';
        abort_unless(in_array($state, ['active', 'archived'], true), 422);
        if ($kind === 'telefon') {
            $data['state'] = $state;
        }
        $q = DB::table($this->table($kind))->where('kind', $kind)->where('is_active', $state === 'active');
        if (($data['q'] ?? '') !== '') {
            $q->where('values', 'like', '%'.$data['q'].'%');
        }
        if (! empty($data['from'])) {
            $q->where('event_on', '>=', $data['from'].' 00:00:00');
        }
        if (! empty($data['to'])) {
            $q->where('event_on', '<', CarbonImmutable::parse($data['to'])->addDay()->format('Y-m-d').' 00:00:00');
        }
        if (! empty($data['room'])) {
            $q->where('values->room_id', (int) $data['room']);
        }
        if ($kind === 'kismiarama' && ! empty($data['block'])) {
            $roomIds = DB::table('housing_rooms as r')->join('housing_blocks as b', 'b.id', '=', 'r.block_id')->where('b.code', $data['block'])->pluck('r.id');
            $q->where(fn ($q) => $q->where('values->room_block', $data['block'])
                ->orWhere(fn ($q) => $q->whereNull('values->room_block')->whereIn('values->room_id', $roomIds)));
        }

        return [$q, $data];
    }

    public function read(User $actor, string $kind, array $input = [], bool $export = false): array
    {
        $actor = $this->authorize($actor, $kind);
        if (($input['state'] ?? 'active') !== 'active') {
            abort_unless($kind === 'telefon' && $actor->can('manage-modules'), 403);
        }
        [$q,$state] = $this->query($kind, $input);
        $count = (clone $q)->count();
        if ($export && $count > ModuleExport::ROW_LIMIT) {
            throw ValidationException::withMessages(['export' => 'En fazla 5000 kayıt dışa aktarılabilir.']);
        }
        $perPage = $kind === 'kismiarama' ? 200 : 50;
        $page = min((int) ($state['page'] ?? 1), max(1, (int) ceil($count / $perPage)));
        $summary = null;
        if ($kind === 'kismiarama') {
            $valid = (clone $q)->whereNull('values->cancelled_at');
            $summary = ['valid' => (clone $valid)->count(), 'rooms' => (clone $valid)->distinct()->count('values->room_id'), 'cancelled' => (clone $q)->whereNotNull('values->cancelled_at')->count()];
        }
        if ($kind === 'duyurular') {
            $q->orderByDesc('created_at')->orderByDesc('id');
        } elseif ($kind === 'kismiarama') {
            $q->orderByDesc('event_on')->orderByDesc('id');
        } else {
            $q->orderBy('event_on')->orderBy('position')->orderBy('id');
        }
        $records = $q->when(! $export, fn ($q) => $q->offset(($page - 1) * $perPage))->limit($export ? ModuleExport::ROW_LIMIT : $perPage)->get();
        $definition = $this->definition($kind);
        $reads = $kind === 'duyurular' ? DB::table('announcement_reads')->where('record_table', $this->table($kind))->where('user_id', $actor->id)->whereIn('record_id', $records->pluck('id'))->pluck('record_id')->all() : [];
        $optionIds = [];
        foreach ($records as $record) {
            $values = json_decode($record->values, true, 64, JSON_THROW_ON_ERROR);
            foreach ($definition['fields'] as $key => $field) {
                if ($field['type'] === 'option' && isset($values[$key])) {
                    $optionIds[] = $values[$key];
                }
            }
        }
        $labels = $optionIds === [] ? [] : DB::table('case_option_items')->whereIn('id', array_unique($optionIds))->pluck('label', 'id')->all();
        $candidates = $this->candidates($kind);
        $rooms = $this->rooms();
        $authors = DB::table('users')->whereIn('id', $records->pluck('created_by')->filter())->pluck('full_name', 'id');
        $rows = $records->map(function ($r) use ($definition, $reads, $candidates, $rooms, $authors, $labels) {
            $values = json_decode($r->values, true, 64, JSON_THROW_ON_ERROR);
            $display = [];
            foreach ($definition['fields'] as $key => $f) {
                $value = $values[$key] ?? '';
                if ($f['type'] === 'user') {
                    $value = $values['user_name'] ?? (collect($candidates)->firstWhere('id', $value)['name'] ?? '');
                }if ($f['type'] === 'room') {
                    $value = $values['room_name'] ?? (collect($rooms)->firstWhere('id', $value)['name'] ?? '');
                }if ($f['type'] === 'option') {
                    $value = $labels[$value] ?? $value;
                }
                if ($f['type'] === 'boolean') {
                    $value = $value ? 'Evet' : 'Hayır';
                }
                $display[$key] = (string) $value;
            }

            return ['id' => (int) $r->id, 'revision' => (int) $r->revision, 'is_active' => (bool) $r->is_active, 'values' => $values, 'display' => $display, 'author' => $authors[$r->created_by] ?? 'Kurum Duyurusu', 'createdAt' => $r->created_at, 'isRead' => in_array($r->id, $reads, true)];
        })->all();
        $unavailable = $kind === 'infaznobet' ? DB::table('duty_unavailable')->orderBy('date')->limit(1000)->get()->all() : [];

        return ['title' => $definition['label'], 'kind' => $kind, 'fields' => $definition['fields'], 'options' => app(CaseRecords::class)->options(), 'rows' => $rows, 'candidates' => $candidates, 'rooms' => $rooms, 'unavailable' => $unavailable, 'queryState' => $state, 'canWrite' => $this->canWrite($actor, $kind), 'pagination' => ['page' => $page, 'pages' => max(1, (int) ceil($count / $perPage)), 'total' => $count], ...($kind === 'kismiarama' ? ['summary' => $summary, 'defaultDateTime' => now()->format('Y-m-d\TH:i')] : [])];
    }

    public function save(User $a, string $kind, ?int $id, array $input): array
    {
        $this->authorize($a, $kind, true);
        $fields = array_filter($this->definition($kind)['fields'], fn ($f) => ! ($f['readonly'] ?? false) && ($f['form_visible'] ?? true));
        $rules = ['payload' => ['required', 'array:revision,values'], 'payload.revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'], 'payload.values' => ['required', 'array:'.implode(',', array_keys($fields))]];
        foreach ($fields as $key => $f) {
            $rules['payload.values.'.$key] = [$id === null && ($f['required'] ?? false) ? 'required' : 'sometimes', ...(($f['required'] ?? false) ? [] : ['nullable']), ...match ($f['type']) {
                'boolean' => ['boolean'], 'decimal' => ['numeric', 'between:-99999999999999,99999999999999'], 'option' => ['integer', 'min:1'], 'time' => ['date_format:H:i'], 'integer','user','room' => ['integer', 'between:0,2147483647'],'date' => ['date_format:Y-m-d'],'datetime' => ['date_format:Y-m-d\TH:i'],default => ['string', 'max:'.($f['max_length'] ?? ($f['type'] === 'longtext' ? 10000 : ($key === 'staff' ? 500 : 255)))]
            }];
        }
        $data = Validator::make(['payload' => $input], $rules)->validate()['payload'];

        return $this->writeTransaction(function () use ($a, $kind, $id, $data) {
            if ($this->writeContext === null) {
                DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            }
            $a = $this->authorize($a, $kind, true, true);
            $row = $id === null ? null : DB::table($this->table($kind))->where('kind', $kind)->where('id', $id)->where('is_active', true)->lockForUpdate()->first();
            abort_if($id !== null && $row === null, 404);
            if ($row !== null && (int) $row->revision !== (int) $data['revision']) {
                throw new PersonConflict;
            }
            if ($kind === 'kismiarama' && $row !== null) {
                throw ValidationException::withMessages(['operation' => 'Arama tutanağı değiştirilemez; gerekçe ile iptal edin.']);
            }
            $before = $row === null ? [] : json_decode($row->values, true, 64, JSON_THROW_ON_ERROR);
            $defaults = [];
            foreach ($this->definition($kind)['fields'] as $key => $field) {
                if (isset($field['default'])) {
                    $defaults[$key] = $field['default'];
                }
            }
            $values = array_replace($defaults, $before, $data['values']);
            foreach ($this->definition($kind)['fields'] as $key => $field) {
                if ($field['type'] === 'option' && ! empty($values[$key])) {
                    abort_unless(DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')->where('i.id', $values[$key])->where('l.code', $field['option_list'] ?? str_replace('_', '-', $key))->exists(), 422, 'Seçenek listesine ait değer seçin.');
                }
            }
            if (in_array($kind, ['mudurnobet', 'vardiyanobet', 'infaznobet'], true)) {
                if (($values['user_id'] ?? null) !== null) {
                    $user = collect($this->candidates($kind))->firstWhere('id', (int) $values['user_id']);
                    if ($user === null) {
                        throw ValidationException::withMessages(['user_id' => 'Bu nöbet için etkin ve uygun personel seçin.']);
                    }$values['user_id'] = (int) $user['id'];
                    $values['user_name'] = $user['name'];
                } else {
                    $values['user_name'] = '';
                }
            }
            if ($kind === 'vardiyanobet') {
                foreach (['night' => 1, 'leave1' => 2, 'leave2' => 3] as $key => $days) {
                    $values[$key] = CarbonImmutable::parse($values['date'])->addDays($days)->format('Y-m-d');
                }
            }
            if ($kind === 'kismiarama') {
                $room = collect($this->rooms())->firstWhere('id', (int) $values['room_id']);
                if ($room === null) {
                    throw ValidationException::withMessages(['room_id' => 'Etkin bir koğuş seçin.']);
                }if (! in_array($values['result'], ['Olumsuzluk yok', 'Olumsuzluk tespit edildi', 'İşlem tamamlanamadı'], true)) {
                    throw ValidationException::withMessages(['result' => 'Geçerli bir sonuç seçin.']);
                }$values['room_id'] = (int) $room['id'];
                $values['room_name'] = $room['name'];
                $values['room_block'] = $room['block'];
                $values['created_by_name'] = $a->full_name ?: $a->username;
            }
            $key = match ($kind) {
                'mudurnobet','vardiyanobet','infaznobet' => $values['date'],'kurul' => (string) $values['position'],default => null
            };
            if ($key !== null && DB::table($this->table($kind))->where('kind', $kind)->where('natural_key', $key)->when($id !== null, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['date' => 'Bu gün veya sıra için kayıt var.']);
            }
            if ($row !== null && $values == $before) {
                return ['id' => $id, 'revision' => (int) $row->revision];
            }
            $revision = (int) ($row->revision ?? 0) + 1;
            $write = ['values' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'natural_key' => $key, 'position' => (int) ($values['position'] ?? 0), 'event_on' => isset($values['date']) ? str_replace('T', ' ', $values['date']).(strlen($values['date']) === 10 ? ' 00:00:00' : ':00') : null, 'revision' => $revision, 'updated_at' => now()];
            if ($id === null) {
                $id = DB::table($this->table($kind))->insertGetId($write + ['kind' => $kind, 'created_by' => $a->id, 'created_at' => now()]);
            } else {
                DB::table($this->table($kind))->where('id', $id)->update($write);
            }
            app(AuditLog::class)->record($a, 'institution_'.$kind, $id, $row === null ? 'created' : 'updated', ['revision' => $revision, 'changed_fields' => array_keys($data['values'])]);

            return ['id' => $id, 'revision' => $revision];
        });
    }

    public function archive(User $a, string $kind, int $id, int $revision, ?string $reason = null): void
    {
        $this->authorize($a, $kind, true);
        if ($kind === 'kismiarama') {
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500', 'regex:/\S/u']])->validate();
        }
        DB::transaction(function () use ($a, $kind, $id, $revision, $reason) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $a = $this->authorize($a, $kind, true, true);
            $r = DB::table($this->table($kind))->where('kind', $kind)->where('id', $id)->lockForUpdate()->first();
            abort_if($r === null, 404);
            if ((int) $r->revision !== $revision) {
                throw new PersonConflict;
            }
            abort_unless((bool) $r->is_active, 409);
            $values = json_decode($r->values, true, 64, JSON_THROW_ON_ERROR);
            if ($kind === 'kismiarama') {
                abort_if(isset($values['cancelled_at']), 409);
                $values += ['cancel_reason' => $reason, 'cancelled_at' => now()->format('Y-m-d H:i:s'), 'cancelled_by' => $a->full_name ?: $a->username];
            }DB::table($this->table($kind))->where('id', $id)->update(['is_active' => $kind === 'kismiarama', 'values' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'revision' => $revision + 1, 'updated_at' => now()]);
            app(AuditLog::class)->record($a, 'institution_'.$kind, $id, $kind === 'kismiarama' ? 'cancelled' : 'archived', ['revision' => $revision + 1]);
        }, 3);
    }

    public function restore(User $actor, string $kind, int $id, int $revision): void
    {
        abort_unless($kind === 'telefon', 404);
        DB::transaction(function () use ($actor, $kind, $id, $revision) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = $this->authorize($actor, $kind, true, true);
            abort_unless($actor->can('manage-modules'), 403);
            $row = DB::table($this->table($kind))->where('kind', $kind)->where('id', $id)->lockForUpdate()->first();
            abort_if($row === null, 404);
            if ((int) $row->revision !== $revision) {
                throw new PersonConflict;
            }
            abort_if((bool) $row->is_active, 409, 'Kayıt zaten aktif.');
            DB::table($this->table($kind))->where('id', $id)->update(['is_active' => true, 'revision' => $revision + 1, 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'institution_telefon', $id, 'restored', ['revision' => $revision + 1]);
        }, 3);
    }

    public function receipt(User $a, ?int $id, bool $read): void
    {
        DB::transaction(function () use ($a, $id, $read) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $this->authorize($a, 'duyurular', false, true);
            $q = DB::table($this->table('duyurular'))->where('kind', 'duyurular')->where('is_active', true);
            if ($id !== null) {
                $q->where('id', $id);
            }$ids = $q->pluck('id');
            abort_if($id !== null && $ids->isEmpty(), 404);
            foreach ($ids as $record) {
                if ($read) {
                    DB::table('announcement_reads')->updateOrInsert(['record_table' => $this->table('duyurular'), 'record_id' => $record, 'user_id' => $a->id], []);
                } else {
                    DB::table('announcement_reads')->where('record_table', $this->table('duyurular'))->where('record_id', $record)->where('user_id', $a->id)->delete();
                }
            }
        }, 3);
    }

    public function snapshot(array $result, bool $csv = false): array
    {
        $fields = collect($result['fields'])->map(fn ($f, $k) => ['id' => $k, ...$f])->values()->all();
        $rows = array_map(fn ($r) => array_values($r['display']), $result['rows']);
        $snapshot = ['title' => $result['title'], 'fields' => $fields, 'rows' => $rows, 'queryState' => $result['queryState']];
        if ($csv && $result['kind'] === 'kismiarama') {
            $labels = ['No', 'Tarih Saat', 'Blok', 'Koğuş', 'Görevliler', 'Sonuç', 'Açıklama', 'Kaydeden', 'İptal Tarihi', 'İptal Nedeni'];
            $snapshot['includeSequence'] = false;
            $snapshot['fields'] = array_map(fn ($label, $index) => ['id' => 'partial_'.$index, 'label' => $label, 'type' => $index === 0 ? 'integer' : 'text'], $labels, array_keys($labels));
            $snapshot['rows'] = array_map(function ($row) {
                $v = $row['values'];
                $room = explode(' · ', $row['display']['room_id'] ?? '', 2);

                return [(string) $row['id'], str_replace('T', ' ', (string) ($v['date'] ?? '')).(! empty($v['date']) && strlen($v['date']) === 16 ? ':00' : ''), (string) ($v['room_block'] ?? ''), end($room), (string) ($v['staff'] ?? ''), (string) ($v['result'] ?? ''), (string) ($v['note'] ?? ''), (string) ($v['created_by_name'] ?? ''), (string) ($v['cancelled_at'] ?? ''), (string) ($v['cancel_reason'] ?? '')];
            }, $result['rows']);
        }
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > ModuleExport::BYTE_LIMIT) {
            throw ValidationException::withMessages(['export' => 'Çıktı en fazla 10 MB olabilir.']);
        }

        return $snapshot;
    }
}
