@extends('layouts.portal')
@section('title', 'Kısmi Arama')
@section('page-title', 'Kısmi Arama')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Günlük koğuş araması</p><h2>Kısmi Arama</h2><p class="muted-text">Eski günlük koğuş görünümü, yıllık sıra ve dönem raporu yeni kayıt modelinden üretilir.</p></div>@can('permission',$permissionModule.'.update')<a class="primary-link small-button" href="{{ route('operations.create',$module) }}">Yeni arama kaydı</a>@endcan</div>
    <form class="toolbar" method="get"><label>Tarih<input type="date" name="date" value="{{ $date->toDateString() }}"></label><label>Yıl<input type="number" name="year" value="{{ $year }}" min="2000" max="2100"></label><label>Dönem<select name="period"><option value="3" @selected($period===3)>Son 3 ay</option><option value="6" @selected($period===6)>Son 6 ay</option><option value="12" @selected($period===12)>Son 12 ay</option></select></label><button class="secondary-button" type="submit">Göster</button><a class="secondary-button" href="{{ route('operations.index',$module) }}">Bugün</a></form>
    <div class="stat-grid"><div class="stat-card"><span>Bugünkü arama</span><strong>{{ $stats['daily'] }}</strong></div><div class="stat-card"><span>Aranan koğuş</span><strong>{{ $stats['searched_wards'] }}</strong></div><div class="stat-card"><span>Aranmayan koğuş</span><strong>{{ $stats['unsearched_wards'] }}</strong></div><div class="stat-card"><span>{{ $year }} toplamı</span><strong>{{ $stats['annual'] }}</strong></div><div class="stat-card"><span>Son {{ $period }} ay</span><strong>{{ $stats['period'] }}</strong></div></div>
</section>
<section class="panel">
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Blok</th><th>Koğuş</th><th>{{ $date->format('d.m.Y') }}</th><th>Yıllık sıra</th><th>Son arama</th><th>Sonuç</th></tr></thead><tbody>
    @forelse($wardRows as $row)<tr><td>{{ $row['ward']->block?->name ?: '—' }}</td><td><strong>{{ $row['ward']->name }}</strong></td><td><span class="status-badge {{ $row['daily']->isNotEmpty() ? 'ok' : 'passive' }}">{{ $row['daily']->count() }} arama</span></td><td>{{ $row['annual_count'] }}</td><td>{{ $row['last']?->starts_at?->format('d.m.Y H:i') ?: '—' }}</td><td>{{ $row['daily']->pluck('data.Arama sonucu')->filter()->join(', ') ?: '—' }}</td></tr>@empty<tr><td colspan="6">Aktif koğuş tanımı bulunamadı.</td></tr>@endforelse
    </tbody></table></div>
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Günlük kayıtlar</p><h2>{{ $date->format('d.m.Y') }}</h2></div></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Saat</th><th>Blok / Koğuş</th><th>Sonuç</th><th>Yıllık sıra</th><th>Kullanıcı</th><th></th></tr></thead><tbody>
    @forelse($records as $record)<tr><td>{{ $record->starts_at?->format('H:i') }}</td><td><strong>{{ $record->title }}</strong><br>{{ $record->data['Blok'] ?? '—' }} / {{ $record->data['Koğuş'] ?? '—' }}</td><td>{{ $record->data['Arama sonucu'] ?? $record->body ?? '—' }}</td><td>{{ $record->data['Yıllık sıra'] ?? '—' }}</td><td>{{ $record->creator?->full_name ?: '—' }}</td><td class="actions-cell">@can('permission',$permissionModule.'.update')<a class="secondary-button small-button" href="{{ route('operations.edit',[$module,$record]) }}">Düzenle</a>@endcan @can('permission',$permissionModule.'.delete')<form method="post" action="{{ route('operations.destroy',[$module,$record]) }}">@csrf @method('delete')<button class="danger-button small-button">Sil</button></form>@endcan</td></tr>@empty<tr><td colspan="6">Bu tarihte arama kaydı yok.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
