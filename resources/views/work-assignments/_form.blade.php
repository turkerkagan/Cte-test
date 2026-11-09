@csrf
<input type="hidden" name="inmate_file_id" value="{{ $inmate->id }}">
<div class="form-grid">
    <label>Görev yeri
        <select name="work_place_id" required>@foreach($places as $place)<option value="{{ $place->id }}" @selected((int) old('work_place_id', $assignment->work_place_id ?? 0) === $place->id)>{{ $place->name }}</option>@endforeach</select>
        @error('work_place_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Karar
        <select name="decision_code"><option value="">Seçilmedi</option>@foreach($decisions as $item)<option value="{{ $item->code }}" @selected(old('decision_code', $assignment->decision_code ?? '') === $item->code)>{{ $item->label }}</option>@endforeach</select>
    </label>
    <label>Başlangıç tarihi
        <input type="date" name="started_at" value="{{ old('started_at', isset($assignment) && $assignment->started_at ? $assignment->started_at->format('Y-m-d') : now()->toDateString()) }}" required>
        @error('started_at')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Bitiş tarihi
        <input type="date" name="ended_at" value="{{ old('ended_at', isset($assignment) && $assignment->ended_at ? $assignment->ended_at->format('Y-m-d') : '') }}">
        @error('ended_at')<small class="field-error">{{ $message }}</small>@enderror
    </label>
</div>
@error('inmate_file_id')<div class="alert">{{ $message }}</div>@enderror
<label class="wide-label">Not
    <textarea name="notes" rows="3">{{ old('notes', $assignment->notes ?? '') }}</textarea>
</label>
<div class="form-actions"><a class="secondary-button" href="{{ route('inmates.edit', $inmate) }}">Vazgeç</a><button class="primary-button compact" type="submit">Kaydet</button></div>
