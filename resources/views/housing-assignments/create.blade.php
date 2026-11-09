@extends('layouts.portal')
@section('title', 'Yeni Konum Kaydı')
@section('page-title', 'Yeni Konum Kaydı')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Konum geçmişi</p><h2>Yeni konum kaydı</h2></div></div>
    <form method="post" action="{{ route('inmates.housing.store', $inmate) }}">
        @include('housing-assignments._form')
    </form>
</section>
@endsection
