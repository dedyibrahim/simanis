<?php

namespace App\Http\Controllers;

use App\Models\BukuSuratNotaris;
use App\Models\BukuSuratPPAT;
use App\Models\DataClient;
use App\Models\TandaTerima;
use App\Models\tb_berkas;
use App\Models\tb_dokumen_legalisasis;
use App\Models\tb_dokumen_notaris;
use App\Models\tb_dokumen_ppat;
use App\Models\tb_dokumen_warmerkings;
use App\Models\tb_nama_dokumens;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DokumenNotaris extends Controller
{
    private function safeOriginalFileName($file, string $directory): string
    {
        $original = (string) $file->getClientOriginalName();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $nameOnly = pathinfo($original, PATHINFO_FILENAME);
        $safeName = preg_replace('/[^\pL\pN\s._-]+/u', '-', $nameOnly) ?: '';
        $safeName = preg_replace('/\s+/', ' ', $safeName) ?: '';
        $safeName = trim($safeName, " ._-");

        if ($safeName === '') {
            $safeName = 'dokumen';
        }

        $candidate = $safeName.($extension ? '.'.$extension : '');
        $targetDirectory = public_path($directory);
        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        $counter = 2;
        while (is_file($targetDirectory.DIRECTORY_SEPARATOR.$candidate)) {
            $candidate = $safeName.'-'.$counter.($extension ? '.'.$extension : '');
            $counter++;
        }

        return $candidate;
    }

    private function moveWithOriginalName($file, string $directory): string
    {
        $filename = $this->safeOriginalFileName($file, $directory);
        $file->move(public_path($directory), $filename);

        return $filename;
    }

    public function UploadDokumenNotaris(Request $request)
    {
        foreach ($request->file('dokumens') as $key => $file) {
            $dokumen = DB::table('tb_dokumen_notaris')
                ->orderBy('id_dokumen_notaris', 'desc')
                ->limit(1)
                ->first();

            if (isset($dokumen->id_dokumen_notaris)) {
                $urutan = (int) substr($dokumen->id_dokumen_notaris, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_dokumen = 'Doc'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            $filenameSimpan = $this->moveWithOriginalName($file, 'berkasnotaris');

            tb_dokumen_notaris::create([
                'id_dokumen_notaris' => $id_dokumen,
                'id_buku_notaris' => $request->post('id_buku_notaris'),
                'id_user' => auth()->user()->id_user,
                'nama_berkas' => $filenameSimpan,
                'nama_dokumen' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ]);
        }
        $response = [
            'status' => true,
            'message' => 'Berhasil Menambahkan dokumen baru',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function UploadDokumenPPAT(Request $request)
    {
        foreach ($request->file('dokumens') as $key => $file) {
            $dokumen = DB::table('tb_dokumen_ppat')
                ->orderBy('id_dokumen_ppat', 'desc')
                ->limit(1)
                ->first();

            if (isset($dokumen->id_dokumen_ppat)) {
                $urutan = (int) substr($dokumen->id_dokumen_ppat, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_dokumen = 'Doc'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            $filenameSimpan = $this->moveWithOriginalName($file, 'berkasppat');

            tb_dokumen_ppat::create([
                'id_dokumen_ppat' => $id_dokumen,
                'id_buku_ppat' => $request->post('id_buku_ppat'),
                'id_user' => auth()->user()->id_user,
                'nama_berkas' => $filenameSimpan,
                'nama_dokumen' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ]);
        }
        $response = [
            'status' => true,
            'message' => 'Berhasil Menambahkan dokumen baru',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function UploadDokumenWarmerking(Request $request)
    {
        foreach ($request->file('dokumens') as $key => $file) {
            $dokumen = DB::table('tb_dokumen_warmerkings')
                ->orderBy('id_dokumen_warmerking', 'desc')
                ->limit(1)
                ->first();

            if (isset($dokumen->id_dokumen_warmerking)) {
                $urutan = (int) substr($dokumen->id_dokumen_warmerking, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_dokumen = 'Doc'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            $filenameSimpan = $this->moveWithOriginalName($file, 'berkaswarmerkings');

            tb_dokumen_warmerkings::create([
                'id_dokumen_warmerking' => $id_dokumen,
                'id_buku_warmerking' => $request->post('id_buku_warmerking'),
                'id_user' => auth()->user()->id_user,
                'nama_berkas' => $filenameSimpan,
                'nama_dokumen' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ]);
        }
        $response = [
            'status' => true,
            'message' => 'Berhasil Menambahkan dokumen baru',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function UploadSuratNotaris(Request $request)
    {
        $file = $request->file('dokumen');

        $filenameSimpan = $this->moveWithOriginalName($file, 'suratnotaris');

        BukuSuratNotaris::where('id_surat_notaris', $request->post('id_surat_notaris'))
        ->update([
            'file' => $filenameSimpan,
        ]);

        $response = [
            'status' => true,
            'message' => 'Berhasil Menambahkan surat notaris',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function UploadTandaTerima(Request $request)
    {
        $file = $request->file('dokumen');

        $filenameSimpan = $this->moveWithOriginalName($file, 'tandaterima');

        TandaTerima::where('id', $request->post('id'))
        ->update([
            'file' => $filenameSimpan,
        ]);

        $response = [
            'status' => true,
            'message' => 'Berhasil Menambahkan Tanda Terima',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function UploadSuratPPAT(Request $request)
    {
        $file = $request->file('dokumen');

        $filenameSimpan = $this->moveWithOriginalName($file, 'suratppats');

        BukuSuratPPAT::where('id_surat_ppat', $request->post('id_surat_ppat'))
        ->update([
            'file' => $filenameSimpan,
        ]);

        $response = [
            'status' => true,
            'message' => 'Berhasil Menambahkan surat ppat',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function UploadDokumenLegalisasi(Request $request)
    {
        foreach ($request->file('dokumens') as $key => $file) {
            $dokumen = DB::table('tb_dokumen_legalisasis')
                ->orderBy('id_dokumen_legalisasi', 'desc')
                ->limit(1)
                ->first();

            if (isset($dokumen->id_dokumen_legalisasi)) {
                $urutan = (int) substr($dokumen->id_dokumen_legalisasi, 6) + 1;
            } else {
                $urutan = 1;
            }

            $id_dokumen = 'Doc'.str_pad($urutan, 7, '0', STR_PAD_LEFT);

            $filenameSimpan = $this->moveWithOriginalName($file, 'berkaslegalisasis');

            tb_dokumen_legalisasis::create([
                'id_dokumen_legalisasi' => $id_dokumen,
                'id_buku_legalisasi' => $request->post('id_buku_legalisasi'),
                'id_user' => auth()->user()->id_user,
                'nama_berkas' => $filenameSimpan,
                'nama_dokumen' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ]);
        }
        $response = [
            'status' => true,
            'message' => 'Berhasil Menambahkan dokumen baru',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function StandarDokumenNotaris(Request $request)
    {
        $data = tb_dokumen_notaris::where('id_buku_notaris', $request->post('id'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_notaris.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_notaris.id_dokumen_notaris', 'desc')
        ->select('id_dokumen_notaris', 'tb_dokumen_notaris.nama_dokumen', 'tb_dokumen_notaris.nama_berkas')
        ->get()->toArray();

        $response = [
            'status' => true,
            'message' => 'Berhasil Memuat Dokumen Notaris',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function StandarDokumenPPAT(Request $request)
    {
        $data = tb_dokumen_ppat::where('id_buku_ppat', $request->post('id'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_ppat.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_ppat.id_dokumen_ppat', 'desc')
        ->select('id_dokumen_ppat', 'tb_dokumen_ppat.nama_dokumen', 'tb_dokumen_ppat.nama_berkas')
        ->get()->toArray();

        $response = [
            'status' => true,
            'message' => 'Berhasil Memuat Dokumen PPAT',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function StandarDokumenWarmerkings(Request $request)
    {
        $data = tb_dokumen_warmerkings::where('id_buku_warmerking', $request->post('id'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_warmerkings.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_warmerkings.id_dokumen_warmerking', 'desc')
        ->select('id_dokumen_warmerking', 'tb_dokumen_warmerkings.nama_dokumen', 'tb_dokumen_warmerkings.nama_berkas')
        ->get()->toArray();

        $response = [
            'status' => true,
            'message' => 'Berhasil Memuat Dokumen Notaris',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function StandarDokumenLegalisasis(Request $request)
    {
        $data = tb_dokumen_legalisasis::where('id_buku_legalisasi', $request->post('id'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_legalisasis.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_legalisasis.id_dokumen_legalisasi', 'desc')
        ->select('id_dokumen_legalisasi', 'tb_dokumen_legalisasis.nama_dokumen', 'tb_dokumen_legalisasis.nama_berkas')
        ->get()->toArray();

        $response = [
            'status' => true,
            'message' => 'Berhasil Memuat Dokumen Notaris',
            'data' => $data,
        ];

        return response($response, 200);
    }

    public function UpdateDokumenNotaris(Request $request)
    {
        $validated = $request->validate([
            'nama_dokumen' => 'required',
            'id_dokumen_notaris' => 'required',
        ]);
        $data = ['nama_dokumen' => $request->post('nama_dokumen')];

        tb_dokumen_notaris::where('id_dokumen_notaris', $request->post('id_dokumen_notaris'))
         ->update($data);

        $response = [
            'status' => true,
            'message' => 'Update Dokumen Berhasil',
            'data' => $request->post('id_dokumen_notaris'),
        ];

        return response($response, 200);
    }

    public function UpdateDokumenPPAT(Request $request)
    {
        $validated = $request->validate([
            'nama_dokumen' => 'required',
            'id_dokumen_ppat' => 'required',
        ]);
        $data = ['nama_dokumen' => $request->post('nama_dokumen')];


        tb_dokumen_ppat::where('id_dokumen_ppat', $request->post('id_dokumen_ppat'))
         ->update($data);

        $response = [
            'status' => true,
            'message' => 'Update Dokumen Berhasil',
            'data' => $request->post('id_dokumen_ppat'),
        ];

        return response($response, 200);
    }

    public function UpdateDokumenWarmerking(Request $request)
    {
        $validated = $request->validate([
            'nama_dokumen' => 'required',
            'id_dokumen_warmerking' => 'required',
        ]);
        $data = ['nama_dokumen' => $request->post('nama_dokumen')];

        tb_dokumen_warmerkings::where('id_dokumen_warmerking', $request->post('id_dokumen_warmerking'))
         ->update($data);

        $response = [
            'status' => true,
            'message' => 'Update Dokumen Berhasil',
            'data' => $request->post('id_dokumen_notaris'),
        ];

        return response($response, 200);
    }

    public function UpdateDokumenLegalisasi(Request $request)
    {
        $validated = $request->validate([
            'nama_dokumen' => 'required',
            'id_dokumen_legalisasi' => 'required',
        ]);
        $data = ['nama_dokumen' => $request->post('nama_dokumen')];


        tb_dokumen_legalisasis::where('id_dokumen_legalisasi', $request->post('id_dokumen_legalisasi'))
         ->update($data);

        $response = [
            'status' => true,
            'message' => 'Update Dokumen Berhasil',
            'data' => $request->post('id_dokumen_notaris'),
        ];

        return response($response, 200);
    }

    public function UpdateDokumenClient(Request $request)
    {
        $validated = $request->validate([
            'nama_dokumen' => 'required',
            'id_berkas' => 'required',
        ]);
        $data = ['nama_dokumen' => $request->post('nama_dokumen')];


        tb_berkas::where('id_berkas', $request->post('id_berkas'))
         ->update($data);

        $response = [
            'status' => true,
            'message' => 'Update Dokumen Berhasil',
            'data' => $request->post('id_dokumen_notaris'),
        ];

        return response($response, 200);
    }

    public function DeleteDokumenNotaris(Request $request)
    {
        $data = tb_dokumen_notaris::where('id_dokumen_notaris', $request->post('id_dokumen_notaris'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_notaris.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_notaris.id_dokumen_notaris', 'desc')
        ->get()
        ->first();

        $path = public_path('berkasnotaris/'.$data->nama_berkas);

        if (file_exists($path)) {
            unlink($path);
        }

        tb_dokumen_notaris::where('id_dokumen_notaris', $request->post('id_dokumen_notaris'))->delete();

        $response = [
            'status' => true,
            'message' => 'Berhasil Menghapus Dokumen Notaris',
            'data' => $path,
        ];

        return response($response, 200);
    }

    public function DeleteDokumenPPAT(Request $request)
    {
        $data = tb_dokumen_ppat::where('id_dokumen_ppat', $request->post('id_dokumen_ppat'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_ppat.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_ppat.id_dokumen_ppat', 'desc')
        ->get()
        ->first();

        $path = public_path('berkasppat/'.$data->nama_berkas);

        if (file_exists($path)) {
            unlink($path);
        }

        tb_dokumen_ppat::where('id_dokumen_ppat', $request->post('id_dokumen_ppat'))->delete();

        $response = [
            'status' => true,
            'message' => 'Berhasil Menghapus Dokumen PPAT',
            'data' => $path,
        ];

        return response($response, 200);
    }

    public function DeleteSuratNotaris(Request $request)
    {
        $data = BukuSuratNotaris::where('id_surat_notaris', $request->post('id_surat_notaris'))
        ->get()
        ->first();

        $path = public_path('suratnotaris/'.$data->file);

        if (file_exists($path)) {
            unlink($path);
        }

        BukuSuratNotaris::where('id_surat_notaris', $request->post('id_surat_notaris'))->update(['file' => null]);

        $response = [
            'status' => true,
            'message' => 'Berhasil Menghapus File Surat',
            'data' => $path,
        ];

        return response($response, 200);
    }

    public function DeleteTandaTerima(Request $request)
    {
        $data = TandaTerima::where('id', $request->post('id'))
        ->get()
        ->first();

        $path = public_path('tandaterima/'.$data->file);

        if (file_exists($path)) {
            unlink($path);
        }

        TandaTerima::where('id', $request->post('id'))->update(['file' => null]);

        $response = [
            'status' => true,
            'message' => 'Berhasil Menghapus File Tanda Terima',
            'data' => $path,
        ];

        return response($response, 200);
    }

    public function DeleteSuratPPAT(Request $request)
    {
        $data = BukuSuratPPAT::where('id_surat_ppat', $request->post('id_surat_ppat'))
        ->get()
        ->first();

        $path = public_path('suratppats/'.$data->file);

        if (file_exists($path)) {
            unlink($path);
        }

        BukuSuratPPAT::where('id_surat_ppat', $request->post('id_surat_ppat'))->update(['file' => null]);

        $response = [
            'status' => true,
            'message' => 'Berhasil Menghapus File Surat',
            'data' => $path,
        ];

        return response($response, 200);
    }

    public function DeleteDokumenWarmerking(Request $request)
    {
        $data = tb_dokumen_warmerkings::where('id_dokumen_warmerking', $request->post('id_dokumen_warmerking'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_warmerkings.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_warmerkings.id_dokumen_warmerking', 'desc')
        ->get()
        ->first();

        $path = public_path('berkaswarmerkings/'.$data->nama_berkas);

        if (file_exists($path)) {
            unlink($path);
        }

        tb_dokumen_warmerkings::where('id_dokumen_warmerking', $request->post('id_dokumen_warmerking'))->delete();

        $response = [
            'status' => true,
            'message' => 'Berhasil Menghapus Dokumen Warmerking',
            'data' => $path,
        ];

        return response($response, 200);
    }

    public function DeleteDokumenLegalisasi(Request $request)
    {
        $data = tb_dokumen_legalisasis::where('id_dokumen_legalisasi', $request->post('id_dokumen_legalisasi'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_legalisasis.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_legalisasis.id_dokumen_legalisasi', 'desc')
        ->get()->first();

        $path = public_path('berkaslegalisasis/'.$data->nama_berkas);

        if (file_exists($path)) {
            unlink($path);
        }

        tb_dokumen_legalisasis::where('id_dokumen_legalisasi', $request->post('id_dokumen_legalisasi'))->delete();

        $response = [
            'status' => true,
            'message' => 'Berhasil Menghapus Dokumen Warmerking',
            'data' => $path,
        ];

        return response($response, 200);
    }

    public function SimpanDokumenStandar(Request $request)
    {
        $dokumen = DB::table('tb_nama_dokumens')
        ->orderBy('id_dokumen', 'desc')
        ->limit(1)
        ->first();

        if (isset($dokumen->id_dokumen)) {
            $urutan = (int) substr($dokumen->id_dokumen, 3) + 1;
        } else {
            $urutan = 1;
        }
        $id_dokumen = 'N_'.str_pad($urutan, 4, '0', STR_PAD_LEFT);
        $data = [
            'id_dokumen' => $id_dokumen,
            'nama_dokumen' => $request->post('nama_dokumen'),
        ];
        if ($request->post('id_dokumen')) {
            $data = [
                'nama_dokumen' => $request->post('nama_dokumen'),
            ];
            tb_nama_dokumens::where('id_dokumen', $request->post('id_dokumen'))->update($data);
        } else {
            tb_nama_dokumens::create($data);
        }

        $response = [
         'status' => true,
         'message' => 'Berhasil Menambahkan dokumen',
         'data' => [],
];

        return response($response, 200);
    }

    public function DeleteDokumenStandar(Request $request)
    {
        $data = tb_dokumen_notaris::where('tb_dokumen_notaris.id_dokumen', $request->post('id_dokumen'))
        ->leftJoin('tb_nama_dokumens', 'tb_dokumen_notaris.id_dokumen', '=', 'tb_nama_dokumens.id_dokumen')
        ->orderBy('tb_dokumen_notaris.id_dokumen_notaris', 'desc')
        ->get()
        ->first();

        if (isset($data->id_dokumen)) {
            $response = [
                'status' => true,
                'message' => 'Tidak Dapat menghapus dokumen terpakai',
                'data' => [],
            ];

            return response($response, 403);
        } else {
            tb_nama_dokumens::where('id_dokumen', $request->post('id_dokumen'))->delete();
            $response = [
                'status' => true,
                'message' => 'Berhasil Menghapus Dokumen Notaris',
                'data' => [],
            ];

            return response($response, 200);
        }
    }

    public function UploadDokumenClient(Request $request)
    {
        $data = DataClient::where('id_client', $request->post('id_client'))->first();

        $path = public_path('berkasclient/'.$data->nama_folder);
        if (!file_exists($path)) {
            mkdir($path);
        }

        foreach ($request->file('dokumens') as $key => $file) {
            $berkas = DB::table('tb_berkas')
                 ->orderBy('id_berkas', 'desc')
                 ->limit(1)
                 ->first();

            if (isset($berkas->id_berkas)) {
                $urutan = (int) substr($berkas->id_berkas, 10) + 1;
            } else {
                $urutan = 1;
            }

            $no_berkas = 'BK'.date('Ymd').str_pad($urutan, 10, '0', STR_PAD_LEFT);

            $filenameSimpan = $this->moveWithOriginalName($file, 'berkasclient/'.$data->nama_folder);

            tb_berkas::create([
                'id_berkas' => $no_berkas,
                'id_client' => $request->post('id_client'),
                'id_dokumen' => null,
                'id_user' => auth()->user()->id_user,
                'nama_berkas' => $filenameSimpan,
                'nama_dokumen' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            ]);
        }
        $response = [
            'status' => true,
            'message' => 'Berhasil Menambahkan dokumen baru',
            'data' => [],
        ];

        return response($response, 200);
    }

    public function DeleteDokumenClient(Request $request)
    {
        $data = tb_berkas::where('id_berkas', $request->post('id_berkas'))
        ->leftJoin('data_clients', 'tb_berkas.id_client', '=', 'data_clients.id_client')
        ->orderBy('tb_berkas.id_client', 'desc')
        ->get()
        ->first();

        $path = public_path('berkasclient/'.$data->nama_folder.'/'.$data->nama_berkas);

        if (file_exists($path)) {
            unlink($path);
        }

        tb_berkas::where('id_berkas', $request->post('id_berkas'))->delete();
        $response = [
            'status' => true,
            'message' => 'Berhasil Menghapus Dokumen Notaris',
            'data' => $path,
        ];

        return response($response, 200);
    }
}
