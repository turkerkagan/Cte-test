<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giriş — CTE Portal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-body" @if($institution) style="--indigo: {{ $institution->primary_color }}; --violet: {{ $institution->accent_color }}" @endif>
<main class="login-layout">
    <section class="login-intro">
        <div class="login-badge">{{ $institution?->short_name ? mb_substr($institution->short_name, 0, 3) : 'CTE' }}</div>
        <p class="eyebrow">{{ $institution->login_eyebrow ?? 'Kurum içi yönetim sistemi' }}</p>
        <h1>{{ $institution->login_heading ?? 'Bilgiye hızlı, yetkiye göre erişim.' }}</h1>
        <p>{{ $institution->login_description ?? 'Ceza infaz kurumu birimlerini tek, güvenli ve çevrimdışı çalışabilen portalda buluşturur.' }}</p>
        <div class="security-note"><strong>Yerel ağ koruması</strong><span>{{ $institution->login_security_note ?? 'Veriler kurum sunucusundan dışarı çıkmaz.' }}</span></div>
    </section>
    <section class="login-panel">
        <form class="login-card" method="post" action="{{ route('login.store') }}">
            @csrf
            <div class="mobile-brand"><span>{{ $institution?->short_name ? mb_substr($institution->short_name, 0, 3) : 'CTE' }}</span> Portal</div>
            <p class="eyebrow">{{ $institution?->displayShortName() ?? 'CTE Portal' }}</p>
            <h2>Oturum açın</h2>
            <p class="form-hint">Sicil numaranız veya yönetici adınızla devam edin.</p>
            @if ($errors->any())<div class="alert" role="alert">{{ $errors->first() }}</div>@endif
            <label for="identifier">Sicil No / Yönetici Adı</label>
            <input id="identifier" name="identifier" value="{{ old('identifier') }}" autocomplete="username" required autofocus>
            <label for="password">Parola</label>
            <div class="password-field"><input id="password" name="password" type="password" autocomplete="current-password" required><button type="button" data-password-toggle aria-label="Parolayı göster">Göster</button></div>
            <button class="primary-button" type="submit">Giriş yap <span>→</span></button>
            <small class="login-footer">{{ $institution?->displayShortName() ?? 'CTE Portal' }} · Yetkisiz erişim yasaktır.</small>
        </form>
    </section>
</main>
</body>
</html>
