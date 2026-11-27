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
 public sealed class FieldItem {public string Key,Label,Value;public string DisplayLabel {get{return System.Text.RegularExpressions.Regex.Replace(Label??"",@"^Penghadap\s+\d+\s*(?:\u00b7|\?)\s*","");}} public override string ToString() {return DisplayLabel+": "+Value;}}
 public sealed class JobItem {public string Id,Title;public override string ToString() {return Title;}}
 [ComVisible(true),Guid(Identity.ControlGuid),ProgId(Identity.ControlProgId),ClassInterface(ClassInterfaceType.AutoDispatch)]
 public class PaneControl:UserControl {
  dynamic app;ApiClient api;string challenge="";bool busy;
  readonly List<FieldItem> allFields=new List<FieldItem>();
  readonly ComboBox outputMode=new ComboBox {DropDownStyle=ComboBoxStyle.DropDownList,Width=370};
  readonly ComboBox people=new ComboBox {DropDownStyle=ComboBoxStyle.DropDownList,Width=370};
  readonly ComboBox salutation=new ComboBox {DropDownStyle=ComboBoxStyle.DropDownList,Width=370};
  readonly CheckBox terbilang=new CheckBox {Text="Sertakan terbilang nominal PPAT",Checked=true,AutoSize=true};
  bool filtering;
  readonly FlowLayoutPanel jobSearch=new FlowLayoutPanel {FlowDirection=FlowDirection.TopDown,AutoSize=true,WrapContents=false};
  readonly Label currentJob=new Label {AutoSize=true,Visible=false,MaximumSize=new Size(370,0),Font=new Font("Segoe UI",9F,FontStyle.Bold)};
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
  readonly CheckBox labels=new CheckBox {Text="Sertakan nama field",Checked=false,AutoSize=true};
  readonly TextBox preview=new TextBox {Width=370,Height=80,Multiline=true,ReadOnly=false,ScrollBars=ScrollBars.Vertical};
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
   AddLabel(jobSearch,"Cari pekerjaan, pesanan atau penghadap");jobSearch.Controls.Add(query);
   query.KeyDown+=async(s,e)=>{if(e.KeyCode==Keys.Enter){e.SuppressKeyPress=true;await Search();}};
   var account=Row();account.Controls.Add(Button("Ganti pekerjaan",delegate {jobSearch.Visible=!jobSearch.Visible;}));account.Controls.Add(Button("Keluar",async delegate {await Logout();}));data.Controls.Add(account);
   var actions=Row();actions.Controls.Add(Button("Cari / Muat ulang",async delegate {await Search();}));jobSearch.Controls.Add(actions);
   jobSearch.Controls.Add(jobs);data.Controls.Add(jobSearch);data.Controls.Add(currentJob);jobs.SelectedIndexChanged+=async(s,e)=>{if(jobs.SelectedItem!=null)await Detail((JobItem)jobs.SelectedItem);};
   AddLabel(data,"Bentuk hasil untuk Word");outputMode.Items.AddRange(new object[]{"Narasi penghadap","Transaksi PPAT","Field pilihan"});outputMode.SelectedIndex=0;data.Controls.Add(outputMode);
   AddLabel(data,"Penghadap (pilih satu)");data.Controls.Add(people);
   AddLabel(data,"Sapaan untuk narasi");salutation.Items.AddRange(new object[]{"Sesuai nama","Tuan","Nyonya","Nona","Tanpa sapaan"});salutation.SelectedIndex=0;data.Controls.Add(salutation);
   people.SelectedIndexChanged+=(s,e)=>RefreshFields();outputMode.SelectedIndexChanged+=(s,e)=>RefreshFields();salutation.SelectedIndexChanged+=(s,e)=>UpdatePreview();
   data.Controls.Add(terbilang);terbilang.CheckedChanged+=(s,e)=>UpdatePreview();
   AddLabel(data,"Pilih data yang disertakan");data.Controls.Add(fields);fields.ItemCheck+=(s,e)=>{BeginInvoke((Action)UpdatePreview);};
   var choices=Row();choices.Controls.Add(Button("Pilih semua",delegate {for(int i=0;i<fields.Items.Count;i++)fields.SetItemChecked(i,true);}));
   choices.Controls.Add(Button("Kosongkan",delegate {for(int i=0;i<fields.Items.Count;i++)fields.SetItemChecked(i,false);}));data.Controls.Add(choices);
   labels.CheckedChanged+=(s,e)=>UpdatePreview();data.Controls.Add(labels);
   insert=Button("Masukkan ke posisi kursor",delegate {try {InsertIntoWord(app,preview.Text,BoldNameForOutput());status.Text="Data dimasukkan. Anda bisa memasukkan data lagi tanpa menutup panel.";}catch(Exception ex){status.Text=ex.Message;}});
   insert.BackColor=Color.FromArgb(30,91,206);insert.ForeColor=Color.White;insert.FlatStyle=FlatStyle.Flat;
   AddLabel(data,"Data masuk ke dokumen aktif, setelah kursor/seleksi. Periksa draf sebelum digunakan.");stack.Controls.Add(data);
   status.AutoSize=false;status.Dock=DockStyle.Bottom;status.Height=50;
   preview.Dock=DockStyle.Top;preview.Height=82;insert.Dock=DockStyle.Top;insert.Height=40;
   footer.Controls.Add(status);footer.Controls.Add(insert);footer.Controls.Add(preview);Controls.Add(footer);
   preview.Visible=insert.Visible=false;
   Resize+=(s,e)=>{int w=Math.Max(235,ClientSize.Width-48);foreach(var c in new Control[]{login,data,jobSearch,server,email,password,otp,query,jobs,fields,preview,outputMode,people,salutation})c.Width=w;status.MaximumSize=new Size(w,0);currentJob.MaximumSize=new Size(w,0);};
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
  void Authorized(bool yes=true){login.Visible=!yes;data.Visible=yes;preview.Visible=insert.Visible=yes;footer.Height=yes?195:65;if(!yes){jobs.Items.Clear();ClearDetail();}}
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
   var rows=(object[])await api.Call("/auth/word/jobs?q="+Uri.EscapeDataString(q));jobs.Items.Clear();ClearDetail();
   foreach(Dictionary<string,object> row in rows)jobs.Items.Add(new JobItem {Id=Convert.ToString(row["id"]),Title=Convert.ToString(row["title"])});
   status.Text=rows.Length==0?"Belum ada pekerjaan yang sesuai atau menjadi milik Anda.":"Pilih pekerjaan. Asisten hanya melihat pekerjaan miliknya.";
  }
  void ClearDetail(){allFields.Clear();fields.Items.Clear();people.Items.Clear();preview.Clear();currentJob.Visible=false;jobSearch.Visible=true;}
  async Task Detail(JobItem job){await Run(async()=>{ClearDetail();var value=(Dictionary<string,object>)await api.Call("/auth/word/jobs/"+Uri.EscapeDataString(job.Id));
   foreach(Dictionary<string,object> field in (object[])value["fields"])allFields.Add(new FieldItem {Key=Convert.ToString(field["key"]),Label=Convert.ToString(field["label"]),Value=Convert.ToString(field["value"])});
   currentJob.Text=Convert.ToString(value["title"]);currentJob.Visible=true;jobSearch.Visible=false;LoadPeople();status.Text=Convert.ToString(value["title"])+" - pilih penghadap dan periksa pratinjau (bisa diedit).";
  });}
  void LoadPeople(){filtering=true;people.Items.Clear();foreach(var person in DraftText.People(allFields))people.Items.Add(person);people.Items.Add(new PersonItem {Prefix="",Name="Data pekerjaan"});people.SelectedIndex=0;filtering=false;RefreshFields();}
  bool NarrativeMode(){return Convert.ToString(outputMode.SelectedItem)=="Narasi penghadap";}
  void RefreshFields(){if(filtering)return;filtering=true;
   try{
    fields.Items.Clear();var person=people.SelectedItem as PersonItem;string prefix=person==null?"":person.Prefix;
    bool narrative=NarrativeMode(),transaction=Convert.ToString(outputMode.SelectedItem)=="Transaksi PPAT";
    people.Enabled=!transaction;salutation.Enabled=narrative;labels.Visible=!narrative;terbilang.Visible=!narrative;
    IEnumerable<FieldItem> list;
    if(transaction)list=allFields.Any(f=>f.Key=="jenis_pekerjaan"&&f.Value.Equals("PPAT",StringComparison.OrdinalIgnoreCase))?allFields.Where(f=>!(f.Key??"").StartsWith("penghadap_")):Enumerable.Empty<FieldItem>();
    else if(prefix=="")list=narrative?Enumerable.Empty<FieldItem>():allFields.Where(f=>!(f.Key??"").StartsWith("penghadap_"));
    else list=narrative?DraftText.PersonFields(allFields,prefix).Where(f=>!new[]{"jenis_client","jenis_kelamin","status_perkawinan","agama","kedudukan"}.Contains(f.Key.Substring(prefix.Length))):allFields.Where(f=>(f.Key??"").StartsWith(prefix,StringComparison.Ordinal));
    foreach(var field in list)fields.Items.Add(field,!transaction||DraftText.IsMoney(field.Key));
   }finally{filtering=false;}UpdatePreview();
  }
  List<FieldItem> ChosenFields(){var chosen=fields.CheckedItems.Cast<FieldItem>().ToList();var person=people.SelectedItem as PersonItem;
   if(person!=null&&person.Prefix!=""){var kind=allFields.FirstOrDefault(f=>f.Key==person.Prefix+"jenis_client");if(kind!=null&&!chosen.Contains(kind))chosen.Add(kind);}return chosen;
  }
  string SelectedText(){
   if(NarrativeMode()){var person=people.SelectedItem as PersonItem;if(person==null||person.Prefix=="")return "";return DraftText.Narrative(ChosenFields(),person.Prefix,Convert.ToString(salutation.SelectedItem));}
   return String.Join("\r\n",fields.CheckedItems.Cast<FieldItem>().Select(f=>{string value=DraftText.IsMoney(f.Key)?DraftText.Rupiah(f.Value,terbilang.Checked):f.Value;return labels.Checked?f.DisplayLabel+": "+value:value;}));
  }
  string BoldNameForOutput(){var person=people.SelectedItem as PersonItem;return NarrativeMode()&&person!=null?DraftText.BoldName(ChosenFields(),person.Prefix):null;}
  void UpdatePreview(){if(IsDisposed||filtering)return;try{preview.Text=SelectedText();}catch(ArgumentException ex){preview.Clear();status.Text=ex.Message;}}
  async Task Logout(){await Run(async()=>{string message="Anda sudah keluar. Login kembali untuk mengakses pekerjaan.";
   try{if(api!=null&&api.Token!="")await api.Call("/auth/word/session",null,"DELETE");}catch{message="Sesi lokal dihapus. Server belum dapat dihubungi untuk mencabut token.";}
   finally{SessionVault.Delete();if(api!=null){api.Token="";api.Dispose();api=null;}challenge="";password.Clear();otp.Clear();loginButton.Text="Login";Authorized(false);}status.Text=message;
  });}
  public static void InsertIntoWord(dynamic app,string text,string boldName=null){if(String.IsNullOrWhiteSpace(text)||text.Length>100000)throw new InvalidOperationException("Pilih data yang akan dimasukkan (maksimum 100.000 karakter).");
   if(app==null||app.Documents.Count==0)throw new InvalidOperationException("Buka dokumen Word terlebih dahulu.");dynamic doc=app.ActiveDocument;
   if(doc.ReadOnly||(int)doc.ProtectionType!=-1)throw new InvalidOperationException("Dokumen terkunci atau hanya baca.");dynamic target=app.Selection.Range.Duplicate;target.Collapse(0);
   app.UndoRecord.StartCustomRecord("Masukkan data SIMANIS");try{
    string normalized=text.Replace("\r\n","\r").Replace("\n","\r");int start=target.Start;dynamic inserted=target.Duplicate;target.InsertAfter(normalized);
    if(!String.IsNullOrEmpty(boldName)){int index=normalized.IndexOf(boldName,StringComparison.Ordinal);if(index>=0){inserted.SetRange(start,start+normalized.Length);inserted.Font.Bold=0;dynamic nameRange=inserted.Duplicate;nameRange.SetRange(start+index,start+index+boldName.Length);nameRange.Font.Bold=-1;}}
    target.Collapse(0);target.Select();}finally{app.UndoRecord.EndCustomRecord();}
  }
#if INTEGRATION_TEST
  public void ShowFixture(){ClearDetail();
   allFields.Add(new FieldItem {Key="jenis_pekerjaan",Label="Jenis pekerjaan",Value="PPAT"});
   allFields.Add(new FieldItem {Key="harga_transaksi",Label="Harga transaksi",Value="125000000"});
   allFields.Add(new FieldItem {Key="penghadap_1.nama_client",Label="Nama",Value="Ny. SITI CONTOH"});
   allFields.Add(new FieldItem {Key="penghadap_1.tempat_tgl_lahir",Label="Tempat/tanggal lahir",Value="Medan, 30-08-1987"});
   allFields.Add(new FieldItem {Key="penghadap_1.no_identitas",Label="NIK",Value="0000000000000001"});
   allFields.Add(new FieldItem {Key="penghadap_2.nama_client",Label="Nama",Value="BUDI CONTOH"});
   currentJob.Text="Contoh pekerjaan PPAT";currentJob.Visible=true;jobSearch.Visible=false;LoadPeople();Authorized();status.Text="Data sintetis untuk pemeriksaan tampilan.";
  }
  public string TestPreview(){return preview.Text;}
  public void TestPickPerson(int index){people.SelectedIndex=index;}
  public void TestMode(string value){outputMode.SelectedItem=value;}
  public void TestInsertPreview(){insert.PerformClick();}
#endif
  protected override void Dispose(bool disposing){if(disposing){SessionVault.Cleared-=SessionCleared;if(api!=null)api.Dispose();}base.Dispose(disposing);}
 }
}
