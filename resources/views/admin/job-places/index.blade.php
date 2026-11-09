@extends('layouts.portal')
@section('title', 'Görev Yerleri')
@section('page-title', 'Görev Yerleri')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Yönetim</p><h2>Görev yerleri</h2></div><a class="primary-link" href="{{ route('admin.job-places.create') }}">Yeni yer</a></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Kod</th><th>Ad</th><th>Kurum</th><th>Sıra</th><th>Durum</th><th></th></tr></thead><tbody>
    @forelse($places as $place)
        <tr><td>{{ $place->code }}</td><td><strong>{{ $place->name }}</strong><br><small>{{ $place->description }}</small></td><td>{{ $place->institution?->name }}</td><td>{{ $place->sort_order }}</td><td><span class="status-badge {{ $place->is_active ? 'ok' : 'passive' }}">{{ $place->is_active ? 'Aktif' : 'Pasif' }}</span></td><td><a class="secondary-button small-button" href="{{ route('admin.job-places.edit', $place) }}">Düzenle</a></td></tr>
    @empty
        <tr><td colspan="6">Görev yeri yok.</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $places->links() }}
</section>
@endsection
