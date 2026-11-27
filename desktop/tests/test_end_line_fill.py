"""Dash leaders must close whitespace without touching content or signatures."""
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import fitz
import end_line_fill as elf
from garis_editor import GarisEditorApp


class EndFillTests(unittest.TestCase):
    def setUp(self):
        self.doc = fitz.open()
        self.page = self.doc.new_page(width=600, height=800)
        self.settings = {"enabled": True, "automatic": False, "right_margin_mm": 20}

    def tearDown(self):
        if not self.doc.is_closed:
            self.doc.close()

    def add(self, text, x=75, y=180, size=12):
        self.doc[0].insert_text((x, y), text, fontsize=size)

    def rows(self):
        return elf.build_document_leaders(self.doc, self.settings).get(0, [])

    def app(self):
        app = GarisEditorApp.__new__(GarisEditorApp)
        app.doc = self.doc
        app.end_fill_settings = dict(self.settings)
        app.segments_by_page = {}
        app.booklet_mode_active = False
        app.page_index = 0
        app.selected_idx = None
        app.max_history = 80
        app.undo_stack = []
        app.redo_stack = []
        app.render_page = app.draw_segments_overlay = lambda: None
        app._refresh_all_thumbnail_overlays = lambda: None
        app._set_create_mode = lambda enabled: None
        app.set_status = lambda message: None
        return app

    def test_leaders_follow_last_visible_character_and_preserve_text(self):
        self.add('Perjanjian ini disetujui.        ')
        before = self.doc[0].get_text()
        rows = self.rows()
        self.assertEqual(len(rows), 1)
        word_end = max(w[2] for w in self.doc[0].get_text('words'))
        self.assertGreater(rows[0]['x0'], word_end)
        self.assertLess(rows[0]['x0'] - word_end, 2)
        self.assertAlmostEqual(rows[0]['x1'], 600 - 20 * 72 / 25.4)
        elf.draw_page_leaders(self.doc[0], rows, (0, 0, 0))
        self.assertEqual(before, self.doc[0].get_text())
        self.assertGreater(len(self.doc[0].get_drawings()), 0)
        self.assertEqual(self.rows(), [], 'Reopening a filled PDF must not double leaders')

    def test_split_blocks_and_mixed_fonts_only_fill_after_rightmost_text(self):
        self.add('Jabatan :', x=75)
        self.add('Direktur Sumber Daya', x=300, size=10)
        rows = self.rows()
        self.assertEqual(len(rows), 1)
        self.assertGreater(rows[0]['x0'], max(w[2] for w in self.doc[0].get_text('words')))
        self.assertAlmostEqual(rows[0]['width'], 0.5)

    def test_section_heading_is_filled_but_title_footer_signature_are_excluded(self):
        self.add('PERNYATAAN KEPUTUSAN RAPAT', x=195, y=65)
        self.add('Nomor : XX.', x=265, y=110)
        self.add('Pasal 1', x=280, y=240)
        self.add('Isi akta yang perlu ditutup.', y=270)
        self.add('Notaris di Jakarta', x=330, y=400)
        self.add('( DEWANTARI HANDAYANI, SH, MPA )', x=300, y=550)
        self.add('1', x=295, y=780)
        self.add('----------------', y=600)
        rows = self.rows()
        self.assertEqual(len(rows), 3)
        self.assertEqual(sum(abs(r['y'] - (240 - 12 * .26)) < .01 for r in rows), 2)
        self.assertEqual(sum(abs(r['y'] - (270 - 12 * .26)) < .01 for r in rows), 1)

    def test_centered_section_headings_fill_both_sides_without_touching_words(self):
        self.add('Isi akta sebelum judul bagian.', y=180)
        for text, y in [('Maksud dan Tujuan serta Kegiatan Usaha', 230), ('Pasal 3', 260)]:
            length = fitz.get_text_length(text, fontsize=12)
            self.add(text, x=(600-length)/2, y=y)
        self.add('Isi akta setelah judul bagian.', y=300)
        before = self.doc[0].get_text()
        rows = self.rows()
        for baseline in (230, 260):
            leaders = [r for r in rows if abs(r['y']-(baseline-12*.26)) < .01]
            self.assertEqual(len(leaders), 2)
            words = [w for w in self.doc[0].get_text('words') if abs(w[3]-(baseline+3.588)) < 1]
            self.assertTrue(words)
            left, right = sorted(leaders, key=lambda r:r['x0'])
            self.assertLess(left['x1'], min(w[0] for w in words))
            self.assertGreater(right['x0'], max(w[2] for w in words))
        elf.draw_page_leaders(self.doc[0], rows, (1,0,0))
        self.assertEqual(before, self.doc[0].get_text())
        self.assertTrue(all(d['color'] == (0,0,0) for d in self.doc[0].get_drawings()))
        self.assertEqual(self.rows(), [], 'Repeated processing must not duplicate either side')

    def test_desktop_preview_leaders_stay_black_when_rails_are_red(self):
        self.add('Isi akta dengan garis vertikal merah.')
        app = self.app()
        app.line_color_name = 'Merah'
        app._get_end_fill_rows = lambda page: self.rows()
        class Canvas:
            fills = []
            def delete(self, tag):
                pass
            def create_line(self, *args, **kwargs):
                self.fills.append(kwargs['fill'])
        canvas = Canvas()
        app._draw_end_fill_overlay(canvas, 0, 1.4)
        self.assertTrue(canvas.fills)
        self.assertEqual(set(canvas.fills), {'#000000'})

    def test_existing_text_dashes_full_lines_and_too_narrow_margins(self):
        self.add('Isi -----------')
        self.add('Baris yang melampaui margin', x=450, y=210)
        rows = self.rows()
        self.assertEqual(len(rows), 1)
        end = max(w[2] for w in self.doc[0].get_text('words') if w[1] < 185)
        self.assertGreater(rows[0]['x0'], end)

    def test_dash_only_rows_inside_body_are_filled_to_the_same_margin(self):
        self.add('d. Aktivitas Kesehatan Manusia dan Aktivitas Sosial;', y=180)
        for y, marker in ((204, '-'), (228, '--'), (252, '---'), (276, '----')):
            self.add(marker + '  ', x=93, y=y)
            self.add('Baris isi akta berikutnya.', y=y+12)
        rows = self.rows()
        for baseline in (204, 228, 252, 276):
            matching = [r for r in rows if abs(r['y'] - (baseline - 12 * .26)) < .01]
            self.assertEqual(len(matching), 1, f'Dash-only row at {baseline} was skipped')
            self.assertAlmostEqual(matching[0]['x1'], 600 - 20 * 72 / 25.4)

    def test_empty_word_paragraphs_between_body_rows_are_filled(self):
        self.add('-Pada hari ini,', y=180)
        self.add(' ', y=204)
        self.add(' ', y=228)
        self.add('-Berhadapan dengan saya, Notaris.', y=252)
        rows = self.rows()
        blanks = [row for row in rows if row.get('blank')]
        self.assertEqual(len(blanks), 2)
        self.assertTrue(all(abs(row['x0'] - 75) < .01 for row in blanks))
        disabled = elf.build_document_leaders(self.doc, dict(self.settings, fill_blank_lines=False))
        self.assertEqual(len(disabled[0]), 2)

    def test_blank_heading_and_signature_spaces_are_not_filled(self):
        self.add(' ', y=150)
        self.add('Isi akta.', y=180)
        self.add('Notaris di Jakarta', x=330, y=400)
        self.add(' ', x=330, y=450)
        self.add('( NAMA NOTARIS, SH )', x=300, y=550)
        self.add(' ', y=580)
        self.assertFalse(any(row.get('blank') for row in self.rows()))

    def test_small_high_footer_is_excluded_without_cutting_bottom_body(self):
        self.add('Isi akta yang berada di bagian bawah halaman.', y=690)
        self.add('PKR/diluar rapat PT. DDW', x=380, y=720, size=8)
        self.add('1', x=295, y=722, size=10)
        self.add(' ', y=734, size=8)
        rows = self.rows()
        self.assertEqual(len(rows), 1)
        self.assertLess(rows[0]['y'], 700)

    def test_blank_paragraph_behind_an_image_is_not_filled(self):
        self.add('Isi akta.', y=180)
        self.add(' ', y=204)
        self.add('Isi berlanjut.', y=228)
        pix = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, 20, 20), False)
        pix.clear_with(255)
        self.doc[0].insert_image(fitz.Rect(75, 190, 500, 215), pixmap=pix)
        self.assertFalse(any(row.get('blank') for row in self.rows()))

    def test_repeated_footer_in_body_font_is_excluded(self):
        self.add('Paragraf isi.', y=180)
        self.add('PKR/diluar rapat PT. DDW', x=350, y=720)
        other = self.doc.new_page(width=600, height=800)
        other.insert_text((75, 180), 'Paragraf lanjutan.', fontsize=12)
        other.insert_text((350, 720), 'PKR/diluar rapat PT. DDW', fontsize=12)
        rows = elf.build_document_leaders(self.doc, self.settings)
        self.assertTrue(all(row['y'] < 700 for page in rows.values() for row in page))

    def test_white_tail_does_not_push_leader_to_far_right(self):
        self.add('- Dibuat dengan', y=270)
        black_end = max(w[2] for w in self.doc[0].get_text('words'))
        self.doc[0].insert_text((black_end+4,270), 'tanpa perubahan.---------------------', fontsize=12, color=(1,1,1))
        row = self.rows()[0]
        self.assertGreater(row['x0'], black_end)
        self.assertLess(row['x0'] - black_end, 2)

    def test_white_body_row_is_filled_but_white_signature_is_preserved(self):
        self.add('Isi akta yang tampak.', y=270)
        self.doc[0].insert_text((75,372), '-SALINAN YANG SAMA BUNYINYA.----------', fontsize=12, color=(1,1,1))
        self.doc[0].insert_text((330,400), 'Notaris di Jakarta', fontsize=12, color=(1,1,1))
        self.doc[0].insert_text((300,550), '( NAMA NOTARIS, SH )', fontsize=12, color=(1,1,1))
        rows = self.rows()
        white_row = [r for r in rows if abs(r['y'] - (372-12*.26)) < .01]
        self.assertEqual(len(white_row), 1)
        self.assertTrue(white_row[0]['blank'])
        self.assertAlmostEqual(white_row[0]['x0'], 75)
        self.assertTrue(all(r['y'] < 385 for r in rows))

    def test_white_text_on_dark_background_is_still_visible_content(self):
        self.doc[0].draw_rect(fitz.Rect(70,250,500,282), fill=(.1,.1,.1), color=None)
        self.doc[0].insert_text((75,270), 'Teks putih yang terlihat', fontsize=12, color=(1,1,1))
        word_end = max(w[2] for w in self.doc[0].get_text('words'))
        rows = self.rows()
        self.assertEqual(len(rows), 1)
        self.assertFalse(rows[0]['blank'])
        self.assertGreater(rows[0]['x0'], word_end)

    def test_light_gray_text_is_not_treated_as_empty(self):
        self.doc[0].insert_text((75,270), 'Teks abu-abu', fontsize=12, color=(.9,.9,.9))
        rows = self.rows()
        self.assertEqual(len(rows), 1)
        self.assertFalse(rows[0]['blank'])

    def test_invisible_pdf_text_does_not_extend_the_visible_end(self):
        self.add('Isi yang terlihat.', y=270)
        end = max(w[2] for w in self.doc[0].get_text('words'))
        self.doc[0].insert_text((end+4,270), 'lapisan tak terlihat-------', fontsize=12, render_mode=3)
        row = self.rows()[0]
        self.assertGreater(row['x0'], end)
        self.assertLess(row['x0'] - end, 2)

    def test_dash_only_header_decoration_and_signature_are_not_filled(self):
        self.add('PERNYATAAN KEPUTUSAN RAPAT', x=195, y=65)
        self.add('Nomor : XX.', x=265, y=110)
        self.add('---', x=75, y=156)
        self.add('Isi akta yang perlu ditutup.', y=180)
        self.add('---', x=280, y=204)  # Centered decoration, outside body indent.
        self.add('Paragraf isi selanjutnya.', y=228)
        self.add('Notaris di Jakarta', x=330, y=400)
        self.add('---', x=330, y=450)
        self.add('( NAMA NOTARIS, SH )', x=300, y=550)
        self.add('---', x=75, y=780)
        rows = self.rows()
        self.assertEqual(len(rows), 2)

    def test_auto_margin_on_sparse_page_uses_body_margin_of_same_paper(self):
        self.add('Baris panjang mencapai batas kanan', size=12)
        text_width = fitz.get_text_length('Isi penuh sampai batas kanan akta.', fontsize=12)
        self.add('Isi penuh sampai batas kanan akta.', x=540-text_width, y=210)
        second = self.doc.new_page(width=600, height=800)
        second.insert_text((75, 100), 'ini.', fontsize=12)
        settings = dict(self.settings, automatic=True)
        rows = elf.build_document_leaders(self.doc, settings)
        self.assertAlmostEqual(rows[1][0]['x1'], 540, places=2)

    def test_image_only_page_has_no_guessed_leaders(self):
        pix = fitz.Pixmap(fitz.csRGB, fitz.IRect(0, 0, 20, 20), False)
        pix.clear_with(255)
        self.doc[0].insert_image(fitz.Rect(50, 150, 550, 700), pixmap=pix)
        self.assertFalse(self.rows())

    def test_auto_margin_of_short_document_falls_back_to_printable_margin(self):
        self.add('ini.')
        rows = elf.build_document_leaders(self.doc, dict(self.settings, automatic=True))
        self.assertEqual(len(rows[0]), 1)
        self.assertAlmostEqual(rows[0][0]['x1'], 600 - 20 * 72 / 25.4)

    def test_large_left_aligned_heading_on_later_page_is_excluded(self):
        page = self.doc.new_page(width=600, height=800)
        page.insert_text((75, 100), 'KETERANGAN LANJUTAN', fontsize=16)
        for i in range(3):
            page.insert_text((75, 160 + i * 24), 'Paragraf isi akta.', fontsize=12)
        rows = elf.build_document_leaders(self.doc, self.settings)
        self.assertEqual(len(rows[1]), 3)
        self.assertTrue(all(row['y'] > 150 for row in rows[1]))

    def test_indented_section_keeps_explicit_existing_leader_margin(self):
        self.add('Isi -----', x=220)
        self.add('Paragraf diteruskan.', x=220, y=210)
        self.add('Acuan ' + '-' * 70, x=220, y=240)
        edge = max(w[2] for w in self.doc[0].get_text('words'))
        # A wide enough explicit leader can define this section's own edge.
        self.assertGreater(edge - 220, 600 * .45)
        rows = elf.build_document_leaders(self.doc, dict(self.settings, automatic=True))
        self.assertTrue(all(abs(row['x1'] - edge) < .01 for row in rows[0]))

    def test_settings_undo_redo_export_and_new_document_reset(self):
        self.add('Paragraf singkat.')
        app = self.app()
        app.end_fill_settings = elf.normalize_settings()
        snapshot = app._snapshot_editor_state()
        self.assertEqual(app._set_end_fill_settings(self.settings), 1)
        app.undo_action()
        self.assertFalse(app.end_fill_settings['enabled'])
        app.redo_action()
        self.assertTrue(app.end_fill_settings['enabled'])
        target = fitz.open(stream=self.doc.tobytes(), filetype='pdf')
        app._apply_manual_lines_to_doc(target)
        self.assertGreater(len(target[0].get_drawings()), 0)
        self.assertEqual(self.doc[0].get_text(), target[0].get_text())
        target.close()
        app._restore_editor_state(snapshot)
        self.assertEqual(app._get_end_fill_rows(0), [])
        app.end_fill_settings = dict(self.settings)
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'new.pdf'
            self.doc.save(source)
            for name in ('clear_page_thumbnails', '_refresh_booklet_button_text', 'build_page_thumbnails'):
                setattr(app, name, lambda: None)
            app.load_pdf(str(source), run_auto_detect=False)
            self.assertFalse(app.end_fill_settings['enabled'])
            self.assertEqual(app._get_end_fill_rows(0), [])
            app.doc.close()

    def test_cache_refreshes_after_page_structure_changes(self):
        self.add('Halaman satu.')
        self.doc.new_page(width=600, height=800).insert_text((75, 150), 'Halaman dua.')
        app = self.app()
        self.assertEqual(len(app._get_end_fill_rows(0)), 1)
        # Page editing reopens a fresh base PDF, just like the actual editor.
        replacement = fitz.open()
        replacement.insert_pdf(self.doc, from_page=1, to_page=1)
        app.doc = replacement
        self.assertAlmostEqual(app._get_end_fill_rows(0)[0]['y'], 150 - 11 * .26, places=2)
        replacement.close()

    def test_booklet_projection_uses_original_page_margin_on_both_halves(self):
        self.add('Halaman satu.')
        self.doc.new_page(width=600, height=800).insert_text((75, 150), 'Halaman dua.')
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'source.pdf'
            self.doc.save(source)
            app = self.app()
            booklet = fitz.open()
            booklet.new_page(width=1200, height=800)
            app.doc = booklet
            app.booklet_mode_active = True
            app.booklet_source_pdf_path = str(source)
            app.booklet_layout_pairs = [(1, 0)]
            app.booklet_source_page_w = 600
            app.booklet_source_page_h = 800
            rows = app._get_end_fill_rows(0)
            self.assertEqual(len(rows), 2)
            self.assertLess(max(s[2] for s in elf.dash_strokes(rows[0])), 600)
            self.assertGreater(min(s[0] for s in elf.dash_strokes(rows[1])), 600)
            app._apply_manual_lines_to_doc(booklet)
            self.assertGreater(len(booklet[0].get_drawings()), 0)
            booklet.close()

    def test_rotated_page_export_remains_in_native_pdf_coordinates(self):
        self.add('Teks dengan rotasi halaman.')
        self.doc[0].set_rotation(90)
        rows = self.rows()
        self.assertEqual(len(rows), 1)
        self.assertAlmostEqual(rows[0]['x1'], 600 - 20 * 72 / 25.4)
        elf.draw_page_leaders(self.doc[0], rows, (0, 0, 0))
        self.assertEqual(self.rows(), [])


if __name__ == '__main__':
    unittest.main()
