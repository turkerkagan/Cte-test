<?php

namespace App\Services\Workflows;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Housing\PersonPlacement;
use App\Services\People\PersonConflict;
use App\Services\Queries\NaturalOrder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CaseRecords
{
    public function detail(User $actor, string $group, int $id): array
    {
        app(WorkflowAccess::class)->authorize($actor, $group);
        $person = DB::table('people')->where('id', $id)->first();
        abort_if($person === null, 404);
        $row = DB::table('person_case_data')->where('person_id', $id)->first();
        $fields = app(CaseFields::class)->visible($actor, $group, true);
        $writableFields = app(CaseFields::class)->writable($actor, $group);
        foreach ($fields as $key => &$field) {
            $field['readonly'] = ! isset($writableFields[$key]);
        } unset($field);
        $options = $this->options();
        foreach ($fields as $key => $field) {
            if ($field['type'] === 'option') {
                $options[$key] = $options[str_replace('-', '_', $field['option_list'] ?? $key)] ?? [];
            }
        }
        $values = [];
        foreach ($fields as $key => $field) {
            $values[$key] = $row->$key ?? $field['default'] ?? null;
            if (($field['secret'] ?? false) && $values[$key] !== null) {
                $values[$key] = Crypt::decryptString($values[$key]);
            }
            if ($field['type'] === 'datetime' && $values[$key] !== null) {
                $values[$key] = str_replace(' ', 'T', substr($values[$key], 0, 16));
            }
            if ($field['type'] === 'option' && $values[$key] !== null) {
                $values[$key] = (int) $values[$key];
            }
        }
        $writable = app(WorkflowAccess::class)->canWrite($actor, $group);

        return ['person' => array_intersect_key((array) $person, array_flip(['id', 'full_name', 'tc_identity', 'admitted_on', 'registered_on', 'revision'])), 'values' => $values, 'revision' => (int) ($row->{$group.'_revision'} ?? 0), 'fields' => $fields, 'options' => $options,
            'canWrite' => $writable, 'placement' => $group === 'ilkgorus' && $writable ? app(PersonPlacement::class)->detailForInterview($actor, $id) : null,
            'photoRevision' => (int) (DB::table('person_photos')->where('person_id', $id)->value('revision') ?? 0)];
    }

    public function options(): array
    {
        return DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')->orderBy('i.position')->orderBy('i.id')->get(['i.id', 'i.label', 'i.legacy_value', 'i.is_active', 'l.is_active as list_active', 'l.code'])
            ->groupBy(fn ($r) => str_replace('-', '_', $r->code))->map(fn ($items) => NaturalOrder::numericChoices($items)->map(fn ($i) => ['id' => (int) $i->id, 'label' => $i->label, 'semantic' => $i->legacy_value, 'is_active' => (bool) $i->is_active && (bool) $i->list_active])->all())->all();
    }

    public function save(User $actor, string $group, int $id, array $input): array
    {
        app(WorkflowAccess::class)->authorize($actor, $group, 'yazma');
        $fields = app(CaseFields::class)->writable($actor, $group);
        foreach (array_keys(is_array($input['values'] ?? null) ? $input['values'] : []) as $key) {
            $all = app(CaseFields::class)->group($group);
            abort_if(isset($all[$key]['permission']) && ! isset($fields[$key]), 403);
        }
        $rules = ['payload' => ['required', 'array:revision,values,identity,placement'], 'payload.revision' => ['required', 'integer', 'min:0'],
            'payload.values' => ['present', 'array:'.implode(',', array_keys($fields))], 'payload.placement' => $group === 'ilkgorus' ? ['sometimes', 'array'] : ['prohibited']];
        foreach ($fields as $key => $f) {
            $rules['payload.values.'.$key] = [($f['required'] ?? false) ? 'required' : 'sometimes', 'nullable', ...match ($f['type']) {
                'integer' => ['integer', 'between:-2147483648,2147483647'], 'decimal' => ['numeric', 'between:-99999999999999,99999999999999'], 'boolean' => ['boolean'], 'datetime' => ['date_format:Y-m-d\TH:i'],
                'option' => ['integer', 'min:1'],
                'ds' => ['string', 'in:0,1,2,3'],
                'date' => ['string', 'regex:/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', 'date_format:Y-m-d'],
                'time' => ['string', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D'],
                default => ['string', 'max:'.($f['max_length'] ?? ($f['type'] === 'longtext' ? 10000 : 255))],
            }];
        }
        $identityFields = $group === 'ilkgorus' ? ['tc_identity', 'full_name'] : ($group === 'infaz' ? ['admitted_on'] : []);
        $rules['payload.identity'] = $identityFields === [] ? ['prohibited'] : ['sometimes', 'array:revision,'.implode(',', $identityFields)];
        $rules['payload.identity.revision'] = ['required_with:payload.identity', 'integer', 'min:1'];
        $rules['payload.identity.tc_identity'] = ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{11}$/D'];
        $rules['payload.identity.full_name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['payload.identity.admitted_on'] = ['sometimes', 'nullable', 'string', 'regex:/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', 'date_format:Y-m-d'];
        $data = Validator::make(['payload' => $input], $rules)->validate()['payload'];
        foreach ($data['values'] as $key => &$value) {
            $f = $fields[$key];
            if ($value === '') {
                $value = null;
            }
            if ($value !== null && in_array($f['type'], ['integer', 'option'], true)) {
                $value = (int) $value;
            }
            if ($f['type'] === 'integer' && $group === 'infaz' && $value === 0) {
                $value = null;
            }
            if ($value !== null && $f['type'] === 'datetime') {
                $value = str_replace('T', ' ', $value).':00';
            }
            if (is_string($value) && ($f['uppercase'] ?? false)) {
                $value = mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $value), 'UTF-8');
            }
            if ($value !== null && $f['type'] === 'time' && strlen($value) === 5) {
                $value .= ':00';
            }
            if ($value === null && in_array($key, ['huk_engelli', 'huk_aile', 'huk_tanidik'], true)) {
                $value = 'YOK';
            }
            if ($value === null && $key === 'huk_notlar') {
                $value = '';
            }
        }
        unset($value);

        return DB::transaction(function () use ($actor, $group, $id, $data, $fields, $identityFields): array {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $person = DB::table('people')->where('id', $id)->lockForUpdate()->first();
            abort_if($person === null, 404);
            $actor = app(WorkflowAccess::class)->authorize($actor, $group, 'yazma', true);
            $freshFields = app(CaseFields::class)->writable($actor, $group);
            foreach (array_keys($data['values']) as $key) {
                abort_unless(isset($freshFields[$key]), 403);
            }
            $row = DB::table('person_case_data')->where('person_id', $id)->lockForUpdate()->first();
            $revisionField = $group.'_revision';
            if ((int) ($row->$revisionField ?? 0) !== (int) $data['revision']) {
                throw new PersonConflict;
            }
            foreach ($data['values'] as $key => $value) {
                if ($fields[$key]['type'] !== 'option' || $value === null) {
                    continue;
                }
                $option = DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')
                    ->where('i.id', $value)->where('l.code', ($fields[$key]['option_list'] ?? str_replace('_', '-', $key)))->first(['i.is_active', 'l.is_active as list_active']);
                $retained = (int) ($row->$key ?? 0) === $value;
                if ($option === null || (! $retained && (! $option->is_active || ! $option->list_active))) {
                    throw ValidationException::withMessages(['values.'.$key => 'Bu alana ait etkin bir seçenek seçin.']);
                }
            }
            if (isset($data['identity'])) {
                if ((int) $person->revision !== (int) $data['identity']['revision']) {
                    throw new PersonConflict;
                }
                $identity = array_intersect_key($data['identity'], array_flip($identityFields));
                if (array_key_exists('full_name', $identity)) {
                    $identity['full_name'] = trim($identity['full_name']);
                    if ($identity['full_name'] === '') {
                        throw ValidationException::withMessages(['identity.full_name' => 'Adı soyadı boş olamaz.']);
                    }
                }
                foreach ($identity as &$v) {
                    if ($v === '') {
                        $v = null;
                    }
                } unset($v);
                $changedIdentity = array_keys(array_filter($identity, fn ($v, $k) => $v !== $person->$k, ARRAY_FILTER_USE_BOTH));
                if ($changedIdentity !== []) {
                    DB::table('people')->where('id', $id)->update($identity + ['revision' => (int) $person->revision + 1, 'updated_by' => $actor->id, 'updated_at' => now()]);
                    app(AuditLog::class)->record($actor, 'person', $id, 'updated', ['changed_fields' => $changedIdentity, 'revision' => (int) $person->revision + 1]);
                }
            }
            if (isset($data['placement'])) {
                $placement = $data['placement'];
                if (($placement['status_id'] ?? null) === null && (($placement['block_id'] ?? null) !== null || ($placement['room_id'] ?? null) !== null)) {
                    $placement['status_id'] = DB::table('person_statuses')->where('code', 'data_change')->value('id');
                }
                app(PersonPlacement::class)->saveForInterview($actor, $id, $placement);
            }
            $stored = [];
            foreach ($data['values'] as $key => $v) {
                $stored[$key] = $row?->$key;
                if (($fields[$key]['secret'] ?? false) && $stored[$key] !== null) {
                    $stored[$key] = Crypt::decryptString($stored[$key]);
                }
            }
            $changed = array_keys(array_filter($data['values'], fn ($v, $k) => $v != $stored[$k] || ($v === null) !== ($stored[$k] === null), ARRAY_FILTER_USE_BOTH));
            $revision = (int) ($row->$revisionField ?? 0);
            if ($changed !== []) {
                $revision++;
                $values = $data['values'];
                foreach ($values as $key => &$v) {
                    if (($fields[$key]['secret'] ?? false) && $v !== null) {
                        $v = Crypt::encryptString($v);
                    }
                } unset($v);
                $revisions = [$revisionField => $revision];
                foreach (array_keys(WorkflowAccess::PAGES) as $other) {
                    if ($other === $group || in_array($other, ['personel_kadrosu', 'izin_rapor', 'infaz_sevk_raporu'], true)) {
                        continue;
                    }
                    if (array_intersect($changed, array_keys(app(CaseFields::class)->group($other))) !== []) {
                        $revisions[$other.'_revision'] = (int) ($row->{$other.'_revision'} ?? 0) + 1;
                    }
                }
                DB::table('person_case_data')->updateOrInsert(['person_id' => $id], $values + $revisions + ['created_at' => $row->created_at ?? now(), 'updated_at' => now()]);
                app(AuditLog::class)->record($actor, 'person_case_'.$group, $id, 'updated', ['revision' => $revision, 'changed_fields' => $changed]);
            }

            return ['revision' => $revision];
        }, 3);
    }

    public function dsPreview(User $actor): array
    {
        app(WorkflowAccess::class)->authorize($actor, 'ilkgorus', 'yazma');
        $rows = DB::table('person_case_data')->whereIn('huk_ds_durum', ['1', '2', '3'])->orderBy('person_id')->get(['person_id', 'ilkgorus_revision']);

        return ['count' => $rows->count(), 'token' => hash_hmac('sha256', $rows->toJson(), config('app.key'))];
    }

    public function dsReset(User $actor, array $input): void
    {
        app(WorkflowAccess::class)->authorize($actor, 'ilkgorus', 'yazma');
        Validator::make($input, ['token' => ['required', 'string', 'size:64'], 'confirm' => ['accepted']])->validate();
        DB::transaction(function () use ($actor, $input): void {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = app(WorkflowAccess::class)->authorize($actor, 'ilkgorus', 'yazma', true);
            $preview = $this->dsPreview($actor);
            if (! hash_equals($preview['token'], $input['token'])) {
                throw new PersonConflict;
            }
            if ($preview['count'] === 0) {
                return;
            }
            DB::table('person_case_data')->whereIn('huk_ds_durum', ['1', '2', '3'])->update(['huk_ds_durum' => '0', 'ilkgorus_revision' => DB::raw('ilkgorus_revision + 1'), 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'person_case_ilkgorus', 0, 'ds_reset', ['count' => $preview['count']]);
        }, 3);
    }
}
