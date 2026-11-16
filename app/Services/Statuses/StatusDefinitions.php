<?php

namespace App\Services\Statuses;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StatusDefinitions
{
    private const FLAGS = ['included_in_active_list', 'counts_room', 'reserves_bed'];

    private function actor(User $actor, bool $lock = false): User
    {
        $query = User::whereKey($actor->id);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($fresh === null, 403);
        Gate::forUser($fresh)->authorize('manage-housing');

        return $fresh;
    }

    public function rows(User $actor): array
    {
        $this->actor($actor);

        return DB::table('person_statuses')->select('person_statuses.*')->selectSub(DB::table('person_status_assignments')->selectRaw('COUNT(*)')->whereColumn('status_id', 'person_statuses.id'), 'usage_count')->orderBy('position')->orderBy('id')->get()->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code, 'label' => $r->label, 'included_in_active_list' => (bool) $r->included_in_active_list, 'counts_room' => (bool) $r->counts_room, 'reserves_bed' => (bool) $r->reserves_bed, 'is_active' => (bool) $r->is_active, 'revision' => (int) $r->revision, 'usage_count' => (int) $r->usage_count])->all();
    }

    public function save(User $actor, array $input, ?int $id = null): int
    {
        $this->actor($actor);
        if ($id === null && array_key_exists('revision', $input)) {
            throw ValidationException::withMessages(['revision' => 'Yeni durumda sürüm gönderilmez.']);
        }
        $rules = ['record' => ['required', 'array:code,label,included_in_active_list,counts_room,reserves_bed,is_active,revision'], 'record.code' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/D'], 'record.label' => ['required', 'string', 'max:120'], 'record.revision' => $id === null ? ['prohibited'] : ['required', 'integer', 'min:1']];
        foreach ([...self::FLAGS, 'is_active'] as $flag) {
            $rules['record.'.$flag] = ['required', 'boolean'];
        }
        try {
            $data = Validator::make(['record' => $input], $rules, ['required' => 'Alan zorunludur.', 'boolean' => 'Evet veya hayır seçin.', 'array' => 'Yalnız izin verilen durum alanlarını gönderin.', 'regex' => 'Kod küçük İngilizce harf, rakam ve alt çizgi içermeli.'])->validate()['record'];
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($v, $k) => [str_starts_with($k, 'record.') ? substr($k, 7) : $k => $v])->all());
        }
        $data['label'] = trim($data['label']);
        if ($data['label'] === '') {
            throw ValidationException::withMessages(['label' => 'Durum adı boş olamaz.']);
        }
        foreach ([...self::FLAGS, 'is_active'] as $flag) {
            $data[$flag] = (bool) $data[$flag];
        }

        return DB::transaction(function () use ($actor, $data, $id) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = $this->actor($actor, true);
            $before = $id === null ? null : DB::table('person_statuses')->where('id', $id)->lockForUpdate()->first();
            abort_if($id !== null && $before === null, 404);
            if ($before !== null && (int) $before->revision !== (int) $data['revision']) {
                throw new StatusConflict;
            }
            if ($before !== null && $before->code !== $data['code']) {
                throw ValidationException::withMessages(['code' => 'Durum kodu bağlantıları korumak için değiştirilemez.']);
            }
            $duplicate = DB::table('person_statuses')->where('code', $data['code']);
            if ($id !== null) {
                $duplicate->where('id', '<>', $id);
            }if ($duplicate->exists()) {
                throw ValidationException::withMessages(['code' => 'Bu durum kodu zaten kullanılıyor.']);
            }
            $values = array_intersect_key($data, array_flip(['code', 'label', ...self::FLAGS, 'is_active']));
            $changed = array_keys(array_filter($values, fn ($v, $k) => $before === null || (string) $v !== (string) $before->$k, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return $id;
            }
            $usage = $id === null ? 0 : DB::table('person_status_assignments')->where('status_id', $id)->count();
            if ($usage > 0 && array_intersect($changed, self::FLAGS) !== []) {
                throw ValidationException::withMessages(['operation' => 'Bu durum kişilere bağlı. Davranış değişikliği için ayrı bir durum oluşturup kontrollü aktarım yapın.']);
            }
            $revision = $before === null ? 1 : (int) $before->revision + 1;
            if ($id === null) {
                $id = DB::table('person_statuses')->insertGetId($values + ['revision' => $revision, 'position' => ((int) DB::table('person_statuses')->max('position')) + 1, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('person_statuses')->where('id', $id)->update($values + ['revision' => $revision, 'updated_at' => now()]);
            }
            app(AuditLog::class)->record($actor, 'person_status', $id, $before === null ? 'created' : 'updated', ['revision' => $revision, 'changed_fields' => $changed, 'usage_count' => $usage]);

            return (int) $id;
        }, 3);
    }
}
