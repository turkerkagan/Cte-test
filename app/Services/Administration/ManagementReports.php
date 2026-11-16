<?php

namespace App\Services\Administration;

use App\Models\User;
use App\Services\Operations\PortalHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ManagementReports
{
    public function overview(User $actor): array
    {
        app(ManagementAccess::class)->actor($actor);
        $today = now()->startOfDay();

        return ['overviewContext' => ['date' => now()->format('d.m.Y'), 'name' => $actor->full_name ?: $actor->username], 'summary' => ['active_users' => User::where('is_active', true)->count(), 'departments' => DB::table('departments')->where('is_active', true)->count(),
            'pages' => DB::table('portal_pages')->where('is_active', true)->count(), 'password_requests' => DB::table('account_requests')->where('kind', 'password')->whereIn('status', ['new', 'read'])->count(),
            'applications' => DB::table('account_requests')->where('kind', 'application')->whereIn('status', ['new', 'read'])->count(),
            'logins_today' => DB::table('portal_login_events')->where('outcome', 'login')->where('created_at', '>=', $today)->count(),
            'actions_today' => DB::table('audit_events')->where('created_at', '>=', $today)->count()],
            'recent' => $this->audit($actor, ['per_page' => 8])['rows'], 'lastBackup' => DB::table('administration_backups')->whereNull('archived_at')->orderByDesc('id')->first(['id', 'name', 'bytes', 'created_at']),
            'health' => app(PortalHealth::class)->snapshot()];
    }

    public function audit(User $actor, array $input): array
    {
        app(ManagementAccess::class)->actor($actor);
        $state = Validator::make($input, ['q' => ['nullable', 'string', 'max:150'], 'resource' => ['nullable', 'string', 'max:50'], 'action' => ['nullable', 'string', 'max:30'],
            'date' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:60']])->validate();
        $query = DB::table('audit_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_id')->leftJoin('departments as d', 'd.id', '=', 'u.department_id');
        if (! empty($state['q'])) {
            $query->where(fn ($q) => $q->where('u.username', 'like', '%'.$state['q'].'%')->orWhere('u.full_name', 'like', '%'.$state['q'].'%'));
        }
        if (! empty($state['resource'])) {
            $query->where('e.resource_type', $state['resource']);
        }
        if (! empty($state['action'])) {
            $query->where('e.action', $state['action']);
        }
        if (! empty($state['date'])) {
            $query->whereDate('e.created_at', $state['date']);
        }
        $count = (clone $query)->count();
        $perPage = (int) ($state['per_page'] ?? 60);
        $pages = max(1, (int) ceil($count / $perPage));
        $page = min((int) ($state['page'] ?? 1), $pages);
        $rows = $query->orderByDesc('e.id')->offset(($page - 1) * $perPage)->limit($perPage)->get(['e.*', 'u.username', 'u.full_name', 'd.name as department'])->map(function ($row) {
            $details = json_decode($row->details, true, 64, JSON_THROW_ON_ERROR);
            $filtered = $this->redact($details);

            return ['id' => (int) $row->id, 'created_at' => $row->created_at, 'username' => $row->username ?? $details['actor_username'] ?? 'Sistem', 'full_name' => $row->full_name ?? $details['actor_name'] ?? '',
                'department' => $row->department ?? '', 'resource' => $row->resource_type, 'resource_id' => (int) $row->resource_id, 'action' => $row->action, 'details' => $filtered, 'ip' => $details['ip_address'] ?? ''];
        })->all();

        return ['rows' => $rows, 'queryState' => $state, 'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $count],
            'resources' => DB::table('audit_events')->distinct()->orderBy('resource_type')->pluck('resource_type')->all(), 'actions' => DB::table('audit_events')->distinct()->orderBy('action')->pluck('action')->all()];
    }

    public function logins(User $actor, array $input): array
    {
        app(ManagementAccess::class)->actor($actor);
        $state = Validator::make($input, ['q' => ['nullable', 'string', 'max:150'], 'user_id' => ['nullable', 'integer', 'min:1'], 'date' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1']])->validate();
        $users = DB::table('users as u')->leftJoin('departments as d', 'd.id', '=', 'u.department_id');
        if (! empty($state['q'])) {
            $users->where(fn ($q) => $q->where('u.username', 'like', '%'.$state['q'].'%')->orWhere('u.full_name', 'like', '%'.$state['q'].'%'));
        }
        $counts = DB::table('portal_login_events')->where('outcome', 'login')->whereDate('created_at', $state['date'] ?? now()->toDateString())->select('user_id')->selectRaw('COUNT(*) AS total')->groupBy('user_id')->pluck('total', 'user_id');
        $activeSessions = DB::table('users')->where('is_active', true)->where('last_activity_at', '>=', now()->subMinutes(15))->pluck('id')->all();
        $rows = $users->orderBy('u.full_name')->orderBy('u.id')->get(['u.id', 'u.username', 'u.full_name', 'u.last_login_at', 'u.last_activity_at', 'u.is_active', 'u.revision', 'd.name as department'])->map(fn ($r) => ['id' => (int) $r->id, 'username' => $r->username, 'full_name' => $r->full_name, 'department' => $r->department,
            'is_active' => (bool) $r->is_active, 'revision' => (int) $r->revision, 'last_login_at' => $r->last_login_at, 'last_activity_at' => $r->last_activity_at, 'today_count' => (int) ($counts[$r->id] ?? 0), 'online' => in_array($r->id, $activeSessions)])->all();
        $events = DB::table('portal_login_events');
        if (! empty($state['user_id'])) {
            $events->where('user_id', $state['user_id']);
        }
        if (! empty($state['q'])) {
            $events->where(fn ($q) => $q->where('username', 'like', '%'.$state['q'].'%')->orWhere('full_name', 'like', '%'.$state['q'].'%'));
        }
        if (! empty($state['date'])) {
            $events->whereDate('created_at', $state['date']);
        }
        $count = (clone $events)->count();
        $pages = max(1, (int) ceil($count / 60));
        $page = min((int) ($state['page'] ?? 1), $pages);

        return ['rows' => $rows, 'events' => $events->orderByDesc('id')->offset(($page - 1) * 60)->limit(60)->get(['id', 'user_id', 'username', 'full_name', 'outcome', 'ip_address', 'created_at'])->map(fn ($r) => (array) $r)->all(),
            'queryState' => $state, 'actorId' => $actor->id, 'sessionMeasurement' => true, 'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $count]];
    }

    private function redact(array $details): array
    {
        $result = [];
        foreach ($details as $key => $value) {
            if (is_string($key) && preg_match('/password|secret|token|hash|credential|snapshot|payload|sql|path/i', $key)) {
                continue;
            }
            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    public function loginEvent(?User $user, string $username, string $outcome, ?string $ip): void
    {
        DB::table('portal_login_events')->insert(['user_id' => $user?->id, 'username' => $username, 'full_name' => $user?->full_name, 'outcome' => $outcome, 'ip_address' => $ip, 'created_at' => now()]);
    }
}
