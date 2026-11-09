@extends('layouts.portal')
@section('title', 'Sevk Raporu')
@section('page-title', 'Sevk Raporu')
@section('content')
<section class="panel report-panel">
    <div class="panel-heading table-heading">
        <div><p class="eyebrow">İnfaz sevk değerlendirmesi</p><h2>{{ $filters['type'] === 'il_disi' ? 'İl Dışı' : 'İl İçi' }} Sevk Raporu</h2></div>
        <div class="actions-cell">
            @if(auth()->user()?->hasPermission('execution.export') || auth()->user()?->hasPermission('inmates.export'))<a class="secondary-button" href="{{ route('reports.transfer.export', request()->query()) }}">Excel'e Aktar</a>@endif
            <button class="secondary-button" type="button" onclick="window.print()">Yazdır</button>
        </div>
    </div>
    <form class="report-filter no-print" method="get">
        <label>Sevk türü<select name="type"><option value="il_disi" @selected($filters['type'] === 'il_disi')>İl Dışı Sevk</option><option value="il_ici" @selected($filters['type'] === 'il_ici')>İl İçi Sevk</option></select></label>
        <label>İl dışı minimum gün<input type="number" name="out_days" min="1" max="365" value="{{ $filters['out_days'] }}"></label>
        <label>İl dışı maksimum yaş<input type="number" name="out_age" min="18" max="100" value="{{ $filters['out_age'] }}"></label>
        <label>İl içi minimum gün<input type="number" name="in_days" min="1" max="365" value="{{ $filters['in_days'] }}"></label>
        <fieldset><legend>Hariç tutulacak geliş nedenleri</legend><div class="check-row">@foreach($arrivalReasons as $reason)<label><input type="checkbox" name="excluded_reasons[]" value="{{ $reason }}" @checked(in_array($reason, $filters['excluded_reasons'], true))>{{ $reason }}</label>@endforeach</div></fieldset>
        <button class="primary-link" type="submit">Kriterleri Uygula</button>
    </form>
    <p class="muted-text">DS tarihi varsa DS, yoksa tahliye tarihi esas alınır. İl dışı sevkte ayrıca yaş kriteri uygulanır.</p>
    <div class="table-wrap report-table-wrap"><table class="data-table report-table"><thead><tr><th>Sıra</th><th>TC Kimlik</th><th>Adı Soyadı</th><th>UYAP No</th><th>Tahliye Tarihi</th><th>Tahliyeye Kalan Gün</th><th>DS Tarihi</th><th>DS Kalan Gün</th></tr></thead><tbody>
    @forelse($rows as $row)<tr><td>{{ $loop->iteration }}</td><td>{{ $row['identity_number'] ?: '—' }}</td><td><strong>{{ $row['full_name'] }}</strong></td><td>{{ $row['uyap'] ?: '—' }}</td><td>{{ $row['release_date'] ?: '—' }}</td><td>{{ $row['release_days'] ?? '—' }}</td><td>{{ $row['ds_date'] ?: '—' }}</td><td>{{ $row['ds_days'] ?? '—' }}</td></tr>@empty<tr><td colspan="8">Kriterlere uygun hükümlü bulunamadı.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endsection
