<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DaftarAktas extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {

        $data_jenis_pekerjaan = array(
            array('id_akta' => 'J_0001','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta pendirian Perseroan Terbatas ( PT )'),
            array('id_akta' => 'J_0002','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta perubahan perseroan terbatas ( PT )'),
            array('id_akta' => 'J_0003','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta pendirian CV'),
            array('id_akta' => 'J_0004','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta perubahan CV'),
            array('id_akta' => 'J_0005','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta pendirian Firma'),
            array('id_akta' => 'J_0006','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta perubahan Firma'),
            array('id_akta' => 'J_0007','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta pendirian Koperasi'),
            array('id_akta' => 'J_0008','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta perubahan Koperasi'),
            array('id_akta' => 'J_0009','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta pendirian Yayasan'),
            array('id_akta' => 'J_0010','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta perubahan Yayasan'),
            array('id_akta' => 'J_0011','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta pendirian Perkumpulan'),
            array('id_akta' => 'J_0012','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta perubahan Perkumpulan'),
            array('id_akta' => 'J_0013','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Pengakuan Hutang'),
            array('id_akta' => 'J_0014','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Kawin'),
            array('id_akta' => 'J_0015','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Pengikatan Jual Beli'),
            array('id_akta' => 'J_0016','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Sewa Menyewa'),
            array('id_akta' => 'J_0017','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Kerjasama'),
            array('id_akta' => 'J_0018','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Kredit'),
            array('id_akta' => 'J_0019','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Jual Beli Saham'),
            array('id_akta' => 'J_0020','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Wasiat'),
            array('id_akta' => 'J_0021','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Corporate Guarantee'),
            array('id_akta' => 'J_0022','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Personal Guarantee'),
            array('id_akta' => 'J_0023','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Fidusia'),
            array('id_akta' => 'J_0024','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta pekerjaan'),
            array('id_akta' => 'J_0025','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Kuasa Untuk Menjual'),
            array('id_akta' => 'J_0026','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Waarmerking Dokumen'),
            array('id_akta' => 'J_0027','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Legalisasi Dokumen'),
            array('id_akta' => 'J_0028','pekerjaan_milik' => 'PPAT','nama_akta' => 'Akta Jual Beli (AJB)'),
            array('id_akta' => 'J_0029','pekerjaan_milik' => 'PPAT','nama_akta' => 'Akta Hibah'),
            array('id_akta' => 'J_0030','pekerjaan_milik' => 'PPAT','nama_akta' => 'Akta Tukar Menukar'),
            array('id_akta' => 'J_0031','pekerjaan_milik' => 'PPAT','nama_akta' => 'Akta Pembagian Hak Bersama (APHB)'),
            array('id_akta' => 'J_0032','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Surat Kuasa Memberikan Hak Tanggungan (SKMHT)'),
            array('id_akta' => 'J_0033','pekerjaan_milik' => 'PPAT','nama_akta' => 'Akta Pemberian Hak Tanggungan (APHT)'),
            array('id_akta' => 'J_0034','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Pernyataan (WARIS)'),
            array('id_akta' => 'J_0035','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Pendirian PT PMA'),
            array('id_akta' => 'J_0036','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perubahan Perjanjian kredit'),
            array('id_akta' => 'J_0037','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Adendum Perjanjian Kredit '),
            array('id_akta' => 'J_0038','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Balik Nama Waris'),
            array('id_akta' => 'J_0039','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Pecah Sertifikat'),
            array('id_akta' => 'J_0040','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Peningkatan Hak'),
            array('id_akta' => 'J_0041','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Penurunan Hak'),
            array('id_akta' => 'J_0042','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Roya Sertifikat'),
            array('id_akta' => 'J_0044','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Utang Piutang'),
            array('id_akta' => 'J_0045','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perpanjangan Perjanjian Sewa Menyewa'),
            array('id_akta' => 'J_0048','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Jaminan Fidusia'),
            array('id_akta' => 'J_0049','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perubahan Perjanjian Pembiayaan Investasi Ekspor'),
            array('id_akta' => 'J_0050','pekerjaan_milik' => 'PPAT','nama_akta' => 'Akta Jaminan Hipotek'),
            array('id_akta' => 'J_0051','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Gadai Saham'),
            array('id_akta' => 'J_0052','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Pernyataan Keputusan Pemegang Saham'),
            array('id_akta' => 'J_0053','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Kuasa Direktur'),
            array('id_akta' => 'J_0054','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Pemberian Jaminan Perusahaan'),
            array('id_akta' => 'J_0055','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Pernyataan'),
            array('id_akta' => 'J_0056','pekerjaan_milik' => 'PPAT','nama_akta' => 'Kuasa Ambil Jaminan'),
            array('id_akta' => 'J_0057','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Perjanjian Subordanasi Hutang'),
            array('id_akta' => 'J_0058','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Akta Kuasa Memasang Hipotik Atas Kapal Laut'),
            array('id_akta' => 'J_0059','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Perubahan Kedua Perjanjian Kredit Investasi Ekspor'),
            array('id_akta' => 'J_0060','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Adendum Perjanjian'),
            array('id_akta' => 'J_0061','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Perubahan Perjanjian Jaminan Fidusisa Piutang'),
            array('id_akta' => 'J_0062','pekerjaan_milik' => 'NOTARIS','nama_akta' => 'Perjanjian Penanggungan Perseorangan')
          );

          DB::table('daftar_aktas')->insert($data_jenis_pekerjaan);

    }
}
