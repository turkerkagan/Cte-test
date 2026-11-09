@extends('layouts.portal')
@section('title', 'Menü ve Modüller — CTE Portal')
@section('page-title', 'Menü ve Modüller')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading">
        <div>
            <p class="eyebrow">Yönetim</p>
            <h2>Menü kayıtları</h2>
        </div>
        <a class="primary-link" href="{{ route('admin.menus.create') }}">Yeni menü</a>
    </div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Başlık</th><th>Üst menü</th><th>Route</th><th>Yetki</th><th>Sıra</th><th>Durum</th><th></th></tr></thead>
            <tbody>
            @forelse($menuItems as $item)
                <tr>
                    <td><strong>{{ $item->icon }} {{ $item->title }}</strong><br><small>{{ $item->module_code ?? 'bağımsız' }}</small></td>
                    <td>{{ $item->parent?->title ?? 'Ana menü' }}</td>
                    <td><small>{{ $item->route_name ?? '—' }}</small></td>
                    <td><small>{{ $item->permission_key ?? '—' }}</small></td>
                    <td>{{ $item->sort_order }}</td>
                    <td>
                        <span class="status-badge {{ $item->is_active ? 'ok' : 'passive' }}">{{ $item->is_active ? 'Aktif' : 'Pasif' }}</span>
                        <span class="status-badge {{ $item->is_visible ? 'ok' : 'passive' }}">{{ $item->is_visible ? 'Görünür' : 'Gizli' }}</span>
                    </td>
                    <td class="actions-cell">
                        <a class="secondary-button small-button" href="{{ route('admin.menus.edit', $item) }}">Düzenle</a>
                        <form method="post" action="{{ route('admin.menus.destroy', $item) }}">@csrf @method('delete')<button class="secondary-button small-button" type="submit">Sil</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7">Menü kaydı bulunamadı.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
<section class="panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Referans</p><h2>Modüller</h2></div></div>
    <div class="module-grid">
        @foreach($modules as $module)
            <article class="module-chip"><strong>{{ $module->icon }} {{ $module->name }}</strong><small>{{ $module->code }} · {{ $module->permission_key ?? 'yetki yok' }}</small></article>
        @endforeach
    </div>
</section>
@endsection
