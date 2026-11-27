"""Regression: canvas anchors must not consume letters in compatibility documents."""
from pathlib import Path
import json
import time
import shutil
import win32com.client

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tmp/sigandi-text-change'
OUT.mkdir(parents=True, exist_ok=True)
app = doc = None
report = []
try:
    app = win32com.client.DispatchEx('Word.Application')
    app.Visible = False
    app.DisplayAlerts = 0
    for addin in app.AddIns:
        if 'sigandi' in addin.Name.lower() and addin.Installed:
            addin.Installed = False
    app.AddIns.Add(str(ROOT / 'output/word-addin/SIGANDI-Word-0.4.4-preview.dotm'), True)
    fixtures = [('live-snapshot', OUT / 'live-snapshot.xml'),
                ('draft-akta', Path(r'C:\Users\LENOVO\Documents\Dokumen\Draft Akta.docx'))]
    for name, source in fixtures:
        if not source.exists():
            continue
        # Always edit a disposable file, never open the user's original in Word.
        test_copy = OUT / (name + '-test' + source.suffix)
        shutil.copy2(source, test_copy)
        doc = app.Documents.Open(str(test_copy), AddToRecentFiles=False, ReadOnly=False)
        original = doc.Content.Text
        if not doc.Bookmarks.Exists('SIGANDI_AREA_ISI'):
            doc.Bookmarks.Add('SIGANDI_AREA_ISI', doc.Content)
        for run in range(2):
            start = time.monotonic()
            value = app.Run('Module1.SigandiTryBuild', True, True)
            assert str(value).isdigit(), value
            assert doc.Content.Text == original, 'Document text changed'
            assert app.ScreenUpdating
            report.append(dict(fixture=name, run=run + 1, strokes=int(value),
                               seconds=round(time.monotonic() - start, 2), text_unchanged=True))
            print(report[-1], flush=True)
        doc.ExportAsFixedFormat(str(OUT / (name + '-fixed.pdf')), 17)
        assert doc.Undo()
        assert doc.Content.Text == original
        doc.Close(False)
        doc = None
finally:
    if doc is not None:
        doc.Close(False)
    if app is not None:
        app.Quit(False)
    (OUT / 'anchor-regression-report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
