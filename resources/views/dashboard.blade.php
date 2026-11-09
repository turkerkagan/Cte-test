@extends('layouts.portal')
@section('title', 'Ana Panel — CTE Portal')
@section('page-title', 'Ana Panel')
@section('content')
@php
    $dashboardStats = [
        ['Aktif Hükümlü', \App\Models\InmateFile::where('is_active', true)->count(), 'Güncel aktif kayıt'],
        ['Bugünkü İşlem', \App\Models\AuditEvent::whereDate('created_at', today())->count(), 'Bugünkü audit olayı'],
        ['Aktif Kullanıcı', \App\Models\User::where('is_active', true)->count(), 'Sisteme erişebilen kullanıcı'],
        ['Açık Modül', count(app(\App\Services\UnitRegistry::class)->visible()) + count(\App\Http\Controllers\OperationModuleController::MODULES), 'Kullanıma açık birim ve operasyon'],
    ];
@endphp
<section class="hero-card">
    <div><p class="eyebrow">{{ now()->translatedFormat('d F Y, l') }}</p><h1>İyi çalışmalar, {{ auth()->user()->full_name }}.</h1><p>CTE Portal hükümlü, birim, operasyon, rapor ve yönetim işlemleriyle yerel ağda kullanıma hazır.</p></div>
    <div class="hero-shield"><span>✓</span><strong>Yerel ve güvenli</strong><small>İnternet bağlantısı gerektirmez</small></div>
</section>
<section class="stat-grid">
    @foreach ($dashboardStats as [$title,$value,$note])
    <article class="stat-card"><span>{{ $title }}</span><strong>{{ $value }}</strong><small>{{ $note }}</small></article>
    @endforeach
</section>
<section class="dashboard-grid">
    <article class="panel"><div class="panel-heading"><div><p class="eyebrow">Hızlı erişim</p><h2>Kurum birimleri</h2></div><span class="pill">Aktif</span></div><div class="module-grid">@foreach(collect(app(\App\Services\UnitRegistry::class)->visible())->take(8) as $code => $module)<a href="{{ route('unit-records.index', $code) }}"><span>{{ mb_substr($module['title'],0,1) }}</span><strong>{{ $module['title'] }}</strong><small>Aç</small></a>@endforeach</div></article>
    <article class="panel timeline"><div class="panel-heading"><div><p class="eyebrow">Sistem</p><h2>Kurulum durumu</h2></div></div><ol><li class="done"><span></span><div><strong>Laravel 13 temeli</strong><small>Tamamlandı</small></div></li><li class="done"><span></span><div><strong>Kimlik ve yetki altyapısı</strong><small>Tamamlandı</small></div></li><li class="done"><span></span><div><strong>Hükümlü ve birimler</strong><small>Tamamlandı</small></div></li><li class="active"><span></span><div><strong>Üretim kabulü</strong><small>Fiziksel sunucu doğrulaması bekliyor</small></div></li></ol></article>
</section>
@endsection
