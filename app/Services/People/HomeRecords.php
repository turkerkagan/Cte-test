<?php

namespace App\Services\People;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Audit\AuditLog;
use App\Services\Housing\PersonPlacement;
use App\Services\Queries\NaturalOrder;
use App\Services\Workflows\CaseFields;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The original index form uses homepage permissions, with separate sensitive-field grants. */
final class HomeRecords
{
    private const CASE_FIELDS = ['huk_calis', 'huk_karar', 'huk_saglik', 'huk_notlar', 'huk_risk', 'infaz_dogum_tarihi'];

    public function options(User $actor): array
    {
        $actor = app(PersonAccess::class)->authorize($actor);
        $caseOptions = array_intersect_key(app(CaseRecords::class)->options(), array_flip(self::CASE_FIELDS));
        if (! $this->risk($actor)) {
            unset($caseOptions['huk_risk']);
        }
        $occupied = DB::table('housing_occupancies')->pluck('bed_id')->all();
        $rooms = DB::table('housing_rooms as r')->join('housing_blocks as b', 'b.id', '=', 'r.block_id')->where('r.is_active', true)->where('b.is_active', true)->get(['r.id', 'r.block_id', 'r.name', 'r.capacity', 'r.bed_count']);
        $rooms = NaturalOrder::rows($rooms, ['name', 'id']);
        $beds = DB::table('housing_beds')->where('is_active', true)->whereIn('room_id', $rooms->pluck('id'))->orderBy('number')->get(['id', 'room_id', 'number'])->map(fn ($b) => ['id' => (int) $b->id, 'room_id' => (int) $b->room_id, 'label' => (int) $b->number === 0 ? 'Ranzasız' : (string) $b->number, 'available' => ! in_array($b->id, $occupied)]);

        return ['caseOptions' => $caseOptions, 'riskWritable' => $this->risk($actor, true), 'birthWritable' => $actor->is_system_admin || app(PageAccess::class)->allows($actor, 'infaz', 'yazma'),
            'statuses' => DB::table('person_statuses')->where('is_active', true)->orderBy('position')->get(['id', 'label', 'code', 'included_in_active_list'])->all(),
            'blocks' => DB::table('housing_blocks')->where('is_active', true)->get(['id', 'name'])->pipe(fn ($rows) => NaturalOrder::rows($rows, ['name', 'id']))->all(), 'rooms' => $rooms->all(), 'beds' => $beds->all(), 'registeredOn' => now()->toDateString()];
    }

    private function risk(User $actor, bool $write = false): bool
    {
        return $actor->is_system_admin || app(PageAccess::class)->allows($actor, 'hassas_risk', $write ? 'yazma' : 'giris');
    }

    public function detail(User $actor, int $id): array
    {
        $actor = app(PersonAccess::class)->authorize($actor);
        $person = DB::table('people')->where('id', $id)->first(['id', 'tc_identity', 'full_name', 'registered_on', 'admitted_on', 'revision']);
        abort_if($person === null, 404);
        $identity = (array) $person;
        $fields = self::CASE_FIELDS;
        if (! $this->risk($actor)) {
            $fields = array_diff($fields, ['huk_risk']);
        }
        if (! $actor->is_system_admin && ! app(PageAccess::class)->allows($actor, 'infaz')) {
            $fields = array_diff($fields, ['infaz_dogum_tarihi']);
        }
        $row = DB::table('person_case_data')->where('person_id', $id)->first(array_merge($fields, ['ilkgorus_revision', 'infaz_revision']));
        $case = [];
        foreach ($fields as $field) {
            $case[$field] = $row?->$field;
        }
        $dates = null;
        if ($actor->is_system_admin || app(PageAccess::class)->allows($actor, 'infaz')) {
            $dates = (array) DB::table('person_case_data')->where('person_id', $id)->first(['infaz_ds_tarihi', 'infaz_tahliye_tarihi']);
        }
        $leaves = null;
        if ($actor->is_system_admin || app(PageAccess::class)->allows($actor, 'izin')) {
            $leaves = (array) DB::table('person_case_data')->where('person_id', $id)->first(['izin_cikis_tarihi', 'izin_cikis_saati', 'izin_donus_tarihi', 'izin_donus_saati']);
        }

        return ['writableFields' => array_values(array_filter($fields, fn ($key) => ($key !== 'huk_risk' || $this->risk($actor, true)) && ($key !== 'infaz_dogum_tarihi' || $actor->is_system_admin || app(PageAccess::class)->allows($actor, 'infaz', 'yazma')))), 'canWrite' => app(PersonAccess::class)->canWrite($actor), 'identity' => $identity, 'values' => $case, 'sentenceRevision' => (int) ($row->infaz_revision ?? 0), 'caseRevision' => (int) ($row->ilkgorus_revision ?? 0), 'placement' => app(PersonPlacement::class)->detailForHomeRead($actor, $id), 'dates' => $dates, 'leaves' => $leaves];
    }

    public function save(User $actor, array $input, ?int $id = null): int
    {
        app(PersonAccess::class)->authorize($actor, 'yazma');
        Validator::make($input, ['payload' => ['prohibited']])->validate();
        $data = Validator::make(['form' => $input], ['form' => ['required', 'array:identity,placement,values,caseRevision,sentenceRevision'], 'form.identity' => ['required', 'array'], 'form.placement' => ['required', 'array'], 'form.values' => ['present', 'array:'.implode(',', self::CASE_FIELDS)], 'form.caseRevision' => ['required', 'integer', 'min:0'],
            'form.sentenceRevision' => ['required_with:form.values.infaz_dogum_tarihi', 'integer', 'min:0'], 'form.values.infaz_dogum_tarihi' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d'],
            'form.values.huk_calis' => ['sometimes', 'nullable', 'integer', 'min:1'], 'form.values.huk_karar' => ['sometimes', 'nullable', 'integer', 'min:1'], 'form.values.huk_risk' => ['sometimes', 'nullable', 'integer', 'min:1'], 'form.values.huk_saglik' => ['sometimes', 'nullable', 'string', 'max:255'], 'form.values.huk_notlar' => ['sometimes', 'nullable', 'string', 'max:10000']])->validate()['form'];

        return DB::transaction(function () use ($actor, $data, $id) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $actor = app(PersonAccess::class)->authorize($actor, 'yazma', true);
            if (array_key_exists('huk_risk', $data['values'])) {
                abort_unless($this->risk($actor, true), 403);
            }
            if ($id === null && (int) $data['caseRevision'] !== 0) {
                throw new PersonConflict;
            }
            $id = app(PersonRecords::class)->runtimeSave($actor, $data['identity'], $id);
            $row = DB::table('person_case_data')->where('person_id', $id)->lockForUpdate()->first();
            if ((int) ($row->ilkgorus_revision ?? 0) !== (int) $data['caseRevision']) {
                throw new PersonConflict;
            }
            if (array_key_exists('infaz_dogum_tarihi', $data['values'])) {
                abort_unless($actor->is_system_admin || app(PageAccess::class)->allows($actor, 'infaz', 'yazma'), 403);
                if ((int) ($row->infaz_revision ?? 0) !== (int) ($data['sentenceRevision'] ?? 0)) {
                    throw new PersonConflict;
                }
            }
            $values = $data['values'];
            foreach ($values as $key => &$value) {
                if (in_array($key, ['huk_calis', 'huk_karar', 'huk_risk']) && $value !== null) {
                    $value = (int) $value;
                    $option = DB::table('case_option_items as i')->join('case_option_lists as l', 'l.id', '=', 'i.option_list_id')->where('i.id', $value)->where('l.code', str_replace('_', '-', $key))->first(['i.is_active', 'l.is_active as list_active']);
                    if ($option === null || ((int) ($row->$key ?? 0) !== $value && (! $option->is_active || ! $option->list_active))) {
                        throw ValidationException::withMessages(['values.'.$key => 'Bu alana ait etkin bir seçenek seçin.']);
                    }
                }
            } unset($value);
            app(PersonPlacement::class)->save($actor, $id, $data['placement']);
            $changed = array_keys(array_filter($values, fn ($v, $k) => $v != $row?->$k || ($v === null) !== ($row?->$k === null), ARRAY_FILTER_USE_BOTH));
            if ($changed !== []) {
                $revisions = ['ilkgorus_revision' => (int) ($row->ilkgorus_revision ?? 0) + 1];
                foreach (array_keys(WorkflowAccess::PAGES) as $group) {
                    if (in_array($group, ['personel_kadrosu', 'izin_rapor', 'infaz_sevk_raporu'], true)) {
                        continue;
                    }
                    if (array_intersect($changed, array_keys(app(CaseFields::class)->group($group))) !== []) {
                        $revisions[$group.'_revision'] = (int) ($row->{$group.'_revision'} ?? 0) + 1;
                    }
                }
                DB::table('person_case_data')->updateOrInsert(['person_id' => $id], $values + $revisions + ['created_at' => $row->created_at ?? now(), 'updated_at' => now()]);
                app(AuditLog::class)->record($actor, 'person_home', $id, 'updated', ['changed_fields' => $changed]);
            }

            return $id;
        }, 3);
    }
}
