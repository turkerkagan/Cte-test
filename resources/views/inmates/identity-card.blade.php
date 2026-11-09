@extends('layouts.portal')
@section('title', 'Kimlik Kartı')
@section('page-title', 'Kimlik Kartı')
@section('content')
<style>.identity-card-print{display:flex;justify-content:center}.identity-card{width:86mm;min-height:54mm;border:1px solid #222;border-radius:4mm;background:#fff;padding:4mm;color:#111}.identity-card header,.identity-card footer{display:flex;justify-content:space-between;gap:4mm;border-bottom:1px solid #ddd;padding-bottom:2mm;font-size:9px}.identity-card footer{border-bottom:0;border-top:1px solid #ddd;padding-top:2mm;margin-top:3mm}.identity-card-body{display:grid;grid-template-columns:25mm 1fr;gap:4mm;margin-top:3mm}.identity-card-photo{width:25mm;height:32mm;border:1px solid #aaa;display:grid;place-items:center;font-size:8px;text-align:center}.identity-card-photo img{width:100%;height:100%;object-fit:cover}.identity-card-info h2{margin:0 0 2mm;font-size:13px}.identity-card-info dl{display:grid;grid-template-columns:18mm 1fr;gap:1mm 2mm;margin:0;font-size:9px}.identity-card-info dt{font-weight:800}.identity-card-info dd{margin:0}@media print{.no-print,.topbar,.sidebar,.sidebar-backdrop{display:none!important}.identity-card-print{display:block}.identity-card{page-break-inside:avoid}}</style>
<section class="panel narrow-panel no-print">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Yazdırılabilir kimlik</p><h2>{{ $inmate->full_name }}</h2></div><div class="actions-cell"><a class="secondary-button small-button" href="{{ route('inmates.identity-photo.create', $inmate) }}">Fotoğraf yönetimi</a><button class="primary-button small-button" type="button" onclick="window.print()">Yazdır</button></div></div>
</section>
<section class="identity-card-print">
    <article class="identity-card">
        <header><strong>{{ $inmate->institution?->displayShortName() ?? 'CTE' }}</strong><span>Hükümlü Kimlik Kartı</span></header>
        <div class="identity-card-body">
            <div class="identity-card-photo">
                @if($photo)<img src="{{ route('inmates.identity-photo.image', [$inmate, $photo]) }}" alt="Kimlik fotoğrafı">@else<span>Fotoğraf Yok</span>@endif
            </div>
            <div class="identity-card-info">
                <h2>{{ $inmate->full_name }}</h2>
                <dl>
                    <dt>TC</dt><dd>{{ $inmate->identity_number ?? 'TC yok' }}</dd>
                    <dt>Legacy ID</dt><dd>{{ $inmate->legacy_id ?? '—' }}</dd>
                    <dt>Durum</dt><dd>{{ $inmate->status_code ?? '—' }}</dd>
                    <dt>Risk</dt><dd>{{ $inmate->risk_code ?? '—' }}</dd>
                    <dt>Konum</dt><dd>{{ $activeHousing?->block?->name ?? '—' }} / {{ $activeHousing?->ward?->name ?? '—' }} / {{ $activeHousing?->bed?->label ?? '—' }}</dd>
                    <dt>Kayıt</dt><dd>{{ $inmate->registered_at?->format('d.m.Y') ?? '—' }}</dd>
                </dl>
            </div>
        </div>
        <footer><span>Yazdırma: {{ now()->format('d.m.Y H:i') }}</span><span>CTE Portal</span></footer>
    </article>
</section>
@endsection
