"""Cross-process DPAPI and actual HTTP transport tests using synthetic credentials only."""
from pathlib import Path
import http.server,json,subprocess,tempfile,threading,os
ROOT=Path(__file__).resolve().parents[1]
compiler=Path(os.environ['WINDIR'])/'Microsoft.NET/Framework64/v4.0.30319/csc.exe'
target=ROOT/'tmp/SessionTests.exe'
subprocess.run([str(compiler),'/nologo','/out:'+str(target),'/r:System.Web.Extensions.dll','/r:System.Security.dll','/r:System.Net.Http.dll',str(ROOT/'word-pane/SessionTests.cs'),str(ROOT/'word-pane/Session.cs'),str(ROOT/'word-pane/SessionTestIdentity.cs')],check=True)
with tempfile.TemporaryDirectory() as folder:
 path=str(Path(folder)/'session.bin')
 for mode in ['write','read']:subprocess.run([str(target),mode,path],check=True)
class Server(http.server.BaseHTTPRequestHandler):
 reads=0;redirect_followed=False
 def log_message(self,*args):pass
 def respond(self,status,data):
  body=json.dumps(data).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
 def do_POST(self):
  body=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))))
  assert body=={'email':'fixture@example.test','password':'fixture-only'}
  self.respond(200,{'status':True,'data':{'access_token':'fixture-token'}})
 def do_GET(self):
  if self.path.endswith('/word/jobs'):
   assert self.headers.get('Authorization')=='Bearer fixture-token'
   Server.reads+=1;self.respond(200 if Server.reads==1 else 401,{'status':True,'data':[{'id':1,'title':'Synthetic job'}]})
  elif self.path.endswith('/redirect'):
   self.send_response(302);self.send_header('Location','/api/should-not-follow');self.send_header('Content-Length','2');self.end_headers();self.wfile.write(b'{}')
  else:Server.redirect_followed=True;self.respond(500,{})
server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Server)
thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
try:
 subprocess.run([str(target),'http',f'http://127.0.0.1:{server.server_port}/api'],check=True)
 assert Server.reads==2 and not Server.redirect_followed
finally:server.shutdown();server.server_close()
