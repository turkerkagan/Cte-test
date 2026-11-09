@php
    $adminSections = [
        'Yönetim' => [
            ['admin.dashboard', 'Genel bakış', 'admin.dashboard'],
            ['admin.users.index', 'Kullanıcılar ve yetkiler', 'admin.users.*'],
            ['admin.institution.edit', 'Kurum profili', 'admin.institution.*'],
        ],
        'Yapılandırma' => [
            ['admin.unit-definitions.index', 'Birim ve tablolar', 'admin.unit-definitions.*'],
            ['admin.menus.index', 'Menü düzeni', 'admin.menus.*'],
            ['admin.lookups.index', 'Tanımlar', 'admin.lookups.*'],
        ],
        'Kurum kaynakları' => [
            ['admin.housing.index', 'Yerleşim', 'admin.housing.*'],
            ['admin.job-places.index', 'Görev yerleri', 'admin.job-places.*'],
            ['admin.phone-directory.index', 'Telefon rehberi', 'admin.phone-directory.*'],
        ],
    ];
@endphp
<a class="admin-return" href="{{ route('dashboard') }}"><span aria-hidden="true">←</span> Portala dön</a>
@foreach($adminSections as $label => $links)
    <div class="nav-group">{{ $label }}</div>
    @foreach($links as [$route, $title, $pattern])
        <a class="nav-link {{ request()->routeIs($pattern) ? 'active' : '' }}" @if(request()->routeIs($pattern)) aria-current="page" @endif href="{{ route($route) }}">{{ $title }}</a>
    @endforeach
@endforeach
<div class="admin-sidebar-note"><span class="admin-sidebar-dot" aria-hidden="true"></span> Kurum yönetimi</div>
