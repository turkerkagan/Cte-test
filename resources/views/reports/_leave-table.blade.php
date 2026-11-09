<div class="table-wrap report-table-wrap">
    <table class="data-table report-table">
        <thead><tr><th>Sıra</th><th>TC Kimlik</th><th>Adı Soyadı</th><th>UYAP No</th><th>Çalışma Yeri</th><th>Blok</th><th>Koğuş</th><th>Ranza</th><th>Çıkış Tarihi</th><th>Çıkış Saati</th><th>Dönüş Tarihi</th><th>Dönüş Saati</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr><td>{{ $loop->iteration }}</td><td>{{ $row['identity_number'] ?: '—' }}</td><td><strong>{{ $row['full_name'] ?: '—' }}</strong></td><td>{{ $row['uyap'] ?: '—' }}</td><td>{{ $row['work'] ?: '—' }}</td><td>{{ $row['block'] ?: '—' }}</td><td>{{ $row['ward'] ?: '—' }}</td><td>{{ $row['bed'] ?: '—' }}</td><td>{{ $row['leave_date'] ?: '—' }}</td><td>{{ $row['leave_time'] ?: '—' }}</td><td>{{ $row['return_date'] ?: '—' }}</td><td>{{ $row['return_time'] ?: '—' }}</td></tr>
        @empty
            <tr><td colspan="12">Seçilen tarih için kayıt bulunamadı.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
