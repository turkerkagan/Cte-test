@extends('layouts.portal')
@section('title', 'Tanım Yönetimi — CTE Portal')
@section('page-title', 'Tanım Yönetimi')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading">
        <div><p class="eyebrow">Yönetim</p><h2>Tanım listeleri</h2></div>
        <a class="primary-link" href="{{ route('admin.lookups.groups.create') }}">Yeni grup</a>
    </div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    @forelse($groups as $group)
        <div class="lookup-group">
            <div class="lookup-group-header">
                <div><h3>{{ $group->name }} <small>{{ $group->code }}</small></h3><p>{{ $group->description }}</p></div>
                <div class="actions-cell">
                    <a class="secondary-button small-button" href="{{ route('admin.lookups.groups.edit', $group) }}">Grup</a>
                    <a class="primary-link small-button" href="{{ route('admin.lookups.items.create', $group) }}">Kalem ekle</a>
                    <form method="post" action="{{ route('admin.lookups.groups.toggle', $group) }}">@csrf @method('patch')<button class="secondary-button small-button" type="submit">{{ $group->is_active ? 'Pasifleştir' : 'Aktifleştir' }}</button></form>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table compact-table">
                    <thead><tr><th>Kod</th><th>Etiket</th><th>Sıra</th><th>Durum</th><th></th></tr></thead>
                    <tbody>
                    @forelse($group->items as $item)
                        <tr>
                            <td><small>{{ $item->code }}</small></td>
                            <td><strong>{{ $item->label }}</strong><br><small>{{ $item->description }}</small></td>
                            <td>{{ $item->sort_order }}</td>
                            <td><span class="status-badge {{ $item->is_active ? 'ok' : 'passive' }}">{{ $item->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                            <td class="actions-cell">
                                <a class="secondary-button small-button" href="{{ route('admin.lookups.items.edit', [$group, $item]) }}">Düzenle</a>
                                <form method="post" action="{{ route('admin.lookups.items.toggle', [$group, $item]) }}">@csrf @method('patch')<button class="secondary-button small-button" type="submit">Durum</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Kayıt yok.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <p>Henüz tanım grubu yok.</p>
    @endforelse
</section>
@endsection
