@csrf
<div class="form-grid">
    <label>Koğuş
        <select name="ward_id" required>
            @foreach($wards as $ward)
                <option value="{{ $ward->id }}" @selected((int) old('ward_id', $bed->ward_id ?? 0) === $ward->id)>{{ $ward->block?->name }} / {{ $ward->name }}</option>
            @endforeach
        </select>
        @error('ward_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Kod
        <input name="code" value="{{ old('code', $bed->code ?? '') }}" required>
        @error('code')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Etiket
        <input name="label" value="{{ old('label', $bed->label ?? '') }}" required>
        @error('label')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Sıra
        <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $bed->sort_order ?? 0) }}" required>
    </label>
</div>
<div class="check-row"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $bed->is_active ?? true))> Aktif</label></div>
<div class="form-actions"><a class="secondary-button" href="{{ route('admin.housing.index') }}">Vazgeç</a><button class="primary-button compact" type="submit">Kaydet</button></div>
