<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
@include('partials.pdf-theme')
</head>
<body>
@include('partials.report-office-header', [
    'officeProfile' => $officeProfile,
    'title' => 'Tanda Terima',
    'logoFallback' => 'assets/garuda.png',
])

<table class="meta-table page-block">
    <tr>
        <td class="meta-label">No Tanda Terima</td>
        <td class="meta-value">{{ $data['nomor_tanda_terima'] }}</td>
        <td class="meta-label">Penerima</td>
        <td class="meta-value">{{ $data['nama_penerima'] }}</td>
    </tr>
    <tr>
        <td class="meta-label">Lokasi</td>
        <td class="meta-value">{{ $data['lokasi'] }}</td>
        <td class="meta-label">Attn</td>
        <td class="meta-value">{{ $data['up_penerima'] }}</td>
    </tr>
    <tr>
        <td class="meta-label">Perihal</td>
        <td class="meta-value" colspan="3">{{ $data['keterangan_tanda_terima'] }}</td>
    </tr>
</table>

@if (($data['nomor_tanda_terima'] ?? '-') === '-')
    <div class="page-block compact-note">Data tanda terima tidak ditemukan. Dokumen ditampilkan dalam mode kosong.</div>
@endif

<div class="page-block">
    Dengan hormat, berikut kami serahkan dokumen-dokumen sebagai berikut:
</div>

<table class="list-table">
    <thead>
        <tr>
            <th class="nowrap" style="width: 48px;">No</th>
            <th>Uraian Dokumen</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($data2 as $index => $r)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $r->isi_diterima }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="2" class="empty-state">Belum ada dokumen yang dicantumkan.</td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="page-block" style="margin-top: 12px;">
    Demikian yang dapat kami sampaikan. Terima kasih atas perhatiannya.
</div>

<table class="signature-table">
    <tr>
        <td class="text-center">
            Yang Menyerahkan
            <div class="signature-line">({{ $data['nama_pengirim'] }})</div>
        </td>
        <td class="text-center">
            Yang Menerima
            <div class="signature-line">({{ $data['up_penerima'] }})</div>
            <div class="compact-note">Tanggal: ____________________</div>
        </td>
    </tr>
</table>
</body>
</html>
