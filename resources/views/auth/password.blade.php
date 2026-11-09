@extends('layouts.portal')
@section('title', 'Şifre Değiştir')
@section('page-title', 'Şifre Değiştir')
@section('content')
<section class="panel narrow-panel password-change-panel">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">Kullanıcı İşlemleri</p>
            <h2>Şifrenizi değiştirin</h2>
            <p class="muted-text">Yeni şifre en az 8 karakter olmalı ve harf ile rakam içermelidir.</p>
        </div>
    </div>
    @if(session('password_notice'))<div class="alert">{{ session('password_notice') }}</div>@endif
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <form method="post" action="{{ route('profile.password.update') }}" class="stack-form">
        @csrf
        @method('put')
        <label>Mevcut şifre
            <input type="password" name="current_password" autocomplete="current-password" required>
            @error('current_password')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>Yeni şifre
            <input type="password" name="password" autocomplete="new-password" required>
            @error('password')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>Yeni şifre tekrar
            <input type="password" name="password_confirmation" autocomplete="new-password" required>
        </label>
        <div class="form-actions">
            <a class="secondary-button" href="{{ route('dashboard') }}">Vazgeç</a>
            <button class="primary-button compact" type="submit">Şifreyi Değiştir</button>
        </div>
    </form>
</section>
@endsection
