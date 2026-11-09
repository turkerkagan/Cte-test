@csrf
<div class="form-grid">
    <label>Kod
        <input name="code" value="{{ old('code', $group->code ?? '') }}" required>
        @error('code')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Ad
        <input name="name" value="{{ old('name', $group->name ?? '') }}" required>
        @error('name')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Sıra
        <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $group->sort_order ?? 0) }}" required>
        @error('sort_order')<small class="field-error">{{ $message }}</small>@enderror
    </label>
</div>
<label class="wide-label">Açıklama
    <textarea name="description" rows="3">{{ old('description', $group->description ?? '') }}</textarea>
    @error('description')<small class="field-error">{{ $message }}</small>@enderror
</label>
<div class="check-row"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $group->is_active ?? true))> Aktif</label></div>
<div class="form-actions">
    <a class="secondary-button" href="{{ route('admin.lookups.index') }}">Vazgeç</a>
    <button class="primary-button compact" type="submit">Kaydet</button>
</div>
