"""Regression cases for wrapped biodata and label/value layouts in text PDFs."""
import sys
import unittest
from unittest.mock import patch
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import fitz
import garis_akta as ga
import scan_line_apply as sla


class TextLayoutTests(unittest.TestCase):
    def setUp(self):
        self.doc = fitz.open()
        self.doc.new_page(width=600, height=800)
        self.page = self.doc.new_page(width=600, height=800)

    def tearDown(self):
        self.doc.close()

    def segments(self):
        rows = sla.build_segments_from_text_page(1, self.page, no_merge=True, outside_shift=4)
        return sla.merge_text_no_merge_segments(rows)

    def test_deep_biodata_continuations_have_one_continuous_rail(self):
        self.page.insert_text((90, 100), '-Direktur Sumber Daya Manusia')
        texts = ['Hukum', 'Lahir di Tanjung Pinang', 'di', 'Provinsi Jawa Barat', '1234567890123456']
        for i, text in enumerate(texts):
            self.page.insert_text((310, 124+i*24), text)
        self.page.insert_text((270, 48), 'PASAL 1')
        self.page.insert_text((295, 780), '2')
        segs = self.segments()
        rails = [s for s in segs if 295 < s['x'] < 310]
        self.assertEqual(len(rails), 1)
        for i in range(len(texts)):
            self.assertLess(rails[0]['y0'], 124+i*24)
            self.assertGreater(rails[0]['y1'], 124+i*24)
        self.assertFalse(any(s['y0'] < 50 for s in segs))
        self.assertFalse(any(s['y1'] > 750 for s in segs))

    def test_both_label_and_value_columns_are_retained(self):
        for i in range(4):
            self.page.insert_text((80, 100+i*24), f'Jabatan {i+1}')
            self.page.insert_text((310, 100+i*24), 'Nama dan keterangan pemegang jabatan')
        segs = self.segments()
        self.assertTrue(any(s['x'] < 80 and s['y1'] > 165 for s in segs))

    def test_actual_word_anchors_remain_correct_after_an_image_block(self):
        pix = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, 4, 4), False)
        pix.clear_with(255)
        self.page.insert_image(fitz.Rect(10, 10, 20, 20), pixmap=pix)
        self.page.insert_text((80, 100), '        Biodata pemegang jabatan')
        cfg = ga.LayoutConfig(70, 8, 16)
        rows = ga.extract_lines(self.page, cfg, preserve_body_indents=True)
        words = self.page.get_text('words')
        self.assertEqual(len(rows), 1)
        self.assertAlmostEqual(rows[0]['x0_raw'], words[0][0], places=3)

    def test_opening_cap_starts_at_body_after_document_number_heading(self):
        first = self.doc[0]
        first.insert_text((210, 65), 'PERNYATAAN KEPUTUSAN RAPAT')
        first.insert_text((260, 108), 'Nomor : XX.')
        first.insert_text((80, 155), '-Pada hari ini, penghadap hadir di hadapan Notaris.')
        first.insert_text((80, 179), 'Baris lanjutan isi akta untuk diperiksa.')
        first.insert_text((245, 90), 'Nomor : XX. ----------------')
        first.insert_text((250, 785), 'footer ----------------')
        rows = sla.build_segments_from_text_page(0, first, no_merge=True, outside_shift=4)
        segs = ga.apply_page_caps(sla.merge_text_no_merge_segments(rows))
        opening = [s for s in segs if s['draw_start_cap']]
        self.assertEqual(len(opening), 1)
        self.assertGreater(opening[0]['y0'], 140)
        self.assertLess(opening[0]['y0'], 155)
        self.assertFalse(any(s['y0'] < 140 or s['y1'] > 750 for s in segs))

    def test_legacy_margin_selection_is_unchanged(self):
        for i in range(4):
            self.page.insert_text((80, 100+i*24), 'Paragraf utama di margin kiri')
        self.page.insert_text((310, 220), 'Keterangan panjang pada kolom kanan')
        cfg = ga.LayoutConfig(70, 8, 16)
        legacy = ga.extract_lines(self.page, cfg)
        self.assertFalse(any(r['x0_raw'] > 300 for r in legacy))
        full = ga.extract_lines(self.page, cfg, preserve_body_indents=True)
        self.assertTrue(any(r['x0_raw'] > 300 for r in full))

    def test_signature_area_is_unlined_and_closing_cap_stays_on_body(self):
        self.page.insert_text((80, 180), '-Minuta akta ini telah ditandatangani dengan sempurna.')
        self.page.insert_text((80, 204), '-DIBERIKAN SEBAGAI SALINAN YANG SAMA BUNYINYA.')
        self.page.insert_text((340, 270), 'Notaris di Jakarta')
        self.page.insert_text((285, 430), '( NAMA NOTARIS, SH, MPA ) ----------------')
        segs = ga.apply_page_caps(self.segments())
        self.assertTrue(segs)
        self.assertFalse(any(s['y1'] > 220 for s in segs))
        closing = [s for s in segs if s['draw_end_cap']]
        self.assertEqual(len(closing), 1)
        self.assertTrue(200 < closing[0]['y1'] < 220)

    def test_notary_reference_inside_body_is_preserved(self):
        self.page.insert_text((80, 180), 'Notaris di Jakarta,')
        self.page.insert_text((80, 204), 'menerangkan bahwa penghadap telah hadir pada hari ini.')
        self.assertIsNone(ga.find_signature_region(self.page))
        self.assertTrue(self.segments())

    def test_signature_only_page_does_not_reenter_scan_detection(self):
        from garis_editor import GarisEditorApp
        self.doc[0].insert_text((80, 155), '-Pada hari ini penghadap hadir di hadapan Notaris.')
        self.page.insert_text((340, 100), 'Notaris di Jakarta,')
        self.page.insert_text((290, 250), '(XXXXXXXXXXXXXXXXXXXXXXX)')
        self.assertEqual(self.segments(), [])
        app = GarisEditorApp.__new__(GarisEditorApp)
        app.doc = self.doc
        app.page_index = 0
        app.scan_model_file = 'scan_line_model_datatrain_v2.json'
        app.outside_shift = 4.0
        app.scan_zoom = 2.0
        app.set_status = lambda _text: None
        app.render_page = lambda: None
        app._refresh_all_thumbnail_overlays = lambda: None
        with patch.object(sla, 'build_segments_from_scan_page', side_effect=AssertionError('Signature must not enter raster fallback')):
            app._run_auto_detect_scan_pipeline()
        self.assertNotIn(1, app.segments_by_page)

    def test_high_footer_is_not_a_rail_or_closing_cap(self):
        self.page.insert_text((80, 660), 'Paragraf isi terakhir yang tetap perlu digaris.', fontsize=12)
        self.page.insert_text((385, 720), 'PKR/diluar rapat PT. DDW', fontsize=8)
        self.page.insert_text((295, 722), '2', fontsize=10)
        segs = ga.apply_page_caps(self.segments())
        self.assertTrue(segs)
        self.assertTrue(all(s['y1'] < 690 for s in segs))

    def test_footer_only_text_page_does_not_enter_raster_fallback(self):
        from garis_editor import GarisEditorApp
        self.page.insert_text((385, 720), 'PKR/diluar rapat PT. DDW', fontsize=8)
        self.page.insert_text((295, 722), '2', fontsize=10)
        self.assertEqual(self.segments(), [])
        app = GarisEditorApp.__new__(GarisEditorApp)
        app.doc = self.doc
        app.page_index = 0
        app.scan_model_file = 'scan_line_model_datatrain_v2.json'
        app.outside_shift = 4
        app.scan_zoom = 2
        app.set_status = lambda _text: None
        app.render_page = app._refresh_all_thumbnail_overlays = lambda: None
        self.doc[0].insert_text((80, 180), 'Paragraf isi akta.')
        with patch.object(sla, 'build_segments_from_scan_page', side_effect=AssertionError('Footer must not reenter scan')):
            app._run_auto_detect_scan_pipeline()
        self.assertNotIn(1, app.segments_by_page)


if __name__ == '__main__':
    unittest.main()
