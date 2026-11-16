<?php

namespace App\Http\Controllers;

use App\Services\Audit\AuditLog;
use App\Services\Modules\ModuleExport;
use App\Services\Queries\NaturalOrder;
use App\Services\Workflows\CaseList;
use App\Services\Workflows\CaseRecords;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class RoomListsController extends Controller
{
    private function data(): array
    {
        $case = app(CaseList::class);
        $options = app(CaseRecords::class)->options()['huk_calis'];
        $workLabels = collect($options)->keyBy('id');
        $people = $case->query()->where('s.included_in_active_list', true)->orderBy('d.number')->orderBy('p.full_name')
            ->get(['p.id as person_id', 'p.full_name', 'p.tc_identity', 'b.name as block', 'r.name as room', 'd.number as bed', 'c.huk_calis', 'l.room_id'])
            ->map(fn ($p) => ['id' => (int) $p->person_id, 'name' => $p->full_name, 'tc' => $p->tc_identity, 'block' => $p->block, 'room' => $p->room, 'bed' => $p->bed, 'work' => $workLabels->get((int) $p->huk_calis)['label'] ?? null, 'room_id' => $p->room_id]);
        $peopleByRoom = $people->groupBy('room_id');
        $rooms = DB::table('housing_rooms as r')->join('housing_blocks as b', 'b.id', '=', 'r.block_id')->where('r.is_active', true)->where('b.is_active', true)->get(['r.*', 'b.name as block'])->pipe(fn ($rows) => NaturalOrder::rows($rows, ['block', 'name', 'id']))->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'code' => $r->code, 'block' => $r->block, 'capacity' => (int) $r->capacity, 'bedCount' => (int) $r->bed_count, 'revision' => (int) $r->revision, 'people' => $peopleByRoom->get($r->id, collect())->values()->all()])->all();
        $known = array_flip(array_column($rooms, 'id'));
        $missing = $people->filter(fn ($p) => ! isset($known[$p['room_id']]))->values()->all();

        return ['rooms' => $rooms, 'unassigned' => $missing];
    }

    public function index(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'koguslar');

        return $this->noStore(Inertia::render('RoomLists', $this->data() + ['applicationName' => config('app.name'), 'title' => 'Koğuş listeleri', 'baseUrl' => route('rooms.index'), 'logoutUrl' => route('logout'), 'canWrite' => app(WorkflowAccess::class)->canWrite($r->user(), 'koguslar')])->toResponse($r));
    }

    public function rename(Request $r)
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:120'], 'revision' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($r, $d) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $a = app(WorkflowAccess::class)->authorize($r->user(), 'koguslar', 'yazma', true);
            $room = DB::table('housing_rooms')->where('id', $r->route('room'))->lockForUpdate()->first();
            abort_if(! $room || ! $room->is_active, 404);
            abort_unless(DB::table('housing_blocks')->where('id', $room->block_id)->where('is_active', true)->exists(), 422);
            abort_if((int) $room->revision !== (int) $d['revision'], 409, 'Koğuş değişti; güncel kaydı yükleyin.');
            $revision = (int) $room->revision;
            if ($room->name !== trim($d['name'])) {
                DB::table('housing_rooms')->where('id', $room->id)->update(['name' => trim($d['name']), 'revision' => ++$revision, 'updated_at' => now()]);
                app(AuditLog::class)->record($a, 'housing_rooms', $room->id, 'renamed', ['revision' => $revision]);
            }

            return response()->json(['revision' => $revision]);
        });
    }

    public function export(Request $r)
    {
        app(WorkflowAccess::class)->authorize($r->user(), 'koguslar');
        $data = $this->data();
        $people = [...array_merge(...array_map(fn ($r) => $r['people'], $data['rooms'])), ...$data['unassigned']];
        abort_if(count($people) > 5000, 422, 'Çıktı en fazla 5000 kişi olabilir.');
        $fields = ['block' => 'Blok', 'room' => 'Koğuş', 'bed' => 'Ranza', 'tc' => 'TC Kimlik', 'name' => 'Adı Soyadı', 'work' => 'Çalıştığı Yer'];
        $snapshot = ['fields' => array_map(fn ($k, $label) => ['id' => $k, 'label' => $label, 'type' => $k === 'bed' ? 'integer' : 'text'], array_keys($fields), array_values($fields)), 'rows' => array_map(fn ($p) => array_map(fn ($k) => (string) ($p[$k] ?? ''), array_keys($fields)), $people)];

        return response(app(ModuleExport::class)->csv($snapshot), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="koguslar.csv"', 'Cache-Control' => 'private, no-store']);
    }
}
