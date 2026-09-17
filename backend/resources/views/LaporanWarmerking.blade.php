<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('partials.pdf-theme')
</head>
<body>
@php
    $summaryRows = is_array($keterangan ?? null) ? $keterangan : [];
@endphp

@include('partials.report-office-header', [
    'officeProfile' => $officeProfile,
    'title' => 'Laporan Waarmerking',
    'logoFallback' => 'assets/logo.png',
])

<table class="meta-table page-block">
    <tr>
        <td class="meta-label">Tanggal Cetak</td>
        <td class="meta-value">{{ $tanggal }}</td>
        <td class="meta-label">Total Data</td>
        <td class="meta-value">{{ count($data) }} entri</td>
    </tr>
</table>

<div class="section-title">Daftar Waarmerking</div>
<table class="data-table">
    <thead>
        <tr>
            <th>Judul Surat</th>
            <th class="nowrap">Tgl Waarmerking</th>
            <th class="nowrap">No Waarmerking</th>
            <th>Pengambil Nomor</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data as $r)
            <tr class="row-primary{{ empty($r['has_document']) ? ' missing-document' : '' }}">
                <td>{{ $r['judul_surat'] }}</td>
                <td class="nowrap">{{ $r['tgl_didaftarkan'] }}</td>
                <td class="nowrap">{{ $r['no_warmerking'] }}</td>
                <td>{{ $r['pengambil'] }}</td>
            </tr>
            @if (empty($r['has_document']))
                <tr class="missing-document-note">
                    <td colspan="4">Dokumen pekerjaan belum diupload.</td>
                </tr>
            @endif
            @if (count($r['daftarpenghadap']) > 0)
                <tr class="detail-heading">
                    <td>Nama Penghadap</td>
                    <td>Status Kedudukan</td>
                    <td colspan="2">Mewakili</td>
                </tr>
                @foreach ($r['daftarpenghadap'] as $p)
                    <tr class="detail-row">
                        <td>{{ $p['nama_client'] }}</td>
                        <td>{{ $p['status_kedudukan'] }}</td>
                        <td colspan="2">{{ $p['mewakili'] ?: '-' }}</td>
                    </tr>
                @endforeach
            @endif
        @empty
            <tr>
                <td colspan="4" class="empty-state">Tidak ada data waarmerking untuk periode ini.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="section-title">Ringkasan Asisten</div>
<table class="summary-table">
    <thead>
        <tr>
            <th>Nama Asisten</th>
            <th class="number">Jumlah</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($summaryRows as $ket)
            <tr>
                <td>{{ $ket['nama_lengkap'] }}</td>
                <td class="number">{{ $ket['total'] }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="2" class="empty-state">Belum ada ringkasan asisten untuk periode ini.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<table class="signature-table">
    <tr>
        <td class="text-muted">Dokumen ini dicetak otomatis dari SIMANIS.</td>
        <td class="text-center">
            {{ $officeProfile['office_city'] }}, {{ $tanggal }}<br>
            Mengetahui<br>
            {{ $officeProfile['signatory_title'] }}<br>
            <div class="signature-line">{{ $officeProfile['signatory_name'] }}</div>
        </td>
    </tr>
</table>
</body>
</html>
