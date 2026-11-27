"""Verify label-left rails using actual PDF glyphs, and line weight editing."""
from pathlib import Path
import json
import re
import winreg
import fitz
import win32com.client
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'tmp/sigandi-word-0.4.4-numbering';OUT.mkdir(parents=True,exist_ok=True)
app=doc=None
result={}
saved=None
prefs=r'Software\VB and VBA Program Settings\SIGANDI Word\Garis'
try:
 with winreg.OpenKey(winreg.HKEY_CURRENT_USER,prefs) as key:saved=winreg.QueryValueEx(key,'Weight')
except FileNotFoundError:pass
try:
 app=win32com.client.DispatchEx('Word.Application');app.Visible=False;app.DisplayAlerts=0
 for a in app.AddIns:
  if 'sigandi' in a.Name.lower() and a.Installed:a.Installed=False
 loaded=app.AddIns.Add(str(ROOT/'output/word-addin/SIGANDI-Word-0.4.4-preview.dotm'),True)
 def run(name,*args):return app.Run('Module1.'+name,*args)
 def leaves():
  result=[]
  for s in doc.Shapes:result.extend(list(s.CanvasItems) if s.Type==20 else [s])
  return result
 print(run('SigandiVersion'),flush=True)
 for alignment,number_style,override in ((1,2,False),(2,2,False),(0,2,False),(0,0,True)):
  doc=app.Documents.Add()
  doc.Content.Text=('Menyampaikan surat kepada para pihak. '+ 'Rapat Umum Pemegang Saham dan keterangan lanjutan. '*5+'\r')*3
  doc.Content.Font.Name='Times New Roman';doc.Content.Font.Size=11
  doc.Content.ParagraphFormat.LineSpacingRule=4;doc.Content.ParagraphFormat.LineSpacing=24
  doc.PageSetup.LeftMargin=doc.PageSetup.RightMargin=72
  template=doc.ListTemplates.Add(True)
  level=template.ListLevels(1);level.NumberFormat='%1.';level.NumberStyle=number_style
  level.NumberPosition=36;level.TextPosition=72;level.TabPosition=72;level.Alignment=alignment
  doc.Content.ListFormat.ApplyListTemplateWithLevel(template,False,0,1,1)
  if override:
   doc.Content.ParagraphFormat.LeftIndent=22.5;doc.Content.ParagraphFormat.FirstLineIndent=-22.5
  doc.Bookmarks.Add('SIGANDI_AREA_ISI',doc.Content)
  original=doc.Content.Text
  value=run('SigandiTryBuild',True,True);assert value.isdigit(),value
  path=OUT/('roman-'+str(alignment)+'-'+str(number_style)+'.pdf');doc.ExportAsFixedFormat(str(path),17)
  pdf=fitz.open(path);page=pdf[0]
  labels=[w for w in page.get_text('words') if re.fullmatch(r'(?:i{1,3}|[1-3])\.',w[4])]
  assert len(labels)==3,[w[4] for w in page.get_text('words')]
  rails=[x['rect'] for x in page.get_drawings() if x['type']=='s' and x['rect'].width<.1 and x['rect'].height>0]
  for word in labels:
   near=[s for s in rails if abs(s.y0-word[1])<8 and s.height<18]
   assert near,word
   assert any(.5<word[0]-s.x0<9 for s in near),(word,[(s.x0,s.y0) for s in near])
  if alignment==1:page.get_pixmap(matrix=fitz.Matrix(1.3,1.3)).save(str(OUT/'roman-right.png'))
  def geometry():return [(s.Name,s.Left,s.Top,s.Width,s.Height,s.Line.ForeColor.RGB) for s in leaves()]
  before=geometry();initial=run('SigandiLineWeight')
  for weight in (.1,.6,1.5,4.):
   assert run('SigandiSetLineWeight',weight)=='OK'
   assert abs(run('SigandiLineWeight')-weight)<.01
   assert all(abs(s.Line.Weight-weight)<.01 for s in leaves() if s.Name.startswith('SIGANDI_W_'))
   assert geometry()==before and doc.Content.Text==original
  assert run('SigandiSetLineWeight',8.).startswith('ERROR:')
  assert abs(run('SigandiLineWeight')-4)<.01
  assert doc.Undo()
  assert all(abs(s.Line.Weight-1.5)<.01 for s in leaves() if s.Name.startswith('SIGANDI_W_'))
  assert run('SigandiUpdateCaps')=='OK'
  assert all(abs(s.Line.Weight-4)<.01 for s in leaves() if s.Name.startswith('SIGANDI_W_C_'))
  result[str(alignment)+'-'+str(number_style)]=dict(status='PASS',labels=3,rail_left_of_actual_pdf_glyphs=True,
   weights='.1,.6,1.5,4 pt',undo=True,invalid_weight_rejected=True,new_caps_use_weight=True,text_geometry_color_unchanged=True)
  doc.Close(False);doc=None
finally:
 if doc is not None:doc.Close(False)
 if app is not None:
  if 'loaded' in locals():loaded.Installed=False
  app.Quit(False)
 with winreg.CreateKey(winreg.HKEY_CURRENT_USER,prefs) as key:
  if saved:winreg.SetValueEx(key,'Weight',0,saved[1],saved[0])
  else:
   try:winreg.DeleteValue(key,'Weight')
   except FileNotFoundError:pass
 (OUT/'report.json').write_text(json.dumps(result,indent=2))
print(json.dumps(result,indent=2),flush=True)
