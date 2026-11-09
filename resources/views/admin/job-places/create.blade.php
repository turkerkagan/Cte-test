@extends('layouts.portal')
@section('title', 'Yeni Görev Yeri')
@section('page-title', 'Yeni Görev Yeri')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading"><div><p class="eyebrow">Yönetim</p><h2>Yeni görev yeri</h2></div></div>
    <form method="post" action="{{ route('admin.job-places.store') }}">
        @csrf
        <div class="form-grid">
            <label>Kurum<select name="institution_id" required>@foreach($institutions as $institution)<option value="{{ $institution->id }}">{{ $institution->name }}</option>@endforeach</select></label>
            <label>Kod<input name="code" value="{{ old('code') }}" required></label>
            <label>Ad<input name="name" value="{{ old('name') }}" required></label>
            <label>Sıra<input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0" max="9999" required></label>
        </div>
        <label class="wide-label">Açıklama<textarea name="description" rows="3">{{ old('description') }}</textarea></label>
        <div class="check-row"><label><input type="checkbox" name="is_active" value="1" checked> Aktif</label></div>
        <div class="form-actions"><a class="secondary-button" href="{{ route('admin.job-places.index') }}">Vazgeç</a><button class="primary-button compact" type="submit">Kaydet</button></div>
    </form>
</section>
@endsection
