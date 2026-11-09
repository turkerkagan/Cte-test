@extends('layouts.portal')
@section('title', 'Yeni Görev Kaydı')
@section('page-title', 'Yeni Görev Kaydı')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Görev geçmişi</p><h2>Yeni görev kaydı</h2></div></div>
    <form method="post" action="{{ route('inmates.jobs.store', $inmate) }}">
        @include('work-assignments._form')
    </form>
</section>
@endsection
