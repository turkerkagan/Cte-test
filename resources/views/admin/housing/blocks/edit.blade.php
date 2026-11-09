@extends('layouts.portal')
@section('title', 'Blok Düzenle')
@section('page-title', 'Blok Düzenle')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Yerleşim</p><h2>{{ $block->name }}</h2></div></div><form method="post" action="{{ route('admin.housing.blocks.update', $block) }}">@method('put')@include('admin.housing.blocks._form')</form></section>
@endsection
