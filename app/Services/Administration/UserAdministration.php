<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Operations\PrivateFileCleanup;
use App\Services\Security\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UserAdministration
{
    public function save(User $actor, array $input, ?int $id = null): User
    {
        Gate::forUser($actor)->authorize('manage-users');
        $data = $this->validate($input, [
            'username' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\pL\pN_.-]+$/uD', Rule::unique('users', 'username')->ignore($id)],
            'registry_number' => ['nullable', 'string', 'max:50'], 'full_name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:100'], 'phone' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer', 'min:1'], 'job_title_id' => ['nullable', 'integer', 'min:1'],
            'level' => ['required', Rule::in(['system_admin', 'personnel'])], 'is_active' => ['required', 'boolean'],
            'revision' => [$id === null ? 'nullable' : 'required', 'integer', 'min:1'],
            ...($id === null ? ['password' => [...PasswordPolicy::rules(), 'confirmed']] : []),
        ]);

        return DB::transaction(function () use ($actor, $data, $id): User {
            $this->lockAdministration();
            $snapshot = $id === null ? null : User::query()->findOrFail($id);
            foreach (['department_id' => 'departments', 'job_title_id' => 'job_titles'] as $key => $table) {
                $target = isset($data[$key]) ? (int) $data[$key] : null;
                $previous = $snapshot?->$key;
                $definitions = DB::table($table)->whereIn('id', array_filter([$target, $previous]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($target !== null && (! $definitions->has($target) || (! $definitions[$target]->is_active && $target !== $previous))) {
                    throw ValidationException::withMessages([$key => 'Mevcut ve aktif bir tanım seçin.']);
                }
            }
            $locked = User::query()->whereIn('id', array_filter([$actor->id, $id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->authorizeFresh($actor, $locked->get($actor->id));
            $user = $id === null ? new User : $locked->get($id);
            abort_if($user === null, 404);
            if ($id !== null) {
                $this->checkRevision($user, (int) $data['revision']);
                $this->guardMaster($user);
            }
            abort_if($user->is_guest, 422, 'Misafir hesabı erişim politikalarından yönetilir.');
            $admin = $data['level'] === 'system_admin';
            if ($id !== null && $user->is_active && $user->is_system_admin && (! $data['is_active'] || ! $admin)
                && User::query()->where('is_active', true)->where('is_system_admin', true)->where('id', '!=', $id)->count() === 0) {
                throw ValidationException::withMessages(['level' => 'Son aktif sistem yöneticisi pasifleştirilemez veya yetkisiz bırakılamaz.']);
            }
            $user->fill(array_intersect_key($data, array_flip(['username', 'registry_number', 'full_name', 'email', 'phone'])));
            $user->department_id = $data['department_id'] ?? null;
            $user->job_title_id = $data['job_title_id'] ?? null;
            $user->is_system_admin = $admin;
            $user->is_active = (bool) $data['is_active'];
            if ($id === null) {
                $user->password = Hash::make($data['password']);
                $user->must_change_password = true;
            }
            $changed = array_keys($user->getDirty());
            if ($id !== null && $changed === []) {
                return $user;
            }
            if ($id !== null) {
                $user->session_version++;
                $user->revision++;
                $user->remember_token = null;
                $user->last_activity_at = null;
            }
            $user->save();
            app(AuditLog::class)->record($actor, 'users', $user->id, $id === null ? 'created' : 'updated',
                ['changed_fields' => array_values(array_diff($changed, ['password', 'remember_token']))]);

            return $user->refresh();
        }, 3);
    }

    public function resetPassword(User $actor, int $id, array $input): User
    {
        Gate::forUser($actor)->authorize('manage-users');
        $data = $this->validate($input, ['revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted'],
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
        ]);

        return DB::transaction(function () use ($actor, $id, $data): User {
            $this->lockAdministration();
            $locked = User::query()->whereIn('id', [$actor->id, $id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->authorizeFresh($actor, $locked->get($actor->id));
            $user = $locked->get($id);
            abort_if($user === null, 404);
            $this->checkRevision($user, (int) $data['revision']);
            $this->guardMaster($user);
            $user->password = Hash::make($data['password']);
            $user->must_change_password = true;
            $user->failed_login_attempts = 0;
            $user->locked_at = null;
            $user->remember_token = null;
            $user->last_activity_at = null;
            $user->session_version++;
            $user->revision++;
            $user->save();
            app(AuditLog::class)->record($actor, 'users', $id, 'password_reset');

            return $user->refresh();
        }, 3);
    }

    public function terminateSessions(User $actor, int $id, array $input): User
    {
        Gate::forUser($actor)->authorize('manage-users');
        $data = $this->validate($input, ['revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted']]);

        return DB::transaction(function () use ($actor, $id, $data): User {
            $this->lockAdministration();
            $locked = User::query()->whereIn('id', [$actor->id, $id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->authorizeFresh($actor, $locked->get($actor->id));
            $user = $locked->get($id);
            abort_if($user === null, 404);
            $this->checkRevision($user, (int) $data['revision']);
            $user->remember_token = null;
            $user->last_activity_at = null;
            $user->session_version++;
            $user->revision++;
            $user->save();
            app(AuditLog::class)->record($actor, 'users', $id, 'sessions_terminated');

            return $user->refresh();
        }, 3);
    }

    public function protected(User $user): bool
    {
        return (bool) $user->is_master_admin;
    }

    private function guardMaster(User $user): void
    {
        if ($this->protected($user)) {
            throw ValidationException::withMessages(['username' => 'Ana Türker hesabı yönetim panelinden değiştirilemez.']);
        }
    }

    public function delete(User $actor, int $id, array $input): void
    {
        Gate::forUser($actor)->authorize('manage-users');
        $data = $this->validate($input, ['revision' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted'], 'confirmation' => ['required', 'in:KULLANICIYI SİL']]);
        DB::transaction(function () use ($actor, $id, $data) {
            $this->lockAdministration();
            $locked = User::whereIn('id', [$actor->id, $id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->authorizeFresh($actor, $locked->get($actor->id));
            $user = $locked->get($id);
            abort_if(! $user, 404);
            $this->checkRevision($user, $data['revision']);
            if ($id === $actor->id || $this->protected($user) || $user->is_guest || ($user->is_active && $user->is_system_admin && ! User::where('id', '!=', $id)->where('is_active', true)->where('is_system_admin', true)->exists())) {
                throw ValidationException::withMessages(['operation' => 'Kendi hesabınız, ana yönetici, misafir hesabı veya son aktif yönetici silinemez.']);
            }
            app(AuditLog::class)->record($actor, 'users', $id, 'deleted', ['username' => $user->username, 'full_name' => $user->full_name]);
            $messages = DB::table('personal_messages')->where(fn ($query) => $query->where('sender_id', $id)->orWhere('recipient_id', $id));
            $cleanup = app(PrivateFileCleanup::class);
            $cleanup->enqueue(DB::table('message_attachments')->whereIn('message_id', (clone $messages)->select('id'))->pluck('path')->all());
            $messages->delete();
            DB::table('sessions')->where('user_id', $id)->delete();
            $user->delete();
            $cleanup->afterCommit();
        }, 3);
    }

    private function lockAdministration(): void
    {
        // One installation row serializes last-admin decisions across all user mutations.
        abort_if(DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first() === null, 503);
    }

    private function authorizeFresh(User $actor, ?User $current): void
    {
        abort_if($current === null || $actor->session_version !== $current->session_version, 403);
        Gate::forUser($current)->authorize('manage-users');
    }

    private function checkRevision(User $user, int $revision): void
    {
        if ($user->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Kullanıcı başka bir işlemde değişti. Sayfayı yenileyin.']);
        }
    }

    private function validate(array $input, array $rules): array
    {
        return Validator::make($input, $rules, [
            'required' => ':attribute alanı zorunlu.', 'max' => ':attribute en fazla :max karakter olabilir.',
            'min' => ':attribute en az :min olmalı.', 'string' => ':attribute metin olmalı.', 'integer' => ':attribute geçerli bir sayı olmalı.',
            'boolean' => 'Aktif durumu geçersiz.', 'in' => 'Seviye geçersiz.', 'email' => 'E-posta geçersiz.',
            'unique' => 'Bu kullanıcı adı zaten kullanılıyor.', 'regex' => 'Kullanıcı adı yalnız harf, rakam, nokta, tire ve alt çizgi içerebilir.',
            'confirmed' => 'Parola tekrarı eşleşmedi.', 'accepted' => 'Güvenlik işlemini onaylayın.',
        ], ['username' => 'Kullanıcı adı', 'full_name' => 'Ad soyad', 'password' => 'Parola', 'revision' => 'Sürüm',
            'registry_number' => 'Sicil', 'phone' => 'Telefon', 'email' => 'E-posta', 'department_id' => 'Birim', 'job_title_id' => 'Unvan'])->validate();
    }
}
