"""Compile the native task pane with embedded Office interop interfaces (no VSTO runtime)."""
from pathlib import Path
import argparse, os, subprocess
ROOT=Path(__file__).resolve().parents[1]
def main():
 p=argparse.ArgumentParser();p.add_argument('--test',action='store_true');a=p.parse_args()
 win=Path(os.environ.get('WINDIR','C:/Windows'))
 compiler=win/'Microsoft.NET/Framework64/v4.0.30319/csc.exe'
 office=next((win/'assembly/GAC_MSIL/office').glob('*/OFFICE.DLL'))
 extensibility=next((win/'assembly/GAC/Extensibility').glob('*/extensibility.dll'))
 target=ROOT/('tmp/Simanis.Word.Test.dll' if a.test else 'output/word-addin/installer/Simanis.Word.dll')
 target.parent.mkdir(parents=True,exist_ok=True)
 args=[str(compiler),'/nologo','/target:library','/platform:anycpu','/out:'+str(target)]
 if a.test:args+=['/define:INTEGRATION_TEST']
 args+=['/r:'+x for x in ['System.Windows.Forms.dll','System.Drawing.dll','System.Web.Extensions.dll','System.Security.dll','System.Net.Http.dll','Microsoft.CSharp.dll']]
 args+=['/link:'+str(office),'/link:'+str(extensibility)]
 args += [str(ROOT/'word-pane'/name) for name in ['Connect.cs','Session.cs','Pane.cs','DraftText.cs']]
 subprocess.run(args,check=True);print(target)
if __name__=='__main__':main()
