@extends('layouts.portal')
@section('title', 'Menü Düzenle — CTE Portal')
@section('page-title', 'Menü Düzenle')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Yönetim</p><h2>{{ $menuItem->title }}</h2></div></div>
    <form method="post" action="{{ route('admin.menus.update', $menuItem) }}">
        @method('put')
        @include('admin.menus._form')
    </form>
</section>
@endsection
