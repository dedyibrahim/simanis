using System;
using System.Collections.Generic;
using System.Drawing;
using System.IO;
using System.Linq;
using System.Net.Http;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Threading.Tasks;
using System.Windows.Forms;
namespace Simanis.Word {
 public sealed class FieldItem {public string Label,Value;public override string ToString() {return Label+": "+Value;}}
 public sealed class JobItem {public string Id,Title;public override string ToString() {return Title;}}
 [ComVisible(true),Guid(Identity.ControlGuid),ProgId(Identity.ControlProgId),ClassInterface(ClassInterfaceType.AutoDispatch)]
 public class PaneControl:UserControl {
  dynamic app;ApiClient api;string challenge="";bool busy;
  readonly Label status=new Label {AutoSize=true,MaximumSize=new Size(385,0),ForeColor=Color.DimGray};
  readonly FlowLayoutPanel stack=new FlowLayoutPanel {Dock=DockStyle.Fill,FlowDirection=FlowDirection.TopDown,WrapContents=false,AutoScroll=true,Padding=new Padding(14),BackColor=Color.FromArgb(247,249,252)};
  readonly FlowLayoutPanel login=new FlowLayoutPanel {FlowDirection=FlowDirection.TopDown,AutoSize=true,WrapContents=false};
  readonly FlowLayoutPanel data=new FlowLayoutPanel {FlowDirection=FlowDirection.TopDown,AutoSize=true,WrapContents=false,Visible=false};
  readonly TextBox server=new TextBox {Text=Identity.DefaultApi,Width=370};
  readonly TextBox email=new TextBox {Width=370},password=new TextBox {Width=370,UseSystemPasswordChar=true},otp=new TextBox {Width=370};
  readonly CheckBox remember=new CheckBox {Text="Ingat login di akun Windows ini",Checked=true,AutoSize=true};
  readonly TextBox query=new TextBox {Width=370};
  readonly ListBox jobs=new ListBox {Width=370,Height=145,HorizontalScrollbar=true};
  readonly CheckedListBox fields=new CheckedListBox {Width=370,Height=190,CheckOnClick=true,HorizontalScrollbar=true};
  readonly CheckBox labels=new CheckBox {Text="Sertakan nama field",Checked=true,AutoSize=true};
  readonly TextBox preview=new TextBox {Width=370,Height=80,Multiline=true,ReadOnly=true,ScrollBars=ScrollBars.Vertical};
  readonly Button loginButton,insert;
  readonly Panel footer=new Panel {Dock=DockStyle.Bottom,Height=65,Padding=new Padding(14,5,14,5),BackColor=Color.FromArgb(247,249,252)};
  public PaneControl() {
   Font=new Font("Segoe UI",9F);MinimumSize=new Size(290,300);Controls.Add(stack);
   stack.Controls.Add(new Label {Text="Pekerjaan Saya",Font=new Font("Segoe UI",15F,FontStyle.Bold),AutoSize=true});
   AddLabel(stack,"Pilih pekerjaan dan masukkan data ke draf Word.");
   AddLabel(login,"Alamat server kantor");login.Controls.Add(server);AddLabel(login,"Email");login.Controls.Add(email);
   AddLabel(login,"Password");login.Controls.Add(password);AddLabel(login,"OTP WhatsApp (jika diminta)");login.Controls.Add(otp);
   login.Controls.Add(remember);loginButton=Button("Login",async delegate {await Login();});var loginActions=Row();loginActions.Controls.Add(loginButton);loginActions.Controls.Add(Button("Ulang login",delegate {challenge="";otp.Clear();password.Clear();loginButton.Text="Login";status.Text="Masukkan akun untuk login.";}));login.Controls.Add(loginActions);
   AddLabel(login,"HTTP hanya untuk LAN kantor tepercaya.");stack.Controls.Add(login);
   AddLabel(data,"Cari pekerjaan, pesanan atau penghadap");data.Controls.Add(query);
   query.KeyDown+=async(s,e)=>{if(e.KeyCode==Keys.Enter){e.SuppressKeyPress=true;await Search();}};
   var actions=Row();actions.Controls.Add(Button("Cari / Muat ulang",async delegate {await Search();}));actions.Controls.Add(Button("Keluar",async delegate {await Logout();}));data.Controls.Add(actions);
   data.Controls.Add(jobs);jobs.SelectedIndexChanged+=async(s,e)=>{if(jobs.SelectedItem!=null)await Detail((JobItem)jobs.SelectedItem);};
   AddLabel(data,"Centang data yang akan dimasukkan");data.Controls.Add(fields);fields.ItemCheck+=(s,e)=>{BeginInvoke((Action)UpdatePreview);};
   var choices=Row();choices.Controls.Add(Button("Pilih semua",delegate {for(int i=0;i<fields.Items.Count;i++)fields.SetItemChecked(i,true);}));
   choices.Controls.Add(Button("Kosongkan",delegate {for(int i=0;i<fields.Items.Count;i++)fields.SetItemChecked(i,false);}));data.Controls.Add(choices);
   labels.CheckedChanged+=(s,e)=>UpdatePreview();data.Controls.Add(labels);
   insert=Button("Masukkan ke posisi kursor",delegate {try {InsertIntoWord(app,SelectedText());status.Text="Data dimasukkan. Anda bisa memasukkan data lagi tanpa menutup panel.";}catch(Exception ex){status.Text=ex.Message;}});
   insert.BackColor=Color.FromArgb(30,91,206);insert.ForeColor=Color.White;insert.FlatStyle=FlatStyle.Flat;
   AddLabel(data,"Data masuk ke dokumen aktif, setelah kursor/seleksi. Periksa draf sebelum digunakan.");stack.Controls.Add(data);
   status.AutoSize=false;status.Dock=DockStyle.Bottom;status.Height=50;
   preview.Dock=DockStyle.Top;preview.Height=62;insert.Dock=DockStyle.Top;insert.Height=40;
   footer.Controls.Add(status);footer.Controls.Add(insert);footer.Controls.Add(preview);Controls.Add(footer);
   preview.Visible=insert.Visible=false;
   Resize+=(s,e)=>{int w=Math.Max(235,ClientSize.Width-48);foreach(var c in new Control[]{login,data,server,email,password,otp,query,jobs,fields,preview})c.Width=w;status.MaximumSize=new Size(w,0);};
  }
  static FlowLayoutPanel Row(){return new FlowLayoutPanel {AutoSize=true,WrapContents=false,Margin=new Padding(0,4,0,4)};}
  static void AddLabel(Control p,string text){p.Controls.Add(new Label {Text=text,AutoSize=true,MaximumSize=new Size(370,0),Margin=new Padding(0,8,0,4)});}
  static Button Button(string text,EventHandler handler){var b=new Button {Text=text,AutoSize=true,Padding=new Padding(5),Margin=new Padding(0,4,7,4)};b.Click+=handler;return b;}
  public void Attach(object application){app=application;SessionVault.Cleared+=SessionCleared;ResumeSession();}
  public void ResumeSession(){if(api!=null&&api.Token!="")return;BeginInvoke((Action)(async()=>{await Run(async()=>{var session=SessionVault.Load();if(session==null){status.Text="Login sekali; sesi dapat diingat di akun Windows ini.";return;}server.Text=session.Api;api=new ApiClient(session.Api){Token=session.Token};Authorized();await LoadJobs();});}));}
  void SessionCleared(){if(IsDisposed)return;if(InvokeRequired){BeginInvoke((Action)SessionCleared);return;}if(api!=null)api.Token="";Authorized(false);}
  async Task Run(Func<Task> action){if(busy)return;busy=true;login.Enabled=data.Enabled=false;status.Text="Memuat...";
   try {await action();}catch(ApiFailure ex){if(ex.Unauthorized){SessionVault.Delete();Authorized(false);}status.Text=ex.Message;}
   catch(Exception ex){status.Text=(ex is HttpRequestException||ex is TaskCanceledException)?"Server belum dapat dihubungi. Periksa LAN lalu coba lagi.":(ex is CryptographicException||ex is IOException?"Sesi Windows belum dapat disimpan. Coba login kembali.":"Permintaan belum berhasil. "+ex.Message);}
   finally{busy=false;login.Enabled=data.Enabled=true;}
  }
  void Authorized(bool yes=true){login.Visible=!yes;data.Visible=yes;preview.Visible=insert.Visible=yes;footer.Height=yes?175:65;if(!yes){jobs.Items.Clear();fields.Items.Clear();preview.Clear();}}
  async Task Login(){await Run(async()=>{
   if(challenge==""){if(api!=null)api.Dispose();api=new ApiClient(server.Text);}object result;
   try{result=challenge==""?await api.Call("/auth/login",new {email=email.Text,password=password.Text}):await api.Call("/auth/login/verify-otp",new {challenge_id=challenge,otp=otp.Text.Trim()});}finally{password.Clear();}
   var value=(Dictionary<string,object>)result;
   if(value.ContainsKey("otp_required")&&Convert.ToBoolean(value["otp_required"])){challenge=Convert.ToString(value["challenge_id"]);status.Text="OTP dikirim ke WhatsApp. Masukkan kode lalu verifikasi.";loginButton.Text="Verifikasi OTP";return;}
   if(!value.ContainsKey("access_token"))throw new ApiFailure("Sesi login tidak valid.");api.Token=Convert.ToString(value["access_token"]);challenge="";otp.Clear();loginButton.Text="Login";
   if(remember.Checked)SessionVault.Save(new SessionData {Api=api.Base,Token=api.Token,RememberedUntilUtc=DateTime.UtcNow.AddDays(30)});else SessionVault.ForgetSaved();
   Authorized();await LoadJobs();
  });}
  async Task Search(){await Run(async()=>{await LoadJobs();Authorized();});}
  async Task LoadJobs(){if(api==null||api.Token=="")throw new ApiFailure("Silakan login.",true);string q=query.Text.Trim();if(q.Length>100)q=q.Substring(0,100);
   var rows=(object[])await api.Call("/auth/word/jobs?q="+Uri.EscapeDataString(q));jobs.Items.Clear();fields.Items.Clear();preview.Clear();
   foreach(Dictionary<string,object> row in rows)jobs.Items.Add(new JobItem {Id=Convert.ToString(row["id"]),Title=Convert.ToString(row["title"])});
   status.Text=rows.Length==0?"Belum ada pekerjaan yang sesuai atau menjadi milik Anda.":"Pilih pekerjaan. Asisten hanya melihat pekerjaan miliknya.";
  }
  async Task Detail(JobItem job){await Run(async()=>{fields.Items.Clear();preview.Clear();var value=(Dictionary<string,object>)await api.Call("/auth/word/jobs/"+Uri.EscapeDataString(job.Id));
   foreach(Dictionary<string,object> field in (object[])value["fields"])fields.Items.Add(new FieldItem {Label=Convert.ToString(field["label"]),Value=Convert.ToString(field["value"])},true);
   UpdatePreview();status.Text=Convert.ToString(value["title"])+" â€” tinjau data sebelum memasukkan.";
  });}
  string SelectedText(){return String.Join("\r\n",fields.CheckedItems.Cast<FieldItem>().Select(f=>labels.Checked?f.Label+": "+f.Value:f.Value));}
  void UpdatePreview(){if(!IsDisposed)preview.Text=SelectedText();}
  async Task Logout(){await Run(async()=>{string message="Anda sudah keluar. Login kembali untuk mengakses pekerjaan.";
   try{if(api!=null&&api.Token!="")await api.Call("/auth/word/session",null,"DELETE");}catch{message="Sesi lokal dihapus. Server belum dapat dihubungi untuk mencabut token.";}
   finally{SessionVault.Delete();if(api!=null){api.Token="";api.Dispose();api=null;}challenge="";password.Clear();otp.Clear();loginButton.Text="Login";Authorized(false);}status.Text=message;
  });}
  public static void InsertIntoWord(dynamic app,string text){if(String.IsNullOrWhiteSpace(text)||text.Length>100000)throw new InvalidOperationException("Pilih data yang akan dimasukkan (maksimum 100.000 karakter).");
   if(app==null||app.Documents.Count==0)throw new InvalidOperationException("Buka dokumen Word terlebih dahulu.");dynamic doc=app.ActiveDocument;
   if(doc.ReadOnly||(int)doc.ProtectionType!=-1)throw new InvalidOperationException("Dokumen terkunci atau hanya baca.");dynamic target=app.Selection.Range.Duplicate;target.Collapse(0);
   app.UndoRecord.StartCustomRecord("Masukkan data SIMANIS");try{target.InsertAfter(text.Replace("\r\n","\r").Replace("\n","\r"));target.Collapse(0);target.Select();}finally{app.UndoRecord.EndCustomRecord();}
  }
#if INTEGRATION_TEST
  public void ShowFixture(){fields.Items.Clear();fields.Items.Add(new FieldItem {Label="Pesanan",Value="Contoh transaksi kantor"},true);fields.Items.Add(new FieldItem {Label="Penghadap 1 ? Nama",Value="Nama penghadap contoh"},true);fields.Items.Add(new FieldItem {Label="Penghadap 1 ? Kedudukan",Value="Penjual"},true);fields.Items.Add(new FieldItem {Label="Harga transaksi",Value="125000000"},true);Authorized();UpdatePreview();status.Text="Data sintetis untuk pemeriksaan tampilan.";}
#endif
  protected override void Dispose(bool disposing){if(disposing){SessionVault.Cleared-=SessionCleared;if(api!=null)api.Dispose();}base.Dispose(disposing);}
 }
}
