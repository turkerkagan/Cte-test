<div class="unit-builder-field" data-unit-field>
    <div class="form-grid">
        <label>Alan anahtarı<input name="fields[{{ $index }}][key]" value="{{ $field['key'] ?? '' }}" pattern="[a-z][a-z0-9_]*" maxlength="60" placeholder="alan_adi" required></label>
        <label>Alan / sütun başlığı<input name="fields[{{ $index }}][label]" value="{{ $field['label'] ?? '' }}" maxlength="100" required></label>
        <label>Alan türü<select name="fields[{{ $index }}][type]">@foreach(['text' => 'Kısa metin', 'textarea' => 'Uzun metin', 'number' => 'Sayı', 'date' => 'Tarih', 'select' => 'Seçim listesi'] as $value => $label)<option value="{{ $value }}" @selected(($field['type'] ?? 'text') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>Seçenekler<textarea name="fields[{{ $index }}][options_text]" rows="2" placeholder="Seçim listesi için her satıra bir seçenek">{{ $field['options_text'] ?? implode("\n", $field['options'] ?? []) }}</textarea></label>
    </div>
    <div class="check-row">@foreach(['required' => 'Zorunlu', 'in_table' => 'Tabloda göster', 'filterable' => 'Filtre olarak kullan'] as $key => $label)<label><input type="checkbox" name="fields[{{ $index }}][{{ $key }}]" value="1" @checked($field[$key] ?? false)> {{ $label }}</label>@endforeach</div>
    <div class="unit-builder-actions"><button class="secondary-button small-button" type="button" data-field-up>Yukarı</button><button class="secondary-button small-button" type="button" data-field-down>Aşağı</button><button class="secondary-button small-button" type="button" data-remove-unit-field>Alanı kaldır</button></div>
</div>
