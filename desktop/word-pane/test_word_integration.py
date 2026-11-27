"""Isolated real Word test: temporary COM ProgIDs, dummy document, no user session or credentials."""
from pathlib import Path
import winreg,json,win32com.client,pythoncom,time
root=Path(__file__).resolve().parents[2]
dll=root/'desktop/tmp/Simanis.Word.Test.dll'
assembly='Simanis.Word.Test, Version=1.0.0.0, Culture=neutral, PublicKeyToken=null'
classes=[('SIMANIS.Word.Test2','E0484F5F-2D65-40DA-A9DC-A51A38F785EB','Simanis.Word.Connect'),('SIMANIS.Word.Pane.Test2','5BE43FDC-16BB-487B-95A9-6491D3F50A47','Simanis.Word.PaneControl')]
roots=[]
def put(key,name,value,kind=winreg.REG_SZ):
 with winreg.CreateKey(winreg.HKEY_CURRENT_USER,key) as k:winreg.SetValueEx(k,name,0,kind,value)
def remove(key):
 try:
  with winreg.OpenKey(winreg.HKEY_CURRENT_USER,key,0,winreg.KEY_READ|winreg.KEY_WRITE) as k:
   children=[]
   while True:
    try:children.append(winreg.EnumKey(k,len(children)))
    except OSError:break
  for name in children:remove(key+'\\'+name)
  winreg.DeleteKey(winreg.HKEY_CURRENT_USER,key)
 except FileNotFoundError:pass
for progid,guid,cls in classes:
 remove(r'Software\Classes\CLSID\{'+guid+'}')
 remove('Software\\Classes\\'+progid)
remove(r'Software\Microsoft\Office\Word\Addins\SIMANIS.Word.Test2')
word=doc=addin=None
try:
 for progid,guid,cls in classes:
  key=r'Software\Classes\CLSID\{'+guid+'}'
  for item in [key,'Software\\Classes\\'+progid]:
   try:
    with winreg.OpenKey(winreg.HKEY_CURRENT_USER,item):raise RuntimeError('Test registry already exists: '+item)
   except FileNotFoundError: pass
   roots.append(item)
  put('Software\\Classes\\'+progid+'\\CLSID','', '{'+guid+'}')
  put(key,'',progid);put(key+'\\ProgId','',progid)
  sub=key+'\\InprocServer32'
  for name,value in [('', r'C:\Windows\System32\mscoree.dll'),('ThreadingModel','Both'),('Class',cls),('Assembly',assembly),('RuntimeVersion','v4.0.30319'),('CodeBase','file:///'+str(dll).replace('\\','/'))]:put(sub,name,value)
  put(key+'\\Implemented Categories\\{62C8FE65-4EBB-45E7-B440-6E39B2CDBF29}','','')
  for name,value in [('Class',cls),('Assembly',assembly),('RuntimeVersion','v4.0.30319'),('CodeBase','file:///'+str(dll).replace('\\','/'))]:put(sub+'\\1.0.0.0',name,value)
  if cls.endswith('PaneControl'):put(key+'\\Control','','');put(key+'\\MiscStatus\\1','','131457')
 key=r'Software\Microsoft\Office\Word\Addins\SIMANIS.Word.Test2';roots.append(key)
 put(key,'FriendlyName','SIMANIS isolated pane test');put(key,'Description','Isolated task pane verification');put(key,'LoadBehavior',3,winreg.REG_DWORD);put(key,'CommandLineSafe',0,winreg.REG_DWORD)
 probe=win32com.client.Dispatch('SIMANIS.Word.Test2');print('COM_CLASS='+probe.Diagnostics(),flush=True)
 word=win32com.client.DispatchEx('Word.Application');word.Visible=True;word.WindowState=1;word.DisplayAlerts=0
 doc=word.Documents.Add();word.COMAddIns.Update()
 addin=word.COMAddIns.Item('SIMANIS.Word.Test2');addin.Connect=True
 print('CONNECTED='+str(addin.Connect),flush=True)
 auto=addin.Object;print('BEFORE='+auto.Diagnostics(),flush=True)
 auto.ShowPane();print('AFTER='+auto.Diagnostics(),flush=True)
 time.sleep(0.8);pythoncom.PumpWaitingMessages();print('SNAPSHOT='+auto.Snapshot(),flush=True)
 auto.ShowFixture();time.sleep(0.3)
 import shutil;shutil.copy2(auto.Snapshot(),root/'desktop/tmp/word-pane-data-preview.png')
 first=auto.Preview()
 assert 'Nyonya SITI CONTOH' in first and 'BUDI CONTOH' not in first and 'Penghadap 1' not in first
 assert 'tiga puluh Agustus seribu sembilan ratus delapan puluh tujuh' in first
 auto.InsertPreview();assert first in doc.Content.Text
 name=doc.Range(7,18);assert name.Font.Bold==-1
 auto.PickPerson(1);second=auto.Preview();assert 'BUDI CONTOH' in second and 'SITI CONTOH' not in second
 auto.InsertPreview();assert second in doc.Content.Text
 doc.Undo();assert second not in doc.Content.Text
 auto.PickMode('Transaksi PPAT');money=auto.Preview()
 assert 'Rp125.000.000,00 (seratus dua puluh lima juta rupiah)' in money and 'SITI CONTOH' not in money
 print('NARRATIVE_PERSON_SELECTION_BOLD_AND_PPAT_WORDS_OK',flush=True)
 doc.Content.Text=''
 auto.InsertTestText('SIMANIS TEST satu');auto.InsertTestText(' dua')
 assert 'SIMANIS TEST satu dua' in doc.Content.Text
 assert 'visible=1' in auto.Diagnostics()
 print('REPEATED_INSERTION_OK',flush=True)
 doc.Undo();assert ' dua' not in doc.Content.Text
 print('UNDO_OK',flush=True)
 doc.Protect(3,True,'test-only')
 try:auto.InsertTestText('must not insert');raise AssertionError('Protected document accepted')
 except pythoncom.com_error:pass
 doc.Unprotect('test-only')
 print('PROTECTED_DOCUMENT_REJECTED',flush=True)
finally:
 if addin is not None:
  try:addin.Connect=False
  except Exception:pass
 if doc is not None:
  try:doc.Close(False)
  except Exception:pass
 if word is not None:
  try:word.Quit(False)
  except Exception:pass
 for key in reversed(roots):remove(key)
