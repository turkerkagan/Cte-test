<?php

namespace App\Http\Controllers;

use App\Models\Block;
use App\Models\InmateFile;
use App\Models\OperationRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CountingController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->hasPermission('counting.view'), 403);
        $rows = $this->rows();
        $previous = OperationRecord::module('kogus-sayim-calisma')->where('record_type', 'Sayım anlık görüntüsü')->latest('starts_at')->first();
        $previousRows = collect($previous?->data['rows'] ?? [])->keyBy('ward_id');

        return view('counting.index', [
            'blocks' => $rows->groupBy('block'),
            'totals' => [
                'total' => $rows->sum('total'),
                'leave' => $rows->sum('leave'),
                'ready' => $rows->sum('ready'),
            ],
            'previous' => $previous,
            'differences' => $rows->mapWithKeys(fn (array $row) => [
                $row['ward_id'] => $row['ready'] - (int) data_get($previousRows->get($row['ward_id']), 'ready', $row['ready']),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('counting.update'), 403);
        $rows = $this->rows()->values();

        OperationRecord::create([
            'institution_id' => $request->user()->institution_id,
            'module_code' => 'kogus-sayim-calisma',
            'record_type' => 'Sayım anlık görüntüsü',
            'title' => now()->format('d.m.Y H:i').' kurum sayımı',
            'starts_at' => now(),
            'ends_at' => now(),
            'data' => [
                'rows' => $rows->all(),
                'total' => $rows->sum('total'),
                'leave' => $rows->sum('leave'),
                'ready' => $rows->sum('ready'),
            ],
            'is_published' => false,
            'is_closed' => true,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('counting.index')->with('status', 'Sayım anlık görüntüsü tarihçeye kaydedildi.');
    }

    private function rows()
    {
        return Block::with([
            'wards' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name'),
        ])->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->flatMap(function (Block $block) {
                return $block->wards->map(function ($ward) use ($block): array {
                    $files = InmateFile::where('is_active', true)
                        ->whereHas('activeHousingAssignment', fn ($query) => $query->where('ward_id', $ward->id));
                    $total = (clone $files)->count();
                    $leave = (clone $files)->onLeave()->count();

                    return [
                        'ward_id' => $ward->id,
                        'block' => $block->name,
                        'ward' => $ward->name,
                        'total' => $total,
                        'leave' => $leave,
                        'ready' => $total - $leave,
                    ];
                });
            });
    }
}
