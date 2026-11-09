@extends('layouts.portal')
@section('title', 'Görev Kaydı Düzenle')
@section('page-title', 'Görev Kaydı Düzenle')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Görev geçmişi</p><h2>Görev kaydını düzenle</h2></div></div>
    <form method="post" action="{{ route('inmates.jobs.update', [$inmate, $assignment]) }}">
        @method('put')
        @include('work-assignments._form')
    </form>
</section>
@endsection
