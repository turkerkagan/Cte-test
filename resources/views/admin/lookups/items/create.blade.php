@extends('layouts.portal')
@section('title', 'Yeni Tanım Kalemi')
@section('page-title', 'Yeni Tanım Kalemi')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">{{ $group->name }}</p><h2>Yeni kalem</h2></div></div>
    <form method="post" action="{{ route('admin.lookups.items.store', $group) }}">
        @include('admin.lookups.items._form')
    </form>
</section>
@endsection
