import unittest

from ktp_ocr_lab.models import OcrLine, ParserConfig
from ktp_ocr_lab.parser import parse_ktp_fields


class KtpParserTest(unittest.TestCase):
    def parse(self, *texts: str):
        return parse_ktp_fields(
            [OcrLine(text=text, confidence=0.9) for text in texts],
            ParserConfig(),
        )

    def test_combined_labels_and_blank_blood_type(self):
        fields = self.parse(
            "PROVINSIJAWABARAT",
            "KOTABOGOR",
            "NIK :3271062301980001",
            "Nama : CONTOH PRATAMA",
            "Tempat/Tql Lahir : BOGOR, 23-01-1998",
            "Jenis kelamin : LAKI-LAKI Gol. Darah",
            "Alamat : KP.SUMURWANGI",
            "RT/RW : 001/007",
            "Kel/Desa : KAYUMANIS",
            "Kecamatan :TANATSARAL",
            "Agama : ISLAM",
            "Status Perkawinan: BELUM KAWIN",
            "Pekerjaan : PELAJARMAHASISWA",
            "Kewarganegaraan:WNI",
            "Berlaku Hingga : SEUMUR HIDUP",
        )

        expected = {
            "provinsi": "JAWA BARAT",
            "kabupaten_kota": "KOTA BOGOR",
            "tempat_tanggal_lahir": "BOGOR, 23-01-1998",
            "golongan_darah": None,
            "alamat": "KP SUMURWANGI",
            "kecamatan": "TANAH SAREAL",
            "pekerjaan": "PELAJAR/MAHASISWA",
            "kewarganegaraan": "WNI",
            "berlaku_hingga": "SEUMUR HIDUP",
        }
        for key, value in expected.items():
            with self.subTest(key=key):
                self.assertEqual(fields[key].value, value)

    def test_split_region_labels(self):
        fields = self.parse(
            "PROVINSI", "JAWA TENGAH", "KABUPATEN", "SEMARANG",
        )
        self.assertEqual(fields["provinsi"].value, "JAWA TENGAH")
        self.assertEqual(fields["kabupaten_kota"].value, "KABUPATEN SEMARANG")


if __name__ == "__main__":
    unittest.main()
