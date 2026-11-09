@extends('layouts.portal')
@section('title', $config['title'])
@section('page-title', $config['title'])
@section('content')
<section class="panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">{{ $config['task'] }} · Salt okunur</p><h2>{{ $config['title'] }}</h2><p class="muted-text">{{ $config['description'] }}</p></div></div>
    <form class="toolbar" method="get"><input name="q" value="{{ request('q') }}" placeholder="Ad, TC veya legacy ara"><button class="secondary-button" type="submit">Ara</button><a class="secondary-button" href="{{ route('operations.index', $module) }}">Temizle</a></form>
</section>
<section class="panel">
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Hükümlü</th><th>Kurum</th><th>Aktif blok</th><th>Aktif koğuş</th><th>Ranza</th><th>Aktif çalışma</th><th>Kayıt</th></tr></thead><tbody>
    @forelse($inmates as $inmate)
        @php($housing = $inmate->activeHousingAssignment->first())
        @php($work = $inmate->activeWorkAssignment->first())
        <tr><td><strong>{{ $inmate->full_name }}</strong><br><small>{{ $inmate->identity_number ?? 'TC yok' }}</small></td><td>{{ $inmate->institution?->displayShortName() ?? '—' }}</td><td>{{ $housing?->block?->name ?? '—' }}</td><td>{{ $housing?->ward?->name ?? '—' }}</td><td>{{ $housing?->bed?->label ?? '—' }}</td><td>{{ $work?->place?->name ?? '—' }}</td><td><a class="secondary-button small-button" href="{{ route('inmates.edit', $inmate) }}">Detay</a></td></tr>
    @empty
        <tr><td colspan="7">Aktif hükümlü kaydı yok.</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $inmates->links() }}
</section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">Faz 6</p><h2>Operasyon modülleri</h2></div></div><div class="module-grid">@foreach($modules as $code => $item)<a class="module-chip" href="{{ route('operations.index', $code) }}"><strong>{{ $item['title'] }}</strong><small>{{ $item['task'] }}</small></a>@endforeach</div></section>
@endsection
