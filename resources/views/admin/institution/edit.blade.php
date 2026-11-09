@extends('layouts.portal')
@section('title', 'Kurum Profili — CTE Portal')
@section('page-title', 'Kurum Profili')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading">
        <div>
            <p class="eyebrow">Yönetim</p>
            <h2>Kurum bilgileri</h2>
        </div>
    </div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <form method="post" action="{{ route('admin.institution.update') }}" enctype="multipart/form-data">
        @csrf
        @method('put')
        <div class="form-grid">
            <label>Kurum adı
                <input name="name" value="{{ old('name', $institution->name) }}" required>
                @error('name')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>Kısa ad
                <input name="short_name" value="{{ old('short_name', $institution->short_name) }}">
                @error('short_name')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>Telefon
                <input name="phone" value="{{ old('phone', $institution->phone) }}">
                @error('phone')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>Faks
                <input name="fax" value="{{ old('fax', $institution->fax) }}">
                @error('fax')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>E-posta
                <input type="email" name="email" value="{{ old('email', $institution->email) }}">
                @error('email')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>Logo
                <input type="file" name="logo" accept="image/*">
                @error('logo')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>Ana renk
                <input type="color" name="primary_color" value="{{ old('primary_color', $institution->primary_color) }}" required>
                @error('primary_color')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>Vurgu rengi
                <input type="color" name="accent_color" value="{{ old('accent_color', $institution->accent_color) }}" required>
                @error('accent_color')<small class="field-error">{{ $message }}</small>@enderror
            </label>
        </div>
        <label class="wide-label">Adres
            <textarea name="address" rows="3">{{ old('address', $institution->address) }}</textarea>
            @error('address')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        @if($institution->logo_path)
            <div class="check-row"><label><input type="checkbox" name="remove_logo" value="1"> Mevcut logoyu kaldır</label></div>
        @endif
        <div class="panel-subtitle"><p class="eyebrow">Giriş ekranı</p><h3>Karşılama metinleri</h3></div>
        <div class="form-grid">
            <label>Üst açıklama
                <input name="login_eyebrow" value="{{ old('login_eyebrow', $institution->login_eyebrow) }}" required>
                @error('login_eyebrow')<small class="field-error">{{ $message }}</small>@enderror
            </label>
            <label>Başlık
                <input name="login_heading" value="{{ old('login_heading', $institution->login_heading) }}" required>
                @error('login_heading')<small class="field-error">{{ $message }}</small>@enderror
            </label>
        </div>
        <label class="wide-label">Açıklama
            <textarea name="login_description" rows="3">{{ old('login_description', $institution->login_description) }}</textarea>
            @error('login_description')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label class="wide-label">Güvenlik notu
            <input name="login_security_note" value="{{ old('login_security_note', $institution->login_security_note) }}" required>
            @error('login_security_note')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <div class="form-actions">
            <button class="primary-button compact" type="submit">Kurum profilini kaydet</button>
        </div>
    </form>
</section>
@endsection
