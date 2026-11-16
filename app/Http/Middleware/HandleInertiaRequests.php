<?php

namespace App\Http\Middleware;

use App\Services\Administration\ManagementNavigation;
use App\Services\Administration\ManagementSettings;
use App\Services\Navigation\MenuConfiguration;
use App\Services\People\PortalIndicators;
use App\Services\Workflows\WorkflowAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'appearance' => fn () => app(ManagementSettings::class)->appearance()['values'], 'managementNavigation' => fn () => $request->user()?->can('manage-modules') ? app(ManagementNavigation::class)->groups() : [], 'institutionName' => config('app.institution_name'), 'localTest' => (bool) config('operations.local_test'), 'notice' => fn () => $request->session()->get('notice'), 'account' => fn () => $request->user() ? ['name' => $request->user()->full_name ?: $request->user()->username, 'role' => $request->user()->is_system_admin ? 'Sistem Yöneticisi' : 'Kurum Personeli'] : null, 'logoutUrl' => route('logout'), 'indicatorUrl' => route('portal.indicators'), 'indicators' => fn () => $request->user() ? app(PortalIndicators::class)->read($request->user()) : ['messages' => 0, 'announcements' => 0], 'navigation' => function () use ($request) {
            if ($request->user() === null) {
                return [];
            }
            $workflows = app(WorkflowAccess::class)->groupedNavigation($request->user());

            $tools = collect($workflows)->firstWhere('url', '#cte-tools');
            $workflows = array_values(array_filter($workflows, fn ($item) => $item['url'] !== '#cte-tools'));
            $manage = $request->user()->can('manage-modules');

            return [
                ['label' => 'Ana Sayfa', 'url' => route('portal')],
                ...$workflows,
                ...app(MenuConfiguration::class)->navigation($request->user()),
                ['label' => 'Kullanıcı İşlemleri', 'url' => '#account', 'children' => [['label' => 'Profil ve Ayarlar', 'url' => route('password.edit')], ...(! $request->user()->is_guest ? [['label' => 'Çalışma merkezi', 'url' => route('workspace.index')]] : [])]],
                ...(! $manage && $tools ? [$tools] : []),
                ...($manage ? [['label' => 'Yönetim Paneli', 'url' => '#management', 'children' => [['label' => 'Genel Bakış', 'url' => route('management.index')], ['label' => 'Erişim Politikaları', 'url' => route('page-access.index')], ...($tools ? [$tools] : [])]]] : []),
            ];
        }];
    }
}
