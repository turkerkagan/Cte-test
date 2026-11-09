@extends('layouts.portal')
@section('title', 'Hükümlü Ana Dosyası')
@section('page-title', 'Hükümlü Ana Dosyası')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Hükümlü çekirdeği</p><h2>{{ $inmate->full_name }}</h2></div>@can('permission', 'inmates.delete')<a class="danger-button small-button" href="{{ route('inmates.permanent-delete.create', $inmate) }}">Kalıcı sil</a>@endcan</div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <div class="toolbar">
        <a class="secondary-button small-button {{ $activeTab === 'overview' ? 'active' : '' }}" href="{{ route('inmates.edit', [$inmate, 'tab' => 'overview']) }}">Genel Profil</a>
        <a class="secondary-button small-button {{ $activeTab === 'identity' ? 'active' : '' }}" href="{{ route('inmates.edit', [$inmate, 'tab' => 'identity']) }}">Kimlik</a>
        <a class="secondary-button small-button {{ $activeTab === 'location' ? 'active' : '' }}" href="{{ route('inmates.edit', [$inmate, 'tab' => 'location']) }}">Konum</a>
        <a class="secondary-button small-button {{ $activeTab === 'jobs' ? 'active' : '' }}" href="{{ route('inmates.edit', [$inmate, 'tab' => 'jobs']) }}">Görev</a>
        <a class="secondary-button small-button {{ $activeTab === 'files' ? 'active' : '' }}" href="{{ route('inmates.edit', [$inmate, 'tab' => 'files']) }}">Private dosyalar</a>
        <a class="secondary-button small-button" href="{{ route('inmates.identity-card', $inmate) }}">Kimlik kartı</a>
        @can('permission', 'inmates.delete')<a class="secondary-button small-button {{ $activeTab === 'danger' ? 'active' : '' }}" href="{{ route('inmates.edit', [$inmate, 'tab' => 'danger']) }}">Güvenlik</a>@endcan
    </div>
</section>
@if($activeTab === 'overview')
@php($activeHousing = $inmate->housingAssignments->firstWhere('ended_at', null))
@php($activeWork = $inmate->workAssignments->firstWhere('ended_at', null))
<section class="profile-hero panel">
    <div>
        <p class="eyebrow">Birleşik hükümlü profili</p>
        <h2>{{ $inmate->full_name }}</h2>
        <p class="muted-text">{{ $inmate->identity_number ?: 'TC kimlik numarası yok' }} · {{ $inmate->institution?->displayShortName() }}</p>
    </div>
    <div class="actions-cell no-print">
        <button class="secondary-button small-button" type="button" onclick="window.print()">Yazdır</button>
        @can('permission', 'inmates.update')<a class="primary-link small-button" href="{{ route('inmates.edit', [$inmate, 'tab' => 'identity']) }}">Ana dosyayı düzenle</a>@endcan
    </div>
</section>
<section class="profile-summary-grid">
    <article class="stat-card"><span>Durum</span><strong>{{ $inmate->status_code ?: ($inmate->is_active ? 'AKTİF' : 'PASİF') }}</strong><small>{{ $inmate->registered_at?->format('d.m.Y') ?: 'Kayıt tarihi yok' }}</small></article>
    <article class="stat-card"><span>Konum</span><strong>{{ $activeHousing?->block?->name ?? '—' }} / {{ $activeHousing?->ward?->name ?? '—' }}</strong><small>Ranza {{ $activeHousing?->bed?->label ?? '—' }}</small></article>
    <article class="stat-card"><span>Çalışma</span><strong>{{ $activeWork?->place?->name ?? 'Çalışmıyor' }}</strong><small>{{ $inmate->decision_code ?? $activeWork?->decision_code ?? 'Karar yok' }}</small></article>
    <article class="stat-card"><span>Sağlık / Risk</span><strong>{{ $inmate->health_status ?: '—' }}</strong><small>{{ $inmate->risk_code ?: 'Risk kaydı yok' }}</small></article>
</section>
@if($inmate->notes)<section class="panel profile-note"><div class="panel-heading"><div><p class="eyebrow">Ana dosya</p><h2>Notlar</h2></div></div><p>{{ $inmate->notes }}</p></section>@endif

@forelse($visibleUnitModules as $moduleCode => $moduleConfig)
    @php($moduleRecords = $inmate->unitRecords->where('module_code', $moduleCode))
    <section class="panel profile-unit-section">
        <div class="panel-heading table-heading">
            <div><p class="eyebrow">{{ $moduleConfig['task'] }}</p><h2>{{ $moduleConfig['title'] }}</h2><p class="muted-text">{{ $moduleConfig['description'] }}</p></div>
            <div class="actions-cell no-print">
                <a class="secondary-button small-button" href="{{ route('unit-records.index', $moduleCode) }}">Modüle git</a>
                @if(auth()->user()?->hasPermission($moduleConfig['permission'].'.update'))<a class="primary-link small-button" href="{{ route('unit-records.create', [$moduleCode, $inmate]) }}">Kayıt ekle</a>@endif
            </div>
        </div>
        <div class="profile-record-list">
        @forelse($moduleRecords as $record)
            <article class="profile-record">
                <header><div><strong>{{ $record->title }}</strong><span>{{ $record->record_type }} · {{ $record->occurred_at?->format('d.m.Y') ?: 'Tarih yok' }}</span></div><span class="status-badge {{ $record->is_closed ? 'passive' : 'ok' }}">{{ $record->is_closed ? 'Kapalı' : 'Açık' }}</span></header>
                @if($record->data)
                    <dl>@foreach($record->data as $key => $value)@if($value !== null && $value !== '')<div><dt>{{ $key }}</dt><dd>{{ is_array($value) ? implode(', ', $value) : $value }}</dd></div>@endif @endforeach</dl>
                @endif
                @if($record->notes)<p>{{ $record->notes }}</p>@endif
                <footer><small>{{ $record->creator?->full_name ?: 'Kullanıcı belirtilmemiş' }}</small>@if(auth()->user()?->hasPermission($moduleConfig['permission'].'.update'))<a class="secondary-button small-button no-print" href="{{ route('unit-records.edit', [$moduleCode, $record]) }}">Düzenle</a>@endif</footer>
            </article>
        @empty
            <p class="muted-text">Bu birimde hükümlüye ait kayıt bulunmuyor.</p>
        @endforelse
        </div>
    </section>
@empty
    <section class="panel"><p class="muted-text">Görüntüleme yetkiniz bulunan birim kaydı yok.</p></section>
@endforelse
@endif
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Faz 5</p><h2>Birim modülleri</h2></div></div>
    <div class="module-grid">
        @forelse($visibleUnitModules as $moduleCode => $moduleConfig)
            <a class="module-chip" href="{{ auth()->user()?->hasPermission($moduleConfig['permission'].'.update') ? route('unit-records.create', [$moduleCode, $inmate]) : route('unit-records.index', $moduleCode) }}"><strong>{{ $moduleConfig['title'] }}</strong><small>{{ $moduleConfig['task'] }}</small></a>
        @empty
            <p class="muted-text">Görüntüleme yetkiniz bulunan birim modülü yok.</p>
        @endforelse
    </div>
</section>
@if($activeTab === 'identity')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Kimlik ve durum</p><h2>Ana dosya bilgileri</h2></div></div>
    @can('permission', 'inmates.update')
        <form method="post" action="{{ route('inmates.update', $inmate) }}">@method('put')@include('inmates._form')</form>
    @else
        <div class="table-wrap"><table class="data-table compact-table"><tbody>
            <tr><th>Kurum</th><td>{{ $inmate->institution?->name ?? '—' }}</td></tr>
            <tr><th>TC</th><td>{{ $inmate->identity_number ?? 'TC yok' }}</td></tr>
            <tr><th>Ad soyad</th><td>{{ $inmate->full_name }}</td></tr>
            <tr><th>Legacy ID</th><td>{{ $inmate->legacy_id ?? '—' }}</td></tr>
            <tr><th>Kayıt</th><td>{{ $inmate->registered_at?->format('d.m.Y') ?? '—' }}</td></tr>
            <tr><th>Giriş</th><td>{{ $inmate->entered_at?->format('d.m.Y') ?? '—' }}</td></tr>
            <tr><th>Durum</th><td>{{ $inmate->status_code ?? '—' }}</td></tr>
            <tr><th>Risk</th><td>{{ $inmate->risk_code ?? '—' }}</td></tr>
            <tr><th>Karar</th><td>{{ $inmate->decision_code ?? '—' }}</td></tr>
            <tr><th>Not</th><td>{{ $inmate->notes ?? '—' }}</td></tr>
        </tbody></table></div>
    @endcan
</section>
@endif
@if($activeTab === 'location')
<section class="panel narrow-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Konum geçmişi</p><h2>Blok / Koğuş / Ranza</h2></div>@can('permission', 'inmates.update')<a class="primary-link small-button" href="{{ route('inmates.housing.create', $inmate) }}">Konum ekle</a>@endcan</div>
    <div class="table-wrap"><table class="data-table compact-table"><thead><tr><th>Başlangıç</th><th>Bitiş</th><th>Blok</th><th>Koğuş</th><th>Ranza</th><th>Durum</th><th></th></tr></thead><tbody>
    @forelse($inmate->housingAssignments as $assignment)
        <tr><td>{{ $assignment->started_at?->format('d.m.Y') }}</td><td>{{ $assignment->ended_at?->format('d.m.Y') ?? '—' }}</td><td>{{ $assignment->block?->name ?? '—' }}</td><td>{{ $assignment->ward?->name ?? '—' }}</td><td>{{ $assignment->bed?->label ?? '—' }}</td><td><span class="status-badge {{ $assignment->ended_at ? 'passive' : 'ok' }}">{{ $assignment->ended_at ? 'Kapalı' : 'Aktif' }}</span></td><td class="actions-cell">
            @can('permission', 'inmates.update')
                <a class="secondary-button small-button" href="{{ route('inmates.housing.edit', [$inmate, $assignment]) }}">Düzenle</a>
                @if(! $assignment->ended_at)
                    <form method="post" action="{{ route('inmates.housing.close', [$inmate, $assignment]) }}">@csrf @method('patch')<button class="secondary-button small-button" type="submit">Kapat</button></form>
                @endif
            @endcan
        </td></tr>
    @empty
        <tr><td colspan="7">Konum kaydı yok.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endif
@if($activeTab === 'jobs')
@php($jobRows = \App\Models\WorkAssignment::with('place')->where('inmate_file_id', $inmate->id)->latest('started_at')->get())
<section class="panel narrow-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Görev geçmişi</p><h2>Görev yeri / karar</h2></div>@can('permission', 'inmates.update')<a class="primary-link small-button" href="{{ route('inmates.jobs.create', $inmate) }}">Görev ekle</a>@endcan</div>
    <div class="table-wrap"><table class="data-table compact-table"><thead><tr><th>Başlangıç</th><th>Bitiş</th><th>Görev yeri</th><th>Karar</th><th>Durum</th><th></th></tr></thead><tbody>
    @forelse($jobRows as $assignment)
        <tr><td>{{ $assignment->started_at?->format('d.m.Y') }}</td><td>{{ $assignment->ended_at?->format('d.m.Y') ?? '—' }}</td><td>{{ $assignment->place?->name ?? '—' }}</td><td>{{ $assignment->decision_code ?? '—' }}</td><td><span class="status-badge {{ $assignment->ended_at ? 'passive' : 'ok' }}">{{ $assignment->ended_at ? 'Kapalı' : 'Aktif' }}</span></td><td class="actions-cell">
            @can('permission', 'inmates.update')
                <a class="secondary-button small-button" href="{{ route('inmates.jobs.edit', [$inmate, $assignment]) }}">Düzenle</a>
                @if(! $assignment->ended_at)
                    <form method="post" action="{{ route('inmates.jobs.close', [$inmate, $assignment]) }}">@csrf @method('patch')<button class="secondary-button small-button" type="submit">Kapat</button></form>
                @endif
            @endcan
        </td></tr>
    @empty
        <tr><td colspan="6">Görev kaydı yok.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endif
@if($activeTab === 'files')
<section class="panel narrow-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Private dosyalar</p><h2>Fotoğraf / belge</h2></div><div class="actions-cell">@can('permission', 'inmates.update')<a class="primary-link small-button" href="{{ route('inmates.identity-photo.create', $inmate) }}">Kimlik fotoğrafı</a>@endcan<a class="secondary-button small-button" href="{{ route('inmates.identity-card', $inmate) }}">Kimlik kartı</a></div></div>
    @can('permission', 'inmates.update')
    <form method="post" action="{{ route('inmates.private-files.store', $inmate) }}" enctype="multipart/form-data" class="stack-form">
        @csrf
        <div class="form-grid">
            <label>Kategori<select name="category" required><option value="document">Belge</option><option value="photo">Fotoğraf</option></select></label>
            <label>Görünen ad<input type="text" name="display_name" maxlength="255" value="{{ old('display_name') }}"></label>
            <label>Dosya<input type="file" name="private_file" required></label>
        </div>
        <label>Açıklama<textarea name="description" rows="2">{{ old('description') }}</textarea></label>
        @if($errors->any())<div class="error-alert">{{ $errors->first() }}</div>@endif
        <p class="muted-text">Dosyalar public klasöre alınmaz. İzin verilen türler: JPG, PNG, PDF, TXT, DOC, DOCX, XLS, XLSX. En fazla 10 MB.</p>
        <button class="primary-button" type="submit">Private dosya yükle</button>
    </form>
    @endcan
    <div class="table-wrap"><table class="data-table compact-table"><thead><tr><th>Tür</th><th>Ad</th><th>MIME</th><th>Boyut</th><th>SHA-256</th><th>Yükleyen</th><th></th></tr></thead><tbody>
    @forelse($inmate->privateFiles as $file)
        <tr><td>{{ $file->category === 'photo' ? 'Fotoğraf' : 'Belge' }}</td><td>{{ $file->display_name ?: $file->original_name }}</td><td>{{ $file->mime_type }}</td><td>{{ $file->humanSize() }}</td><td><code>{{ substr($file->sha256, 0, 12) }}…</code></td><td>{{ $file->uploader?->full_name ?? '—' }}</td><td class="actions-cell"><a class="secondary-button small-button" href="{{ route('inmates.private-files.download', [$inmate, $file]) }}">İndir</a></td></tr>
    @empty
        <tr><td colspan="7">Private dosya kaydı yok.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
@endif
@if($activeTab === 'danger')
<section class="panel narrow-panel danger-panel">
    <div class="panel-heading"><div><p class="eyebrow">Güvenlik</p><h2>Kalıcı silme</h2></div></div>
    <p class="muted-text">Bu sekme yalnız silme yetkisi olan kullanıcılara gösterilir. Kalıcı silme işlemi ayrı onay ekranında parola ve çift onay ister.</p>
    <a class="danger-button" href="{{ route('inmates.permanent-delete.create', $inmate) }}">Kalıcı silme onayına git</a>
</section>
@endif
@endsection
