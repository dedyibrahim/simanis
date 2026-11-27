using System;
using System.Collections.Generic;
using System.Runtime.InteropServices;
using Office = Microsoft.Office.Core;
[assembly: ComVisible(false)]
[assembly: System.Reflection.AssemblyVersion("1.0.0.0")]
[assembly: System.Reflection.AssemblyFileVersion("1.6.0.0")]
namespace Simanis.Word {
 public static class Identity {
#if INTEGRATION_TEST
  public const string AddinGuid="E0484F5F-2D65-40DA-A9DC-A51A38F785EB", ControlGuid="5BE43FDC-16BB-487B-95A9-6491D3F50A47";
  public const string AddinProgId="SIMANIS.Word.Test2", ControlProgId="SIMANIS.Word.Pane.Test2";
#else
  public const string AddinGuid="34FF62FC-8B12-4516-A0D5-9A27C5586925", ControlGuid="E55076D4-A710-4E71-B0C5-E0CAAB2CAC61";
  public const string AddinProgId="SIMANIS.Word", ControlProgId="SIMANIS.Word.Pane";
#endif
  public const string DefaultApi="http://192.168.0.12:8000/api";
 }
 [ComVisible(true),Guid("ED560DE2-EBB7-4D7A-AC28-00518C300B27"),InterfaceType(ComInterfaceType.InterfaceIsIDispatch)]
 public interface IPaneAutomation {
  [DispId(1)] void ShowPane();
  [DispId(2)] string Diagnostics();
#if INTEGRATION_TEST
  [DispId(3)] void InsertTestText(string text);
  [DispId(4)] string Snapshot();
  [DispId(5)] void ShowFixture();
#endif
 }
 [ComVisible(true),Guid(Identity.AddinGuid),ProgId(Identity.AddinProgId),ClassInterface(ClassInterfaceType.None),ComDefaultInterface(typeof(IPaneAutomation))]
 public class Connect:Extensibility.IDTExtensibility2,Office.ICustomTaskPaneConsumer,IPaneAutomation {
  dynamic app; Office.ICTPFactory factory; System.Windows.Forms.Control dispatcher;

  readonly Dictionary<int,Office.CustomTaskPane> panes=new Dictionary<int,Office.CustomTaskPane>();
  public void OnConnection(object application,Extensibility.ext_ConnectMode mode,object addin,ref Array custom) {app=application;dispatcher=new System.Windows.Forms.Control();var handle=dispatcher.Handle; ((dynamic)addin).Object=this;}
  public void CTPFactoryAvailable(Office.ICTPFactory value) {factory=value;}
  public void OnDisconnection(Extensibility.ext_DisconnectMode mode,ref Array custom) {foreach(var pane in panes.Values) {try {pane.Delete();}catch(COMException) {}} panes.Clear();factory=null;app=null;if(dispatcher!=null){dispatcher.Dispose();dispatcher=null;}}
  public void OnAddInsUpdate(ref Array custom) {} public void OnStartupComplete(ref Array custom) {} public void OnBeginShutdown(ref Array custom) {}
  public void ShowPane() {
   if(dispatcher.InvokeRequired){dispatcher.Invoke((Action)ShowPane);return;}
   if(factory==null) throw new InvalidOperationException("Panel Word belum siap. Tutup Word lalu buka kembali.");
   if(app.Documents.Count==0) throw new InvalidOperationException("Buka dokumen Word terlebih dahulu.");
   dynamic window=app.ActiveWindow; int id=window.Hwnd; Office.CustomTaskPane pane;
   if(panes.TryGetValue(id,out pane)) {try {pane.Visible=true;((PaneControl)pane.ContentControl).ResumeSession();return;}catch(COMException) {panes.Remove(id);}}
   pane=factory.CreateCTP(Identity.ControlProgId,"SIMANIS | Pekerjaan Saya",window);
   ((PaneControl)pane.ContentControl).Attach(app);
   pane.DockPosition=Office.MsoCTPDockPosition.msoCTPDockPositionRight;pane.Width=430;pane.Visible=true;panes[id]=pane;
  }
  public string Diagnostics() {if(dispatcher!=null&&dispatcher.InvokeRequired)return (string)dispatcher.Invoke((Func<string>)Diagnostics);int visible=0;foreach(var pane in panes.Values) if(pane.Visible) visible++;return "factory="+(factory!=null)+";panes="+panes.Count+";visible="+visible;}
#if INTEGRATION_TEST
  public void InsertTestText(string text) {if(dispatcher.InvokeRequired){dispatcher.Invoke((Action)(()=>InsertTestText(text)));return;}PaneControl.InsertIntoWord(app,text);}
  public void ShowFixture(){if(dispatcher.InvokeRequired){dispatcher.Invoke((Action)ShowFixture);return;}foreach(var pane in panes.Values)((PaneControl)pane.ContentControl).ShowFixture();}
  public string Snapshot() {if(dispatcher.InvokeRequired)return (string)dispatcher.Invoke((Func<string>)Snapshot);
   foreach(var pane in panes.Values) {
    var control=(PaneControl)pane.ContentControl;
    string path=System.IO.Path.Combine(System.IO.Path.GetDirectoryName(typeof(Connect).Assembly.Location),"word-pane-preview.png");
    using(var image=new System.Drawing.Bitmap(control.Width,control.Height)){control.DrawToBitmap(image,control.ClientRectangle);image.Save(path);}return path;
   }return "";
  }
#endif
 }
}
