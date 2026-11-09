@extends('layouts.portal')
@section('title', 'Özel Rapor')
@section('page-title', 'Özel Rapor')
@section('content')
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Eski işlev · yeni veri modeli</p><h2>Özel rapor ve dışa aktarma</h2><p class="muted-text">Hükümlü, konum, çalışma ve birim alanlarını seçerek rapor oluşturun. Ham SQL çalıştırılmaz.</p></div></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="error-alert">{{ $errors->first() }}</div>@endif
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Kayıtlı tanım</p><h2>Rapor şablonu oluştur</h2></div></div>
    <form method="post" action="{{ route('tools.reports.store') }}" class="stack-form">@csrf
        <div class="form-grid"><label>Rapor adı<input name="name" required maxlength="255"></label><label>Kaynak<select name="source" required>@foreach($sources as $key => $source)<option value="{{ $key }}">{{ $source['title'] }}</option>@endforeach</select></label><label><input type="checkbox" name="is_shared" value="1"> Paylaşılan</label><label>Kolon ara<input type="search" placeholder="Alan veya birim adı" data-column-search></label></div>
        @foreach($sources as $sourceKey => $source)
            <fieldset><legend>{{ $source['title'] }}</legend><div class="form-grid">@foreach($source['columns'] as $column)<label data-report-column="{{ mb_strtolower($column) }}"><input type="checkbox" name="columns[]" value="{{ $column }}"> {{ $column }}</label>@endforeach</div></fieldset>
        @endforeach
        <button class="primary-button" type="submit">Raporu kaydet</button>
    </form>
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">CSV / XLSX / PDF</p><h2>Rapor oluştur ve indir</h2></div></div>
    <form method="get" action="{{ route('tools.export') }}" class="stack-form">
        <div class="form-grid"><label>Kaynak<select name="source" required>@foreach($sources as $key => $source)<option value="{{ $key }}">{{ $source['title'] }}</option>@endforeach</select></label><label>Dosya biçimi<select name="format" required><option value="csv">CSV</option><option value="xlsx">Excel (XLSX)</option><option value="pdf">PDF</option></select></label></div>
        @foreach($sources as $sourceKey => $source)
            <fieldset><legend>{{ $source['title'] }}</legend><div class="form-grid">@foreach($source['columns'] as $column)<label><input type="checkbox" name="columns[]" value="{{ $column }}"> {{ $column }}</label>@endforeach</div></fieldset>
        @endforeach
        <button class="secondary-button" type="submit">Dosyayı indir</button>
    </form>
</section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">Kayıtlı raporlar</p><h2>Tanımlar</h2></div></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Ad</th><th>Kaynak</th><th>Kolonlar</th><th>Paylaşılan</th></tr></thead><tbody>@forelse($reports as $report)<tr><td>{{ $report->name }}</td><td>{{ $report->source }}</td><td>{{ implode(', ', $report->columns ?? []) }}</td><td>{{ $report->is_shared ? 'Evet' : 'Hayır' }}</td></tr>@empty<tr><td colspan="4">Kayıtlı rapor yok.</td></tr>@endforelse</tbody></table></div>{{ $reports->links() }}</section>
<script>
document.querySelector('[data-column-search]')?.addEventListener('input',event=>{const q=event.target.value.toLocaleLowerCase('tr-TR');document.querySelectorAll('[data-report-column]').forEach(item=>item.hidden=q!==''&&!item.dataset.reportColumn.includes(q));});
</script>
@endsection
