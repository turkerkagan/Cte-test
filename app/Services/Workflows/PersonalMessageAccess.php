<?php

namespace App\Services\Workflows;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PersonalMessageAccess
{
    /** Hiding a message removes that participant's access, without affecting the other participant. */
    public function visibleTo(User $actor): Builder
    {
        return DB::table('personal_messages')->where(function (Builder $query) use ($actor) {
            $query->where(fn (Builder $sender) => $sender->where('sender_id', $actor->id)->where('sender_deleted', false))
                ->orWhere(fn (Builder $recipient) => $recipient->where('recipient_id', $actor->id)->where('recipient_deleted', false));
        });
    }
}
