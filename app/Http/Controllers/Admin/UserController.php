<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UserPasswordRequest;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use LogicException;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->with(['department', 'role'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.trim((string) $request->input('q')).'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('full_name', 'like', $term)
                        ->orWhere('personnel_number', 'like', $term)
                        ->orWhere('login_name', 'like', $term);
                });
            })
            ->orderByDesc('is_protected_superadmin')
            ->orderBy('full_name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        return view('admin.users.create', $this->formData());
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create($this->validatedUserData($request) + [
            'password' => $request->input('password'),
            'password_changed_at' => now(),
        ]);
        $this->syncPermissionOverrides($user, $request->input('permissions', []));

        return redirect()->route('admin.users.index')->with('status', 'Kullanıcı oluşturuldu.');
    }

    public function edit(User $user): View
    {
        $user->load(['role.permissions', 'permissionOverrides']);

        return view('admin.users.edit', $this->formData() + ['managedUser' => $user]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->guardProtectedUser($user, $request->boolean('is_active'), (int) $request->input('role_id'), $request->input('login_name'));

        DB::transaction(function () use ($request, $user): void {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $user->update($this->validatedUserData($request));
            $this->syncPermissionOverrides($user, $request->input('permissions', []));
            if (! $user->is_active) {
                $this->destroyUserSessions($user);
            }
        });

        return redirect()->route('admin.users.index')->with('status', 'Kullanıcı güncellendi.');
    }

    public function resetPassword(UserPasswordRequest $request, User $user): RedirectResponse
    {
        $user->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => $request->boolean('must_change_password', true),
            'password_changed_at' => now(),
        ])->save();

        $this->destroyUserSessions($user);

        return back()->with('status', 'Parola güncellendi ve kullanıcının açık oturumları kapatıldı.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('admin.manage'), 403);

        $newState = ! $user->is_active;
        $this->guardProtectedUser($user, $newState, $user->role_id, $user->login_name);

        $user->forceFill(['is_active' => $newState])->save();

        if (! $newState) {
            $this->destroyUserSessions($user);
        }

        return back()->with('status', $newState ? 'Kullanıcı aktifleştirildi.' : 'Kullanıcı pasifleştirildi ve açık oturumları kapatıldı.');
    }

    public function destroySessions(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('admin.manage'), 403);

        $this->destroyUserSessions($user);

        return back()->with('status', 'Kullanıcının açık oturumları kapatıldı.');
    }

    private function formData(): array
    {
        return [
            'institutions' => Institution::orderBy('name')->get(),
            'departments' => Department::orderBy('sort_order')->orderBy('name')->get(),
            'roles' => Role::where('is_active', true)->orderBy('name')->get(),
            'permissionGroups' => Permission::query()
                ->whereIn('action', ['view', 'create', 'update', 'delete', 'print', 'export', 'report', 'manage'])
                ->orderBy('module')->orderBy('action')->get()->groupBy('module'),
        ];
    }

    private function validatedUserData(StoreUserRequest|UpdateUserRequest $request): array
    {
        return [
            'institution_id' => $request->integer('institution_id'),
            'department_id' => $request->input('department_id') ?: null,
            'role_id' => $request->integer('role_id'),
            'full_name' => trim((string) $request->input('full_name')),
            'personnel_number' => $request->input('personnel_number') ?: null,
            'login_name' => $request->input('login_name') ?: null,
            'email' => $request->input('email') ?: null,
            'is_active' => $request->boolean('is_active'),
            'must_change_password' => $request->boolean('must_change_password'),
        ];
    }

    private function guardProtectedUser(User $user, bool $isActive, int $roleId, ?string $loginName): void
    {
        if (! $user->is_protected_superadmin) {
            return;
        }

        if (! $isActive || $roleId !== $user->role_id || $loginName !== $user->login_name) {
            throw new LogicException('Korunan ana yönetici hesabının kritik güvenlik alanları değiştirilemez.');
        }
    }

    private function destroyUserSessions(User $user): void
    {
        User::whereKey($user->id)->increment('session_version');
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    /** @param array<int|string, string> $choices */
    private function syncPermissionOverrides(User $user, array $choices): void
    {
        if ($user->is_protected_superadmin) {
            return;
        }

        $choices = $this->applyPermissionDependencies($choices);

        DB::transaction(function () use ($user, $choices): void {
            foreach ($choices as $permissionId => $choice) {
                $permission = Permission::find($permissionId);
                if (! $permission) {
                    continue;
                }

                if ($choice === 'inherit') {
                    UserPermissionOverride::where('user_id', $user->id)
                        ->where('permission_id', $permission->id)->get()->each->delete();

                    continue;
                }

                UserPermissionOverride::updateOrCreate(
                    ['user_id' => $user->id, 'permission_id' => $permission->id],
                    ['allowed' => $choice === 'allow'],
                );
            }
        });
    }

    /** @param array<int|string, string> $choices */
    private function applyPermissionDependencies(array $choices): array
    {
        $permissions = Permission::whereIn('id', array_keys($choices))->get()->keyBy('id');
        $deniedViewModules = $permissions
            ->filter(fn (Permission $permission) => $permission->action === 'view' && ($choices[$permission->id] ?? null) === 'deny')
            ->pluck('module')
            ->all();

        foreach ($choices as $permissionId => $choice) {
            $permission = $permissions->get((int) $permissionId);
            if (! $permission || $choice !== 'allow' || $permission->action === 'view' || in_array($permission->module, $deniedViewModules, true)) {
                continue;
            }

            $viewPermission = Permission::where('module', $permission->module)->where('action', 'view')->first();
            if ($viewPermission) {
                $choices[$viewPermission->id] = 'allow';
            }
        }

        foreach ($deniedViewModules as $module) {
            foreach (Permission::where('module', $module)->pluck('id') as $modulePermissionId) {
                $choices[$modulePermissionId] = 'deny';
            }
        }

        return $choices;
    }
}
