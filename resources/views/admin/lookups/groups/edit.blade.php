@extends('layouts.portal')
@section('title', 'Tanım Grubu Düzenle — CTE Portal')
@section('page-title', 'Tanım Grubu Düzenle')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Tanım Yönetimi</p><h2>{{ $group->name }}</h2></div></div>
    <form method="post" action="{{ route('admin.lookups.groups.update', $group) }}">
        @method('put')
        @include('admin.lookups.groups._form')
    </form>
</section>
@endsection
