@extends('layouts.portal')
@section('title', 'Telefon Rehberi')
@section('page-title', 'Telefon Rehberi')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading">
        <div><p class="eyebrow">Kurum Hakkında</p><h2>Telefon rehberi</h2></div>
        @can('permission', 'admin.manage')<a class="primary-link" href="{{ route('admin.phone-directory.create') }}">Yeni kayıt</a>@endcan
    </div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <form class="toolbar" method="get">
        <input name="q" value="{{ request('q') }}" placeholder="Birim, kişi, dahili, telefon veya e-posta ara">
        <select name="department_id"><option value="">Tüm birimler</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((int) request('department_id') === $department->id)>{{ $department->name }}</option>@endforeach</select>
        <button class="secondary-button" type="submit">Ara</button>
    </form>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Başlık</th><th>Birim</th><th>Kişi</th><th>Dahili</th><th>Harici</th><th>Mobil</th><th>Durum</th><th></th></tr></thead>
            <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td><strong>{{ $entry->title }}</strong>@if($entry->notes)<br><small>{{ $entry->notes }}</small>@endif</td>
                    <td>{{ $entry->department?->name ?? '—' }}</td>
                    <td>{{ $entry->person_name ?? '—' }}@if($entry->email)<br><small>{{ $entry->email }}</small>@endif</td>
                    <td>{{ $entry->internal_number ?? '—' }}</td>
                    <td>{{ $entry->external_number ?? '—' }}</td>
                    <td>{{ $entry->mobile_number ?? '—' }}</td>
                    <td><span class="status-badge {{ $entry->is_active ? 'ok' : 'passive' }}">{{ $entry->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                    <td class="actions-cell">@can('permission', 'admin.manage')<a class="secondary-button small-button" href="{{ route('admin.phone-directory.edit', $entry) }}">Düzenle</a><form method="post" action="{{ route('admin.phone-directory.toggle', $entry) }}">@csrf @method('patch')<button class="secondary-button small-button" type="submit">Durum</button></form>@else<span class="muted-text">Salt okunur</span>@endcan</td>
                </tr>
            @empty
                <tr><td colspan="8">Telefon rehberi kaydı yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $entries->links() }}
</section>
@endsection
