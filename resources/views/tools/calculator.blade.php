@extends('layouts.portal')
@section('title', 'İnfaz Hesaplayıcıları')
@section('page-title', 'İnfaz Hesaplayıcıları')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Eski formüllerle eşdeğer</p><h2>İnfaz hesaplayıcıları</h2><p class="muted-text">Tarih aralığı, APC, Terör 1/3, para cezası, onda bir, DS1, DS3 ve 7550 S.K. araçları ayrı matematikleriyle çalışır.</p></div></div>
    @if($errors->any())<div class="error-alert">{{ $errors->first() }}</div>@endif
    <form method="get" class="stack-form" data-calculator-form>
        <div class="form-grid">
            <label data-field="tool">Hesap aracı<select name="tool" data-tool-select>@foreach($tools as $code => $label)<option value="{{ $code }}" @selected($selectedTool === $code)>{{ $label }}</option>@endforeach</select></label>
            <label data-field="start_date">Başlangıç / şartlı tahliye<input type="date" name="start_date" value="{{ request('start_date', now()->toDateString()) }}" required></label>
            <label data-field="end_date">Bitiş / 7550 şartla tahliye tarihi<input type="date" name="end_date" value="{{ request('end_date') }}"></label>
            <label data-field="years">Yıl<input type="number" name="years" min="0" value="{{ request('years', 0) }}"></label>
            <label data-field="months">Ay<input type="number" name="months" min="0" value="{{ request('months', 0) }}"></label>
            <label data-field="days">Gün<input type="number" name="days" min="0" value="{{ request('days', 0) }}"></label>
            <label data-field="credit_days">Mahsup gün<input type="number" name="credit_days" min="0" value="{{ request('credit_days', 0) }}"></label>
            <label data-field="value_days">APC / para cezası / önceki DS günü<input type="number" name="value_days" min="0" value="{{ request('value_days', 0) }}"></label>
        </div>
        <div class="form-actions" style="justify-content:flex-start;border:0;padding:0;margin-top:1rem">
            <button class="primary-button compact" type="submit">Hesapla</button>
            @if($result)<button class="secondary-button" type="button" onclick="window.print()">Yazdır</button>@endif
        </div>
    </form>
</section>
@if($result)
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">{{ $tools[$selectedTool] }}</p><h2>Hesap özeti</h2></div></div><div class="table-wrap"><table class="data-table compact-table"><tbody>@foreach($result as $key => $value)<tr><th>{{ $key }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table></div></section>
@endif
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Çapraz doğrulama</p><h2>Örnek eşdeğerlik testleri</h2></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Örnek</th><th>Beklenen gün</th><th>Bulunan gün</th><th>Durum</th></tr></thead><tbody>@foreach($examples as $example)<tr><td>{{ $example['name'] }}</td><td>{{ $example['expected_total_days'] }}</td><td>{{ $example['actual_total_days'] }}</td><td><span class="status-badge {{ $example['passed'] ? 'ok' : 'passive' }}">{{ $example['passed'] ? 'Geçti' : 'Hata' }}</span></td></tr>@endforeach</tbody></table></div><p class="muted-text">Bu ekran kurum içi kontrol aracıdır; hukuki değerlendirme ve UYAP kaydı esastır.</p></section>
@endsection
