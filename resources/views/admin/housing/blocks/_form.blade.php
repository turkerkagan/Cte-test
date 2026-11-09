@csrf
<div class="form-grid">
    <label>Kurum
        <select name="institution_id" required>
            @foreach($institutions as $institution)
                <option value="{{ $institution->id }}" @selected((int) old('institution_id', $block->institution_id ?? 0) === $institution->id)>{{ $institution->name }}</option>
            @endforeach
        </select>
        @error('institution_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Kod
        <input name="code" value="{{ old('code', $block->code ?? '') }}" required>
        @error('code')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Ad
        <input name="name" value="{{ old('name', $block->name ?? '') }}" required>
        @error('name')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Sıra
        <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $block->sort_order ?? 0) }}" required>
        @error('sort_order')<small class="field-error">{{ $message }}</small>@enderror
    </label>
</div>
<label class="wide-label">Açıklama
    <textarea name="description" rows="3">{{ old('description', $block->description ?? '') }}</textarea>
</label>
<div class="check-row"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $block->is_active ?? true))> Aktif</label></div>
<div class="form-actions"><a class="secondary-button" href="{{ route('admin.housing.index') }}">Vazgeç</a><button class="primary-button compact" type="submit">Kaydet</button></div>
