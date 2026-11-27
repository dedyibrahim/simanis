from pathlib import Path
import json
import hashlib
import zipfile
import xml.etree.ElementTree as ET
import win32com.client

root = Path(__file__).resolve().parents[1]
target = root / "output/word-addin/SIMANIS-Word-0.5.3-preview.dotm"
with zipfile.ZipFile(target) as archive:
    ribbon = ET.fromstring(archive.read("customUI/customUI.xml"))
    assert any(e.attrib.get("label") == "SIMANIS" for e in ribbon.iter())
    assert any(e.attrib.get("onAction") == "RibbonSimanisData" for e in ribbon.iter())
original_hash = hashlib.sha256(target.read_bytes()).hexdigest()
app = None
doc = None
report = {"ribbon_verified": True, "word_open_no_save": False, "macros_executed": False}
try:
    app = win32com.client.DispatchEx("Word.Application")
    app.Visible = False
    app.AutomationSecurity = 3
    app.DisplayAlerts = 0
    doc = app.Documents.Open(str(target), ReadOnly=True, AddToRecentFiles=False)
    report["word_open_no_save"] = True
    report["readonly_requested"] = True
    report["word_reported_readonly"] = bool(doc.ReadOnly)
finally:
    if doc is not None: doc.Close(False)
    if app is not None: app.Quit(False)
assert hashlib.sha256(target.read_bytes()).hexdigest() == original_hash
(root / "output/word-addin/word-open-report.json").write_text(json.dumps(report, indent=2))
print(json.dumps(report))
