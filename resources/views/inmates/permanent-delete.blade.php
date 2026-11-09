@extends('layouts.portal')
@section('title', 'Kalıcı Silme Onayı')
@section('page-title', 'Kalıcı Silme Onayı')
@section('content')
<section class="panel narrow-panel danger-panel">
    <div class="panel-heading"><div><p class="eyebrow">Güvenlik kritik işlem</p><h2>Hükümlü dosyasını kalıcı sil</h2></div></div>
    <div class="error-alert">
        Bu işlem geri alınamaz. Ana dosya, konum geçmişi, görev geçmişi ve private dosya metadata kayıtları aktif veritabanından kaldırılır. Private dosyalar önce karantinaya alınır, işlem başarılı olursa karantina temizlenir.
    </div>
    <div class="table-wrap">
        <table class="data-table compact-table">
            <tbody>
                <tr><th>Ad soyad</th><td>{{ $inmate->full_name }}</td></tr>
                <tr><th>Legacy ID</th><td>{{ $inmate->legacy_id ?? '—' }}</td></tr>
                <tr><th>Konum kaydı</th><td>{{ $inmate->housing_assignments_count }}</td></tr>
                <tr><th>Görev kaydı</th><td>{{ $inmate->work_assignments_count }}</td></tr>
                <tr><th>Private dosya</th><td>{{ $inmate->private_files_count }}</td></tr>
            </tbody>
        </table>
    </div>
    <form method="post" action="{{ route('inmates.permanent-delete.destroy', $inmate) }}" class="stack-form">
        @csrf
        @method('delete')
        <label>Parola
            <input type="password" name="password" autocomplete="current-password" required>
            @error('password')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>Onay metni
            <input name="confirm_text" value="{{ old('confirm_text') }}" placeholder="{{ $confirmText }}" required>
            @error('confirm_text')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <div class="check-row"><label><input type="checkbox" name="confirm_permanent" value="1" required> Bu işlemin geri alınamayacağını ve kalıcı silme olduğunu onaylıyorum.</label></div>
        @error('confirm_permanent')<small class="field-error">{{ $message }}</small>@enderror
        @error('delete')<div class="error-alert">{{ $message }}</div>@enderror
        <div class="form-actions"><a class="secondary-button" href="{{ route('inmates.edit', $inmate) }}">Vazgeç</a><button class="danger-button compact" type="submit">Kalıcı sil</button></div>
    </form>
</section>
@endsection
