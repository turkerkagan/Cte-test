@extends('layouts.portal')
@section('title', 'Birim ve Tablo Oluşturucu')
@section('page-title', 'Birim ve Tablo Oluşturucu')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading"><div><h2>Birim ve Tablo Oluşturucu</h2><p class="muted-text">Özel birimlerin form alanlarını, tablo sütunlarını ve rol yetkilerini yönetin.</p></div><a class="primary-button" href="{{ route('admin.unit-definitions.create') }}">Yeni birim</a></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Birim</th><th>Alan sayısı</th><th>Durum</th><th>Menü</th><th>İşlemler</th></tr></thead><tbody>
    @forelse($definitions as $definition)
        <tr><td><strong>{{ $definition->title }}</strong><br><small>{{ $definition->code }}</small></td><td>{{ count($definition->fields) }}</td><td>{{ $definition->is_active ? 'Aktif' : 'Pasif' }}</td><td>{{ $definition->is_visible ? 'Görünür' : 'Gizli' }}</td><td class="actions-cell"><a class="secondary-button" href="{{ route('admin.unit-definitions.edit', $definition) }}">Düzenle</a>@if($definition->is_active)<a class="secondary-button" href="{{ route('unit-records.index', $definition->code) }}">Birimi aç</a>@endif</td></tr>
    @empty<tr><td colspan="5">Henüz özel birim oluşturulmadı.</td></tr>@endforelse
    </tbody></table></div>
    <p class="muted-text">Yeni birimler hükümlü dosyalarına bağlı kayıtlarla çalışır. Yerleşik birimlerin özel işlem kuralları kendi ekranlarında devam eder.</p>
</section>
@endsection
