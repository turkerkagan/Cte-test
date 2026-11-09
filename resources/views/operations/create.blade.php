@extends('layouts.portal')
@section('title', $config['title'].' Kaydı')
@section('page-title', $config['title'].' Kaydı')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">{{ $config['task'] }} · {{ $config['title'] }}</p><h2>{{ $record ? 'Kaydı düzenle' : 'Yeni operasyon kaydı' }}</h2></div><a class="secondary-button small-button" href="{{ route('operations.index', $module) }}">Modüle dön</a></div>
    @if($errors->any())<div class="error-alert">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ $record ? route('operations.update', [$module, $record]) : route('operations.store', $module) }}" class="stack-form" enctype="multipart/form-data">
        @csrf @if($record) @method('put') @endif
        <div class="form-grid">
            <label>Hükümlü<select name="inmate_file_id"><option value="">Genel kayıt</option>@foreach($inmates as $inmate)<option value="{{ $inmate->id }}" @selected((string) old('inmate_file_id', $record?->inmate_file_id) === (string) $inmate->id)>{{ $inmate->full_name }} · {{ $inmate->identity_number ?? 'TC yok' }}</option>@endforeach</select></label>
            <label>Tür<select name="record_type" required>@foreach($config['types'] as $type)<option value="{{ $type }}" @selected(old('record_type', $record?->record_type) === $type)>{{ $type }}</option>@endforeach</select></label>
            <label>Başlık<input name="title" maxlength="255" value="{{ old('title', $record?->title) }}" required></label>
            <label>Başlangıç<input type="datetime-local" name="starts_at" value="{{ old('starts_at', $record?->starts_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}"></label>
            <label>Bitiş<input type="datetime-local" name="ends_at" value="{{ old('ends_at', $record?->ends_at?->format('Y-m-d\TH:i')) }}"></label>
            <label>Durum kodu<input name="status_code" maxlength="100" value="{{ old('status_code', $record?->status_code) }}"></label>
        </div>
        <div class="form-grid">
            @foreach($config['fields'] as $field)
                @if($module === 'nobet' && $field === 'Personel')
                    <label>{{ $field }}
                        <select name="data[{{ $field }}]">
                            <option value="">Seçiniz</option>
                            @foreach(\App\Models\User::where('is_active', true)->orderBy('full_name')->get() as $u)
                                <option value="{{ $u->id }}" @selected((string) old('data.'.$field, $record?->data[$field] ?? '') === (string) $u->id)>{{ $u->full_name }}</option>
                            @endforeach
                        </select>
                    </label>
                @elseif($module === 'nobet' && $field === 'Uygun olmayan tarih')
                    <label>{{ $field }}
                        <input type="date" name="data[{{ $field }}]" value="{{ old('data.'.$field, $record?->data[$field] ?? '') }}">
                    </label>
                @else
                    <label>{{ $field }}<input name="data[{{ $field }}]" value="{{ old('data.'.$field, ($record?->data[$field] ?? '')) }}" maxlength="1000"></label>
                @endif
            @endforeach
        </div>
        <label class="wide-label">Açıklama / mesaj / tutanak<textarea name="body" rows="4">{{ old('body', $record?->body) }}</textarea></label>
        @if($module === 'kurul-komisyon')
            <div style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
                <h3>Kurul / Komisyon Üyeleri</h3>
                <p class="muted-text" style="margin-bottom: 0.75rem;">Bu kurula/komisyona atanacak üyeleri seçiniz:</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; border: 1px solid var(--border-color, #e5e7eb); padding: 15px; border-radius: 8px; max-height: 200px; overflow-y: auto;">
                    @php($selectedMembers = old('member_ids', $record ? $record->members->pluck('id')->toArray() : []))
                    @foreach(\App\Models\User::where('is_active', true)->orderBy('full_name')->get() as $user)
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
                            <input type="checkbox" name="member_ids[]" value="{{ $user->id }}" @checked(in_array($user->id, $selectedMembers))>
                            {{ $user->full_name }}
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
        @if($module === 'yemek')
            <div style="margin-top: 1rem; margin-bottom: 1.5rem;">
                <label>Menü Belgesi (PDF, Resim, Excel vb.)
                    <input type="file" name="menu_file">
                </label>
                @if($record && $record->privateFiles->isNotEmpty())
                    <p class="muted-text" style="margin-top: 0.5rem;">
                        Mevcut belge: <a href="{{ route('operations.yemek.download-file', $record->privateFiles->first()->id) }}" target="_blank" class="primary-link" style="font-size: 0.85rem;">{{ $record->privateFiles->first()->original_filename }}</a>
                    </p>
                @endif
            </div>
        @endif
        <div class="check-row"><label><input type="checkbox" name="is_published" value="1" @checked(old('is_published', $record?->is_published ?? true))> Yayında</label><label><input type="checkbox" name="is_closed" value="1" @checked(old('is_closed', $record?->is_closed))> Kapalı/tamamlandı</label></div>
        <button class="primary-button" type="submit">Kaydet</button>
    </form>
</section>
@endsection
