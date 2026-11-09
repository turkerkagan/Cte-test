@extends('layouts.portal')
@section('title', 'İşlem Kısayolları')
@section('page-title', 'İşlem Kısayolları')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Toplu durum geçişi</p><h2>İşlem Kısayolları</h2><p class="muted-text">Eski durumdaki bütün hükümlüler seçilen yeni duruma çevrilir.</p></div></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="error-alert">{{ $errors->first() }}</div>@endif
    <form method="get" class="stack-form">
        <div class="form-grid">
            <label>Mevcut durum<select name="from_status" required><option value="">Seçin</option>@foreach($statuses as $code => $label)<option value="{{ $code }}" @selected($fromStatus === $code)>{{ $label }}</option>@endforeach</select></label>
            <label>Yeni durum<select name="to_status" required><option value="">Seçin</option>@foreach($statuses as $code => $label)<option value="{{ $code }}" @selected($toStatus === $code)>{{ $label }}</option>@endforeach</select></label>
        </div>
        <button class="secondary-button" type="submit">Önizle</button>
    </form>
    @if($fromStatus && $toStatus)
        <div class="shortcut-preview"><span>Etkilenecek kayıt</span><strong>{{ $affectedCount }}</strong><p><b>{{ $fromStatus }}</b> → <b>{{ $toStatus }}</b></p></div>
        <form method="post" action="{{ route('inmates.shortcuts.update') }}">
            @csrf
            <input type="hidden" name="from_status" value="{{ $fromStatus }}">
            <input type="hidden" name="to_status" value="{{ $toStatus }}">
            <input type="hidden" name="confirm_count" value="{{ $affectedCount }}">
            <button class="primary-button" type="submit" @disabled($fromStatus === $toStatus)>Toplu Durum Geçişini Uygula</button>
        </form>
    @endif
</section>
@endsection
