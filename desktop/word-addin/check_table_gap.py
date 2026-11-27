"""Native preview regression for external table rails and menu shortcuts."""
from pathlib import Path
import json,re,zipfile,xml.etree.ElementTree as ET
import fitz,win32com.client
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'tmp/sigandi-gap-local';OUT.mkdir(exist_ok=True)
template=ROOT/'output/word-addin/SIGANDI-Word-0.4.9-preview.dotm'
app=doc=None;report={}
def strokes():
 result=[]
 for shape in doc.Shapes:result.extend(list(shape.CanvasItems) if shape.Type==20 else [shape])
 return [s for s in result if s.Name.startswith('SIGANDI_W_')]
def build():
 text=doc.Content.Text;value=str(app.Run('Module1.SigandiTryBuild',True,True))
 assert value.isdigit(),value
 assert doc.Content.Text==text
 return int(value)
def verify_pdf(name):
 path=OUT/(name+'.pdf');doc.ExportAsFixedFormat(str(path),17)
 pdf=fitz.open(path)
 for page in pdf:
  # Left-aligned cell text begins 5.4 pt inside the table; rail is another 6 pt outside.
  boxes=page.search_for('Kolom kiri')
  if boxes:
   left=boxes[0].x0-5.4
   rails=[p['rect'] for p in page.get_drawings() if p['rect'].width<.5 and p['rect'].height>20]
   assert any(abs((left-r.x0)-6)<1.3 for r in rails),(name,left,rails)
  for box in boxes:
   assert not any(re.search(r'\[\s*\d',p.get('dashes') or '') and p['rect'].height<.5 and box.y0<=p['rect'].y0<=box.y1 for p in page.get_drawings())
  assert all(p['rect'].y1 <= page.rect.height+1 for p in page.get_drawings()),'Line outside page'
 pdf[0].get_pixmap(matrix=fitz.Matrix(1.5,1.5)).save(OUT/(name+'.png'))
 return len(pdf)
try:
 with zipfile.ZipFile(template) as z:
  ui=ET.fromstring(z.read('customUI/customUI.xml'))
  labels={n.get('id'):n.get('label') for n in ui.iter() if n.get('id')}
  assert labels['SigandiAll']=='Buat Semua (Ctrl+F6)'
  assert labels['SigandiMark']=='Tandai Area Isi (Ctrl+F7)'
 app=win32com.client.DispatchEx('Word.Application');app.Visible=False;app.DisplayAlerts=0
 for addin in app.AddIns:
  if 'sigandi' in addin.Name.lower() and addin.Installed:addin.Installed=False
 app.AddIns.Add(str(template),True)
 for number,action in [(6,'All'),(7,'Mark'),(8,'Vertical'),(9,'Fill'),(10,'Refresh'),(11,'PDF')]:
  assert ('SIGANDISHORTCUT'+action.upper()) in app.FindKey(app.BuildKeyCode(512,111+number)).Command.upper()
 print('Labels and Ctrl+F6..F11 PASS',flush=True)
 for name,count in [('table-gap',4),('multipage-gap',65)]:
  doc=app.Documents.Add();doc.Content.Text='-Isi sebelum tabel.\r\r-Isi sesudah tabel.\r'
  doc.Content.Font.Name='Arial';doc.Content.Font.Size=11;doc.Content.ParagraphFormat.SpaceAfter=0
  table=doc.Tables.Add(doc.Paragraphs(2).Range,count,2);table.Borders.Enable=False
  table.Rows.LeftIndent=36;table.Rows.HeightRule=2;table.Rows.Height=28
  for i,c in enumerate(table.Range.Cells):c.Range.Text=('Kolom kiri ' if i%2==0 else 'Kolom kanan ')+str(i+1)
  if count==4:
   table.Cell(1,1).Merge(table.Cell(1,2))
   table.Cell(2,1).Merge(table.Cell(3,1))
  if count==65:table.Rows(1).HeadingFormat=True
  doc.Content.Select();app.FindKey(app.BuildKeyCode(512,118)).Execute()
  assert doc.Bookmarks.Exists('SIGANDI_AREA_ISI')
  # Build quietly via native shortcut; no message boxes in valid shortcut paths.
  app.FindKey(app.BuildKeyCode(512,117)).Execute()
  assert any(s.Name.startswith('SIGANDI_W_V_') for s in strokes())
  assert all(c.Borders(-2).LineStyle==0 for c in table.Range.Cells if c.ColumnIndex==1)
  assert len([s for s in strokes() if s.Name.startswith('SIGANDI_W_H_')])==2
  pages=verify_pdf(name)
  if count==65:assert pages>=3
  build()
  report[name]={'pages':pages,'gap_pt':6,'table_borders_unchanged':True,'no_fill_inside_cells':True,'repeat_build':True}
  doc.SaveAs2(str(OUT/(name+'.docx')),16)
  app.Run('Module1.SigandiRemove')
  assert not strokes()
  doc.Close(False);doc=None
  print(name+' PASS',flush=True)
 doc=app.Documents.Add()
 table=doc.Tables.Add(doc.Range(0,0),2,2);table.Borders.Enable=False
 for cell in table.Range.Cells:cell.Range.Text='Tabel saja'
 doc.Bookmarks.Add('SIGANDI_AREA_ISI',table.Range)
 build()
 assert any(s.Name.startswith('SIGANDI_W_V_') for s in strokes())
 assert not any(s.Name.startswith('SIGANDI_W_H_') for s in strokes())
 assert all(cell.Borders(-2).LineStyle==0 for cell in table.Range.Cells)
 app.Run('Module1.SigandiRemove');assert not strokes()
 report['table-only']={'no_cell_fill':True,'table_borders_unchanged':True,'remove':True}
 doc.Close(False);doc=None
 legacy=ROOT/'tmp/sigandi-tables-shortcuts-local/merged-table.docx'
 if legacy.exists():
  doc=app.Documents.Open(str(legacy),ReadOnly=False,AddToRecentFiles=False)
  build()
  assert not any(b.Name.startswith('SGNDT_') for b in doc.Bookmarks)
  border=doc.Tables(1).Cell(1,1).Borders(-2)
  assert (border.LineStyle,border.LineWidth,border.Color)==(3,8,32768)
  report['legacy_native_border_restored']=True
 report['status']='PASS'
finally:
 if doc is not None:doc.Close(False)
 if app is not None:app.Quit(False)
 (OUT/'report.json').write_text(json.dumps(report,indent=2))
print(json.dumps(report,indent=2))
