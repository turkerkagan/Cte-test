<?php

namespace App\Services\Housing;

use App\Services\Queries\NaturalOrder;
use Illuminate\Support\Facades\DB;

final class HousingSummary
{
    // Institution-wide counts: list scope, search and pagination do not change occupancy.
    public function read(): array
    {
        return DB::transaction(fn () => $this->snapshot());
    }

    private function snapshot(): array
    {
        $wrap = fn (string $column) => DB::connection()->getQueryGrammar()->wrap($column);
        $people = DB::table('people as p')->leftJoin('person_status_assignments as a', 'a.person_id', '=', 'p.id')->leftJoin('person_statuses as s', 's.id', '=', 'a.status_id')
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN '.$wrap('s.included_in_active_list').' = 1 THEN 1 ELSE 0 END), 0) as active, COALESCE(SUM(CASE WHEN '.$wrap('a.status_id').' IS NULL THEN 1 ELSE 0 END), 0) as unassigned')->first();
        $counts = DB::table('person_locations as l')->join('person_status_assignments as a', 'a.person_id', '=', 'l.person_id')->join('person_statuses as s', 's.id', '=', 'a.status_id')
            ->where('s.counts_room', true)->whereNotNull('l.room_id')->groupBy('l.room_id')->select('l.room_id')->selectRaw('COUNT(*) as present')->pluck('present', 'room_id');
        $beds = DB::table('housing_beds as d')->leftJoin('housing_occupancies as o', 'o.bed_id', '=', 'd.id')->groupBy('d.room_id')
            ->select('d.room_id')->selectRaw('SUM(CASE WHEN '.$wrap('d.number').' > 0 AND '.$wrap('d.is_active').' = 1 THEN 1 ELSE 0 END) as physical, SUM(CASE WHEN '.$wrap('d.number').' > 0 AND '.$wrap('o.person_id').' IS NOT NULL THEN 1 ELSE 0 END) as reserved, SUM(CASE WHEN '.$wrap('d.number').' = 0 AND '.$wrap('o.person_id').' IS NOT NULL THEN 1 ELSE 0 END) as bedless_reserved')->get()->keyBy('room_id');
        $rooms = DB::table('housing_rooms as r')->join('housing_blocks as b', 'b.id', '=', 'r.block_id')
            ->get(['r.id', 'r.name', 'r.capacity', 'r.is_active', 'b.name as block', 'b.is_active as block_active'])->pipe(fn ($rows) => NaturalOrder::rows($rows, ['block', 'name', 'id']))->map(function ($room) use ($counts, $beds) {
                $bed = $beds[$room->id] ?? null;
                $active = (bool) $room->is_active && (bool) $room->block_active;
                $physical = $active ? (int) ($bed->physical ?? 0) : 0;
                $reserved = (int) ($bed->reserved ?? 0);

                return ['id' => (int) $room->id, 'block' => $room->block, 'room' => $room->name, 'is_active' => $active,
                    'capacity' => (int) $room->capacity, 'present' => (int) ($counts[$room->id] ?? 0), 'physical' => $physical,
                    'reserved' => $reserved, 'available' => max(0, $physical - $reserved), 'bedless_reserved' => (int) ($bed->bedless_reserved ?? 0)];
            })->filter(fn ($room) => $room['is_active'] || $room['present'] > 0 || $room['reserved'] > 0 || $room['bedless_reserved'] > 0)->values();

        return ['total' => (int) $people->total, 'active' => (int) $people->active, 'unassigned' => (int) $people->unassigned,
            'present' => $rooms->sum('present'), 'physical' => $rooms->sum('physical'), 'reserved' => $rooms->sum('reserved'),
            'available' => $rooms->sum('available'), 'bedless_reserved' => $rooms->sum('bedless_reserved'), 'rooms' => $rooms->all()];
    }
}
