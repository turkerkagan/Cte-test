@csrf
<input type="hidden" name="inmate_file_id" value="{{ $inmate->id }}">
<div class="form-grid">
    <label>Blok
        <select name="block_id" required>@foreach($blocks as $block)<option value="{{ $block->id }}" @selected((int) old('block_id', $assignment->block_id ?? 0) === $block->id)>{{ $block->name }}</option>@endforeach</select>
        @error('block_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Koğuş
        <select name="ward_id" required>@foreach($wards as $ward)<option value="{{ $ward->id }}" @selected((int) old('ward_id', $assignment->ward_id ?? 0) === $ward->id)>{{ $ward->block?->name }} / {{ $ward->name }}</option>@endforeach</select>
        @error('ward_id')<small class="field-error">{{ $message }}</small>@enderror
    </label>
    <label>Ranza
        <select name="bed_id"><option value="">Ranza seçilmedi</option>@foreach($beds as $bed)<option value="{{ $bed->id }}" @selected((int) old('bed_id', $assignment->bed_id ?? 0) === $bed->id)>{{ $bed->ward?->block?->name }} / {{ $bed->ward?->name }} / {{ $bed->label }}</option>@endforeach</select>
        @error('bed_id')<small class="field-error">{{ $message }}</small>@enderror
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
