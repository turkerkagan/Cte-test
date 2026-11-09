@extends('layouts.portal')
@section('title', 'Admin Paneli')
@section('page-title', 'Genel bakış')
@section('content')
@php
    $checkLabels = [
        'app_debug_disabled' => 'Hata ayıklama kapalı', 'session_driver_file' => 'Oturumlar yerel dosyada',
        'cache_store_file' => 'Önbellek yerel dosyada', 'queue_connection_sync' => 'İşler doğrudan çalıştırılıyor',
        'session_lifetime_30' => 'Oturum süresi 30 dakika', 'session_encrypted' => 'Oturum verileri şifreli',
        'session_http_only' => 'Oturum çerezi HTTP erişimiyle sınırlı', 'session_same_site_strict' => 'Siteler arası çerez koruması',
        'safe_log_level' => 'Üretime uygun günlük seviyesi',
    ];
    $passedChecks = count(array_filter($systemChecks));
    $sections = [
        ['title' => 'Kullanıcılar ve yetkiler', 'description' => 'Hesaplar, roller ve erişim izinleri', 'route' => 'admin.users.index', 'count' => $counts['users'], 'unit' => 'kullanıcı'],
        ['title' => 'Birim ve tablolar', 'description' => 'Birim sayfaları, form alanları ve sütunlar', 'route' => 'admin.unit-definitions.index', 'count' => $customUnitCount, 'unit' => 'özel birim'],
        ['title' => 'Menü düzeni', 'description' => 'Gezinme ağacı, sıralama ve görünürlük', 'route' => 'admin.menus.index', 'count' => $counts['menu_items'], 'unit' => 'menü kaydı'],
        ['title' => 'Tanımlar', 'description' => 'Seçim listeleri ve ortak değerler', 'route' => 'admin.lookups.index', 'count' => $counts['lookup_groups'], 'unit' => 'tanım grubu'],
        ['title' => 'Yerleşim', 'description' => 'Bloklar, koğuşlar ve ranza düzeni', 'route' => 'admin.housing.index', 'count' => $counts['wards'], 'unit' => 'koğuş'],
        ['title' => 'Görev yerleri', 'description' => 'Çalışma alanları ve görevlendirme tanımları', 'route' => 'admin.job-places.index', 'count' => null, 'unit' => null],
        ['title' => 'Telefon rehberi', 'description' => 'Kurum içi numaralar ve iletişim bilgileri', 'route' => 'admin.phone-directory.index', 'count' => $counts['phone_entries'], 'unit' => 'kayıt'],
        ['title' => 'Kurum profili', 'description' => 'Kurum bilgileri ve giriş ekranı ayarları', 'route' => 'admin.institution.edit', 'count' => null, 'unit' => null],
    ];
@endphp
<header class="admin-page-header">
    <div><p class="admin-kicker">KURUM YÖNETİMİ</p><h1>Genel bakış</h1><p>Hesapları, birimleri ve kurum ayarlarını yönetin.</p></div>
    <a class="primary-button" href="{{ route('admin.users.create') }}"><span aria-hidden="true">+</span> Yeni kullanıcı</a>
</header>
<div class="admin-metrics" aria-label="Yönetim özeti">
    <div><span>Aktif kullanıcı</span><strong>{{ $counts['active_users'] }}<small>/ {{ $counts['users'] }}</small></strong></div>
    <div><span>Tanımlı rol</span><strong>{{ $counts['roles'] }}</strong></div>
    <div><span>Özel birim</span><strong>{{ $customUnitCount }}</strong></div>
    <div><span>Açık oturum</span><strong>{{ $counts['sessions'] }}</strong></div>
</div>
<div class="admin-overview-grid">
    <div class="admin-overview-main">
        <section class="panel admin-directory">
            <div class="panel-heading"><h2>Yönetim alanları</h2><span class="admin-caption">{{ count($sections) }} alan</span></div>
            <div class="admin-directory-grid">
                @foreach($sections as $section)
                    <a class="admin-directory-link" href="{{ route($section['route']) }}">
                        <div><strong>{{ $section['title'] }}</strong><p>{{ $section['description'] }}</p>@if($section['count'] !== null)<small>{{ $section['count'] }} {{ $section['unit'] }}</small>@endif</div><span class="admin-link-arrow" aria-hidden="true">↗</span>
                    </a>
                @endforeach
            </div>
        </section>
        <section class="panel admin-recent-users">
            <div class="panel-heading table-heading"><div><h2>Son eklenen kullanıcılar</h2></div><a class="admin-text-link" href="{{ route('admin.users.index') }}">Tüm kullanıcılar <span aria-hidden="true">→</span></a></div>
            <div class="table-wrap"><table class="data-table"><thead><tr><th>Kullanıcı</th><th>Birim / Rol</th><th>Durum</th><th><span class="sr-only">İşlem</span></th></tr></thead><tbody>
            @forelse($recentUsers as $user)
                <tr><td><div class="admin-person"><span class="admin-initial" aria-hidden="true">{{ mb_substr($user->full_name, 0, 1) }}</span><div><strong>{{ $user->full_name }}</strong><small>{{ $user->login_name ?: 'Giriş adı tanımlanmamış' }}</small></div></div></td><td>{{ $user->department?->name ?? '—' }}<small class="admin-cell-note">{{ $user->is_protected_superadmin ? 'Ana yönetici' : ($user->role?->name ?? 'Rol atanmamış') }}</small></td><td><span class="status-badge {{ $user->is_active ? 'ok' : 'passive' }}">{{ $user->is_active ? 'Aktif' : 'Pasif' }}</span></td><td><a class="admin-text-link" href="{{ route('admin.users.edit', $user) }}" aria-label="{{ $user->full_name }} kullanıcısını düzenle">Düzenle</a></td></tr>
            @empty<tr><td colspan="4">Henüz kullanıcı eklenmedi.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    </div>
    <aside class="admin-overview-aside">
        <section class="panel admin-system-panel">
            <div class="panel-heading"><h2>Sistem durumu</h2><span class="admin-live-dot {{ $securityPasses ? '' : 'needs-attention' }}" aria-hidden="true"></span></div>
            <div class="admin-system-result"><strong>{{ $passedChecks }}<span>/ {{ count($systemChecks) }}</span></strong><p>{{ $securityPasses ? 'Tüm yapılandırma kontrolleri uygun.' : 'Bazı yapılandırmalar gözden geçirilmeli.' }}</p></div>
            <div class="admin-system-line"><span>Çalışma durumu</span><strong>{{ $maintenanceMode ? 'Bakım modu' : 'Normal' }}</strong></div>
            <details class="admin-check-details"><summary>Sistem kontrolleri</summary><ul class="admin-checks">@foreach($systemChecks as $key => $passed)<li><span>{{ $checkLabels[$key] ?? str_replace('_', ' ', $key) }}</span><span class="admin-check-result {{ $passed ? 'passed' : 'pending' }}">{{ $passed ? 'Uygun' : 'Kontrol et' }}</span></li>@endforeach</ul></details>
        </section>
        <section class="panel admin-resource-panel">
            <div class="panel-heading"><h2>Kurum kaynakları</h2></div>
            <dl class="admin-resources">@foreach(['Blok' => 'blocks', 'Koğuş' => 'wards', 'Ranza' => 'beds', 'Tanım kalemi' => 'lookup_items', 'Telefon kaydı' => 'phone_entries'] as $label => $key)<div><dt>{{ $label }}</dt><dd>{{ $counts[$key] }}</dd></div>@endforeach</dl>
        </section>
    </aside>
</div>
<section class="panel admin-role-panel">
    <details><summary><span>Rol / İzin Matrisi</span><span class="admin-caption">{{ $counts['roles'] }} rol · {{ $counts['permissions'] }} izin</span></summary>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Rol</th><th>İzinler</th><th>Durum</th></tr></thead><tbody>@forelse($roles as $role)<tr><td><strong>{{ $role->name }}</strong><small class="admin-cell-note">{{ $role->code }}</small></td><td><div class="admin-permission-list">@forelse($role->permissions as $permission)<span class="pill small" title="{{ $permission->label }}">{{ $permission->key }}</span>@empty<span class="muted-text">İzin yok</span>@endforelse</div></td><td>{{ $role->is_active ? 'Aktif' : 'Pasif' }}</td></tr>@empty<tr><td colspan="3">Rol tanımlanmamış.</td></tr>@endforelse</tbody></table></div>
    </details>
</section>
@endsection
