@extends('layouts.portal')
@section('title', 'Kimlik Kartı Baskısı')
@section('page-title', 'Kimlik Kartı Baskısı')
@section('content')
<style>
.identity-selection{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.65rem;max-height:430px;overflow:auto;padding:.25rem}
.identity-person{display:flex;align-items:center;gap:.7rem;border:1px solid var(--line);border-radius:12px;padding:.7rem;background:var(--surface)}
.identity-person small{display:block}.identity-print-grid{display:grid;grid-template-columns:repeat({{ $settings['cards_per_row'] }},1fr);gap:6mm}
.identity-batch-card{width:{{ $settings['card_width'] }}mm;min-height:{{ $settings['card_height'] }}mm;border:1px solid #222;border-radius:4mm;background:#fff;padding:4mm;color:#111;page-break-inside:avoid}
.identity-batch-card header,.identity-batch-card footer{display:flex;justify-content:space-between;gap:3mm;font-size:8px;border-bottom:1px solid #ddd;padding-bottom:2mm}.identity-batch-card footer{border:0;border-top:1px solid #ddd;margin-top:2mm;padding-top:2mm}
.identity-batch-body{display:grid;grid-template-columns:{{ $settings['photo_width'] }}mm 1fr;gap:3mm;margin-top:3mm}.identity-batch-photo{width:{{ $settings['photo_width'] }}mm;height:{{ $settings['photo_height'] }}mm;border:1px solid #aaa;display:grid;place-items:center;font-size:8px;text-align:center}.identity-batch-photo img{width:100%;height:100%;object-fit:cover}
.identity-batch-info h2{font-size:12px;margin:0 0 2mm}.identity-batch-info dl{display:grid;grid-template-columns:15mm 1fr;gap:1mm 2mm;font-size:8px;margin:0}.identity-batch-info dt{font-weight:800}.identity-batch-info dd{margin:0}
@media print{.no-print,.topbar,.sidebar,.sidebar-backdrop{display:none!important}.portal-main{padding:0!important}.identity-print-grid{gap:4mm}}
</style>
<section class="panel no-print">
    <div class="panel-heading"><div><p class="eyebrow">Eski işlev · yeni tasarım</p><h2>Toplu kimlik kartı hazırlama</h2><p class="muted-text">Kişileri seçin, kart ölçülerini belirleyin, önizleyin ve yazdırın.</p></div></div>
    <form method="get" class="stack-form" id="identity-card-form">
        <div class="form-grid">
            <label>İsim veya TC ara<input name="q" value="{{ request('q') }}" placeholder="İsim veya TC"></label>
            <label>Kart genişliği (mm)<input type="number" name="card_width" value="{{ $settings['card_width'] }}" min="50" max="150" step=".1"></label>
            <label>Kart yüksekliği (mm)<input type="number" name="card_height" value="{{ $settings['card_height'] }}" min="30" max="150" step=".1"></label>
            <label>Fotoğraf genişliği (mm)<input type="number" name="photo_width" value="{{ $settings['photo_width'] }}" min="15" max="40" step=".1"></label>
            <label>Fotoğraf yüksekliği (mm)<input type="number" name="photo_height" value="{{ $settings['photo_height'] }}" min="20" max="50" step=".1"></label>
            <label>Satırdaki kart<select name="cards_per_row"><option value="2" @selected($settings['cards_per_row']==2)>2</option><option value="3" @selected($settings['cards_per_row']==3)>3</option><option value="4" @selected($settings['cards_per_row']==4)>4</option></select></label>
            <label>Kurum adı<input name="institution_name" value="{{ $settings['institution_name'] }}" maxlength="80"></label>
        </div>
        <div class="actions-cell"><button type="button" class="secondary-button small-button" data-select-all>Tümünü seç</button><button type="button" class="secondary-button small-button" data-select-none>Seçimi temizle</button><button class="primary-button" type="submit">Kartları oluştur</button>@if($selected->isNotEmpty())<button class="secondary-button" type="button" onclick="window.print()">Yazdır</button>@endif</div>
        <p class="muted-text">Toplam {{ $inmates->count() }} kişi · seçili {{ count($selectedIds) }} kişi</p>
        <div class="identity-selection">
            @foreach($inmates as $inmate)
                <label class="identity-person"><input type="checkbox" name="inmate_ids[]" value="{{ $inmate->id }}" @checked(in_array($inmate->id,$selectedIds,true))><span><strong>{{ $inmate->full_name }}</strong><small>{{ $inmate->identity_number ?: 'TC yok' }} · {{ $inmate->privateFiles->isNotEmpty() ? 'Fotoğraf var' : 'Fotoğraf yok' }}</small></span></label>
            @endforeach
        </div>
    </form>
</section>
@if($selected->isNotEmpty())
<section class="identity-print-grid">
@foreach($selected as $inmate)
    @php($housing=$inmate->activeHousingAssignment->first())
    @php($photo=$inmate->privateFiles->first())
    <article class="identity-batch-card">
        <header><strong>{{ $settings['institution_name'] }}</strong><span>Hükümlü Kimlik Kartı</span></header>
        <div class="identity-batch-body">
            <div class="identity-batch-photo">@if($photo)<img src="{{ route('inmates.identity-photo.image',[$inmate,$photo]) }}" alt="">@else Fotoğraf Yok @endif</div>
            <div class="identity-batch-info"><h2>{{ $inmate->full_name }}</h2><dl>
                <dt>TC</dt><dd>{{ $inmate->identity_number ?: '—' }}</dd>
                <dt>Durum</dt><dd>{{ $inmate->status_code ?: '—' }}</dd>
                <dt>Konum</dt><dd>{{ $housing?->block?->name ?: '—' }} / {{ $housing?->ward?->name ?: '—' }} / {{ $housing?->bed?->label ?: '—' }}</dd>
                <dt>Kayıt</dt><dd>{{ $inmate->registered_at?->format('d.m.Y') ?: '—' }}</dd>
            </dl></div>
        </div>
        <footer><span>{{ now()->format('d.m.Y H:i') }}</span><span>CTE Portal</span></footer>
    </article>
@endforeach
</section>
@endif
<script>
document.querySelector('[data-select-all]')?.addEventListener('click',()=>document.querySelectorAll('input[name="inmate_ids[]"]').forEach(el=>el.checked=true));
document.querySelector('[data-select-none]')?.addEventListener('click',()=>document.querySelectorAll('input[name="inmate_ids[]"]').forEach(el=>el.checked=false));
</script>
@endsection
