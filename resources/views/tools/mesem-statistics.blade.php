@extends('layouts.portal')
@section('title', 'MESEM İstatistikleri')
@section('page-title', 'MESEM İstatistikleri')
@section('content')
<section class="panel statistics-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Mesleki Eğitim Merkezi</p><h2>MESEM İstatistik Paneli</h2></div><button class="secondary-button" type="button" onclick="window.print()">Yazdır</button></div>
    <div class="statistics-kpis">
        @foreach(['total' => 'Toplam Hükümlü', 'interviewed' => 'Görüşülen', 'not_interviewed' => 'Görüşme Yapılmayan', 'applications' => 'Başvuru Sürecinde', 'does_not_want' => 'İstemiyor', 'petitions' => 'Dilekçe Alındı', 'petition_waiting' => 'Dilekçe Bekleniyor', 'education_documents' => 'Öğrenim Belgesi', 'in_exam_process' => 'Sınav Sürecinde'] as $key => $label)
            <article class="stat-card"><span>{{ $label }}</span><strong>{{ $stats['summary'][$key] }}</strong></article>
        @endforeach
    </div>
</section>
<section class="statistics-grid">
    @foreach(['decision' => 'Çalışma Kararı', 'branches' => 'MESEM Dalı', 'documents' => 'Belge Türü', 'classes' => 'Sınıf Dağılımı', 'employment_trend' => 'İşe Giriş Trendi'] as $key => $title)
        <article class="panel distribution-card"><div class="panel-heading"><div><p class="eyebrow">MESEM</p><h2>{{ $title }}</h2></div></div>@php($max = max(1, (int) ($stats[$key]->max() ?? 1)))<div class="distribution-list">@forelse($stats[$key] as $label => $count)<div class="distribution-row"><div><span>{{ $label }}</span><strong>{{ $count }}</strong></div><div class="distribution-track"><i style="width: {{ round(($count / $max) * 100, 2) }}%"></i></div></div>@empty<p class="muted-text">Kayıt bulunamadı.</p>@endforelse</div></article>
    @endforeach
</section>
<section class="panel"><div class="panel-heading table-heading"><div><p class="eyebrow">Çalışma yeri matrisi</p><h2>Karar ve MESEM Süreçleri</h2></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Çalışma Yeri</th><th>Toplam</th><th>İşyurtları</th><th>Genel Bütçe</th><th>Muhtelif</th><th>Kararsız</th><th>Görüşülen</th><th>Başvuru</th><th>Dilekçe</th></tr></thead><tbody>@foreach($stats['work_rows'] as $work => $row)<tr><td><strong>{{ $work }}</strong></td><td>{{ $row['total'] }}</td><td>{{ $row['isyurtlari'] }}</td><td>{{ $row['genel'] }}</td><td>{{ $row['muhtelif'] }}</td><td>{{ $row['kararsiz'] }}</td><td>{{ $row['interviewed'] }}</td><td>{{ $row['applications'] }}</td><td>{{ $row['petitions'] }}</td></tr>@endforeach</tbody></table></div></section>
<section class="statistics-grid">
    @foreach(['waiting_isyurtlari' => 'İşyurtları Görüşme Bekleyen', 'waiting_general' => 'Genel Bütçe Görüşme Bekleyen'] as $key => $title)
        <article class="panel"><div class="panel-heading"><div><p class="eyebrow">Bekleyenler</p><h2>{{ $title }}</h2></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Adı Soyadı</th><th>Çalışma Yeri</th></tr></thead><tbody>@forelse($stats[$key] as $row)<tr><td>{{ $row['name'] }}</td><td>{{ $row['work'] }}</td></tr>@empty<tr><td colspan="2">Bekleyen kayıt yok.</td></tr>@endforelse</tbody></table></div></article>
    @endforeach
</section>
@endsection
