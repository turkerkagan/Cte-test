@extends('layouts.portal')
@section('title', $config['title'].' Kaydı')
@section('page-title', $config['title'].' Kaydı')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">{{ $config['task'] }} · {{ $config['title'] }}</p><h2>{{ $record ? 'Kaydı düzenle' : 'Yeni kayıt' }}</h2></div><a class="secondary-button small-button" href="{{ route('unit-records.index', $module) }}">Modüle dön</a></div>
    @if($errors->any())<div class="error-alert">{{ $errors->first() }}</div>@endif
    <p class="muted-text">Hükümlü: <strong>{{ $inmate->full_name }}</strong> · {{ $inmate->identity_number ?? 'TC yok' }}</p>
    <form method="post" action="{{ $record ? route('unit-records.update', [$module, $record]) : route('unit-records.store', [$module, $inmate]) }}" class="stack-form">
        @csrf
        @if($record) @method('put') @endif
        <div class="form-grid">
            <label>Kayıt türü<select name="record_type" required>@foreach($config['types'] as $type)<option value="{{ $type }}" @selected(old('record_type', $record?->record_type) === $type)>{{ $type }}</option>@endforeach</select></label>
            <label>Tarih<input type="date" name="occurred_at" value="{{ old('occurred_at', $record?->occurred_at?->toDateString() ?? now()->toDateString()) }}"></label>
            <label>Başlık<input name="title" maxlength="255" value="{{ old('title', $record?->title) }}" required></label>
            <label>Durum kodu<input name="status_code" maxlength="100" value="{{ old('status_code', $record?->status_code) }}" placeholder="Açık, tamamlandı, sevk, takip vb."></label>
        </div>
        <div class="form-grid">
            @if(isset($config['field_definitions']))
                @foreach($config['field_definitions'] as $field)
                    <label>{{ $field['label'] }}{{ $field['required'] ? ' *' : '' }}
                        @if($field['type'] === 'textarea')
                            <textarea name="data[{{ $field['key'] }}]" rows="3" maxlength="1000" @required($field['required'])>{{ old('data.'.$field['key'], $record?->data[$field['key']] ?? '') }}</textarea>
                        @elseif($field['type'] === 'select')
                            <select name="data[{{ $field['key'] }}]" @required($field['required'])><option value="">Seçin</option>@foreach($field['options'] as $option)<option value="{{ $option }}" @selected((string) old('data.'.$field['key'], $record?->data[$field['key']] ?? '') === $option)>{{ $option }}</option>@endforeach</select>
                        @else
                            <input type="{{ $field['type'] }}" @if($field['type'] === 'number') step="any" @endif name="data[{{ $field['key'] }}]" value="{{ old('data.'.$field['key'], $record?->data[$field['key']] ?? '') }}" @required($field['required'])>
                        @endif
                        @error('data.'.$field['key'])<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                @endforeach
            @else
            @foreach($config['fields'] as $field)
                @php($isDate = str_contains(mb_strtolower($field), 'tarih') || in_array($field, ['Çıkış', 'Dönüş'], true))
                @php($isLong = str_contains(mb_strtolower($field), 'not') || str_contains(mb_strtolower($field), 'açıklama') || str_contains(mb_strtolower($field), 'detay') || str_contains(mb_strtolower($field), 'bilgi'))
                <label>{{ $field }}
                    @if($isLong)
                        <textarea name="data[{{ $field }}]" rows="2" maxlength="1000">{{ old('data.'.$field, ($record?->data[$field] ?? '')) }}</textarea>
                    @else
                        <input type="{{ $isDate ? 'date' : 'text' }}" name="data[{{ $field }}]" value="{{ old('data.'.$field, ($record?->data[$field] ?? '')) }}" maxlength="1000">
                    @endif
                    @error('data.'.$field)<small class="field-error">{{ $message }}</small>@enderror
                </label>
            @endforeach
            @endif
        </div>
        <label class="wide-label">Not<textarea name="notes" rows="4">{{ old('notes', $record?->notes) }}</textarea></label>
        <div class="check-row"><label><input type="checkbox" name="is_closed" value="1" @checked(old('is_closed', $record?->is_closed))> Kayıt kapalı/tamamlandı</label></div>
        <button class="primary-button" type="submit">Kaydet</button>
    </form>
</section>
@endsection
