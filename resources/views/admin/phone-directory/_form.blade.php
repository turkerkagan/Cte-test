@csrf
<div class="form-grid">
    <label>Kurum
        <select name="institution_id" required>@foreach($institutions as $institution)<option value="{{ $institution->id }}" @selected((int) old('institution_id', $entry->institution_id ?? 0) === $institution->id)>{{ $institution->name }}</option>@endforeach</select>
        @error('institution_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Birim
        <select name="department_id"><option value="">Birim yok</option>@foreach($departments as $department)<option value="{{ $department->id }}" @selected((int) old('department_id', $entry->department_id ?? 0) === $department->id)>{{ $department->name }}</option>@endforeach</select>
        @error('department_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Başlık
        <input name="title" value="{{ old('title', $entry->title ?? '') }}" required>
        @error('title')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Kişi adı
        <input name="person_name" value="{{ old('person_name', $entry->person_name ?? '') }}">
    </label>
    <label>Dahili
        <input name="internal_number" value="{{ old('internal_number', $entry->internal_number ?? '') }}">
        @error('internal_number')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Harici telefon
        <input name="external_number" value="{{ old('external_number', $entry->external_number ?? '') }}">
    </label>
    <label>Mobil
        <input name="mobile_number" value="{{ old('mobile_number', $entry->mobile_number ?? '') }}">
    </label>
    <label>E-posta
        <input type="email" name="email" value="{{ old('email', $entry->email ?? '') }}">
        @error('email')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Sıra
        <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $entry->sort_order ?? 0) }}" required>
    </label>
</div>
<label class="wide-label">Not
    <textarea name="notes" rows="3">{{ old('notes', $entry->notes ?? '') }}</textarea>
</label>
<div class="check-row"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $entry->is_active ?? true))> Aktif</label></div>
<div class="form-actions"><a class="secondary-button" href="{{ route('admin.phone-directory.index') }}">Vazgeç</a><button class="primary-button compact" type="submit">Kaydet</button></div>
