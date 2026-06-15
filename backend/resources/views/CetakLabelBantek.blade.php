<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('partials.pdf-theme')
</head>
<body>
@include('partials.report-office-header', [
    'officeProfile' => $officeProfile,
    'title' => 'Label Penyimpanan Bantek',
    'logoFallback' => 'assets/logo.png',
])

<table class="meta-table page-block">
    <tr>
        <td class="meta-label">Lokasi</td>
        <td class="meta-value">{{ data_get($data1, 'lokasi_bantek', '-') }}</td>
        <td class="meta-label">No Bantek</td>
        <td class="meta-value">{{ data_get($data1, 'no_bantek', '-') }}</td>
    </tr>
    <tr>
        <td class="meta-label">Total Client</td>
        <td class="meta-value">{{ count($data2) }} client</td>
        <td class="meta-label">Status</td>
        <td class="meta-value">Aktif</td>
    </tr>
</table>

@if (empty($data1))
    <div class="page-block compact-note">Data bantek tidak ditemukan. Label ditampilkan dalam mode kosong.</div>
@endif

<div class="section-title">Isi Bantek</div>
<table class="list-table">
    <thead>
        <tr>
            <th style="width: 48px;">No</th>
            <th>Nama Client</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data2 as $index => $r)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $r['nama_client'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="2" class="empty-state">Belum ada client di bantek ini.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="compact-note" style="margin-top: 12px;">
    Label ini dicetak otomatis dari SIMANIS untuk kebutuhan penyimpanan fisik.
</div>
</body>
</html>
