@extends('layouts.portal')
@section('title', 'Kullanıcı Yönetimi — CTE Portal')
@section('page-title', 'Kullanıcı Yönetimi')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading">
        <div>
            <p class="eyebrow">Yönetim</p>
            <h2>Kullanıcılar</h2>
        </div>
        <a class="primary-link" href="{{ route('admin.users.create') }}">Yeni kullanıcı</a>
    </div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <form class="toolbar" method="get">
        <input name="q" value="{{ request('q') }}" placeholder="Ad, sicil veya giriş adı ara">
        <button class="secondary-button" type="submit">Ara</button>
    </form>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Ad Soyad</th><th>Giriş</th><th>Birim</th><th>Rol</th><th>Durum</th><th>Son giriş</th><th></th></tr></thead>
            <tbody>
            @forelse($users as $user)
                <tr>
                    <td><strong>{{ $user->full_name }}</strong>@if($user->is_protected_superadmin)<span class="pill small">Korunan admin</span>@endif</td>
                    <td><small>{{ $user->personnel_number ?: 'Sicil yok' }}</small><br><small>{{ $user->login_name ?: 'Özel giriş yok' }}</small></td>
                    <td>{{ $user->department?->name ?? '—' }}</td>
                    <td>{{ $user->role?->name ?? '—' }}</td>
                    <td><span class="status-badge {{ $user->is_active ? 'ok' : 'passive' }}">{{ $user->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                    <td><small>{{ $user->last_login_at?->translatedFormat('d.m.Y H:i') ?? '—' }}</small></td>
                    <td class="actions-cell">
                        <a class="secondary-button small-button" href="{{ route('admin.users.edit', $user) }}">Düzenle</a>
                        <form method="post" action="{{ route('admin.users.toggle-active', $user) }}">@csrf @method('patch')<button class="secondary-button small-button" type="submit" @disabled($user->is_protected_superadmin)>{{ $user->is_active ? 'Pasifleştir' : 'Aktifleştir' }}</button></form>
                        <form method="post" action="{{ route('admin.users.sessions.destroy', $user) }}">@csrf @method('delete')<button class="secondary-button small-button" type="submit">Oturumu kapat</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">Kayıtlı kullanıcı bulunamadı.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
</section>
@endsection
