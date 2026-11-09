@csrf
<div class="form-grid">
    <label>Kod
        <input name="code" value="{{ old('code', $item->code ?? '') }}" required>
        @error('code')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Etiket
        <input name="label" value="{{ old('label', $item->label ?? '') }}" required>
        @error('label')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Renk
        <input type="color" name="color" value="{{ old('color', $item->color ?? '#312e81') }}">
        @error('color')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Sıra
        <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $item->sort_order ?? 0) }}" required>
        @error('sort_order')<small class="field-error">{{ $message }}</small>@enderror
    </label>
</div>
<label class="wide-label">Açıklama
    <textarea name="description" rows="3">{{ old('description', $item->description ?? '') }}</textarea>
    @error('description')<small class="field-error">{{ $message }}</small>@enderror
</label>
<div class="check-row"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item->is_active ?? true))> Aktif</label></div>
<div class="form-actions">
    <a class="secondary-button" href="{{ route('admin.lookups.index') }}">Vazgeç</a>
    <button class="primary-button compact" type="submit">Kaydet</button>
</div>
