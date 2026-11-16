<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Modules\DefinitionConflict;
use App\Services\Security\PortalReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AccountRequests
{
    public function submit(string $kind, array $input): void
    {
        abort_unless(app(PortalReadiness::class)->ready(), 503, 'İlk kurulum tamamlanmalıdır.');
        abort_unless(in_array($kind, ['application', 'password']), 404);
        $rules = ['username' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[\pL\pN_.-]+$/uD'], 'email' => ['nullable', 'email', 'max:100'], 'phone' => ['nullable', 'string', 'max:30'], 'message' => ['nullable', 'string', 'max:1000']];
        if ($kind === 'application') {
            $rules += ['full_name' => ['required', 'string', 'max:150'], 'registry_number' => ['nullable', 'string', 'max:50'],
                'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('is_active', true)], 'job_title_id' => ['required', 'integer', Rule::exists('job_titles', 'id')->where('is_active', true)]];
        }
        $data = Validator::make($input, $rules)->validate();
        DB::transaction(function () use ($kind, $data) {
            DB::table('portal_installations')->where('id', 1)->lockForUpdate()->first();
            $user = User::where('username', $data['username'])->first();
            if (($kind === 'application' && $user) || DB::table('account_requests')->where('kind', $kind)->where('username', $data['username'])->whereIn('status', ['new', 'read'])->exists()) {
                return;
            }
            DB::table('account_requests')->insert($data + ['kind' => $kind, 'user_id' => $kind === 'password' ? $user?->id : null, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function read(User $actor, array $input): array
    {
        app(ManagementAccess::class)->actor($actor);
        $state = Validator::make($input, ['kind' => ['nullable', Rule::in(['application', 'password'])], 'status' => ['nullable', Rule::in(['new', 'read', 'completed', 'rejected'])], 'q' => ['nullable', 'string', 'max:150'], 'page' => ['nullable', 'integer', 'min:1']])->validate();
        $query = DB::table('account_requests as r')->leftJoin('departments as d', 'd.id', '=', 'r.department_id')->leftJoin('job_titles as j', 'j.id', '=', 'r.job_title_id')->leftJoin('users as u', 'u.id', '=', 'r.user_id');
        foreach (['kind', 'status'] as $key) {
            if (! empty($state[$key])) {
                $query->where('r.'.$key, $state[$key]);
            }
        }
        if (! empty($state['q'])) {
            $query->where(fn ($q) => $q->where('r.username', 'like', '%'.$state['q'].'%')->orWhere('r.full_name', 'like', '%'.$state['q'].'%'));
        }
        $count = (clone $query)->count();
        $pages = max(1, (int) ceil($count / 50));
        $page = min((int) ($state['page'] ?? 1), $pages);

        return ['pagination' => ['page' => $page, 'pages' => $pages, 'total' => $count], 'requests' => $query->orderByDesc('r.id')->offset(($page - 1) * 50)->limit(50)->get(['r.*', 'd.name as department', 'j.name as job_title', 'u.revision as user_revision'])->map(fn ($r) => (array) $r)->all(), 'queryState' => $state,
            'pending' => DB::table('account_requests')->whereIn('status', ['new', 'read'])->select('kind')->selectRaw('COUNT(*) AS total')->groupBy('kind')->pluck('total', 'kind')->all()];
    }

    public function decide(User $actor, int $id, array $input): void
    {
        $data = Validator::make($input, ['revision' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['new', 'read', 'completed', 'delete', 'approve', 'reject', 'password'])], 'resolution' => ['nullable', 'string', 'max:500'],
            'password' => ['required_if:action,approve,password', 'nullable', 'string', 'max:128'], 'password_confirmation' => ['required_with:password', 'nullable', 'string', 'max:128'], 'user_revision' => ['required_if:action,password', 'nullable', 'integer', 'min:1'], 'confirmed' => ['accepted']])->validate();
        DB::transaction(function () use ($actor, $id, $data) {
            $actor = app(ManagementAccess::class)->actor($actor, true);
            $request = DB::table('account_requests')->where('id', $id)->lockForUpdate()->first();
            abort_if(! $request, 404);
            if ((int) $request->revision !== (int) $data['revision']) {
                throw new DefinitionConflict;
            }
            if ($data['action'] === 'delete') {
                DB::table('account_requests')->where('id', $id)->delete();
                app(AuditLog::class)->record($actor, 'account_requests', $id, 'deleted', ['kind' => $request->kind]);

                return;
            }
            if ((in_array($data['action'], ['approve', 'password']) && in_array($request->status, ['completed', 'rejected']))
                || ($request->kind === 'application' && $request->user_id !== null)
                || ($request->kind === 'password' && in_array($request->status, ['completed', 'rejected']))) {
                throw ValidationException::withMessages(['action' => 'Bu talep sonuçlandırılmış.']);
            }
            $status = match ($data['action']) {
                'new' => 'new', 'read' => 'read', 'reject' => 'rejected', default => 'completed'
            };
            if ($data['action'] === 'approve') {
                if ($request->kind !== 'application') {
                    throw ValidationException::withMessages(['action' => 'Üyelik başvurusu seçin.']);
                }
                $user = app(UserAdministration::class)->save($actor, ['username' => $request->username, 'registry_number' => $request->registry_number, 'full_name' => $request->full_name,
                    'email' => $request->email, 'phone' => $request->phone, 'department_id' => $request->department_id, 'job_title_id' => $request->job_title_id,
                    'level' => 'personnel', 'is_active' => true, 'password' => $data['password'], 'password_confirmation' => $data['password_confirmation']]);
                DB::table('account_requests')->where('id', $id)->update(['user_id' => $user->id]);
            }
            if ($data['action'] === 'password') {
                if ($request->kind !== 'password' || ! $request->user_id) {
                    throw ValidationException::withMessages(['action' => 'Bu talebe bağlı kullanıcı hesabı bulunamadı.']);
                }
                app(UserAdministration::class)->resetPassword($actor, $request->user_id, ['revision' => $data['user_revision'], 'confirmed' => true, 'password' => $data['password'], 'password_confirmation' => $data['password_confirmation']]);
            }
            DB::table('account_requests')->where('id', $id)->update(['status' => $status, 'resolution' => $data['resolution'] ?? null, 'revision' => $request->revision + 1, 'decided_by' => $actor->id,
                'decided_at' => in_array($status, ['new', 'read']) ? null : now(), 'updated_at' => now()]);
            app(AuditLog::class)->record($actor, 'account_requests', $id, $status, ['kind' => $request->kind, 'revision' => $request->revision + 1]);
        }, 3);
    }
}
