<?php

namespace App\Services\Security;

use App\Models\User;
use App\Services\Imports\ImportTargetGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class InitialAdministrator
{
    public function create(string $username, string $password, ?string $name = null): User
    {
        app(ImportTargetGuard::class)->assertSafe();
        Validator::make(compact('username', 'password', 'name'), [
            'username' => ['required', 'string', 'max:50', 'regex:/^[\pL\pN_.-]+$/uD'],
            'password' => PasswordPolicy::rules(),
            'name' => ['nullable', 'string', 'max:150'],
        ])->validate();

        return DB::transaction(function () use ($username, $password, $name): User {
            $installation = DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            if ($installation === null || $installation->installed_at !== null || User::query()->exists()) {
                throw new RuntimeException('İlk yönetici yalnız hazırlanmış boş veritabanında bir kez oluşturulabilir.');
            }
            $user = new User;
            $user->username = $username;
            $user->full_name = $name;
            $user->password = Hash::make($password);
            $user->is_system_admin = true;
            $user->is_master_admin = MasterAdministrator::fromLegacy($user->getAttributes());
            $user->is_active = true;
            $user->must_change_password = false;
            $user->save();
            DB::table('portal_installations')->where('id', 1)->update(['installed_at' => now()]);

            return $user->refresh();
        });
    }
}
