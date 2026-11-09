@extends('layouts.portal')
@section('title', 'Yeni Blok')
@section('page-title', 'Yeni Blok')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Yerleşim</p><h2>Yeni blok</h2></div></div><form method="post" action="{{ route('admin.housing.blocks.store') }}">@include('admin.housing.blocks._form')</form></section>
@endsection
