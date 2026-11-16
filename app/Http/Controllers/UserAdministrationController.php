<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access\PageAccessView;
use App\Services\Administration\UserAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class UserAdministrationController extends Controller
{
    public function index(): Response
    {
        $rows = DB::table('users')->leftJoin('departments', 'departments.id', '=', 'users.department_id')
            ->leftJoin('job_titles', 'job_titles.id', '=', 'users.job_title_id')
            ->select(['users.id', 'username', 'registry_number', 'full_name', 'email', 'last_login_at', 'users.department_id', 'users.is_active', 'is_system_admin', 'departments.name as department', 'job_titles.name as job_title'])
            ->orderBy('full_name')->orderBy('users.id')->get()->map(fn ($row) => [
                'id' => (int) $row->id, 'username' => $row->username, 'registry_number' => $row->registry_number,
                'full_name' => $row->full_name, 'is_active' => (bool) $row->is_active, 'department' => $row->department,
                'job_title' => $row->job_title, 'level' => $row->is_system_admin ? 'Sistem Yöneticisi' : 'Kurum Personeli',
                'email' => $row->email, 'department_id' => $row->department_id, 'last_login_at' => $row->last_login_at === null ? null : Carbon::parse($row->last_login_at)->toIso8601String(),
            ]);

        return Inertia::render('Users', $this->common() + ['rows' => $rows]);
    }

    public function create(Request $request): Response|JsonResponse
    {
        return $this->editorResponse($request, $this->common() + $this->options() + ['user' => null, 'events' => [], 'submitUrl' => route('users.store'), 'resetUrl' => null, 'terminateUrl' => null]);
    }

    public function show(Request $request, int $id): Response|JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $data = $user->only(['id', 'username', 'registry_number', 'full_name', 'email', 'phone', 'department_id', 'job_title_id', 'is_active',
            'revision', 'must_change_password', 'failed_login_attempts']);
        $data['level'] = $user->is_system_admin ? 'system_admin' : 'personnel';
        $data['last_login_at'] = $user->last_login_at?->toIso8601String();
        $data['locked_at'] = $user->locked_at?->toIso8601String();
        $events = DB::table('audit_events')->leftJoin('users as actors', 'actors.id', '=', 'audit_events.actor_id')
            ->where('resource_type', 'users')->where('resource_id', $id)->orderByDesc('audit_events.id')->limit(20)
            ->get(['audit_events.id', 'action', 'audit_events.created_at', 'actors.full_name as actor'])
            ->map(fn ($event) => ['id' => (int) $event->id, 'action' => $event->action, 'actor' => $event->actor,
                'created_at' => Carbon::parse($event->created_at)->toIso8601String()]);

        return $this->editorResponse($request, $this->common() + $this->options() + ['user' => $data, 'events' => $events,
            'submitUrl' => route('users.update', $id), 'resetUrl' => route('users.reset', $id),
            'terminateUrl' => route('users.terminate', $id), 'deleteUrl' => ($id === $request->user()->id || app(UserAdministration::class)->protected($user) || $user->is_guest) ? null : route('users.delete', $id),
            'accessRows' => app(PageAccessView::class)->rows($user), 'accessUrl' => route('users.page-access', $id)]);
    }

    public function store(Request $request, UserAdministration $service): RedirectResponse|JsonResponse
    {
        $user = $this->mutate(fn () => $service->save($request->user(), $request->all()));

        return $request->wantsJson() ? response()->json(['id' => $user->id, 'message' => 'Kullanıcı oluşturuldu. İlk girişte parola değişimi zorunlu.'])
            : redirect()->route('users.show', $user->id)->with('notice', 'Kullanıcı oluşturuldu. İlk girişte parola değişimi zorunlu.');
    }

    public function update(Request $request, int $id, UserAdministration $service): RedirectResponse|JsonResponse
    {
        $user = $this->mutate(fn () => $service->save($request->user(), $request->all(), $id));

        return $this->afterChange($request, $user, 'Kullanıcı bilgileri kaydedildi.');
    }

    public function reset(Request $request, int $id, UserAdministration $service): RedirectResponse|JsonResponse
    {
        $user = $this->mutate(fn () => $service->resetPassword($request->user(), $id, $request->all()));

        return $this->afterChange($request, $user, 'Parola sıfırlandı. Kullanıcı ilk girişte yeni parola belirlemeli.');
    }

    public function terminate(Request $request, int $id, UserAdministration $service): RedirectResponse|JsonResponse
    {
        $user = $this->mutate(fn () => $service->terminateSessions($request->user(), $id, $request->all()));

        return $this->afterChange($request, $user, 'Kullanıcının tüm oturumları sonlandırıldı.');
    }

    public function delete(Request $request, int $id, UserAdministration $service): RedirectResponse|JsonResponse
    {
        $service->delete($request->user(), $id, $request->all());

        return $request->wantsJson() ? response()->json(['deleted' => true, 'redirect' => route('users.index')]) : redirect()->route('users.index')->with('notice', 'Kullanıcı silindi.');
    }

    private function editorResponse(Request $request, array $data): Response|JsonResponse
    {
        return $request->wantsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store') : Inertia::render('UserEditor', $data);
    }

    private function afterChange(Request $request, User $user, string $notice): RedirectResponse|JsonResponse
    {
        if ($user->id === $request->user()->id && $user->session_version !== $request->session()->get('auth_session_version')) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->wantsJson() ? response()->json(['id' => $user->id, 'redirect' => route('login'), 'message' => $notice.' Tekrar giriş yapın.'])
                : redirect()->route('login')->with('notice', $notice.' Tekrar giriş yapın.');
        }

        return $request->wantsJson() ? response()->json(['id' => $user->id, 'message' => $notice]) : redirect()->route('users.show', $user->id)->with('notice', $notice);
    }

    private function mutate(callable $operation): User
    {
        try {
            return $operation();
        } catch (QueryException $error) {
            throw ValidationException::withMessages(['operation' => 'İşlem tamamlanamadı. Kullanıcı adı ve güncel atamaları kontrol edip yeniden deneyin.']);
        }
    }

    private function common(): array
    {
        return ['applicationName' => config('app.name'), 'baseUrl' => route('users.index'), 'logoutUrl' => route('logout')];
    }

    private function options(): array
    {
        $options = fn (string $table) => DB::table($table)->orderBy('name')->orderBy('id')->get(['id', 'name', 'is_active'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'is_active' => (bool) $row->is_active]);

        return ['departments' => $options('departments'), 'jobTitles' => $options('job_titles')];
    }
}
