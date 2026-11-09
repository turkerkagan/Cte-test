@extends('layouts.portal')
@section('title', 'Yeni Koğuş')
@section('page-title', 'Yeni Koğuş')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Yerleşim</p><h2>Yeni koğuş</h2></div></div><form method="post" action="{{ route('admin.housing.wards.store') }}">@include('admin.housing.wards._form')</form></section>
@endsection
