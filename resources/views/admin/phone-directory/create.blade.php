@extends('layouts.portal')
@section('title', 'Yeni Telefon Kaydı')
@section('page-title', 'Yeni Telefon Kaydı')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Telefon Rehberi</p><h2>Yeni kayıt</h2></div></div><form method="post" action="{{ route('admin.phone-directory.store') }}">@include('admin.phone-directory._form')</form></section>
@endsection
