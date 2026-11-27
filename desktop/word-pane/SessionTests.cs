using System;
using System.IO;
using System.Text;
using System.Collections.Generic;
using Simanis.Word;
class SessionTests {
 static void Require(bool condition,string message){if(!condition)throw new Exception(message);}
 static int Main(string[] args){try{
  string mode=args[0],path=args[1];
  if(mode=="write"){
   SessionVault.Save(new SessionData {Api=Identity.DefaultApi,Token="SIMANIS-TEST-TOKEN",RememberedUntilUtc=DateTime.UtcNow.AddDays(1)},path);
   string raw=Encoding.UTF8.GetString(File.ReadAllBytes(path));Require(!raw.Contains("SIMANIS-TEST-TOKEN")&&!raw.Contains("192.168.0.12"),"Plaintext secret found");
   Console.WriteLine("DPAPI_WRITE_OK");
  }else if(mode=="read"){
   var value=SessionVault.Load(path);Require(value!=null&&value.Token=="SIMANIS-TEST-TOKEN","Cross-process session restore failed");Console.WriteLine("DPAPI_RESTORE_OK");
   SessionVault.Save(new SessionData {Api=Identity.DefaultApi,Token="REPLACED",RememberedUntilUtc=DateTime.UtcNow.AddDays(1)},path);
   Require(SessionVault.Load(path).Token=="REPLACED","Atomic replacement failed");
   SessionVault.Save(new SessionData {Api=Identity.DefaultApi,Token="EXPIRED",RememberedUntilUtc=DateTime.UtcNow.AddSeconds(-1)},path);
   Require(SessionVault.Load(path)==null&&!File.Exists(path),"Expired session accepted");
   File.WriteAllText(path,"tampered");Require(SessionVault.Load(path)==null&&!File.Exists(path),"Tampered session accepted");
   SessionVault.Save(new SessionData {Api=Identity.DefaultApi,Token="DELETE-ME",RememberedUntilUtc=DateTime.UtcNow.AddDays(1)},path);SessionVault.Delete(path);Require(!File.Exists(path),"Logout did not clear session");
   foreach(string url in new[]{"http://example.com/api","http://8.8.8.8/api","http://169.254.169.254/api","https://user:pass@server/api","https://server/api?token=x"}){
    bool rejected=false;try{ApiClient.ValidateBase(url);}catch(ArgumentException){rejected=true;}Require(rejected,"Unsafe URL accepted");
   }
   Require(ApiClient.ValidateBase("http://192.168.0.12:8000/api")==Identity.DefaultApi,"Office URL rejected");Console.WriteLine("EXPIRY_TAMPER_LOGOUT_AND_URLS_OK");
  }else if(mode=="http"){
   using(var api=new ApiClient(path)){
    var login=(Dictionary<string,object>)api.Call("/auth/login",new {email="fixture@example.test",password="fixture-only"}).GetAwaiter().GetResult();
    api.Token=Convert.ToString(login["access_token"]);
    var jobs=(object[])api.Call("/auth/word/jobs").GetAwaiter().GetResult();Require(jobs.Length==1,"Job parsing failed");
    bool denied=false;try{api.Call("/auth/word/jobs").GetAwaiter().GetResult();}catch(ApiFailure e){denied=e.Unauthorized;}Require(denied&&api.Token=="","401 did not clear session token");
    bool redirect=false;try{api.Call("/redirect").GetAwaiter().GetResult();}catch(ApiFailure){redirect=true;}Require(redirect,"Redirect accepted");
   }Console.WriteLine("HTTP_LOGIN_JOBS_401_NO_REDIRECT_OK");
  }else throw new Exception("Unknown test mode");return 0;
 }catch(Exception e){Console.Error.WriteLine(e);return 1;}}
}
