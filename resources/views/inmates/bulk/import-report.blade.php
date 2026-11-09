@extends('layouts.portal')
@section('title', 'Toplu İçe Alma Raporu')
@section('page-title', 'Toplu İçe Alma Raporu')
@section('content')
<section class="panel">
    <div class="panel-heading table-heading"><div><p class="eyebrow">CSV doğrulama raporu</p><h2>Toplu içe alma sonucu</h2></div><a class="secondary-button" href="{{ route('inmates.bulk.index') }}">Toplu işlemlere dön</a></div>
    <div class="success-alert">Oluşturulan: {{ $report['created'] }} | Atlanan: {{ $report['skipped'] }}</div>
    <div class="table-wrap"><table class="data-table compact-table"><thead><tr><th>Satır</th><th>Ad soyad</th><th>Durum</th><th>Mesaj</th></tr></thead><tbody>
    @foreach($report['rows'] as $row)
        <tr><td>{{ $row['line'] }}</td><td>{{ $row['name'] ?: '—' }}</td><td><span class="status-badge {{ $row['status'] === 'Aktarıldı' ? 'ok' : 'passive' }}">{{ $row['status'] }}</span></td><td>{{ $row['message'] }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endsection
