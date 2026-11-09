@extends('layouts.portal')
@section('title', 'Yeni Ranza')
@section('page-title', 'Yeni Ranza')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Yerleşim</p><h2>Yeni ranza</h2></div></div><form method="post" action="{{ route('admin.housing.beds.store') }}">@include('admin.housing.beds._form')</form></section>
@endsection
