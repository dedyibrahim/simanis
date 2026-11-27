using System;
using System.Collections.Generic;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Threading.Tasks;
using System.Web.Script.Serialization;
namespace Simanis.Word {
 public sealed class ApiFailure:Exception {public readonly bool Unauthorized; public ApiFailure(string message,bool unauthorized=false):base(message) {Unauthorized=unauthorized;}}
 public sealed class SessionData {public string Api {get;set;} public string Token {get;set;} public DateTime RememberedUntilUtc {get;set;}}
 public static class SessionVault {
  public static event Action Cleared;
  static readonly byte[] Entropy=Encoding.UTF8.GetBytes("SIMANIS.Word.Session.v1");
  public static string DefaultPath {get {return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"SIMANIS",
#if INTEGRATION_TEST
   "word-pane-test-session.bin"
#else
   "word-session.bin"
#endif
  );}}
  public static void Save(SessionData session,string path=null) {
   path=path??DefaultPath;byte[] plain=Encoding.UTF8.GetBytes(new JavaScriptSerializer().Serialize(session));byte[] encrypted;
   try {encrypted=ProtectedData.Protect(plain,Entropy,DataProtectionScope.CurrentUser);}finally {Array.Clear(plain,0,plain.Length);}
   Directory.CreateDirectory(Path.GetDirectoryName(path));string temp=path+"."+Guid.NewGuid().ToString("N")+".tmp";
   try {File.WriteAllBytes(temp,encrypted);if(File.Exists(path)) File.Replace(temp,path,null);else File.Move(temp,path);}finally {if(File.Exists(temp)) File.Delete(temp);}
  }
  public static SessionData Load(string path=null) {
   path=path??DefaultPath;if(!File.Exists(path))return null;
   try {byte[] plain=ProtectedData.Unprotect(File.ReadAllBytes(path),Entropy,DataProtectionScope.CurrentUser);SessionData value;
    try {value=new JavaScriptSerializer().Deserialize<SessionData>(Encoding.UTF8.GetString(plain));}finally {Array.Clear(plain,0,plain.Length);}
    ApiClient.ValidateBase(value.Api);
    if(String.IsNullOrWhiteSpace(value.Token)||value.RememberedUntilUtc<=DateTime.UtcNow) {Delete(path);return null;}return value;
   }catch(Exception ex) {if(!(ex is CryptographicException||ex is IOException||ex is ArgumentException||ex is InvalidOperationException))throw;Delete(path);return null;}
  }
  public static void ForgetSaved() {if(File.Exists(DefaultPath))File.Delete(DefaultPath);}
  public static void Delete(string path=null) {path=path??DefaultPath;if(File.Exists(path))File.Delete(path);if(path==DefaultPath&&Cleared!=null)Cleared();}
 }
 public sealed class ApiClient:IDisposable {
  public readonly string Base;public string Token="";readonly HttpClient client;
  public ApiClient(string address) {Base=ValidateBase(address);client=new HttpClient(new HttpClientHandler {AllowAutoRedirect=false}) {Timeout=TimeSpan.FromSeconds(20)};}
  public static string ValidateBase(string address) {
   Uri uri;if(!Uri.TryCreate((address??"").Trim().TrimEnd('/'),UriKind.Absolute,out uri)||(uri.Scheme!="http"&&uri.Scheme!="https")||uri.UserInfo!=""||uri.Query!=""||uri.Fragment!=""||uri.AbsolutePath!="/api")throw new ArgumentException("Alamat API harus HTTP LAN atau HTTPS dan diakhiri /api.");
   if(uri.Scheme=="http") {IPAddress ip;if(!IPAddress.TryParse(uri.Host.Trim('[',']'),out ip))throw new ArgumentException("HTTP hanya untuk IP LAN privat.");byte[] b=ip.GetAddressBytes();bool lan=IPAddress.IsLoopback(ip)||(b.Length==4&&(b[0]==10||(b[0]==172&&b[1]>=16&&b[1]<=31)||(b[0]==192&&b[1]==168)));if(!lan)throw new ArgumentException("HTTP hanya untuk IP LAN privat.");}
   return uri.AbsoluteUri.TrimEnd('/');
  }
  public async Task<object> Call(string path,object payload=null,string method=null) {
   string originalToken=Token;
   using(var req=new HttpRequestMessage(new HttpMethod(method??(payload==null?"GET":"POST")),Base+path)) {
    req.Headers.Accept.ParseAdd("application/json");if(Token!="")req.Headers.Authorization=new System.Net.Http.Headers.AuthenticationHeaderValue("Bearer",Token);
    if(payload!=null)req.Content=new StringContent(new JavaScriptSerializer().Serialize(payload),Encoding.UTF8,"application/json");
    using(var response=await client.SendAsync(req,HttpCompletionOption.ResponseHeadersRead)) {
     using(var stream=await response.Content.ReadAsStreamAsync())using(var output=new MemoryStream()) {
      byte[] buf=new byte[8192];int n;while((n=await stream.ReadAsync(buf,0,buf.Length))>0) {if(output.Length+n>2000000)throw new ApiFailure("Respons terlalu besar. Persempit pencarian.");output.Write(buf,0,n);}
      if((int)response.StatusCode==401) {Token="";throw new ApiFailure("Sesi berakhir. Silakan login kembali.",true);}
      if(originalToken!=""&&originalToken!=Token)throw new ApiFailure("Sesi berubah. Silakan login kembali.",true);
      var json=new JavaScriptSerializer().DeserializeObject(Encoding.UTF8.GetString(output.ToArray())) as Dictionary<string,object>;
      if(!response.IsSuccessStatusCode||json==null||(json.ContainsKey("status")&&json["status"] is bool&&!(bool)json["status"]))throw new ApiFailure(json!=null&&json.ContainsKey("message")?Convert.ToString(json["message"]):"Permintaan ditolak server ("+(int)response.StatusCode+").");
      return json.ContainsKey("data")?json["data"]:json;
     }
    }
   }
  }
  public void Dispose() {client.Dispose();}
 }
}
