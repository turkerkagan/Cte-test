@extends('layouts.portal')
@section('title', 'Tanım Kalemi Düzenle')
@section('page-title', 'Tanım Kalemi Düzenle')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">{{ $group->name }}</p><h2>{{ $item->label }}</h2></div></div>
    <form method="post" action="{{ route('admin.lookups.items.update', [$group, $item]) }}">
        @method('put')
        @include('admin.lookups.items._form')
    </form>
</section>
@endsection
