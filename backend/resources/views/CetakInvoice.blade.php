<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('partials.pdf-theme')
</head>
<body>
@php
    $invoiceRow = $data['total_invoice'][0] ?? [];
    $status = trim((string) ($invoiceRow['status_invoice'] ?? '-'));
    $statusClass = 'status-cancel';
    $hasInvoice = !empty($data['id_order']);

    if ($status === 'Lunas') {
        $statusClass = 'status-lunas';
    } elseif ($status === 'Belum Lunas' || $status === 'Belum Bayar') {
        $statusClass = $status === 'Belum Lunas' ? 'status-belum-lunas' : 'status-belum-bayar';
    }
@endphp

@include('partials.report-office-header', [
    'officeProfile' => $officeProfile,
    'title' => 'Invoice / Kwitansi',
    'logoFallback' => 'assets/logo.png',
])

<table class="meta-table page-block">
    <tr>
        <td class="meta-label">No Invoice</td>
        <td class="meta-value">{{ $data['no_inv'] }}</td>
        <td class="meta-label">Status</td>
        <td class="meta-value"><span class="status-chip {{ $statusClass }}">{{ $status }}</span></td>
    </tr>
    <tr>
        <td class="meta-label">Tanggal Cetak</td>
        <td class="meta-value">{{ trim($tanggal) }}</td>
        <td class="meta-label">Jumlah Item</td>
        <td class="meta-value">{{ count($data['detail_order']) }} baris</td>
    </tr>
    <tr>
        <td class="meta-label">Sudah Terima Dari</td>
        <td class="meta-value">{{ $data['nama_pesanan'] }}</td>
        <td class="meta-label">Banyaknya Uang</td>
        <td class="meta-value"><strong>Rp.{{ number_format($invoiceRow['grand_total'] ?? 0) }}</strong></td>
    </tr>
</table>

@if (!$hasInvoice)
    <div class="page-block compact-note">Data invoice tidak ditemukan. Dokumen ditampilkan dalam mode kosong.</div>
@endif

<div class="section-title">Detail Pembayaran</div>
<table class="data-table">
    <thead>
        <tr>
            <th class="nowrap">Nomor Akta</th>
            <th class="nowrap">Tanggal Akta</th>
            <th>Keterangan</th>
            <th class="number nowrap">Jumlah</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data['detail_order'] as $r)
            <tr>
                <td class="nowrap">{{ $r['no_pekerjaan'] }}</td>
                <td class="nowrap">{{ $r['tanggal_pekerjaan'] }}</td>
                <td>{{ $r['nama_pekerjaan'] }}</td>
                <td class="number nowrap">Rp.{{ number_format($r['harga']) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="empty-state">Belum ada detail invoice.</td>
            </tr>
        @endforelse
        @if (!empty($invoiceRow['status_diskon']))
            <tr>
                <td colspan="3"><strong>Diskon {{ $invoiceRow['nilai_diskon'] }}%</strong></td>
                <td class="number nowrap">Rp.{{ number_format($invoiceRow['diskon']) }}</td>
            </tr>
        @endif
        @if (!empty($invoiceRow['status_tax']))
            <tr>
                <td colspan="3"><strong>Tax</strong></td>
                <td class="number nowrap">Rp.{{ number_format($invoiceRow['tax']) }}</td>
            </tr>
        @endif
        <tr class="row-primary">
            <td colspan="3">Total</td>
            <td class="number nowrap">Rp.{{ number_format($invoiceRow['grand_total'] ?? 0) }}</td>
        </tr>
    </tbody>
</table>

<div class="page-block">
    <span class="invoice-title">Jumlah: Rp.{{ number_format($invoiceRow['grand_total'] ?? 0) }},- Nett</span>
</div>

<div class="section-title">Rekening Pembayaran</div>
<table class="bank-table page-block">
    <tr>
        <td>{!! nl2br(e($officeProfile['invoice_bank_account_1'])) !!}</td>
        <td>{!! nl2br(e($officeProfile['invoice_bank_account_2'])) !!}</td>
        <td>{!! nl2br(e($officeProfile['invoice_bank_account_3'])) !!}</td>
    </tr>
</table>

<table class="signature-table">
    <tr>
        <td class="text-muted">Invoice ini dicetak otomatis dari SIMANIS.</td>
        <td class="text-center">
            {{ $officeProfile['office_city'] }}, {{ trim($tanggal) }}<br>
            {{ $officeProfile['signatory_title'] }}<br>
            <div class="signature-line">{{ $officeProfile['signatory_name'] }}</div>
        </td>
    </tr>
</table>
</body>
</html>
