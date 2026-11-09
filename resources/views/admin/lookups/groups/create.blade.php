@extends('layouts.portal')
@section('title', 'Yeni Tanım Grubu — CTE Portal')
@section('page-title', 'Yeni Tanım Grubu')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Tanım Yönetimi</p><h2>Yeni grup</h2></div></div>
    <form method="post" action="{{ route('admin.lookups.groups.store') }}">
        @include('admin.lookups.groups._form')
    </form>
</section>
@endsection
