<?php

namespace App\Services\People;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Institution\InstitutionRecords;
use Illuminate\Support\Facades\DB;

final class HomeSummary
{
    public function read(User $actor): array
    {
        $today = now()->toDateString();
        $statuses = DB::table('person_status_assignments as a')->join('person_statuses as s', 's.id', '=', 'a.status_id')->selectRaw(DB::connection()->getQueryGrammar()->wrap('s.code').', COUNT(*) AS total')->groupBy('s.code')->pluck('total', 'code');
        $matrix = app(PageAccess::class)->displayMatrix($actor);
        $leaves = null;
        if ($actor->is_system_admin || ($matrix['izin_rapor']['giris'] ?? false)) {
            $base = DB::table('person_case_data as c')->join('person_status_assignments as a', 'a.person_id', '=', 'c.person_id')->join('person_statuses as s', 's.id', '=', 'a.status_id');
            $clock = DB::getDriverName() === 'mysql' ? (string) DB::selectOne('SELECT CURRENT_TIME AS clock')->clock : now()->format('H:i:s');
            $leaves = ['departure' => (clone $base)->where('s.included_in_active_list', true)->where('c.izin_cikis_tarihi', $today)->count(), 'return' => (clone $base)->where('s.included_in_active_list', true)->where('c.izin_donus_tarihi', $today)->count(), 'control' => (clone $base)->where('s.code', 'special_leave')->whereNotNull('c.izin_donus_tarihi')->where(fn ($q) => $q->where('c.izin_donus_tarihi', '<', $today)->orWhere(fn ($q) => $q->where('c.izin_donus_tarihi', $today)->whereRaw('? >= ?', [$clock, '14:00:00'])))->count()];
        }
        $duty = [];
        foreach (['mudurnobet', 'infaznobet'] as $kind) {
            $records = DB::table(app(InstitutionRecords::class)->table($kind))->where('kind', $kind)->where('is_active', true)->whereDate('event_on', $today)->orderBy('id')->pluck('values');
            $duty[$kind] = $records->map(fn ($v) => json_decode($v, true)['user_name'] ?? null)->filter()->implode(', ') ?: 'Belirtilmemiş';
        }

        return ['today' => DB::table('people')->where('registered_on', $today)->count(), 'specialLeave' => (int) ($statuses['special_leave'] ?? 0), 'dataChange' => (int) ($statuses['data_change'] ?? 0), 'leaves' => $leaves, 'duty' => $duty];
    }
}
