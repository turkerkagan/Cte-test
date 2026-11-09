@extends('layouts.portal')
@section('title', 'Koğuş Sayımı')
@section('page-title', 'Koğuş Sayımı')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading">
        <div><p class="eyebrow">Aktif konumdan canlı sayım</p><h2>Koğuş Sayımı</h2><p class="muted-text">Hazır mevcut = kişi sayısı − özel izin.</p></div>
        <div class="actions-cell no-print">
            @can('permission', 'counting.update')<form method="post" action="{{ route('counting.store') }}">@csrf<button class="primary-link" type="submit">Sayımı Kaydet</button></form>@endcan
            <button class="secondary-button" type="button" onclick="window.print()">Yazdır</button>
        </div>
    </div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    @if($previous)<p class="muted-text">Karşılaştırılan son kayıt: {{ $previous->starts_at?->format('d.m.Y H:i') }} — {{ $previous->title }}</p>@endif
</section>
<section class="counting-grid">
@foreach($blocks as $block => $rows)
    <article class="panel counting-block">
        <div class="panel-heading"><div><p class="eyebrow">Blok</p><h2>{{ $block }}</h2></div></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Koğuş</th><th>Kişi</th><th>Özel İzin</th><th>Hazır Mevcut</th><th>Önceki Fark</th></tr></thead><tbody>
        @foreach($rows as $row)<tr><td><strong>{{ $row['ward'] }}</strong></td><td>{{ $row['total'] }}</td><td>{{ $row['leave'] }}</td><td>{{ $row['ready'] }}</td><td><span class="status-badge {{ $differences[$row['ward_id']] === 0 ? 'ok' : 'passive' }}">{{ $differences[$row['ward_id']] > 0 ? '+' : '' }}{{ $differences[$row['ward_id']] }}</span></td></tr>@endforeach
        <tr class="table-total"><th>Toplam</th><th>{{ $rows->sum('total') }}</th><th>{{ $rows->sum('leave') }}</th><th>{{ $rows->sum('ready') }}</th><th></th></tr>
        </tbody></table></div>
    </article>
@endforeach
</section>
<section class="panel counting-total"><div><span>Tüm Koğuşlar Toplamı</span><strong>{{ $totals['total'] }}</strong></div><div><span>Özel İzin</span><strong>{{ $totals['leave'] }}</strong></div><div><span>Hazır Mevcut</span><strong>{{ $totals['ready'] }}</strong></div></section>
@endsection
