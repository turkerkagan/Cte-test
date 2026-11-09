@extends('layouts.portal')
@section('title', 'Yeni Menü — CTE Portal')
@section('page-title', 'Yeni Menü')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Yönetim</p><h2>Yeni menü oluştur</h2></div></div>
    <form method="post" action="{{ route('admin.menus.store') }}">
        @include('admin.menus._form')
    </form>
</section>
@endsection
