@extends('layouts.portal')
@section('title', 'Yeni Hükümlü Ana Dosyası')
@section('page-title', 'Yeni Hükümlü Ana Dosyası')
@section('content')
<section class="panel narrow-panel"><div class="panel-heading"><div><p class="eyebrow">Hükümlü çekirdeği</p><h2>Yeni ana dosya</h2></div></div><form method="post" action="{{ route('inmates.store') }}">@include('inmates._form')</form></section>
@endsection
