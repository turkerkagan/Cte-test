<?php

namespace App\Http\Controllers;

use App\Services\Housing\PersonPlacement;
use App\Services\People\BulkPreview;
use App\Services\People\PersonAccess;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class BulkStatusController extends Controller
{
    public function index(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'kisayol');

        return Inertia::render('BulkStatus', ['applicationName' => config('app.name'), 'title' => 'Toplu durum değişikliği', 'baseUrl' => route('bulk-status.index'), 'logoutUrl' => route('logout'), 'statuses' => DB::table('person_statuses')->where('is_active', true)->where('included_in_active_list', true)->orderBy('position')->get(['id', 'label']), 'canWrite' => app(PersonAccess::class)->canWrite($r->user())]);
    }

    private function snapshot(int $from): array
    {
        return DB::table('person_status_assignments as a')->join('people as p', 'p.id', '=', 'a.person_id')->leftJoin('person_locations as l', 'l.person_id', '=', 'p.id')->where('a.status_id', $from)->orderBy('p.id')->get(['p.id', 'p.full_name', 'a.revision as status_revision', 'a.status_id', 'l.block_id', 'l.room_id', 'l.bed_id', 'l.revision'])->map(fn ($p) => (array) $p)->all();
    }

    public function mutate(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'kisayol');
        app(PersonAccess::class)->authorize($r->user(), 'yazma');
        $d = $r->validate(['action' => ['required', 'in:preview,apply'], 'from' => ['required', 'integer', 'min:1'], 'to' => ['required', 'integer', 'min:1', 'different:from'], 'token' => ['required_if:action,apply', 'nullable', 'string', 'size:64']]);

        return DB::transaction(function () use ($r, $d) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $a = app(PersonAccess::class)->authorize($r->user(), 'yazma', true);
            app(WorkflowAccess::class)->authorize($a, 'kisayol', 'giris', true);
            $definitions = DB::table('person_statuses')->whereIn('id', [$d['from'], $d['to']])->where('is_active', true)->where('included_in_active_list', true)->orderBy('id')->get();
            abort_unless($definitions->count() === 2, 422, 'Aktif kaynak ve hedef durum seçin.');
            $rows = $this->snapshot($d['from']);
            abort_if(count($rows) > 5000, 422, 'Toplu işlem en fazla 5000 kişi olabilir.');
            $token = hash_hmac('sha256', json_encode([$a->id, $d['from'], $d['to'], $rows, $definitions], JSON_THROW_ON_ERROR), config('app.key'));
            if ($d['action'] === 'preview') {
                return response()->json(['token' => $token, 'count' => count($rows), 'people' => array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['full_name']], $rows)] + app(BulkPreview::class)->statuses($rows, (int) $d['to']));
            }abort_unless(hash_equals($token, $d['token']), 409, 'Kayıtlar değişti; önizlemeyi yenileyin.');
            $placements = app(PersonPlacement::class);
            $placements->writeBatch($a, function () use ($placements, $a, $rows, $d) {
                foreach ($rows as $p) {
                    $placements->save($a, $p['id'], ['status_id' => $d['to'], 'block_id' => $p['block_id'], 'room_id' => $p['room_id'], 'bed_id' => $p['bed_id'], 'revision' => $p['revision'] ?? 0, 'status_revision' => $p['status_revision']]);
                }
            });

            return response()->json(['saved' => true, 'count' => count($rows)]);
        }, 3);
    }
}
