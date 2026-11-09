<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CTE Portal')</title>
    <script>try{var t=localStorage.getItem('cte-theme');if(t==='dark')document.documentElement.setAttribute('data-theme','dark');var f=localStorage.getItem('cte-font-scale');if(f&&Number.isFinite(Number(f)))document.documentElement.style.setProperty('--table-font-scale',String(Math.min(1.4,Math.max(.8,Number(f)))));}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="portal-body {{ request()->routeIs('admin.*') ? 'admin-workspace' : '' }} {{ request()->routeIs('dashboard') ? 'portal-home' : '' }}">
@php
    $menuRoots = \App\Models\MenuItem::query()->with('children.children')->roots()->orderBy('sort_order')->orderBy('title')->get();
    $renderedDynamicMenu = false;
    $unreadAnnouncementsCount = auth()->check()
        ? \App\Models\OperationRecord::where('module_code', 'duyurular')
            ->where('is_published', true)
            ->where('is_closed', false)
            ->whereDoesntHave('readUsers', fn ($query) => $query->where('user_id', auth()->id()))
            ->count()
        : 0;
@endphp
<div class="portal-shell" data-portal>
    <aside class="sidebar" data-sidebar>
        <div class="brand"><div class="brand-mark">CTE</div><div><strong>CTE Portal</strong><span>Kurum Yönetim Sistemi</span></div></div>
        <nav class="nav-list" aria-label="Ana menü">
            @if(request()->routeIs('admin.*'))
                @include('layouts._admin-navigation')
            @else
            @include('layouts._legacy-navigation')
            @can('permission', 'admin.manage')
                <a class="nav-link {{ request()->routeIs('admin.unit-definitions.*') ? 'active' : '' }}" href="{{ route('admin.unit-definitions.index') }}">Birim ve Tablo Oluşturucu</a>
            @endcan
            @foreach(app(\App\Services\UnitRegistry::class)->visible() as $customCode => $customUnit)
                @if(isset($customUnit['field_definitions']))
                    <a class="nav-link {{ request()->is('birim-modulleri/'.$customCode.'*') ? 'active' : '' }}" href="{{ route('unit-records.index', $customCode) }}">{{ $customUnit['title'] }}</a>
                @endif
            @endforeach
            @foreach($menuRoots as $root)
                @if($root->canBeRenderedFor(auth()->user()))
                    @include('layouts._menu-tree', ['item' => $root, 'visited' => []])
                    @php($renderedDynamicMenu = true)
                @endif
            @endforeach
            @unless($renderedDynamicMenu)
                <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span>⌂</span>Ana Sayfa</a>
                @can('permission', 'admin.manage')
                    <div class="nav-group"><span>Yönetim</span></div>
                    <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}"><span>◈</span>Admin Paneli</a>
                    <a class="nav-link {{ request()->routeIs('admin.institution.*') ? 'active' : '' }}" href="{{ route('admin.institution.edit') }}"><span>◆</span>Kurum Profili</a>
                    <a class="nav-link {{ request()->routeIs('admin.menus.*') ? 'active' : '' }}" href="{{ route('admin.menus.index') }}"><span>☰</span>Menü ve Modüller</a>
                    <a class="nav-link {{ request()->routeIs('admin.lookups.*') ? 'active' : '' }}" href="{{ route('admin.lookups.index') }}"><span>◇</span>Tanım Yönetimi</a>
                    <a class="nav-link {{ request()->routeIs('admin.housing.*') ? 'active' : '' }}" href="{{ route('admin.housing.index') }}"><span>▦</span>Yerleşim</a>
                    <a class="nav-link {{ request()->routeIs('admin.phone-directory.*') ? 'active' : '' }}" href="{{ route('admin.phone-directory.index') }}"><span>☎</span>Telefon Rehberi</a>
                    <a class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}"><span>●</span>Kullanıcılar</a>
                @endcan
            @endunless
            @can('permission', 'inmates.view')
                <div class="nav-group"><span>Hükümlü İşlemleri</span></div>
                <a class="nav-link {{ request()->routeIs('inmates.index') ? 'active' : '' }}" href="{{ route('inmates.index') }}"><span>♙</span>Hükümlü Dosyaları</a>
                @can('permission', 'inmates.update')<a class="nav-link {{ request()->routeIs('inmates.bulk.*') ? 'active' : '' }}" href="{{ route('inmates.bulk.index') }}"><span>⇄</span>Toplu İşlemler</a>@endcan
                @can('permission', 'counting.view')<a class="nav-link {{ request()->is('operasyon/kogus-sayim-calisma*') ? 'active' : '' }}" href="{{ route('operations.index', 'kogus-sayim-calisma') }}"><span>▦</span>Koğuş / Sayım / Çalışma</a>@endcan
                <div class="nav-group"><span>Kurum Operasyonları</span></div>
                <?php
                    $unreadAnnouncementsCount = 0;
                    if (auth()->check()) {
                        $unreadAnnouncementsCount = \App\Models\OperationRecord::where('module_code', 'duyurular')
                            ->where('is_published', true)
                            ->where('is_closed', false)
                            ->whereDoesntHave('readUsers', function ($q) {
                                $q->where('user_id', auth()->id());
                            })
                            ->count();
                    }
                ?>
                @foreach(\App\Http\Controllers\OperationModuleController::MODULES as $code => $operation)
                    @php($operationPermission = \App\Http\Controllers\OperationModuleController::permissionModule($code).'.view')
                    @if($code !== 'kogus-sayim-calisma' && auth()->user()?->hasPermission($operationPermission))
                        <a class="nav-link {{ request()->is('operasyon/'.$code.'*') ? 'active' : '' }}" href="{{ route('operations.index', $code) }}">
                            <span>◆</span>
                            {{ $operation['title'] }}
                            @if($code === 'duyurular' && $unreadAnnouncementsCount > 0)
                                <span class="unread-badge" style="background: #ef4444; color: white; border-radius: 12px; padding: 1px 6px; font-size: 10px; font-weight: bold; margin-left: 6px;">{{ $unreadAnnouncementsCount }}</span>
                            @endif
                        </a>
                    @endif
                @endforeach
                <div class="nav-group"><span>Birim Modülleri</span></div>
                @foreach(app(\App\Services\UnitRegistry::class)->visible() as $code => $unit)
                    @if(!isset($unit['field_definitions']) && auth()->user()?->hasPermission($unit['permission'].'.view'))
                        <a class="nav-link {{ request()->is('birim-modulleri/'.$code.'*') ? 'active' : '' }}" href="{{ route('unit-records.index', $code) }}"><span>{{ in_array($code, ['infaz', 'izin', 'teblig-bakanlik-sevk']) ? '↳' : '●' }}</span>{{ $unit['title'] }}</a>
                    @endif
                @endforeach
                <div class="nav-group"><span>Araçlar ve Analiz</span></div>
                @can('permission', 'execution.view')<a class="nav-link {{ request()->routeIs('tools.calculator') ? 'active' : '' }}" href="{{ route('tools.calculator') }}"><span>∑</span>İnfaz Hesaplayıcı</a>@endcan
                @can('permission', 'reports.view')<a class="nav-link {{ request()->routeIs('tools.reports') ? 'active' : '' }}" href="{{ route('tools.reports') }}"><span>▤</span>Özel Rapor</a>@endcan
                @can('permission', 'statistics.view')<a class="nav-link {{ request()->routeIs('tools.statistics') ? 'active' : '' }}" href="{{ route('tools.statistics') }}"><span>▥</span>İstatistik ve Analiz</a>@endcan
            @endcan
            @endif
        </nav>
    </aside>
    <div class="portal-main">
        <header class="topbar"><button class="icon-button" type="button" data-menu-toggle aria-label="Menüyü aç/kapat">☰</button><div class="topbar-title"><span>Ceza İnfaz Kurumu</span><strong>@yield('page-title', 'Ana Panel')</strong></div><div class="user-menu"><button class="theme-toggle" type="button" data-theme-toggle aria-label="Koyu/açık tema"><span data-theme-icon>☾</span></button><div><strong>{{ auth()->user()->full_name }}</strong><span>{{ auth()->user()->is_protected_superadmin ? 'Ana Yönetici' : (auth()->user()->department?->name ?? 'Personel') }}</span></div><form method="post" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit">Çıkış</button></form></div></header>
        <main class="content" id="main-content">
            @if(request()->routeIs('admin.*'))
                <nav class="admin-breadcrumb" aria-label="Sayfa yolu"><a href="{{ route('admin.dashboard') }}">Yönetim</a><span aria-hidden="true">/</span><span aria-current="page">@yield('page-title', 'Genel bakış')</span></nav>
            @endif
            @yield('content')
        </main>
    </div>
    <button class="sidebar-backdrop" type="button" data-menu-toggle aria-label="Menüyü kapat"></button>
</div>
</body>
</html>
