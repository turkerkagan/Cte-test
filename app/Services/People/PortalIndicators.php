<?php

namespace App\Services\People;

use App\Models\User;
use App\Services\Access\PageAccess;
use App\Services\Institution\InstitutionRecords;
use Illuminate\Support\Facades\DB;

final class PortalIndicators
{
    public function read(User $actor): array
    {
        if (! $actor->is_active || $actor->must_change_password) {
            return ['messages' => 0, 'announcements' => 0];
        }
        $matrix = app(PageAccess::class)->displayMatrix($actor);
        $messages = 0;
        $announcements = 0;
        if ($actor->is_system_admin || ($matrix['mesajlar']['giris'] ?? false)) {
            $messages = DB::table('personal_messages')->where('recipient_id', $actor->id)->where('recipient_deleted', false)->whereNull('read_at')->count();
        }
        if ($actor->is_system_admin || ($matrix['duyurular']['giris'] ?? false)) {
            $announcements = DB::table(app(InstitutionRecords::class)->table('duyurular').' as r')->where('kind', 'duyurular')->where('is_active', true)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('announcement_reads as a')->whereColumn('a.record_id', 'r.id')->where('a.record_table', app(InstitutionRecords::class)->table('duyurular'))->where('a.user_id', $actor->id))->count();
        }

        return compact('messages', 'announcements');
    }
}
