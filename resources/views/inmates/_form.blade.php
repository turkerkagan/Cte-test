@csrf
@isset($returnTo)<input type="hidden" name="return_to" value="{{ $returnTo }}">@endisset
<input type="hidden" name="institution_id" value="{{ old('institution_id', $inmate->institution_id ?? auth()->user()->institution_id ?? optional($institutions->first())->id) }}">
<div class="form-grid">
    <label>TC kimlik no
        <input name="identity_number" value="{{ old('identity_number', $inmate->identity_number ?? '') }}" maxlength="11" inputmode="numeric" placeholder="TC yoksa boş bırak">
        @error('identity_number')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Ad soyad
        <input name="full_name" value="{{ old('full_name', $inmate->full_name ?? '') }}" required>
        @error('full_name')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Legacy ID
        <input type="number" name="legacy_id" min="1" value="{{ old('legacy_id', $inmate->legacy_id ?? '') }}">
    </label>
    <label>Kayıt tarihi
        <input type="date" name="registered_at" value="{{ old('registered_at', isset($inmate) && $inmate->registered_at ? $inmate->registered_at->format('Y-m-d') : '') }}">
    </label>
    <label>Giriş tarihi
        <input type="date" name="entered_at" value="{{ old('entered_at', isset($inmate) && $inmate->entered_at ? $inmate->entered_at->format('Y-m-d') : '') }}">
    </label>
    <label>Durum
        <select name="status_code"><option value="">Seçilmedi</option>@foreach($statuses as $item)<option value="{{ $item->code }}" @selected(old('status_code', $inmate->status_code ?? '') === $item->code)>{{ $item->label }}</option>@endforeach</select>
    </label>
    <label>Risk
        <select name="risk_code"><option value="">Seçilmedi</option>@foreach($risks as $item)<option value="{{ $item->code }}" @selected(old('risk_code', $inmate->risk_code ?? '') === $item->code)>{{ $item->label }}</option>@endforeach</select>
    </label>
    <label>Karar
        <select name="decision_code"><option value="">Seçilmedi</option>@foreach($decisions as $item)<option value="{{ $item->code }}" @selected(old('decision_code', $inmate->decision_code ?? '') === $item->code)>{{ $item->label }}</option>@endforeach</select>
    </label>
    <label>Sağlık durumu
        <input name="health_status" value="{{ old('health_status', $inmate->health_status ?? 'SAĞLIKLI') }}" placeholder="SAĞLIKLI">
        @error('health_status')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    @if(! isset($inmate))
    <label>Blok
        <select name="block_id" data-location-block><option value="">Seçilmedi</option>@foreach($blocks as $block)<option value="{{ $block->id }}" @selected((int) old('block_id') === $block->id)>{{ $block->name }}</option>@endforeach</select>
        @error('block_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Koğuş
        <select name="ward_id" data-location-ward><option value="">Seçilmedi</option>@foreach($blocks as $block)@foreach($block->wards as $ward)<option value="{{ $ward->id }}" data-block-id="{{ $block->id }}" @selected((int) old('ward_id') === $ward->id)>{{ $block->name }} / {{ $ward->name }}</option>@endforeach @endforeach</select>
        @error('ward_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Ranza
        <select name="bed_id" data-location-bed><option value="">Seçilmedi</option>@foreach($blocks as $block)@foreach($block->wards as $ward)@foreach($ward->beds as $bed)<option value="{{ $bed->id }}" data-ward-id="{{ $ward->id }}" @selected((int) old('bed_id') === $bed->id)>{{ $block->name }} / {{ $ward->name }} / {{ $bed->label }}</option>@endforeach @endforeach @endforeach</select>
        @error('bed_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Çalışma yeri
        <select name="work_place_id"><option value="">Çalışmıyor / seçilmedi</option>@foreach($workPlaces as $place)<option value="{{ $place->id }}" @selected((int) old('work_place_id') === $place->id)>{{ $place->name }}</option>@endforeach</select>
        @error('work_place_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    @endif
</div>
<label class="wide-label">Not
    <textarea name="notes" rows="4">{{ old('notes', $inmate->notes ?? '') }}</textarea>
</label>
<div class="check-row"><label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $inmate->is_active ?? true))> Aktif dosya</label></div>
<div class="form-actions">
    @if($formInModal ?? false)
        <button class="secondary-button" type="button" data-modal-close>Vazgeç</button>
    @else
        <a class="secondary-button" href="{{ $cancelHref ?? route('dashboard') }}">Vazgeç</a>
    @endif
    <button class="primary-button compact" type="submit">Kaydet</button>
</div>
