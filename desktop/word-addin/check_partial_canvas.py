"""Exercise partial redraws and preservation of user content in managed canvases."""
from pathlib import Path
import json
import win32com.client
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'tmp/sigandi-word-0.4.4-partial';OUT.mkdir(parents=True,exist_ok=True)
app=doc=None;report={};old_color=None
try:
 app=win32com.client.DispatchEx('Word.Application');app.Visible=False;app.DisplayAlerts=0
 for a in app.AddIns:
  if 'sigandi' in a.Name.lower() and a.Installed:a.Installed=False
 loaded=app.AddIns.Add(str(ROOT/'output/word-addin/SIGANDI-Word-0.4.4-preview.dotm'),True)
 old_color=app.Run('Module1.SigandiLineColorIndex')
 doc=app.Documents.Add();doc.Content.Text=('Isi akta, ketentuan para pihak. '+'Kalimat lanjutan untuk pemeriksaan garis. '*6+'\r')*3
 doc.Content.Font.Name='Arial';doc.Content.Font.Size=11
 doc.Content.ParagraphFormat.LineSpacingRule=4;doc.Content.ParagraphFormat.LineSpacing=24
 doc.Bookmarks.Add('SIGANDI_AREA_ISI',doc.Content);text=doc.Content.Text
 def run(v,h):
  value=app.Run('Module1.SigandiTryBuild',v,h);assert value.isdigit(),value
 def strokes(kind):
  result=[]
  for root in doc.Shapes:
   for s in (list(root.CanvasItems) if root.Type==20 else [root]):
    if s.Name.startswith('SIGANDI_W_'+kind+'_'):result.append(s.AlternativeText)
  return sorted(result)
 run(True,True);verts=strokes('V');caps=strokes('C')
 run(False,True);assert strokes('V')==verts and strokes('C')==caps
 fills=strokes('H');run(True,False);assert strokes('H')==fills
 assert doc.Content.Text==text
 frame=next(s for s in doc.Shapes if s.Type==20 and s.Name.startswith('SIGANDI_W_P_'))
 custom=frame.CanvasItems.AddShape(1,200,200,20,20);custom.Name='USER_CHILD_KEEP';custom.Line.Weight=2
 app.Run('Module1.SigandiSetLineColor',2)
 assert custom.Line.Weight==2
 app.Run('Module1.SigandiRemove')
 assert not strokes('V') and not strokes('C') and not strokes('H')
 kept=next(s for s in doc.Shapes if s.Name.startswith('USER_PRESERVED_'))
 assert any(s.Name=='USER_CHILD_KEEP' for s in kept.CanvasItems)
 assert doc.Content.Text==text and doc.Undo()
 assert strokes('V') and strokes('H') and strokes('C')
 doc.SaveAs2(str(OUT/'roundtrip.docx'),FileFormat=12,AddToRecentFiles=False)
 doc.Close(False);doc=app.Documents.Open(str(OUT/'roundtrip.docx'),AddToRecentFiles=False)
 assert strokes('V') and strokes('H') and strokes('C')
 app.Run('Module1.SigandiRemove')
 assert any(s.Name.startswith('USER_PRESERVED_') for s in doc.Shapes)
 report=dict(status='PASS',horizontal_only_preserves_vertical_and_caps=True,
  vertical_only_preserves_horizontal=True,managed_canvas_user_child_preserved=True,
  remove_undo=True,save_reopen_remove=True,text_unchanged=True)
finally:
 if app is not None and old_color is not None:app.Run('Module1.SigandiSetLineColor',old_color)
 if doc is not None:doc.Close(False)
 if app is not None:
  if 'loaded' in locals():loaded.Installed=False
  app.Quit(False)
 (OUT/'report.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,indent=2))
