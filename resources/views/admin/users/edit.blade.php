@extends('layouts.portal')
@section('title', 'Kullanıcı Düzenle — CTE Portal')
@section('page-title', 'Kullanıcı Düzenle')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Yönetim</p><h2>{{ $managedUser->full_name }}</h2></div></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <form method="post" action="{{ route('admin.users.update', $managedUser) }}">
        @method('put')
        @include('admin.users._form')
    </form>
</section>
<section class="panel narrow-panel danger-panel">
    <div class="panel-heading"><div><p class="eyebrow">Güvenlik</p><h2>Parola ve oturum</h2></div></div>
    <form method="post" action="{{ route('admin.users.password.update', $managedUser) }}" class="stack-form">
        @csrf
        @method('patch')
        <label>Yeni geçici parola
            <input type="password" name="password" required>
            @error('password')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>Yeni geçici parola tekrar
            <input type="password" name="password_confirmation" required>
        </label>
        <label class="inline-check"><input type="checkbox" name="must_change_password" value="1" checked> İlk girişte parola değiştirsin</label>
        <button class="secondary-button" type="submit">Parolayı güncelle ve oturumları kapat</button>
    </form>
</section>
@endsection
