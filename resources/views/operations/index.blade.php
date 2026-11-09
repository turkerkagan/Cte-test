@extends('layouts.portal')
@section('title', $config['title'])
@section('page-title', $config['title'])
@section('content')
<section class="panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">{{ $config['task'] }} · Operasyon modülü</p><h2>{{ $config['title'] }}</h2><p class="muted-text">{{ $config['description'] }}</p></div><div style="display: flex; gap: 8px; align-items: center;">@if($module === 'duyurular')<form method="post" action="{{ route('operations.announcements.read-all', $module) }}">@csrf<button class="secondary-button small-button" type="submit">Tümünü okundu yap</button></form>@endif @if($module === 'nobet' && auth()->user()?->hasPermission('duty.update'))<a class="secondary-button small-button" href="{{ route('operations.nobet.auto-plan-form') }}">Otomatik Nöbet Planla</a>@endif @can('permission', $permissionModule.'.update')<a class="primary-link small-button" href="{{ route('operations.create', $module) }}">Yeni kayıt</a>@endcan</div></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <div class="stat-grid"><div class="stat-card"><span>Toplam</span><strong>{{ $stats['total'] }}</strong><small>Kayıt</small></div><div class="stat-card"><span>Açık</span><strong>{{ $stats['open'] }}</strong><small>Takip</small></div><div class="stat-card"><span>Kapalı</span><strong>{{ $stats['closed'] }}</strong><small>Tamamlandı</small></div><div class="stat-card"><span>Tür</span><strong>{{ count($config['types']) }}</strong><small>Tanım</small></div></div>
    <form class="toolbar" method="get">
        <input name="q" value="{{ request('q') }}" placeholder="Başlık, not, hükümlü ara">
        <select name="record_type"><option value="">Tüm türler</option>@foreach($config['types'] as $type)<option value="{{ $type }}" @selected(request('record_type') === $type)>{{ $type }}</option>@endforeach</select>
        <input type="date" name="from" value="{{ request('from') }}">
        <input type="date" name="to" value="{{ request('to') }}">
        <select name="is_closed"><option value="">Açık/kapalı tümü</option><option value="0" @selected(request('is_closed') === '0')>Açık</option><option value="1" @selected(request('is_closed') === '1')>Kapalı</option></select>
        <button class="secondary-button" type="submit">Filtrele</button><a class="secondary-button" href="{{ route('operations.index', $module) }}">Temizle</a>
    </form>
</section>
<section class="panel">
    <div class="list-tools" data-list-tools data-table-key="op-{{ $module }}">
        <div class="list-tools-search">
            <span class="list-tools-icon">⌕</span>
            <input type="search" data-instant-filter placeholder="Listede anlık ara…" autocomplete="off">
            <span class="list-tools-count" data-filter-count></span>
        </div>
        <div class="list-tools-actions">
            <div class="list-density" role="group" aria-label="Yazı boyutu">
                <button class="secondary-button small-button" type="button" data-font-step="-1" title="Küçült">A−</button>
                <button class="secondary-button small-button" type="button" data-font-step="0" title="Sıfırla">A</button>
                <button class="secondary-button small-button" type="button" data-font-step="1" title="Büyüt">A+</button>
            </div>
        </div>
    </div>
    <div class="table-wrap"><table class="data-table" data-list-table><thead><tr><th>Tarih</th><th>Tür</th><th>Başlık</th><th>Hükümlü</th><th>Durum</th><th>Özet</th><th></th></tr></thead><tbody>
    @forelse($records as $record)
        <tr data-filter-row="{{ \Illuminate\Support\Str::lower(trim(($record->record_type ?? '').' '.($record->title ?? '').' '.($record->inmate?->full_name ?? '').' '.($record->status_code ?? '').' '.($record->body ?? ''))) }}">
            <td>{{ $record->starts_at?->format('d.m.Y H:i') ?? '—' }}</td><td>{{ $record->record_type }}</td><td><strong>{{ $record->title }}</strong></td><td>{{ $record->inmate?->full_name ?? 'Genel' }}</td><td><span class="status-badge {{ $record->is_closed ? 'passive' : 'ok' }}">{{ $record->is_closed ? 'Kapalı' : 'Açık' }}</span><br><small>{{ $record->status_code ?? '—' }}</small>@if($module === 'duyurular')<br><span class="status-badge {{ $record->readUsers->contains(auth()->id()) ? 'passive' : 'ok' }}" style="background-color: {{ $record->readUsers->contains(auth()->id()) ? '#6b7280' : '#f59e0b' }}; color: white; margin-top: 4px; display: inline-block;">{{ $record->readUsers->contains(auth()->id()) ? 'Okundu' : 'Okunmadı' }}</span><br><small>{{ $record->readUsers->count() }} okuma</small>@endif</td>
            <td><small>@foreach(($record->data ?? []) as $key => $value)<strong>{{ $key }}:</strong> @if($key === 'Personel' && is_numeric($value) && $u = \App\Models\User::find($value)) {{ $u->full_name }} @else {{ $value }} @endif<br>@endforeach{{ $record->body ? \Illuminate\Support\Str::limit($record->body, 100) : '' }}@if($module === 'kurul-komisyon' && $record->members->isNotEmpty())<br><strong>Üyeler:</strong> {{ $record->members->pluck('full_name')->join(', ') }}@endif @if($module === 'yemek' && $record->privateFiles->isNotEmpty())<br><strong>Belge:</strong> <a href="{{ route('operations.yemek.download-file', $record->privateFiles->first()->id) }}" target="_blank" class="primary-link" style="font-size: 0.85rem;">💾 {{ $record->privateFiles->first()->original_filename }}</a>@endif</small></td>
            <td class="actions-cell">
                @if($module === 'duyurular' && !$record->readUsers->contains(auth()->id()))
                    <form method="post" action="{{ route('operations.announcements.read', [$module, $record]) }}">@csrf<button class="secondary-button small-button" type="submit">Okundu yap</button></form>
                @endif
                @can('permission', $permissionModule.'.update')
                    <a class="secondary-button small-button" href="{{ route('operations.edit', [$module, $record]) }}">Düzenle</a>
                    @if(! $record->is_closed)
                        <form method="post" action="{{ route('operations.close', [$module, $record]) }}">@csrf @method('patch')<button class="secondary-button small-button" type="submit">Kapat</button></form>
                    @endif
                @endcan
                @can('permission', $permissionModule.'.delete')
                    <form method="post" action="{{ route('operations.destroy', [$module, $record]) }}">@csrf @method('delete')<button class="danger-button small-button" type="submit">Sil</button></form>
                @endcan
            </td>
        </tr>
    @empty
        <tr data-empty-row><td colspan="7">Bu modülde kayıt yok.</td></tr>
    @endforelse
        <tr data-no-match-row hidden><td colspan="7">Anlık aramaya uyan kayıt yok.</td></tr>
    </tbody></table></div>
    {{ $records->links() }}
</section>
<section class="panel"><div class="panel-heading"><div><p class="eyebrow">Kurum</p><h2>Operasyon modülleri</h2></div></div><div class="module-grid">@foreach($modules as $code => $item)@if(auth()->user()?->hasPermission(\App\Http\Controllers\OperationModuleController::permissionModule($code).'.view'))<a class="module-chip" href="{{ route('operations.index', $code) }}"><strong>{{ $item['title'] }}</strong><small>{{ $item['task'] }}</small></a>@endif @endforeach</div></section>
@endsection
