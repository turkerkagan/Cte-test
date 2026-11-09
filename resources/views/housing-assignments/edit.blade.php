@extends('layouts.portal')
@section('title', 'Konum Kaydı Düzenle')
@section('page-title', 'Konum Kaydı Düzenle')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Konum geçmişi</p><h2>Konum kaydını düzenle</h2></div></div>
    <form method="post" action="{{ route('inmates.housing.update', [$inmate, $assignment]) }}">
        @method('put')
        @include('housing-assignments._form')
    </form>
</section>
@endsection
