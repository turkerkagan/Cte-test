@extends('layouts.portal')
@section('title', ($isHome ?? false) ? 'Ana Sayfa' : 'Hükümlü Ana Dosyaları')
@section('page-title', ($isHome ?? false) ? 'Ana Sayfa' : 'Hükümlü Ana Dosyaları')
@section('content')
@php($canCreate = auth()->user()?->hasPermission('inmates.create'))
@php($canUpdate = auth()->user()?->hasPermission('inmates.update'))
@php($canDelete = auth()->user()?->hasPermission('inmates.delete'))
@php($canInfo = auth()->user()?->hasPermission('execution.view'))

@if($isHome ?? false)
<section class="overview-card">
    <div class="overview-head">
        <div>
            <p class="eyebrow">Kurum özeti</p>
            <h2>Toplam Mevcut <strong>{{ $summary['active'] }}</strong></h2>
        </div>
        <div class="overview-duty">
            <div><span>Nöbetçi İnfaz Memuru</span><strong>{{ $summary['execution_duty'] ?: 'Belirtilmemiş' }}</strong></div>
            <div><span>Nöbetçi Müdür</span><strong>{{ $summary['manager_duty'] ?: 'Belirtilmemiş' }}</strong></div>
        </div>
    </div>
    <div class="overview-tiles">
        <div class="tile tile-blue"><span>Bugün Yapılan Kayıt</span><strong>{{ $summary['registered_today'] }}</strong></div>
        <a class="tile tile-purple" href="{{ route('dashboard', ['on_leave' => '1']) }}"><span>İzinli Hükümlüler</span><strong>{{ $summary['special_leave'] }}</strong></a>
        <div class="tile tile-amber"><span>İzin Raporu</span><strong><button class="tile-link" type="button" data-modal-open="leavingModal">Çıkış {{ $summary['leaving_today'] }}</button> · <button class="tile-link" type="button" data-modal-open="returningModal">Dönüş {{ $summary['returning_today'] }}</button></strong></div>
        <a class="tile tile-rose" href="{{ route('dashboard', ['status_code' => 'VERİ DEĞİŞİKLİĞİ']) }}"><span>Konum değişikliği</span><strong>{{ $summary['housing_changes_today'] }}</strong></a>
        <div class="tile tile-green"><span>Bugünkü Nöbet</span><strong>{{ $summary['duty_today'] }}</strong></div>
        <div class="tile tile-teal"><span>Yeni Kayıt</span><strong>{{ $summary['new_record'] }}</strong></div>
    </div>
</section>
@endif

<section class="panel list-panel">
    <div class="panel-heading table-heading">
        <div><p class="eyebrow">Hükümlü çekirdeği</p><h2>Ana dosyalar</h2></div>
        <div class="actions-cell">
            <button class="secondary-button small-button" type="button" data-modal-open="emptyRoomsModal">Boş Koğuşları Göster</button>
            @can('permission', 'inmates.export')<a class="secondary-button small-button" href="{{ route('tools.export', request()->query() + ['source' => 'inmates', 'format' => 'xlsx', 'columns' => ['identity_number', 'full_name', 'status_code', 'registered_at']]) }}">Dışa aktar</a>@endcan
            @can('permission', 'inmates.update')<a class="secondary-button small-button" href="{{ route('inmates.bulk.index') }}">Toplu işlemler</a>@endcan
            @if($canCreate)<button class="primary-button compact" type="button" data-modal-open="newInmateModal">+ Yeni Kayıt Ekle</button>@endif
        </div>
    </div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif

    @php($listColumns = ['Sıra', 'TC No', 'Adı Soyadı', 'Blok', 'Koğuş', 'Ranza', 'Çalışma Yeri', 'Çalışma Kararı', 'Sağlık Durumu', 'Değerlendirme', 'Durum', 'Kayıt Tarihi', 'Notlar', 'İşlem'])
    <div class="list-tools" data-list-tools data-table-key="inmates-main">
        <div class="list-tools-search">
            <span class="list-tools-icon">⌕</span>
            <input type="search" data-instant-filter placeholder="Listede anlık ara (TC, ad, koğuş…)" autocomplete="off">
            <span class="list-tools-count" data-filter-count></span>
        </div>
        <div class="list-tools-actions">
            <div class="list-menu" data-list-menu>
                <button class="secondary-button small-button" type="button" data-list-menu-toggle>▦ Sütunlar</button>
                <div class="list-menu-pop" data-list-menu-pop hidden>
                    @foreach($listColumns as $i => $col)<label><input type="checkbox" data-col-toggle value="{{ $i }}" checked> {{ $col }}</label>@endforeach
                </div>
            </div>
            <div class="list-menu" data-list-menu>
                <button class="secondary-button small-button" type="button" data-list-menu-toggle>⧉ Kopyala</button>
                <div class="list-menu-pop" data-list-menu-pop hidden>
                    @foreach($listColumns as $i => $col)@if($col !== 'İşlem')<button type="button" data-col-copy="{{ $i }}">{{ $col }}</button>@endif @endforeach
                </div>
            </div>
            <div class="list-density" role="group" aria-label="Yazı boyutu">
                <button class="secondary-button small-button" type="button" data-font-step="-1" title="Küçült">A−</button>
                <button class="secondary-button small-button" type="button" data-font-step="0" title="Sıfırla">A</button>
                <button class="secondary-button small-button" type="button" data-font-step="1" title="Büyüt">A+</button>
            </div>
        </div>
    </div>

    <form class="filter-bar" method="get">
        <input name="q" value="{{ request('q') }}" placeholder="TC veya adı soyadı">
        @include('inmates._multi-filter', ['name' => 'status_code', 'placeholder' => 'Tüm durumlar', 'items' => $statuses])
        @include('inmates._multi-filter', ['name' => 'risk_code', 'placeholder' => 'Tüm riskler', 'items' => $risks])
        @include('inmates._multi-filter', ['name' => 'decision_code', 'placeholder' => 'Tüm kararlar', 'items' => $decisions])
        <select name="block_id" data-location-block><option value="">Blok</option>@foreach($blocks as $block)<option value="{{ $block->id }}" @selected((int) request('block_id') === $block->id)>{{ $block->name }}</option>@endforeach</select>
        <select name="ward_id" data-location-ward><option value="">Koğuş</option>@foreach($blocks as $block)@foreach($block->wards as $ward)<option value="{{ $ward->id }}" data-block-id="{{ $block->id }}" @selected((int) request('ward_id') === $ward->id)>{{ $block->name }} / {{ $ward->name }}</option>@endforeach @endforeach</select>
        <select name="bed_id" data-location-bed><option value="">Ranza</option>@foreach($blocks as $block)@foreach($block->wards as $ward)@foreach($ward->beds as $bed)<option value="{{ $bed->id }}" data-ward-id="{{ $ward->id }}" @selected((int) request('bed_id') === $bed->id)>{{ $block->name }} / {{ $ward->name }} / {{ $bed->label }}</option>@endforeach @endforeach @endforeach</select>
        <input name="health_status" value="{{ request('health_status') }}" placeholder="Sağlık durumu">
        <input name="notes" value="{{ request('notes') }}" placeholder="Notlarda ara">
        <input type="date" name="registered_on" value="{{ request('registered_on') }}" title="Kayıt tarihi">
        <select name="work_place_id"><option value="">Çalışma yeri</option>@foreach($workPlaces as $place)<option value="{{ $place->id }}" @selected((int) request('work_place_id') === $place->id)>{{ $place->name }}</option>@endforeach</select>
        <select name="is_active"><option value="">Aktif/pasif tümü</option><option value="1" @selected(request('is_active') === '1')>Aktif</option><option value="0" @selected(request('is_active') === '0')>Pasif</option></select>
        <input type="hidden" name="sort" value="{{ $sort }}">
        <div class="filter-buttons">
            <button class="primary-button compact" type="submit">Filtrele</button>
            <button class="secondary-button" type="button" data-modal-open="sortModal">Sırala</button>
            <a class="secondary-button" href="{{ ($isHome ?? false) ? route('dashboard') : route('inmates.index') }}">Temizle</a>
        </div>
    </form>

    <div class="table-wrap list-table-wrap">
        <table class="data-table main-list-table" data-list-table>
            <thead><tr>@foreach($listColumns as $i => $col)<th data-col="{{ $i }}">{{ $col }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse($files as $file)
                @php($activeHousing = $file->activeHousingAssignment->first())
                @php($activeWork = $file->activeWorkAssignment->first())
                @php($executionRecord = $file->unitRecords->first())
                @php($executionData = $executionRecord?->data ?? [])
                @php($infoPayload = [
                    'name' => $file->full_name,
                    'identity' => $file->identity_number ?: 'TC yok',
                    'ds' => $executionData['DS/tahliye durumu'] ?? $executionData['Tahliye/DS durumu'] ?? $executionData['DS Tarihi'] ?? $executionData['DS tahliye tarihi'] ?? 'Belirtilmemiş',
                    'release' => $executionData['Tahliye tarihi'] ?? $executionData['Tahliye Tarihi'] ?? $executionData['Şartlı tahliye'] ?? 'Belirtilmemiş',
                    'uyap' => $executionData['UYAP özeti'] ?? $executionData['UYAP No'] ?? 'Belirtilmemiş',
                    'note' => $executionRecord?->notes ?? $executionData['Kurul notu'] ?? $executionData['Ceza bilgisi'] ?? '',
                ])
                @php($editPayload = [
                    'url' => route('inmates.update', $file),
                    'detay' => route('inmates.edit', [$file, 'tab' => 'overview']),
                    'institution_id' => $file->institution_id,
                    'identity_number' => $file->identity_number,
                    'full_name' => $file->full_name,
                    'registered_at' => $file->registered_at?->format('Y-m-d'),
                    'status_code' => $file->status_code,
                    'risk_code' => $file->risk_code,
                    'decision_code' => $file->decision_code,
                    'health_status' => $file->health_status,
                    'notes' => $file->notes,
                    'is_active' => $file->is_active ? 1 : 0,
                ])
                <tr data-filter-row="{{ \Illuminate\Support\Str::lower(trim(($file->identity_number ?? '').' '.$file->full_name.' '.($activeHousing?->block?->name ?? '').' '.($activeHousing?->ward?->name ?? '').' '.($activeWork?->place?->name ?? '').' '.($file->status_code ?? '').' '.($file->health_status ?? '').' '.($file->notes ?? ''))) }}">
                    <td data-col="0" class="row-index">{{ $loop->iteration }}</td>
                    <td data-col="1">{{ $file->identity_number ?? 'TC yok' }}</td>
                    <td data-col="2"><strong>{{ $file->full_name }}</strong></td>
                    <td data-col="3">{{ $activeHousing?->block?->name ?? '—' }}</td>
                    <td data-col="4">{{ $activeHousing?->ward?->name ?? '—' }}</td>
                    <td data-col="5">{{ $activeHousing?->bed?->label ?? '—' }}</td>
                    <td data-col="6">{{ $activeWork?->place?->name ?? '—' }}</td>
                    <td data-col="7">{{ $file->decision_code ?? $activeWork?->decision_code ?? '—' }}</td>
                    <td data-col="8">{{ $file->health_status ?? '—' }}</td>
                    <td data-col="9">{{ $file->risk_code ?? '—' }}</td>
                    <td data-col="10"><span class="status-badge {{ $file->is_active ? 'ok' : 'passive' }}">{{ $file->status_code ?? ($file->is_active ? 'AKTİF' : 'PASİF') }}</span></td>
                    <td data-col="11">{{ $file->registered_at?->format('d.m.Y') ?? '—' }}</td>
                    <td data-col="12"><small>{{ \Illuminate\Support\Str::limit($file->notes, 80) ?: '—' }}</small></td>
                    <td data-col="13" class="actions-cell row-actions">
                        @can('permission', 'inmates.view')
                            <a class="icon-btn icon-view" href="{{ route('inmates.edit', [$file, 'tab' => 'overview']) }}" title="Görüntüle" aria-label="Görüntüle">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
                            </a>
                        @endcan
                        @if($canUpdate)
                            <button class="icon-btn icon-edit" type="button" title="Düzenle" aria-label="Düzenle" data-edit-open data-edit='@json($editPayload)'>
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                            </button>
                        @endif
                        @if($canInfo)
                            <button class="icon-btn icon-info" type="button" title="Bilgi" aria-label="Bilgi" data-modal-open="infoModal" data-info='@json($infoPayload)'>
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            </button>
                        @endif
                        @can('permission', 'inmates.delete')
                            <a class="icon-btn icon-delete" href="{{ route('inmates.permanent-delete.create', $file) }}" title="Sil" aria-label="Sil">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                            </a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr data-empty-row><td colspan="14">Hükümlü kaydı bulunamadı.</td></tr>
            @endforelse
                <tr data-no-match-row hidden><td colspan="14">Anlık aramaya uyan kayıt yok.</td></tr>
                <tr class="list-spacer" aria-hidden="true"><td colspan="14"></td></tr>
            </tbody>
        </table>
    </div>
    <p class="list-footnote">Toplam {{ $files->count() }} kayıt · son eklenen en altta</p>
</section>

{{-- ===== Modallar ===== --}}
@if($canCreate)
<div class="portal-modal" id="newInmateModal" data-open-on-load="{{ ($errors->any() && (old('_form') === 'new' || old('return_to') === 'dashboard')) ? 'true' : 'false' }}" hidden>
    <div class="portal-modal-card wide-modal">
        <button class="modal-close" type="button" data-modal-close>×</button>
        <p class="eyebrow">Hükümlü işlemleri</p>
        <h2>Yeni Kayıt Ekle</h2>
        <p class="modal-hint">Ana dosya, koğuş/ranza ve çalışma yeri bilgilerini tek işlemde kaydedin.</p>
        <form method="post" action="{{ route('inmates.store') }}">
            <input type="hidden" name="_form" value="new">
            @include('inmates._form', ['formInModal' => true, 'returnTo' => ($isHome ?? false) ? 'dashboard' : 'index'])
        </form>
    </div>
</div>
@endif

@if($canUpdate)
<div class="portal-modal" id="editInmateModal" hidden>
    <div class="portal-modal-card">
        <button class="modal-close" type="button" data-modal-close>×</button>
        <p class="eyebrow">Hükümlü işlemleri</p>
        <h2>Kaydı Düzenle</h2>
        <form method="post" data-edit-form>
            @csrf
            @method('put')
            <input type="hidden" name="institution_id" data-edit-field="institution_id">
            <div class="form-grid">
                <label>TC kimlik no<input name="identity_number" data-edit-field="identity_number" maxlength="11" inputmode="numeric" placeholder="TC yoksa boş bırak"></label>
                <label>Ad soyad<input name="full_name" data-edit-field="full_name" required></label>
                <label>Kayıt tarihi<input type="date" name="registered_at" data-edit-field="registered_at"></label>
                <label>Durum<select name="status_code" data-edit-field="status_code"><option value="">Seçilmedi</option>@foreach($statuses as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</select></label>
                <label>Risk<select name="risk_code" data-edit-field="risk_code"><option value="">Seçilmedi</option>@foreach($risks as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</select></label>
                <label>Karar<select name="decision_code" data-edit-field="decision_code"><option value="">Seçilmedi</option>@foreach($decisions as $item)<option value="{{ $item->code }}">{{ $item->label }}</option>@endforeach</select></label>
                <label>Sağlık durumu<input name="health_status" data-edit-field="health_status"></label>
            </div>
            <label class="wide-label">Not<textarea name="notes" rows="3" data-edit-field="notes"></textarea></label>
            <div class="check-row"><label><input type="checkbox" name="is_active" value="1" data-edit-field="is_active"> Aktif dosya</label></div>
            <div class="form-actions">
                <a class="secondary-button" href="#" data-edit-detay>Tam profil / konum</a>
                <button class="secondary-button" type="button" data-modal-close>Vazgeç</button>
                <button class="primary-button compact" type="submit">Kaydet</button>
            </div>
        </form>
    </div>
</div>
@endif

<div class="portal-modal" id="emptyRoomsModal" hidden>
    <div class="portal-modal-card wide-modal">
        <button class="modal-close" type="button" data-modal-close>×</button>
        <p class="eyebrow">Eski ana sayfa eşdeğeri</p>
        <h2>Boş Koğuşlar ve Ranzalar</h2>
        <div class="empty-room-grid">
            @foreach($emptyRooms as $room)
                <article class="empty-room-card">
                    <strong>{{ $room['block'] }} / {{ $room['ward'] }}</strong>
                    <span>Kapasite {{ $room['capacity'] ?: 'Ranzasız' }} · Dolu {{ $room['occupied'] }} · Boş {{ $room['empty'] }}</span>
                    <small>{{ implode(', ', array_slice($room['beds'], 0, 30)) }}@if(count($room['beds']) > 30) …@endif</small>
                </article>
            @endforeach
        </div>
    </div>
</div>

@foreach(['leaving' => 'Bugün İzne Ayrılacaklar', 'returning' => 'Bugün İzinden Dönecekler'] as $key => $title)
<div class="portal-modal" id="{{ $key }}Modal" hidden>
    <div class="portal-modal-card wide-modal">
        <button class="modal-close" type="button" data-modal-close>×</button>
        <p class="eyebrow">İzin raporu</p>
        <h2>{{ $title }}</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Sıra</th><th>TC</th><th>Adı Soyadı</th><th>Çalışma</th><th>Blok</th><th>Koğuş</th><th>Ranza</th><th>Çıkış</th><th>Çıkış Saat</th><th>Dönüş</th><th>Dönüş Saat</th></tr></thead>
                <tbody>
                @forelse($todayLeaveLists[$key] as $row)
                    <tr><td>{{ $loop->iteration }}</td><td>{{ $row['identity_number'] ?: '—' }}</td><td><strong>{{ $row['full_name'] ?: '—' }}</strong></td><td>{{ $row['work_place'] ?: '—' }}</td><td>{{ $row['block'] ?: '—' }}</td><td>{{ $row['ward'] ?: '—' }}</td><td>{{ $row['bed'] ?: '—' }}</td><td>{{ $row['leave_date'] ?: '—' }}</td><td>{{ $row['leave_time'] ?: '—' }}</td><td>{{ $row['return_date'] ?: '—' }}</td><td>{{ $row['return_time'] ?: '—' }}</td></tr>
                @empty
                    <tr><td colspan="11">Bugün için kayıt bulunmamaktadır.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="form-actions"><button class="secondary-button" type="button" onclick="window.print()">Yazdır</button></div>
    </div>
</div>
@endforeach

<div class="portal-modal" id="infoModal" hidden>
    <div class="portal-modal-card">
        <button class="modal-close" type="button" data-modal-close>×</button>
        <p class="eyebrow">Tahliye bilgileri</p>
        <h2 data-info-name>Hükümlü bilgisi</h2>
        <div class="info-grid">
            <span>TC Kimlik</span><strong data-info-identity>—</strong>
            <span>UYAP</span><strong data-info-uyap>—</strong>
            <span>DS Tahliye Tarihi / Durumu</span><strong data-info-ds>—</strong>
            <span>Tahliye Tarihi</span><strong data-info-release>—</strong>
            <span>Not</span><strong data-info-note>—</strong>
        </div>
    </div>
</div>

<div class="portal-modal" id="sortModal" hidden>
    <div class="portal-modal-card">
        <button class="modal-close" type="button" data-modal-close>×</button>
        <p class="eyebrow">Sıralama</p>
        <h2>Sıralama Seçenekleri</h2>
        <div class="form-grid">
            <label>Sütun seç
                <select data-sort-column>
                    <option value="full_name">Adı Soyadı</option>
                    <option value="registered_at">Kayıt tarihi</option>
                    <option value="created_at" selected>Eklenme tarihi</option>
                </select>
            </label>
            <label>Yön
                <select data-sort-direction>
                    <option value="asc" selected>Artan (en yeni altta)</option>
                    <option value="desc">Azalan</option>
                </select>
            </label>
        </div>
        <div class="form-actions"><button class="primary-button compact" type="button" data-apply-sort>Sırala</button></div>
    </div>
</div>
@endsection
