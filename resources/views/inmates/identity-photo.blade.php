@extends('layouts.portal')
@section('title', 'Kimlik Fotoğrafı')
@section('page-title', 'Kimlik Fotoğrafı')
@section('content')
<style>.identity-camera-box{margin-top:1rem;border:1px solid var(--line);border-radius:14px;padding:1rem;background:#fbfbfe}.identity-camera-box video,.identity-camera-box canvas{display:block;width:min(100%,360px);aspect-ratio:4/5;object-fit:cover;border-radius:12px;background:#1f2140}.identity-thumb{width:72px;height:90px;object-fit:cover;border-radius:8px;border:1px solid var(--line)}</style>
<section class="panel narrow-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Hükümlü kimlik/fotoğraf</p><h2>{{ $inmate->full_name }}</h2></div><a class="secondary-button small-button" href="{{ route('inmates.edit', [$inmate, 'tab' => 'files']) }}">Dosyaya dön</a></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="error-alert">{{ $errors->first() }}</div>@endif
    <p class="muted-text">Fotoğraflar public klasöre alınmaz. Webcam çalışmazsa dosya yükleme alanını kullan.</p>
</section>
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Webcam / yükleme</p><h2>Yeni kimlik fotoğrafı</h2></div></div>
    <form method="post" action="{{ route('inmates.identity-photo.store', $inmate) }}" enctype="multipart/form-data" class="stack-form" data-camera-form>
        @csrf
        <div class="form-grid">
            <label>Dosyadan yükle<input type="file" name="photo_upload" accept="image/jpeg,image/png"></label>
        </div>
        <div class="identity-camera-box">
            <video data-camera-video playsinline muted></video>
            <canvas data-camera-canvas width="480" height="600" hidden></canvas>
            <input type="hidden" name="captured_photo" data-camera-output>
            <div class="form-actions">
                <button class="secondary-button small-button" type="button" data-camera-start>Kamerayı aç</button>
                <button class="secondary-button small-button" type="button" data-camera-capture>Fotoğraf çek</button>
                <button class="secondary-button small-button" type="button" data-camera-clear>Temizle</button>
            </div>
        </div>
        <button class="primary-button" type="submit">Fotoğrafı kaydet</button>
    </form>
</section>
<section class="panel narrow-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Fotoğraf yönetimi</p><h2>Kayıtlı fotoğraflar</h2></div><a class="primary-link small-button" href="{{ route('inmates.identity-card', $inmate) }}">Kimlik kartı yazdır</a></div>
    <div class="table-wrap"><table class="data-table compact-table"><thead><tr><th>Önizleme</th><th>Ad</th><th>Boyut</th><th>Hash</th><th>Tarih</th><th></th></tr></thead><tbody>
    @forelse($photos as $photo)
        <tr>
            <td><img class="identity-thumb" src="{{ route('inmates.identity-photo.image', [$inmate, $photo]) }}" alt="Kimlik fotoğrafı"></td>
            <td>{{ $photo->display_name ?: $photo->original_name }}</td>
            <td>{{ $photo->humanSize() }}</td>
            <td><code>{{ substr($photo->sha256, 0, 12) }}…</code></td>
            <td>{{ $photo->created_at?->format('d.m.Y H:i') }}</td>
            <td class="actions-cell"><a class="secondary-button small-button" href="{{ route('inmates.identity-photo.image', [$inmate, $photo]) }}">Aç</a><form method="post" action="{{ route('inmates.identity-photo.destroy', [$inmate, $photo]) }}">@csrf @method('delete')<button class="danger-button small-button" type="submit">Sil</button></form></td>
        </tr>
    @empty
        <tr><td colspan="6">Kimlik fotoğrafı yok.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endsection
