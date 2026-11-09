@extends('layouts.portal')
@section('title', 'Telefon Kaydı Düzenle')
@section('page-title', 'Telefon Kaydı Düzenle')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Telefon Rehberi</p><h2>{{ $entry->title }}</h2></div></div><form method="post" action="{{ route('admin.phone-directory.update', $entry) }}">@method('put')@include('admin.phone-directory._form')</form></section>
@endsection
