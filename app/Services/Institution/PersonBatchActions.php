<?php

namespace App\Services\Institution;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Housing\PersonPlacement;
use App\Services\People\BulkPreview;
use App\Services\People\PersonAccess;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class PersonBatchActions
{
    public function search(User $a, string $text): array
    {
        app(WorkflowAccess::class)->authorize($a, 'uyap_karsilastirma');
        $lines = preg_split('/\R/u', $text);
        abort_if(count($lines) > 500, 422);
        $rows = [];
        foreach (array_unique(array_filter(array_map('trim', $lines))) as $line) {
            $q = app(CaseList::class)->query();
            if (preg_match('/^([0-9]{11})(?:\s+.+)?$/u', $line, $m)) {
                $q->where('p.tc_identity', $m[1]);
            } else {
                $q->where('p.full_name', mb_strtoupper(strtr($line, ['i' => 'İ', 'ı' => 'I']), 'UTF-8'));
            }foreach ($q->leftJoin('case_option_items as work_option', 'work_option.id', '=', 'c.huk_calis')->leftJoin('case_option_items as decision_option', 'decision_option.id', '=', 'c.huk_karar')->limit(501)->get(['p.id', 'p.full_name', 'p.tc_identity', 's.label as status', 'b.name as block', 'r.name as room', 'd.number as bed', 'work_option.label as calisma_yeri', 'decision_option.label as calisma_karari']) as $row) {
                $rows[$row->id] = (array) $row;
            }abort_if(count($rows) > 500, 422, 'Arama en fazla 500 kişi olabilir.');
        }

        return array_values($rows);
    }

    public function mutate(User $a, array $input, ?array $xml = null): array
    {
        $d = Validator::make($input, ['action' => ['required', 'in:status-preview,status-apply,delete-preview,delete-apply'], 'ids' => ['required', 'array', 'list', 'min:1', 'max:500'], 'ids.*' => ['integer', 'distinct', 'min:1'], 'status' => ['required_if:action,status-preview,status-apply', 'nullable', 'integer', 'min:1'], 'token' => ['required_if:action,status-apply,delete-apply', 'nullable', 'string', 'size:64'], 'confirmation' => ['required_if:action,delete-apply', 'nullable', 'in:KALICI SİL']])->validate();
        $delete = str_starts_with($d['action'], 'delete');

        return DB::transaction(function () use ($a, $d, $delete, $xml) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $a = app(PersonAccess::class)->authorize($a, 'yazma', true);
            app(WorkflowAccess::class)->authorize($a, 'uyap_karsilastirma', 'giris', true);
            $ids = array_map('intval', $d['ids']);
            sort($ids);
            $rows = DB::table('people as p')->leftJoin('person_locations as l', 'l.person_id', '=', 'p.id')->leftJoin('person_status_assignments as a', 'a.person_id', '=', 'p.id')->whereIn('p.id', $ids)->orderBy('p.id')->get(['p.id', 'p.revision as person_revision', 'p.full_name', 'p.tc_identity', 'l.block_id', 'l.room_id', 'l.bed_id', 'l.revision', 'a.revision as status_revision', 'a.status_id'])->map(fn ($p) => (array) $p)->all();
            abort_unless(count($rows) === count($ids), 404);
            $target = null;
            if (! $delete) {
                $target = DB::table('person_statuses')->where('id', $d['status'])->where('is_active', true)->where('included_in_active_list', true)->first();
                abort_unless($target !== null, 422, 'Etkin listede kullanılan bir durum seçin.');
            }if ($delete && $xml !== null) {
                $tcs = array_column($xml['records'], 'tc');
                foreach ($rows as $row) {
                    abort_if($row['tc_identity'] !== null && in_array($row['tc_identity'], $tcs, true), 422, 'Seçilen kişi XML listesinde bulunuyor.');
                }
            }if ($delete) {
                abort_if(DB::table('module_records')->whereIn('person_id', $ids)->exists(), 422, 'Kişiye bağlı modül kayıtları var. Bu kayıtlar korunur; kalıcı silme uygulanamaz.');
            }$revisions = $delete ? ['cases' => DB::table('person_case_data')->whereIn('person_id', $ids)->orderBy('person_id')->get()->map(fn ($p) => hash('sha256', json_encode($p, JSON_THROW_ON_ERROR)))->all(), 'notes' => DB::table('person_notes')->whereIn('person_id', $ids)->orderBy('id')->pluck('id')->all(), 'photos' => DB::table('person_photos')->whereIn('person_id', $ids)->orderBy('person_id')->get(['person_id', 'revision'])->all()] : [];
            $token = hash_hmac('sha256', json_encode([$a->id, $delete, $d['status'] ?? null, $target, $rows, $revisions, $xml === null ? null : array_column($xml['records'], 'tc')], JSON_THROW_ON_ERROR), config('app.key'));
            if (str_ends_with($d['action'], 'preview')) {
                return ['token' => $token, 'count' => count($rows), 'people' => array_map(fn ($p) => ['id' => $p['id'], 'tc_identity' => $p['tc_identity'], 'name' => $p['full_name']], $rows)] + ($delete ? [] : app(BulkPreview::class)->statuses($rows, (int) $d['status']));
            }abort_unless(hash_equals($token, $d['token']), 409, 'Kayıtlar değişti; önizlemeyi yenileyin.');
            if (! $delete) {
                $placements = app(PersonPlacement::class);
                $placements->writeBatch($a, function () use ($placements, $a, $rows, $d) {
                    foreach ($rows as $p) {
                        $placements->save($a, $p['id'], ['status_id' => $d['status'], 'block_id' => $p['block_id'], 'room_id' => $p['room_id'], 'bed_id' => $p['bed_id'], 'revision' => $p['revision'] ?? 0, 'status_revision' => $p['status_revision'] ?? 0]);
                    }
                });
            } else {
                $paths = DB::table('person_photos')->whereIn('person_id', $ids)->whereNotNull('path')->pluck('path')->all();
                foreach (['housing_occupancies', 'person_locations', 'person_status_assignments', 'person_case_data', 'person_photos', 'person_notes'] as $table) {
                    DB::table($table)->whereIn('person_id', $ids)->delete();
                }DB::table('people')->whereIn('id', $ids)->delete();
                DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
                app(AuditLog::class)->record($a, 'person_batch', 0, 'permanently_deleted', ['ids' => $ids, 'count' => count($ids)]);
            }

            return ['saved' => true, 'count' => count($rows)];
        }, 3);
    }
}
