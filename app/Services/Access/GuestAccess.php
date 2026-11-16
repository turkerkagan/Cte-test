<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class GuestAccess
{
    public function enabled(): bool
    {
        return DB::table('page_permissions as p')->join('portal_pages as g', 'g.id', '=', 'p.page_id')->where('p.target_type', 'misafir')->where('p.permission', 'giris')->where('g.is_active', true)->exists();
    }

    public function account(): User
    {
        abort_unless($this->enabled(), 403, 'Misafir erişimi tanımlanmamış.');

        return DB::transaction(function () {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $user = User::where('is_guest', true)->first();
            if (! $user) {
                $user = new User;
                $user->username = 'guest_'.bin2hex(random_bytes(8));
                $user->full_name = 'Misafir';
                $user->password = Hash::make(bin2hex(random_bytes(32)));
                $user->is_guest = true;
                $user->is_system_admin = false;
                $user->is_active = true;
                $user->must_change_password = false;
                $user->save();
            } elseif (! $user->is_active || $user->must_change_password || $user->is_system_admin) {
                $user->is_active = true;
                $user->must_change_password = false;
                $user->is_system_admin = false;
                $user->save();
            }

            return $user->refresh();
        });
    }

    public function destination(User $guest): string
    {
        foreach (app(PageAccess::class)->matrix($guest) as $page) {
            if ($page['giris']) {
                $record = DB::table('portal_pages')->find($page['page_id']);
                $url = app(PageCatalog::class)->destination($record);
                if ($url) {
                    return $url;
                }
            }
        }
        abort(403, 'Misafir için açılabilir sayfa bulunamadı.');
    }
}
