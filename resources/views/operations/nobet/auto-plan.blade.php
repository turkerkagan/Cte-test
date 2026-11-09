@extends('layouts.portal')
@section('title', 'Otomatik Nöbet Planla')
@section('page-title', 'Otomatik Nöbet Planla')
@section('content')
<section class="panel narrow-panel">
    <div class="panel-heading table-heading">
        <div>
            <p class="eyebrow">{{ $config['task'] }} · {{ $config['title'] }}</p>
            <h2>Otomatik Nöbet Programı Oluştur</h2>
        </div>
        <a class="secondary-button small-button" href="{{ route('operations.index', $module) }}">Nöbetlere Dön</a>
    </div>

    @if($errors->any())
        <div class="error-alert">{{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('operations.nobet.auto-plan') }}" class="stack-form">
        @csrf
        <div class="form-grid">
            <label>Başlangıç Tarihi
                <input type="date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" required>
            </label>
            <label>Bitiş Tarihi
                <input type="date" name="end_date" value="{{ old('end_date', now()->addWeek()->toDateString()) }}" required>
            </label>
            <label>Nöbet Türü
                <select name="record_type" required>
                    <option value="Vardiya" @selected(old('record_type') === 'Vardiya')>Vardiya</option>
                    <option value="Müdür nöbeti" @selected(old('record_type') === 'Müdür nöbeti')>Müdür nöbeti</option>
                    <option value="İnfaz nöbeti" @selected(old('record_type') === 'İnfaz nöbeti')>İnfaz nöbeti</option>
                </select>
            </label>
        </div>

        <div style="margin-top: 1.5rem; margin-bottom: 1.5rem;">
            <h3>Personel Havuzu</h3>
            <p class="muted-text" style="margin-bottom: 0.75rem;">Nöbet dağıtılacak aktif personelleri seçiniz (çakışma ve uygunsuzluklar otomatik kontrol edilecektir):</p>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; border: 1px solid var(--border-color, #e5e7eb); padding: 15px; border-radius: 8px; max-height: 300px; overflow-y: auto;">
                @foreach($users as $user)
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal;">
                        <input type="checkbox" name="personnel_ids[]" value="{{ $user->id }}" checked>
                        {{ $user->full_name }}
                    </label>
                @endforeach
            </div>
        </div>

        <button class="primary-button" type="submit">Algoritmayı Çalıştır ve Planla</button>
    </form>
</section>
@endsection
