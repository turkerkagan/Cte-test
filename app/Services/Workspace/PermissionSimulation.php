<?php

namespace App\Services\Workspace;

use App\Models\User;
use App\Services\Access\PageAccessView;
use App\Services\Administration\ManagementAccess;
use App\Services\Modules\ModuleAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PermissionSimulation
{
    public function read(User $actor, array $input): array
    {
        app(ManagementAccess::class)->actor($actor);
        $data = Validator::make($input, ['user' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100']])->validate();
        $query = DB::table('users')->where('is_guest', false)->orderBy('full_name')->orderBy('id');
        if (trim($data['q'] ?? '') !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("full_name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("username LIKE ? ESCAPE '!'", [$term]));
        }
        $users = $query->limit(25)->get(['id', 'full_name', 'username', 'is_active'])->all();
        $selected = isset($data['user']) ? User::findOrFail($data['user']) : null;
        abort_if($selected?->is_guest, 422, 'Personel hesabı seçin.');
        $rows = [];
        if ($selected) {
            $modules = DB::table('portal_pages')->whereNotNull('module_id')->pluck('module_id', 'id');
            foreach (app(PageAccessView::class)->rows($selected) as $page) {
                if (isset($modules[$page['id']])) {
                    foreach (['giris', 'yazma'] as $permission) {
                        try {
                            app(ModuleAccess::class)->authorize($selected, (int) $modules[$page['id']], $permission);
                        } catch (HttpExceptionInterface $error) {
                            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                                throw $error;
                            }
                            $page['effective'][$permission] = false;
                        }
                    }
                }
                $rows[] = array_intersect_key($page, array_flip(['id', 'name', 'code', 'is_active', 'read_only', 'effective', 'personal_reason']));
            }
        }

        return ['users' => $users, 'selected' => $selected ? [
            'id' => $selected->id, 'name' => $selected->full_name ?: $selected->username,
            'active' => (bool) $selected->is_active, 'passwordChangeRequired' => (bool) $selected->must_change_password,
            'department' => DB::table('departments')->where('id', $selected->department_id)->value('name'),
            'jobTitle' => DB::table('job_titles')->where('id', $selected->job_title_id)->value('name'),
        ] : null, 'pages' => $rows];
    }
}
