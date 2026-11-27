"""Native Word regression: two black leaders around headings, colored rails only."""
from pathlib import Path
import json
import fitz
import win32com.client

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tmp/sigandi-center-0.4.9-local'
OUT.mkdir(parents=True, exist_ok=True)
app = doc = None
report = {}
old_color = None
try:
    app = win32com.client.DispatchEx('Word.Application')
    app.Visible = False
    app.DisplayAlerts = 0
    for addin in app.AddIns:
        if 'sigandi' in addin.Name.lower() and addin.Installed:
            addin.Installed = False
    app.AddIns.Add(str(ROOT / 'output/word-addin/SIGANDI-Word-0.4.9-preview.dotm'), True)
    assert app.Run('Module1.SigandiVersion') == 'SIGANDI Word 0.4.9 Preview'
    old_color = app.Run('Module1.SigandiLineColorIndex')
    doc = app.Documents.Add()
    doc.Content.Text = ('JUDUL AKTA UTAMA\r'
                        '-Pada hari ini para pihak menyatakan kesepakatannya.\r'
                        'Maksud dan Tujuan serta Kegiatan Usaha\r'
                        'Pasal 3\r'
                        '1. Kegiatan usaha dilaksanakan berdasarkan ketentuan yang berlaku.\r'
                        'Notaris di Kota Contoh\r\r(NAMA NOTARIS)\r')
    doc.Content.Font.Name = 'Arial'
    doc.Content.Font.Size = 11
    doc.Content.ParagraphFormat.LineSpacingRule = 4
    doc.Content.ParagraphFormat.LineSpacing = 24
    doc.PageSetup.LeftMargin = doc.PageSetup.RightMargin = 72
    for number in (1, 3, 4):
        doc.Paragraphs(number).Alignment = 1
        doc.Paragraphs(number).Range.Font.Bold = True
    doc.Bookmarks.Add('SIGANDI_AREA_ISI', doc.Range(doc.Paragraphs(2).Range.Start, doc.Paragraphs(6).Range.Start))
    original = doc.Content.Text

    def strokes():
        result = []
        for shape in doc.Shapes:
            result.extend(list(shape.CanvasItems) if shape.Type == 20 else [shape])
        return [s for s in result if s.Name.startswith('SIGANDI_W_')]

    value = app.Run('Module1.SigandiTryBuild', True, True)
    assert value.isdigit(), value
    for index in (1, 2, 3, 0):
        assert app.Run('Module1.SigandiSetLineColor', index) == 'OK'
        color = app.Run('Module1.SigandiLineColor')
        for stroke in strokes():
            assert stroke.Line.ForeColor.RGB == (0 if stroke.Name.startswith('SIGANDI_W_H_') else color)
        assert doc.Content.Text == original
    # A repeat builds new black leaders with the selected non-black rail color.
    assert app.Run('Module1.SigandiSetLineColor', 1) == 'OK'
    value = app.Run('Module1.SigandiTryBuild', True, True)
    assert value.isdigit(), value
    assert all(s.Line.ForeColor.RGB == 0 for s in strokes() if s.Name.startswith('SIGANDI_W_H_'))
    assert doc.Content.Text == original
    doc.ExportAsFixedFormat(str(OUT / 'word-headings.pdf'), 17)
    pdf = fitz.open(OUT / 'word-headings.pdf')
    page = pdf[0]
    paths = page.get_drawings()
    headings = []
    for term in ('Maksud dan Tujuan serta Kegiatan Usaha', 'Pasal 3'):
        box = page.search_for(term)[0]
        black = [p['rect'] for p in paths if p.get('color') == (0, 0, 0)
                 and abs(p['rect'].y1 - p['rect'].y0) < .5
                 and box.y0 <= p['rect'].y0 <= box.y1]
        assert any(p.x1 < box.x0 + .8 for p in black), (term, 'missing left leader', black)
        assert any(p.x0 > box.x1 - .8 for p in black), (term, 'missing right leader', black)
        assert all(p.x1 <= box.x0 + .8 or p.x0 >= box.x1 - .8 for p in black), (term, 'line over text')
        headings.append(term)
    title = page.search_for('JUDUL AKTA UTAMA')[0]
    assert not any(title.y0 <= p['rect'].y0 <= title.y1 for p in paths if p.get('color') == (0, 0, 0))
    page.get_pixmap(matrix=fitz.Matrix(1.5, 1.5)).save(str(OUT / 'word-headings.png'))
    report = dict(status='PASS', headings_both_sides=headings, text_unchanged=True,
                  horizontal_always_black=True, four_rail_colors=True, repeated_build=True,
                  main_title_excluded=True, strokes=int(value))
finally:
    if doc is not None:
        doc.Close(False)
    if app is not None:
        if old_color is not None:
            app.Run('Module1.SigandiSetLineColor', old_color)
        app.Quit(False)
    (OUT / 'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps(report, indent=2))
