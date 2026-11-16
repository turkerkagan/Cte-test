<?php

namespace App\Services\People;

use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Shared person identity only. Placement, sensitive notes and unit records have separate contracts. */
final class PersonRecords
{
    private const FIELDS = ['tc_identity', 'full_name', 'admitted_on', 'registered_on'];

    public function detail(User $actor, int $id): array
    {
        $this->actor($actor);

        return $this->detailData($id);
    }

    public function runtimeDetail(User $actor, int $id): array
    {
        app(PersonAccess::class)->authorize($actor, 'yazma');

        return $this->detailData($id);
    }

    private function detailData(int $id): array
    {
        $row = DB::table('people')->where('id', $id)->first();
        abort_if($row === null, 404);

        return ['id' => (int) $row->id, 'revision' => (int) $row->revision,
            ...collect(self::FIELDS)->mapWithKeys(fn ($field) => [$field => $row->$field])->all()];
    }

    public function save(User $actor, array $input, ?int $id = null): int
    {
        Gate::forUser($actor)->authorize('manage-people');

        return $this->persist($actor, $input, $id, false);
    }

    public function runtimeSave(User $actor, array $input, ?int $id = null): int
    {
        app(PersonAccess::class)->authorize($actor, 'yazma');

        return $this->persist($actor, $input, $id, true);
    }

    private function persist(User $actor, array $input, ?int $id, bool $runtime): int
    {
        if ($id === null && array_key_exists('revision', $input)) {
            throw ValidationException::withMessages(['revision' => 'Yeni kayıtta revision gönderilmez.']);
        }
        $rules = ['record' => ['required', 'array:tc_identity,full_name,admitted_on,registered_on,revision'],
            'record.tc_identity' => ['present', 'nullable', 'string', 'regex:/^[0-9]{11}$/D'],
            'record.full_name' => ['required', 'string', 'max:255'],
            'record.revision' => $id === null ? ['prohibited'] : ['required', 'integer', 'min:1']];
        foreach (['admitted_on', 'registered_on'] as $field) {
            $rules['record.'.$field] = ['present', 'nullable', 'string', 'regex:/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', 'date_format:Y-m-d'];
        }
        try {
            $data = Validator::make(['record' => $input], $rules, ['required' => 'Alan zorunludur.', 'present' => 'Alan gönderilmelidir.', 'record.tc_identity.regex' => 'TC Kimlik 11 rakam içermeli.', 'regex' => 'Tarih YYYY-MM-DD biçiminde olmalı.', 'date_format' => 'Geçerli bir takvim tarihi girin.', 'max' => 'Adı soyadı en fazla 255 karakter olabilir.', 'array' => 'Yalnız izin verilen kayıt alanlarını gönderin.', 'integer' => 'Kayıt sürümü tam sayı olmalı.'])->validate()['record'];
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(fn ($messages, $key) => [str_starts_with($key, 'record.') ? substr($key, 7) : $key => $messages])->all());
        }
        $data['full_name'] = trim($data['full_name']);
        if ($data['full_name'] === '') {
            throw ValidationException::withMessages(['full_name' => 'Adı soyadı boş olamaz.']);
        }
        // Form blanks become null for new writes; a future legacy adapter must preserve raw representations.
        foreach (['tc_identity', 'admitted_on', 'registered_on'] as $field) {
            $data[$field] = $data[$field] === '' ? null : $data[$field];
        }

        return DB::transaction(function () use ($actor, $data, $id, $runtime): int {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $before = $id === null ? null : DB::table('people')->where('id', $id)->lockForUpdate()->first();
            $actor = $runtime ? app(PersonAccess::class)->authorize($actor, 'yazma', true) : $this->actor($actor, true);
            abort_if($id !== null && $before === null, 404);
            if ($before !== null && (int) $before->revision !== (int) $data['revision']) {
                throw new PersonConflict;
            }
            $values = array_intersect_key($data, array_flip(self::FIELDS));
            $changed = array_values(array_filter(self::FIELDS, fn ($field) => $before === null || $values[$field] !== $before->$field));
            if ($changed === []) {
                return (int) $id;
            }
            $revision = $before === null ? 1 : (int) $before->revision + 1;
            $values += ['revision' => $revision, 'updated_by' => $actor->id, 'updated_at' => now()];
            if ($before === null) {
                $id = DB::table('people')->insertGetId($values + ['created_by' => $actor->id, 'created_at' => now()]);
            } else {
                DB::table('people')->where('id', $id)->update($values);
            }
            // Audit identifies changed fields; names, TC and date values never enter the event payload.
            app(AuditLog::class)->record($actor, 'person', $id, $before === null ? 'created' : 'updated', ['revision' => $revision, 'changed_fields' => $changed]);

            return (int) $id;
        }, 3);
    }

    private function actor(User $actor, bool $lock = false): User
    {
        $query = User::query()->whereKey($actor->id);
        $fresh = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($fresh === null, 403);
        Gate::forUser($fresh)->authorize('manage-people');

        return $fresh;
    }
}
