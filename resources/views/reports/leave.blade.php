@extends('layouts.portal')
@section('title', 'İzin Raporu')
@section('page-title', 'İzin Raporu')
@section('content')
<section class="panel report-panel">
    <div class="panel-heading table-heading">
        <div><p class="eyebrow">Günlük izin giriş-çıkış takibi</p><h2>İzin Raporu</h2></div>
        <button class="secondary-button" type="button" onclick="window.print()">Yazdır</button>
    </div>
    <form class="toolbar no-print" method="get">
        <input type="date" name="date" value="{{ $date }}" required>
        <button class="primary-link" type="submit">Tarihi Getir</button>
    </form>
    <div class="report-summary"><article class="stat-card"><span>İzne ayrılacak</span><strong>{{ $leaving->count() }}</strong></article><article class="stat-card"><span>İzinden dönecek</span><strong>{{ $returning->count() }}</strong></article></div>
</section>
<section class="panel report-panel"><div class="panel-heading table-heading"><div><p class="eyebrow">{{ \Carbon\Carbon::parse($date)->format('d.m.Y') }}</p><h2>İzne Ayrılacaklar</h2></div></div>@include('reports._leave-table', ['rows' => $leaving])</section>
<section class="panel report-panel"><div class="panel-heading table-heading"><div><p class="eyebrow">{{ \Carbon\Carbon::parse($date)->format('d.m.Y') }}</p><h2>İzinden Dönecekler</h2></div></div>@include('reports._leave-table', ['rows' => $returning])</section>
@endsection
