from pathlib import Path
import os,subprocess
ROOT=Path(__file__).resolve().parents[1]
subprocess.run([os.sys.executable,str(ROOT/'word-pane/build.py'),'--test'],check=True)
compiler=Path(os.environ['WINDIR'])/'Microsoft.NET/Framework64/v4.0.30319/csc.exe'
exe=ROOT/'tmp/DraftTests.exe'
subprocess.run([str(compiler),'/nologo','/out:'+str(exe),'/r:'+str(ROOT/'tmp/Simanis.Word.Test.dll'),str(ROOT/'word-pane/DraftTests.cs')],check=True)
subprocess.run([str(exe)],check=True)
