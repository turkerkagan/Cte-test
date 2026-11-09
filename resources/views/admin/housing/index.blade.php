@extends('layouts.portal')
@section('title', 'Yerleşim Yönetimi')
@section('page-title', 'Yerleşim Yönetimi')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading">
        <div><p class="eyebrow">Yönetim</p><h2>Blok, koğuş ve ranzalar</h2></div>
        <div class="actions-cell">
            <a class="primary-link small-button" href="{{ route('admin.housing.blocks.create') }}">Blok ekle</a>
            <a class="primary-link small-button" href="{{ route('admin.housing.wards.create') }}">Koğuş ekle</a>
            <a class="primary-link small-button" href="{{ route('admin.housing.beds.create') }}">Ranza ekle</a>
        </div>
    </div>
    @if(session('status'))<div class="success-alert">{{ session('status') }}</div>@endif
    @forelse($blocks as $block)
        <div class="lookup-group">
            <div class="lookup-group-header">
                <div><h3>{{ $block->name }} <small>{{ $block->code }}</small></h3><p>{{ $block->description }}</p></div>
                <div class="actions-cell"><span class="status-badge {{ $block->is_active ? 'ok' : 'passive' }}">{{ $block->is_active ? 'Aktif' : 'Pasif' }}</span><a class="secondary-button small-button" href="{{ route('admin.housing.blocks.edit', $block) }}">Blok düzenle</a></div>
            </div>
            @forelse($block->wards as $ward)
                <div class="housing-ward">
                    <div class="housing-ward-title"><strong>{{ $ward->name }}</strong><small>{{ $ward->code }} · Kapasite: {{ $ward->capacity }} · Ranza: {{ $ward->beds->count() }}</small><a class="secondary-button small-button" href="{{ route('admin.housing.wards.edit', $ward) }}">Koğuş düzenle</a></div>
                    <div class="bed-grid">
                        @forelse($ward->beds as $bed)
                            <a class="bed-chip {{ $bed->is_active ? '' : 'passive' }}" href="{{ route('admin.housing.beds.edit', $bed) }}"><strong>{{ $bed->label }}</strong><small>{{ $bed->code }}</small></a>
                        @empty
                            <small>Ranza kaydı yok.</small>
                        @endforelse
                    </div>
                </div>
            @empty
                <p>Bu blokta koğuş yok.</p>
            @endforelse
        </div>
    @empty
        <p>Yerleşim kaydı yok.</p>
    @endforelse
</section>
@endsection
