@php
    $user = auth()->user();
    $canInstitutionInfo = collect(['announcement.view', 'duty.view', 'committee.view', 'meal.view', 'chat.view', 'phone.view'])
        ->contains(fn ($permission) => $user?->hasPermission($permission));
    $canUnits = collect(['intake.view', 'health.view', 'execution.view', 'leave.view', 'notification.view', 'referral.view', 'visit.view', 'spiritual.view', 'education.view', 'psychosocial.view', 'mesem.view'])
        ->contains(fn ($permission) => $user?->hasPermission($permission));
    $canInmateOperations = collect(['inmates.view', 'counting.view', 'evaluation.view', 'reports.view'])
        ->contains(fn ($permission) => $user?->hasPermission($permission));
@endphp

<div class="legacy-navigation">
    <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><span>⌂</span>Ana Sayfa</a>

    @if($canInstitutionInfo)
        <details class="nav-tree" @if(request()->is('operasyon/duyurular*', 'operasyon/nobet*', 'operasyon/kurul-komisyon*', 'operasyon/yemek*', 'operasyon/sohbet*', 'telefon-rehberi*')) open @endif>
            <summary><span>◆</span>Kurum Hakkında</summary>
            <div class="nav-tree-children">
                @can('permission', 'announcement.view')
                    <a class="nav-link {{ request()->is('operasyon/duyurular*') ? 'active' : '' }}" href="{{ route('operations.index', 'duyurular') }}">
                        Duyurular
                        @if(($unreadAnnouncementsCount ?? 0) > 0)<small class="menu-count">{{ $unreadAnnouncementsCount }}</small>@endif
                    </a>
                @endcan
                @can('permission', 'duty.view')
                    <details class="nav-tree nav-tree-nested" @if(request()->is('operasyon/nobet*')) open @endif>
                        <summary><span>↳</span>Nöbetler</summary>
                        <div class="nav-tree-children">
                            <a class="nav-link" href="{{ route('operations.index', ['module' => 'nobet', 'record_type' => 'Müdür nöbeti']) }}">Müdür Nöbetleri</a>
                            <a class="nav-link" href="{{ route('operations.index', ['module' => 'nobet', 'record_type' => 'Vardiya']) }}">Vardiya Nöbetleri</a>
                            <a class="nav-link" href="{{ route('operations.index', ['module' => 'nobet', 'record_type' => 'İnfaz nöbeti']) }}">İnfaz Nöbetleri</a>
                        </div>
                    </details>
                @endcan
                @can('permission', 'committee.view')<a class="nav-link {{ request()->is('operasyon/kurul-komisyon*') ? 'active' : '' }}" href="{{ route('operations.index', 'kurul-komisyon') }}">Kurul ve Komisyonlar</a>@endcan
                @can('permission', 'meal.view')<a class="nav-link {{ request()->is('operasyon/yemek*') ? 'active' : '' }}" href="{{ route('operations.index', 'yemek') }}">Haftalık Yemek Listesi</a>@endcan
                @can('permission', 'chat.view')<a class="nav-link {{ request()->is('operasyon/sohbet*') ? 'active' : '' }}" href="{{ route('operations.index', 'sohbet') }}">Sohbet Odası</a>@endcan
                @can('permission', 'phone.view')<a class="nav-link {{ request()->routeIs('phone-directory.index') ? 'active' : '' }}" href="{{ route('phone-directory.index') }}">Telefon Rehberi</a>@endcan
            </div>
        </details>
    @endif

    @if($canUnits)
        <details class="nav-tree" @if(request()->is('birim-modulleri/*')) open @endif>
            <summary><span>●</span>Kurum Birimleri</summary>
            <div class="nav-tree-children">
                @can('permission', 'intake.view')<a class="nav-link {{ request()->is('birim-modulleri/sorumlu-basmemurluk*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'sorumlu-basmemurluk') }}">Sorumlu Başmemurluk</a>@endcan
                @can('permission', 'health.view')<a class="nav-link {{ request()->is('birim-modulleri/revir*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'revir') }}">Revir Birimi</a>@endcan
                @if($user?->hasPermission('execution.view') || $user?->hasPermission('leave.view') || $user?->hasPermission('notification.view'))
                    <details class="nav-tree nav-tree-nested" @if(request()->is('birim-modulleri/infaz*', 'birim-modulleri/izin*', 'birim-modulleri/teblig-bakanlik-sevk*')) open @endif>
                        <summary><span>↳</span>İnfaz Birimi</summary>
                        <div class="nav-tree-children">
                            @can('permission', 'execution.view')<a class="nav-link {{ request()->is('birim-modulleri/infaz*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'infaz') }}">İnfaz</a>@endcan
                            @can('permission', 'leave.view')<a class="nav-link {{ request()->is('birim-modulleri/izin*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'izin') }}">İzinler</a><a class="nav-link {{ request()->routeIs('reports.leave') ? 'active' : '' }}" href="{{ route('reports.leave') }}">İzin Raporu</a>@endcan
                            @can('permission', 'notification.view')<a class="nav-link" href="{{ route('unit-records.index', ['module' => 'teblig-bakanlik-sevk', 'record_type' => 'Tebliğ']) }}">Tebliğler</a><a class="nav-link {{ request()->routeIs('reports.notification') ? 'active' : '' }}" href="{{ route('reports.notification') }}">Tebliğ Formu</a>@endcan
                            @can('permission', 'notification.view')<a class="nav-link" href="{{ route('unit-records.index', ['module' => 'teblig-bakanlik-sevk', 'record_type' => 'Bakanlık sevk']) }}">Bakanlık Sevk</a><a class="nav-link {{ request()->routeIs('reports.ministry-referral') ? 'active' : '' }}" href="{{ route('reports.ministry-referral') }}">Bakanlık Sevk Formu</a>@endcan
                        </div>
                    </details>
                @endif
                @can('permission', 'visit.view')<a class="nav-link {{ request()->is('birim-modulleri/ziyaret*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'ziyaret') }}">Ziyaret Birimi</a>@endcan
                @can('permission', 'spiritual.view')<a class="nav-link {{ request()->is('birim-modulleri/manevi-rehberlik*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'manevi-rehberlik') }}">Manevi Rehberlik</a>@endcan
                @can('permission', 'education.view')<a class="nav-link {{ request()->is('birim-modulleri/egitim*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'egitim') }}">Eğitim Servisi</a>@endcan
                @can('permission', 'psychosocial.view')<a class="nav-link {{ request()->is('birim-modulleri/psikososyal*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'psikososyal') }}">Psiko Sosyal Servisi</a>@endcan
                @can('permission', 'mesem.view')<details class="nav-tree nav-tree-nested" @if(request()->is('birim-modulleri/mesem*') || request()->routeIs('tools.mesem-statistics')) open @endif><summary><span>↳</span>Mesleki Eğitim Merkezi</summary><div class="nav-tree-children"><a class="nav-link {{ request()->is('birim-modulleri/mesem*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'mesem') }}">MESEM İşlemleri</a><a class="nav-link {{ request()->routeIs('tools.mesem-statistics') ? 'active' : '' }}" href="{{ route('tools.mesem-statistics') }}">MESEM İstatistikleri</a></div></details>@endcan
            </div>
        </details>
    @endif

    @if($canInmateOperations)
        <details class="nav-tree" @if(request()->is('hukumlu-dosyalari*', 'operasyon/kogus-sayim-calisma*', 'operasyon/kismi-arama*', 'birim-modulleri/degerlendirme*', 'araclar/raporlar*')) open @endif>
            <summary><span>♙</span>Hükümlü İşlemleri</summary>
            <div class="nav-tree-children">
                @can('permission', 'counting.view')
                    <a class="nav-link {{ request()->routeIs('counting.*') ? 'active' : '' }}" href="{{ route('counting.index') }}">Koğuşlar / Sayım</a>
                    <a class="nav-link" href="{{ route('operations.index', ['module' => 'kogus-sayim-calisma', 'record_type' => 'Çalışma özeti']) }}">Çalışma Yeri</a>
                    <a class="nav-link {{ request()->is('operasyon/kismi-arama*') ? 'active' : '' }}" href="{{ route('operations.index', 'kismi-arama') }}">Kısmi Arama</a>
                @endcan
                @can('permission', 'evaluation.view')<a class="nav-link {{ request()->is('birim-modulleri/degerlendirme*') ? 'active' : '' }}" href="{{ route('unit-records.index', 'degerlendirme') }}">Hükümlü Değerlendirme</a>@endcan
                @can('permission', 'inmates.view')
                    <a class="nav-link {{ request()->routeIs('inmates.index') ? 'active' : '' }}" href="{{ route('inmates.index') }}">Hükümlü Detay / Liste</a>
                    @can('permission', 'inmates.update')<a class="nav-link {{ request()->routeIs('inmates.bulk.*') ? 'active' : '' }}" href="{{ route('inmates.bulk.index') }}">Toplu Kayıt ve İşlemler</a>@endcan
                    @can('permission', 'inmates.update')<a class="nav-link {{ request()->routeIs('inmates.shortcuts.*') ? 'active' : '' }}" href="{{ route('inmates.shortcuts.index') }}">Kısayollar</a>@endcan
                    @can('permission', 'inmates.print')<a class="nav-link {{ request()->routeIs('inmates.identity-cards') ? 'active' : '' }}" href="{{ route('inmates.identity-cards') }}">Kimlik Kartı Baskısı</a>@endcan
                @endcan
                @can('permission', 'reports.view')<a class="nav-link {{ request()->routeIs('tools.reports') ? 'active' : '' }}" href="{{ route('tools.reports') }}">Özel Rapor</a>@endcan
            </div>
        </details>
    @endif

    @can('permission', 'statistics.view')<a class="nav-link {{ request()->routeIs('tools.statistics') ? 'active' : '' }}" href="{{ route('tools.statistics') }}"><span>▥</span>İstatistik ve Analiz</a>@endcan
    @can('permission', 'execution.view')<a class="nav-link {{ request()->routeIs('tools.calculator') ? 'active' : '' }}" href="{{ route('tools.calculator') }}"><span>∑</span>İnfaz Hesaplama Araçları</a><a class="nav-link {{ request()->routeIs('reports.transfer*') ? 'active' : '' }}" href="{{ route('reports.transfer') }}"><span>⇢</span>Sevk Raporu</a>@endcan

    <details class="nav-tree" @if(request()->routeIs('profile.password.*', 'admin.*')) open @endif>
        <summary><span>⚙</span>Kullanıcı İşlemleri</summary>
        <div class="nav-tree-children">
            <a class="nav-link {{ request()->routeIs('profile.password.*') ? 'active' : '' }}" href="{{ route('profile.password.edit') }}">Şifre Değiştir</a>
            @can('permission', 'admin.manage')<a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Admin Paneli</a>@endcan
        </div>
    </details>

    @can('permission', 'admin.manage')
        <details class="nav-tree" @if(request()->is('yonetim*')) open @endif>
            <summary><span>◈</span>Yönetim</summary>
            <div class="nav-tree-children">
                <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Admin Paneli</a>
                <a class="nav-link {{ request()->routeIs('admin.institution.*') ? 'active' : '' }}" href="{{ route('admin.institution.edit') }}">Kurum Profili</a>
                <a class="nav-link {{ request()->routeIs('admin.menus.*') ? 'active' : '' }}" href="{{ route('admin.menus.index') }}">Menü ve Modüller</a>
                <a class="nav-link {{ request()->routeIs('admin.lookups.*') ? 'active' : '' }}" href="{{ route('admin.lookups.index') }}">Tanım Yönetimi</a>
                <a class="nav-link {{ request()->routeIs('admin.housing.*') ? 'active' : '' }}" href="{{ route('admin.housing.index') }}">Yerleşim</a>
                <a class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}">Kullanıcılar</a>
            </div>
        </details>
    @endcan
</div>
