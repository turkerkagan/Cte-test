@extends('layouts.portal')
@section('title', 'İstatistik ve Analiz')
@section('page-title', 'İstatistik ve Analiz')
@section('content')
<section class="panel statistics-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Eski kapsamlı istatistik paneli</p><h2>Genel İstatistik ve Göstergeler</h2><p class="muted-text">Genel istatistik verileri aktif hükümlü ve tarihsel modül kayıtlarından üretilir.</p></div><button class="secondary-button" type="button" onclick="window.print()">Yazdır</button></div>
    <div class="statistics-kpis">
        @foreach(['total' => 'Toplam Kayıt', 'today' => 'Bugün', 'month' => 'Bu Ay', 'year' => 'Bu Yıl', 'leave' => 'İzinli', 'leaving' => 'İzne Ayrılacak', 'returning' => 'İzinden Dönecek', 'workplaces' => 'Çalışma Yeri'] as $key => $label)
            <article class="stat-card"><span>{{ $label }}</span><strong>{{ $stats['summary'][$key] }}</strong></article>
        @endforeach
    </div>
</section>
<section class="statistics-grid">
    @foreach($stats['distributions'] as $title => $distribution)
        <article class="panel distribution-card">
            <div class="panel-heading"><div><p class="eyebrow">Dağılım</p><h2>{{ $title }}</h2></div></div>
            @php($max = max(1, (int) ($distribution->max() ?? 1)))
            <div class="distribution-list">
                @forelse($distribution as $label => $count)
                    <div class="distribution-row"><div><span>{{ $label }}</span><strong>{{ $count }}</strong></div><div class="distribution-track"><i style="width: {{ round(($count / $max) * 100, 2) }}%"></i></div></div>
                @empty
                    <p class="muted-text">Kayıt bulunamadı.</p>
                @endforelse
            </div>
        </article>
    @endforeach
</section>
<section class="panel"><div class="panel-heading table-heading"><div><p class="eyebrow">Son 12 ay</p><h2>Kayıt Trendi</h2></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Ay</th><th>Kayıt</th></tr></thead><tbody>@forelse($stats['trend'] as $month => $count)<tr><td>{{ $month }}</td><td>{{ $count }}</td></tr>@empty<tr><td colspan="2">Trend verisi yok.</td></tr>@endforelse</tbody></table></div></section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">Modül kayıtları</p><h2>Birim ve Operasyon</h2></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Kaynak</th><th>Modül</th><th>Sayı</th></tr></thead><tbody>@foreach($stats['unit_records'] as $code => $total)<tr><td>Birim</td><td>{{ $code }}</td><td>{{ $total }}</td></tr>@endforeach @foreach($stats['operation_records'] as $code => $total)<tr><td>Operasyon</td><td>{{ $code }}</td><td>{{ $total }}</td></tr>@endforeach</tbody></table></div></section>
@endsection
