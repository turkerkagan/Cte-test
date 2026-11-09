@extends('layouts.portal')
@section('title', 'Ranza Düzenle')
@section('page-title', 'Ranza Düzenle')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Yerleşim</p><h2>{{ $bed->label }}</h2></div></div><form method="post" action="{{ route('admin.housing.beds.update', $bed) }}">@method('put')@include('admin.housing.beds._form')</form></section>
@endsection
