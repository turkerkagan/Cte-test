@extends('layouts.portal')
@section('title', 'Yeni Kullanıcı — CTE Portal')
@section('page-title', 'Yeni Kullanıcı')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Yönetim</p><h2>Yeni kullanıcı oluştur</h2></div></div>
    <form method="post" action="{{ route('admin.users.store') }}">
        @include('admin.users._form')
    </form>
</section>
@endsection
