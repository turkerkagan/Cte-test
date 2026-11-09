@csrf
<div class="form-grid">
    <label>Blok
        <select name="block_id" required>
            @foreach($blocks as $block)
                <option value="{{ $block->id }}" @selected((int) old('block_id', $ward->block_id ?? 0) === $block->id)>{{ $block->name }}</option>
            @endforeach
        </select>
        @error('block_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Kod
        <input name="code" value="{{ old('code', $ward->code ?? '') }}" required>
        @error('code')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Ad
        <input name="name" value="{{ old('name', $ward->name ?? '') }}" required>
        @error('name')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Kapasite
        <input type="number" name="capacity" min="0" max="9999" value="{{ old('capacity', $ward->capacity ?? 0) }}" required>
    </label>
    <label>Sıra
        <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $ward->sort_order ?? 0) }}" required>
    </label>
</div>
<div class="check-row"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $ward->is_active ?? true))> Aktif</label></div>
<div class="form-actions"><a class="secondary-button" href="{{ route('admin.housing.index') }}">Vazgeç</a><button class="primary-button compact" type="submit">Kaydet</button></div>
