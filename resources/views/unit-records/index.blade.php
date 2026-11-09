@extends('layouts.portal')
@section('title', $config['title'])
@section('page-title', $config['title'])
@section('content')
@php
    $tableFields = array_values(array_filter($config['field_definitions'] ?? [], fn ($field) => $field['in_table']));
    $filterFields = isset($config['field_definitions']) ? array_filter($config['field_definitions'], fn ($field) => $field['filterable']) : array_map(fn ($field) => ['key' => $field, 'label' => $field], $config['fields']);
    $fieldLabels = array_column($config['field_definitions'] ?? [], 'label', 'key');
@endphp
<section class="panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">{{ $config['task'] }} · Birim modülü</p><h2>{{ $config['title'] }}</h2><p class="muted-text">{{ $config['description'] }}</p></div><a class="secondary-button small-button" href="{{ route('inmates.index') }}">Hükümlü seç</a></div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    <div class="stat-grid"><div class="stat-card"><span>Toplam</span><strong>{{ $stats['total'] }}</strong><small>Kayıt</small></div><div class="stat-card"><span>Açık</span><strong>{{ $stats['open'] }}</strong><small>Takip</small></div><div class="stat-card"><span>Kapalı</span><strong>{{ $stats['closed'] }}</strong><small>Tamamlandı</small></div><div class="stat-card"><span>Hassas</span><strong>{{ $config['sensitive'] ? 'Evet' : 'Hayır' }}</strong><small>Yetki filtresi</small></div></div>
    <form class="toolbar" method="get">
        <input name="q" value="{{ request('q') }}" placeholder="Ad, TC, başlık, not ara">
        <select name="record_type"><option value="">Tüm türler</option>@foreach($config['types'] as $type)<option value="{{ $type }}" @selected(request('record_type') === $type)>{{ $type }}</option>@endforeach</select>
        <input name="status_code" value="{{ request('status_code') }}" placeholder="Durum kodu">
        <input type="date" name="from" value="{{ request('from') }}">
        <input type="date" name="to" value="{{ request('to') }}">
        <select name="is_closed"><option value="">Açık/kapalı tümü</option><option value="0" @selected(request('is_closed') === '0')>Açık</option><option value="1" @selected(request('is_closed') === '1')>Kapalı</option></select>
        <select name="field"><option value="">Alan seç</option>@foreach($filterFields as $field)<option value="{{ $field['key'] }}" @selected(request('field') === $field['key'])>{{ $field['label'] }}</option>@endforeach</select>
        <input name="field_value" value="{{ request('field_value') }}" placeholder="Alan değerinde ara">
        <button class="secondary-button" type="submit">Filtrele</button>
        <a class="secondary-button" href="{{ route('unit-records.index', $module) }}">Temizle</a>
    </form>
</section>
<section class="panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">Kayıt geçmişi</p><h2>{{ $config['title'] }} kayıtları</h2></div></div>
    <div class="list-tools" data-list-tools data-table-key="unit-{{ $module }}">
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
    <div class="table-wrap"><table class="data-table" data-list-table><thead><tr><th>Tarih</th><th>Hükümlü</th><th>Tür</th><th>Başlık</th><th>Durum</th><th>Özet</th>@foreach($tableFields as $field)<th>{{ $field['label'] }}</th>@endforeach<th></th></tr></thead><tbody>
    @forelse($records as $record)
        <tr data-filter-row="{{ \Illuminate\Support\Str::lower(trim(($record->inmate?->full_name ?? '').' '.($record->inmate?->identity_number ?? '').' '.($record->record_type ?? '').' '.($record->title ?? '').' '.($record->status_code ?? '').' '.($record->notes ?? '').' '.implode(' ', array_values($record->data ?? [])))) }}">
            <td>{{ $record->occurred_at?->format('d.m.Y') ?? '—' }}</td>
            <td><strong>{{ $record->inmate?->full_name }}</strong><br><small>{{ $record->inmate?->identity_number ?? 'TC yok' }}</small></td>
            <td>{{ $record->record_type }}</td>
            <td>{{ $record->title }}</td>
            <td><span class="status-badge {{ $record->is_closed ? 'passive' : 'ok' }}">{{ $record->is_closed ? 'Kapalı' : 'Açık' }}</span><br><small>{{ $record->status_code ?? '—' }}</small></td>
            <td><small>@foreach((isset($config['field_definitions']) ? [] : ($record->data ?? [])) as $key => $value)<strong>{{ $fieldLabels[$key] ?? $key }}:</strong> {{ $value }}<br>@endforeach{{ $record->notes ? \Illuminate\Support\Str::limit($record->notes, 120) : '' }}</small></td>
            @foreach($tableFields as $field)<td>{{ $record->data[$field['key']] ?? '—' }}</td>@endforeach
            <td class="actions-cell">
                @can('permission', $config['permission'].'.update')
                    <a class="secondary-button small-button" href="{{ route('unit-records.edit', [$module, $record]) }}">Düzenle</a>
                    @if(! $record->is_closed)
                        <form method="post" action="{{ route('unit-records.close', [$module, $record]) }}">@csrf @method('patch')<button class="secondary-button small-button" type="submit">Kapat</button></form>
                    @endif
                @endcan
            </td>
        </tr>
    @empty
        <tr data-empty-row><td colspan="{{ 7 + count($tableFields) }}">Bu modülde kayıt yok.</td></tr>
    @endforelse
        <tr data-no-match-row hidden><td colspan="{{ 7 + count($tableFields) }}">Anlık aramaya uyan kayıt yok.</td></tr>
    </tbody></table></div>
    {{ $records->links() }}
</section>
<section class="panel">
    <div class="panel-heading"><div><p class="eyebrow">Faz 5 modülleri</p><h2>Hızlı geçiş</h2></div></div>
    <div class="module-grid">@foreach($modules as $code => $item)@if(auth()->user()?->hasPermission($item['permission'].'.view'))<a class="module-chip" href="{{ route('unit-records.index', $code) }}"><strong>{{ $item['title'] }}</strong><small>{{ $item['task'] }}</small></a>@endif @endforeach</div>
</section>
@endsection
