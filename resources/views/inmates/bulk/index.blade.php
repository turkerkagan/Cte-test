@extends('layouts.portal')
@section('title', 'Toplu Hükümlü İşlemleri')
@section('page-title', 'Toplu Hükümlü İşlemleri')
@section('content')
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Hükümlü çekirdeği</p><h2>Toplu işlemler</h2></div></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="error-alert">{{ $errors->first() }}</div>@endif
    <p class="muted-text">CSV dosyaları kalıcı veya herkese açık klasörlere alınmaz; yalnız işlem sırasında okunur. Gerçek veriyle işlem yapmadan önce yedek al.</p>
</section>
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">CSV içe alma</p><h2>TC'li / TC'siz kayıt aktarımı</h2></div></div>
    <form method="post" action="{{ route('inmates.bulk.import') }}" enctype="multipart/form-data" class="stack-form">
        @csrf
        <div class="form-grid">
            <label>Kurum<select name="institution_id" required>@foreach($institutions as $institution)<option value="{{ $institution->id }}">{{ $institution->name }}</option>@endforeach</select></label>
            <label>CSV dosyası<input type="file" name="csv_file" accept=".csv,.txt" required></label>
        </div>
        <p class="muted-text">Başlıklar: full_name zorunlu; identity_number, registered_at, entered_at, status_code, risk_code, decision_code, legacy_id, is_active isteğe bağlı.</p>
        @can('permission', 'inmates.create')<button class="primary-button" type="submit">CSV içe aktar</button>@endcan
    </form>
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Seçili kayıtlar</p><h2>Toplu durum / konum / silme</h2></div></div>
    <p class="muted-text">Aşağıdaki ilk 250 kayıt listelenir. ID alanına virgül, noktalı virgül veya boşlukla ayrılmış değerler yazılabilir.</p>
    <div class="table-wrap"><table class="data-table compact-table"><thead><tr><th>Seçim ID</th><th>Ad soyad</th><th>TC</th><th>Durum</th><th>Risk</th><th>Aktif</th></tr></thead><tbody>
    @forelse($files as $file)
        <tr><td><code>{{ $file->id }}</code></td><td>{{ $file->full_name }}</td><td>{{ $file->identity_number ?? 'TC yok' }}</td><td>{{ $file->status_code ?? '—' }}</td><td>{{ $file->risk_code ?? '—' }}</td><td>{{ $file->is_active ? 'Aktif' : 'Pasif' }}</td></tr>
    @empty
        <tr><td colspan="6">Kayıt yok.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Toplu durum</p><h2>Durum / risk / karar güncelle</h2></div></div>
    <form method="post" action="{{ route('inmates.bulk.status') }}" class="stack-form">
        @csrf
        <label>Seçili ID'ler<input name="selected_ids_text" placeholder="Örnek: 1,2,3"></label>
        <div class="form-grid">
            <label>Durum<select name="status_code"><option value="">Değiştirme</option>@foreach($statuses as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</select></label>
            <label>Risk<select name="risk_code"><option value="">Değiştirme</option>@foreach($risks as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</select></label>
            <label>Karar<select name="decision_code"><option value="">Değiştirme</option>@foreach($decisions as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</select></label>
            <label>Aktiflik<select name="is_active"><option value="">Değiştirme</option><option value="1">Aktif</option><option value="0">Pasif</option></select></label>
        </div>
        @can('permission', 'inmates.update')<button class="primary-button" type="submit">Toplu güncelle</button>@endcan
    </form>
</section>
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Toplu konum</p><h2>Yeni konum kaydı oluştur</h2></div></div>
    <form method="post" action="{{ route('inmates.bulk.housing') }}" class="stack-form">
        @csrf
        <label>Seçili ID'ler<input name="selected_ids_text" placeholder="Örnek: 1,2,3"></label>
        <div class="form-grid">
            <label>Blok<select name="block_id" required>@foreach($blocks as $block)<option value="{{ $block->id }}">{{ $block->name }}</option>@endforeach</select></label>
            <label>Koğuş<select name="ward_id" required>@foreach($wards as $ward)<option value="{{ $ward->id }}">{{ $ward->block?->name }} / {{ $ward->name }}</option>@endforeach</select></label>
            <label>Ranza<select name="bed_id"><option value="">Ranza yok</option>@foreach($beds as $bed)<option value="{{ $bed->id }}">{{ $bed->ward?->block?->name }} / {{ $bed->ward?->name }} / {{ $bed->label }}</option>@endforeach</select></label>
            <label>Başlangıç<input type="date" name="started_at" value="{{ now()->toDateString() }}" required></label>
        </div>
        <label>Not<textarea name="notes" rows="2"></textarea></label>
        @can('permission', 'inmates.update')<button class="primary-button" type="submit">Toplu konum ata</button>@endcan
    </form>
</section>
<section class="panel narrow-panel danger-panel">
    <div class="panel-heading"><div><p class="eyebrow">Toplu kalıcı silme</p><h2>Geri alınamaz işlem</h2></div></div>
    <form method="post" action="{{ route('inmates.bulk.destroy') }}" class="stack-form">
        @csrf
        @method('delete')
        <label>Seçili ID'ler<input name="selected_ids_text" placeholder="Örnek: 1,2"></label>
        <label>Parola<input type="password" name="password" autocomplete="current-password"></label>
        <label>Onay metni<input name="confirm_text" placeholder="{{ $confirmText }}"></label>
        <p class="muted-text">Private dosyalar önce karantinaya alınır; DB transaction başarılı olursa karantina temizlenir.</p>
        @can('permission', 'inmates.delete')<button class="danger-button" type="submit">Toplu kalıcı sil</button>@endcan
    </form>
</section>
@endsection
