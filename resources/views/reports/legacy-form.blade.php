@php($isNotification = $form === 'notification')
@extends('layouts.portal')
@section('title', $isNotification ? 'Tebliğ Formu' : 'Bakanlık Sevk Formu')
@section('page-title', $isNotification ? 'Tebliğ Formu' : 'Bakanlık Sevk Formu')
@section('content')
<section class="panel report-panel no-print">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Toplu seçim</p><h2>{{ $isNotification ? 'Müddetname Tebliğ Tebellüğ Belgesi' : 'Hükümlü/Tutuklu Sevk Bilgi Formu' }}</h2></div>@if($selected->isNotEmpty())<button class="secondary-button" type="button" onclick="window.print()">Yazdır</button>@endif</div>
    <form class="toolbar" method="get"><input name="q" value="{{ request('q') }}" placeholder="TC veya ad soyad"><button class="secondary-button" type="submit">Ara</button></form>
    <form method="get">
        <input type="hidden" name="q" value="{{ request('q') }}">
        <div class="selection-list">
            @foreach($inmates as $inmate)<label><input type="checkbox" name="inmate_ids[]" value="{{ $inmate->id }}" @checked(in_array($inmate->id, $selectedIds, true))><span><strong>{{ $inmate->full_name }}</strong><small>{{ $inmate->identity_number ?: 'TC yok' }}</small></span></label>@endforeach
        </div>
        <div class="form-actions"><button class="secondary-button" type="button" data-select-all> Tümünü Seç</button><button class="secondary-button" type="button" data-clear-selection>Seçimi Temizle</button><button class="primary-link" type="submit">Formu Oluştur</button></div>
    </form>
</section>
@if($selected->isNotEmpty())
<section class="panel report-panel printable-report">
    <div class="print-report-header"><h2>{{ $isNotification ? 'Müddetname Tebliğ Tebellüğ Belgesi' : 'Hükümlü/Tutuklu Sevk Bilgi Formu' }}</h2><p>{{ now()->format('d.m.Y') }}</p></div>
    <div class="table-wrap report-table-wrap"><table class="data-table report-table"><thead><tr>
        <th>Sıra</th><th>Adı Soyadı</th>@if($isNotification)<th>Dosya/UYAP No</th><th>TC Kimlik</th><th>Blok, Koğuş</th><th>Müddetname Tarihi</th><th>Tebliğ Konusu</th><th>Tarih</th><th>İmza</th>@else<th>UYAP No</th><th>TC Kimlik</th><th>Yaşı</th><th>Daha Önce Kaldığı Kurumlar</th><th>Geliş Nedeni</th><th>DS Tahliye</th><th>Şartlı Tahliye</th><th>Disiplin Cezası</th><th>Kurumda Yakını</th><th>Kronik Rahatsızlık</th><th>Devam Eden Yargılama</th>@endif
    </tr></thead><tbody>
    @foreach($selected as $inmate)
        @php($housing = $inmate->activeHousingAssignment->first())
        @php($infaz = $inmate->unitRecords->firstWhere('module_code', 'infaz'))
        @php($intake = $inmate->unitRecords->firstWhere('module_code', 'sorumlu-basmemurluk'))
        @php($health = $inmate->unitRecords->firstWhere('module_code', 'revir'))
        @php($data = $infaz?->data ?? [])
        <tr><td>{{ $loop->iteration }}</td><td><strong>{{ $inmate->full_name }}</strong></td>
        @if($isNotification)
            <td>{{ $data['UYAP No'] ?? $data['UYAP özeti'] ?? '—' }}</td><td>{{ $inmate->identity_number ?: '—' }}</td><td>{{ trim(($housing?->block?->name ?? '').' '.($housing?->ward?->name ?? '')) ?: '—' }}</td><td></td><td></td><td></td><td class="signature-cell"></td>
        @else
            @php($birth = $intake?->data['Doğum Tarihi'] ?? $intake?->data['Doğum tarihi'] ?? null)
            <td>{{ $data['UYAP No'] ?? $data['UYAP özeti'] ?? '—' }}</td><td>{{ $inmate->identity_number ?: '—' }}</td><td>{{ $birth ? \Carbon\Carbon::parse($birth)->age : '—' }}</td><td>{{ $data['Daha Önce Kaldığı Kurumlar'] ?? '—' }}</td><td>{{ $data['Geliş Nedeni'] ?? $data['Geliş nedeni'] ?? '—' }}</td><td>{{ $data['DS Tarihi'] ?? '—' }}</td><td>{{ $data['Tahliye Tarihi'] ?? $data['Şartlı tahliye'] ?? '—' }}</td><td>{{ $data['Disiplin Cezası'] ?? 'Hayır' }}</td><td>{{ $data['Kurumda Yakını'] ?? 'Hayır' }}</td><td>{{ $health?->data['Kronik rahatsızlık'] ?? 'Hayır' }}</td><td>{{ $data['Devam Eden Yargılama'] ?? 'Hayır' }}</td>
        @endif
        </tr>
    @endforeach
    </tbody></table></div>
</section>
@endif
@endsection
