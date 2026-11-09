@extends('layouts.portal')
@section('title', 'Koğuş Düzenle')
@section('page-title', 'Koğuş Düzenle')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Yerleşim</p><h2>{{ $ward->name }}</h2></div></div><form method="post" action="{{ route('admin.housing.wards.update', $ward) }}">@method('put')@include('admin.housing.wards._form')</form></section>
@endsection
