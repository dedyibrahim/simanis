<?php

namespace App\Http\Controllers;

use App\Models\detail_pesanan;
use App\Models\invoice_non_taxs;
use App\Models\invoice_taxs;
use App\Models\IsiDiterima;
use App\Models\PenyimpananBantek;
use App\Models\ReportSetting;
use App\Models\TandaTerima;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Report extends Controller
{
    private function reportProfile(): array
    {
        return ReportSetting::current()->toReportProfile();
    }

    private function buildUserSummary($query): array
    {
        $data = [];

        foreach ($query as $row) {
            $user = User::where('id_user', $row->id_user)->first();
            $data[] = [
                'total' => $row->total,
                'nama_lengkap' => $user?->nama_lengkap ?? '-',
            ];
        }

        return $data;
    }

    private function defaultInvoicePayload(): array
    {
        return [
            'created_at' => null,
            'id_order' => null,
            'id_user' => null,
            'keterangan_order' => null,
            'nama_lengkap' => '-',
            'nama_pesanan' => '-',
            'status_order' => null,
            'updated_at' => null,
            'detail_order' => [],
            'no_inv' => '-',
            'jenis_invoice' => null,
            'total_invoice' => [],
        ];
    }

    private function defaultTandaTerimaPayload(): array
    {
        return [
            'nomor_tanda_terima' => '-',
            'nama_penerima' => '-',
            'lokasi' => '-',
            'up_penerima' => '-',
            'keterangan_tanda_terima' => '-',
            'nama_pengirim' => '-',
        ];
    }

    public function CetakLaporanNotaris(Request $request)
    {
        $tgl = explode('-', $request->get('date'));
        $query = DB::table('buku_notaris')
            ->whereYear('buku_notaris.tgl_akta', $tgl[0])
            ->whereMonth('buku_notaris.tgl_akta', $tgl[1])
            ->leftJoin('daftar_aktas', 'buku_notaris.id_akta', '=', 'daftar_aktas.id_akta')
            ->join('users', 'buku_notaris.id_user', '=', 'users.id_user')
            ->select('buku_notaris.id_buku_notaris', 'buku_notaris.nama_client', 'buku_notaris.judul_pekerjaan', 'buku_notaris.status_akta', 'users.nama_lengkap', 'users.id_user', 'daftar_aktas.nama_akta', 'buku_notaris.tgl_akta', 'buku_notaris.no_akta')
            ->orderBy('buku_notaris.id_buku_notaris', 'Asc')
            ->get();

        $data = [];
        $keteranganlaporan = $this->KeteranganLaporanNotaris($request->get('date'));
        foreach ($query as $r) {
            $p = new PembuatanNomor();

            $daftar = $p->getDataPenghadapNotaris($r->id_buku_notaris);
            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_notaris' => $r->id_buku_notaris,
                'nama_akta' => $r->nama_akta,
                'judul_pekerjaan' => $r->judul_pekerjaan,
                'tgl_akta' => $r->tgl_akta,
                'nama_client' => $r->nama_client,
                'no_akta' => $r->no_akta,
                'status_akta' => $r->status_akta,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $daftar,
            ];
        }

        $tgl = date('l j F Y');

        $date = Carbon::parse($tgl)->locale('id');
        $date->settings(['formatFunction' => 'translatedFormat']);
        setlocale(LC_ALL, 'IND');
        $tanggal = $date->format('l j F Y');
        //    return view('LaporanNotaris',['data'=>$data,'keterangan'=>$keteranganlaporan,'tanggal'=>$tanggal]);
        $pdf = PDF::loadView('LaporanNotaris', [
            'data' => $data,
            'keterangan' => $keteranganlaporan,
            'tanggal' => $tanggal,
            'officeProfile' => $this->reportProfile(),
        ]);

        return $pdf->stream();
    }

    public function KeteranganLaporanNotaris($tanggal)
    {
        $tgl = explode('-', $tanggal);
        $query = DB::table('buku_notaris')
            ->whereYear('buku_notaris.tgl_akta', $tgl[0])
            ->whereMonth('buku_notaris.tgl_akta', $tgl[1])
            ->select('buku_notaris.id_user', DB::raw('count(*) as total'))
            ->groupBy('buku_notaris.id_user')
            ->orderBy('total', 'DESC')
            ->get();

        return $this->buildUserSummary($query);
    }

    public function CetakLaporanLegalisasi(Request $request)
    {
        $tgl = explode('-', $request->get('date'));

        $query = DB::table('buku_legalisasis')
            ->join('users', 'buku_legalisasis.id_user', '=', 'users.id_user')
            ->whereYear('buku_legalisasis.tgl_surat', $tgl[0])
            ->whereMonth('buku_legalisasis.tgl_surat', $tgl[1])
            ->select('buku_legalisasis.id_buku_legalisasi', 'buku_legalisasis.status_legalisasi', 'buku_legalisasis.keterangan_surat', 'buku_legalisasis.no_legalisasi', 'users.nama_lengkap', 'users.id_user', 'buku_legalisasis.tgl_surat', 'buku_legalisasis.judul_surat')
            ->orderByDesc('buku_legalisasis.id_buku_legalisasi')
            ->get();

        $data = [];

        foreach ($query as $r) {
            $p = new PembuatanNomor();

            $daftar = $p->getDataPenghadapLegalisasi($r->id_buku_legalisasi);

            if ($r->keterangan_surat != null) {
                $keterangan = $r->keterangan_surat;
            } else {
                $keterangan = null;
            }

            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_legalisasi' => $r->id_buku_legalisasi,
                'judul_surat' => $r->judul_surat,
                'tgl_surat' => $r->tgl_surat,
                'no_legalisasi' => $p->ProsesNomorLegalisasi($r->no_legalisasi, $r->tgl_surat, $keterangan),
                'status_legalisasi' => $r->status_legalisasi,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $daftar,
            ];
        }

        $keteranganlaporan = $this->KeteranganLaporanLegalisasi($request->get('date'));

        $tgl = date('l j F Y');

        $date = Carbon::parse($tgl)->locale('id');
        $date->settings(['formatFunction' => 'translatedFormat']);
        setlocale(LC_ALL, 'IND');
        $tanggal = $date->format('l j F Y');
        //  echo print_r($data);
        //  return view('LaporanLegalisasi',['data'=>$data,'keterangan'=>$keteranganlaporan,'tanggal'=>$tanggal]);
        $pdf = PDF::loadView('LaporanLegalisasi', [
            'data' => $data,
            'keterangan' => $keteranganlaporan,
            'tanggal' => $tanggal,
            'officeProfile' => $this->reportProfile(),
        ]);

        return $pdf->stream();
    }

    public function KeteranganLaporanLegalisasi($tanggal)
    {
        $tgl = explode('-', $tanggal);
        $query = DB::table('buku_legalisasis')
        ->join('users', 'buku_legalisasis.id_user', '=', 'users.id_user')
        ->whereYear('buku_legalisasis.tgl_surat', $tgl[0])
        ->whereMonth('buku_legalisasis.tgl_surat', $tgl[1])
        ->select('buku_legalisasis.id_user', DB::raw('count(*) as total'))
        ->groupBy('buku_legalisasis.id_user')
        ->orderBy('total', 'DESC')
        ->get();

        return $this->buildUserSummary($query);
    }

    public function CetakLaporanWarmerking(Request $request)
    {
        $tgl = explode('-', $request->get('date'));

        $query = DB::table('buku_warmerkings')
            ->join('users', 'buku_warmerkings.id_user', '=', 'users.id_user')
            ->whereYear('buku_warmerkings.tgl_didaftarkan', $tgl[0])
            ->whereMonth('buku_warmerkings.tgl_didaftarkan', $tgl[1])
            ->select('buku_warmerkings.id_buku_warmerking', 'buku_warmerkings.status_warmerking', 'buku_warmerkings.keterangan_surat', 'buku_warmerkings.no_warmerking', 'users.nama_lengkap', 'users.id_user', 'buku_warmerkings.tgl_didaftarkan', 'buku_warmerkings.judul_surat')
            ->orderByDesc('buku_warmerkings.id_buku_warmerking')
            ->get();

        $data = [];
        $p = new PembuatanNomor();

        foreach ($query as $r) {
            $daftar = $p->getDataPenghadapWarmerking($r->id_buku_warmerking);

            if ($r->keterangan_surat != null) {
                $keterangan = $r->keterangan_surat;
            } else {
                $keterangan = null;
            }

            $data[] = [
                'id_user' => $r->id_user,
                'id_buku_warmerking' => $r->id_buku_warmerking,
                'judul_surat' => $r->judul_surat,
                'tgl_didaftarkan' => $r->tgl_didaftarkan,
                'no_warmerking' => $p->ProsesNomorWarmerking($r->no_warmerking, $r->tgl_didaftarkan, $keterangan),
                'status_warmerking' => $r->status_warmerking,
                'pengambil' => $r->nama_lengkap,
                'daftarpenghadap' => $daftar,
            ];
        }

        $keteranganlaporan = $this->KeteranganLaporanWarmerking($request->get('date'));

        $tgl = date('l j F Y');

        $date = Carbon::parse($tgl)->locale('id');
        $date->settings(['formatFunction' => 'translatedFormat']);
        setlocale(LC_ALL, 'IND');
        $tanggal = $date->format('l j F Y');
        //    echo print_r($data);
        // return view('LaporanWarmerking',['data'=>$data,'keterangan'=>$keteranganlaporan,'tanggal'=>$tanggal]);
        $pdf = PDF::loadView('LaporanWarmerking', [
            'data' => $data,
            'keterangan' => $keteranganlaporan,
            'tanggal' => $tanggal,
            'officeProfile' => $this->reportProfile(),
        ]);

        return $pdf->stream();
    }

    public function KeteranganLaporanWarmerking($tanggal)
    {
        $tgl = explode('-', $tanggal);

        $query = DB::table('buku_warmerkings')
        ->join('users', 'buku_warmerkings.id_user', '=', 'users.id_user')
        ->whereYear('buku_warmerkings.tgl_didaftarkan', $tgl[0])
        ->whereMonth('buku_warmerkings.tgl_didaftarkan', $tgl[1])
        ->select('buku_warmerkings.id_user', DB::raw('count(*) as total'))
        ->groupBy('buku_warmerkings.id_user')
        ->orderBy('total', 'DESC')
        ->get();

        return $this->buildUserSummary($query);
    }

    public function CetakLaporanPPAT(Request $request)
    {
        $tgl = explode('-', $request->get('date'));

        $query = DB::table('buku_ppats')
            ->leftjoin('daftar_aktas', 'buku_ppats.id_akta', '=', 'daftar_aktas.id_akta')
            ->leftjoin('users', 'buku_ppats.id_user', '=', 'users.id_user')
            ->whereYear('buku_ppats.tanggal_akta', $tgl[0])
            ->whereMonth('buku_ppats.tanggal_akta', $tgl[1])
            ->select(
                'buku_ppats.id_buku_ppat',
                'buku_ppats.status_akta',
                'users.nama_lengkap',
                'users.id_user',
                'daftar_aktas.nama_akta',
                'buku_ppats.tanggal_akta',
                'buku_ppats.no_akta',
                'buku_ppats.no_hak_milik',
                'buku_ppats.luas_tanah_bangunan',
                'buku_ppats.luas_tanah',
                'buku_ppats.luas_bangunan',
                'buku_ppats.harga_transaksi',
                'buku_ppats.nop',
                'buku_ppats.harga_njop',
                'buku_ppats.tgl_bphtb',
                'buku_ppats.harga_bphtb',
                'buku_ppats.tgl_pph',
                'buku_ppats.harga_pph',
                'buku_ppats.keterangan',
            )
            ->orderByDesc('buku_ppats.id_buku_ppat')
            ->get();

        $data = [];
        $p = new PembuatanNomor();

        foreach ($query as $r) {
            $daftar = $p->getDataPenghadapPPAT($r->id_buku_ppat);
            $data[] = [
             'id_user' => $r->id_user,
             'id_buku_ppat' => $r->id_buku_ppat,
             'nama_akta' => $r->nama_akta,
             'tanggal_akta' => $r->tanggal_akta,
             'no_akta' => $r->no_akta,
             'status_akta' => $r->status_akta,
             'pengambil' => $r->nama_lengkap,
             'daftarpenghadap' => $daftar,
             'no_hak_milik' => $r->no_hak_milik,
             'luas_tanah' => number_format($r->luas_tanah),
             'luas_bangunan' => number_format($r->luas_bangunan),
             'harga_transaksi' => 'Rp. '.number_format($r->harga_transaksi),
             'nop' => $r->nop,
             'harga_njop' => 'Rp. '.number_format($r->harga_njop),
             'tgl_bphtb' => $r->tgl_bphtb,
             'harga_bphtb' => 'Rp. '.number_format($r->harga_bphtb),
             'tgl_pph' => $r->tgl_pph,
             'harga_pph' => 'Rp. '.number_format($r->harga_pph),
             'keterangan' => $r->keterangan,
            ];
        }

        $keteranganlaporan = $this->KeteranganLaporanPPAT($request->get('date'));

        $tgl = date('l j F Y');

        $date = Carbon::parse($tgl)->locale('id');
        $date->settings(['formatFunction' => 'translatedFormat']);
        setlocale(LC_ALL, 'IND');
        $tanggal = $date->format('l j F Y');
        // echo print_r($data);
        // return view('LaporanPPAT',['data'=>$data,'keterangan'=>$keteranganlaporan,'tanggal'=>$tanggal]);
        $pdf = PDF::loadView('LaporanPPAT', [
            'data' => $data,
            'keterangan' => $keteranganlaporan,
            'tanggal' => $tanggal,
            'officeProfile' => $this->reportProfile(),
        ]);

        return $pdf->stream();
    }

    public function KeteranganLaporanPPAT($tanggal)
    {
        $tgl = explode('-', $tanggal);
        $query = DB::table('buku_ppats')
        ->leftjoin('daftar_aktas', 'buku_ppats.id_akta', '=', 'daftar_aktas.id_akta')
        ->leftjoin('users', 'buku_ppats.id_user', '=', 'users.id_user')
        ->whereYear('buku_ppats.tanggal_akta', $tgl[0])
        ->whereMonth('buku_ppats.tanggal_akta', $tgl[1])
        ->select('buku_ppats.id_user', DB::raw('count(*) as total'))
        ->groupBy('buku_ppats.id_user')
        ->orderBy('total', 'DESC')
        ->get();

        return $this->buildUserSummary($query);
    }

    public function CetakInvoice(Request $request)
    {
        $query = DB::table('orders')
            ->where('id_order', $request->get('id'))
            ->join('users', 'orders.id_user', '=', 'users.id_user')
            ->orderByDesc('orders.id_order')
            ->select('id_order', 'ket_noinv', 'nama_pesanan', 'keterangan_order', 'orders.id_user', 'orders.no_inv', 'orders.jenis_invoice', 'status_order', 'users.nama_lengkap', 'orders.created_at', 'orders.updated_at')
            ->get();

        $data = $this->defaultInvoicePayload();

        foreach ($query as $d) {
            if ($d->jenis_invoice == 'tax') {
                $total_invoice = invoice_taxs::where('id_order', $d->id_order)->get()->toArray();
            } else {
                $total_invoice = invoice_non_taxs::where('id_order', $d->id_order)->get()->toArray();
            }

            $nomor = new PembuatanNomor();
            $data = [
             'created_at' => $d->created_at,
             'id_order' => $d->id_order,
             'id_user' => $d->id_user,
             'keterangan_order' => $d->keterangan_order,
             'nama_lengkap' => $d->nama_lengkap,
             'nama_pesanan' => $d->nama_pesanan,
             'status_order' => $d->status_order,
             'updated_at' => $d->updated_at,
             'detail_order' => detail_pesanan::where('id_order', $d->id_order)->get()->toArray(),
             'no_inv' => $nomor->ProsesNomorInvoice($d->no_inv, $d->created_at, $d->ket_noinv),
             'jenis_invoice' => $d->jenis_invoice,
             'total_invoice' => $total_invoice,
            ];
        }

        $tgl = date('l j F Y');

        $date = Carbon::parse($tgl)->locale('id');
        $date->settings(['formatFunction' => 'translatedFormat']);
        setlocale(LC_ALL, 'IND');
        $tanggal = $date->format(' j F Y');
        // return view('CetakInvoice',['tanggal'=>$tanggal,'data'=>$data]);

        $pdf = PDF::loadView('CetakInvoice', [
            'tanggal' => $tanggal,
            'data' => $data,
            'officeProfile' => $this->reportProfile(),
        ]);

        return $pdf->stream();
    }

    public function CetakLabelBantek(Request $request)
    {
        $data1 = PenyimpananBantek::where('no_bantek', $request->get('no_bantek'))->get()->first();

        $data2 = PenyimpananBantek::where('no_bantek', $request->get('no_bantek'))
             ->leftJoin('data_clients', 'penyimpanan_bantek.id_client', '=', 'data_clients.id_client')
             ->get();

        $pdf = PDF::loadView('CetakLabelBantek', [
            'data1' => $data1,
            'data2' => $data2,
            'officeProfile' => $this->reportProfile(),
        ]);

        return $pdf->stream();

        //  return view('CetakLabelBantek', ['data1' => $data1, 'data2' => $data2]);
    }

    public function CetakTandaTerima(Request $request)
    {
        $data1 = TandaTerima::where('id', $request->get('id'))
        ->get()->first();

        $data2 = isiDiterima::where('tanda_terima_id', $request->get('id'))
        ->get();

        $data = $data1 ? $data1->toArray() : $this->defaultTandaTerimaPayload();

        $pdf = PDF::loadView('CetakTandaTerima', [
            'data' => $data,
            'data2' => $data2,
            'officeProfile' => $this->reportProfile(),
        ]);
        $pdf->setOption('margin-top', 0);
        $pdf->setOption('margin-right', 15);
        $pdf->setOption('margin-bottom', 0);
        $pdf->setOption('margin-left', 15);

        return $pdf->stream();

        // return view('CetakTandaTerima', ['data' => $data1, 'data2'=>$data2 ]);
    }
}
