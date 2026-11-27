using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Text.RegularExpressions;
namespace Simanis.Word {
 public sealed class PersonItem {
  public string Prefix,Name;
  public override string ToString(){return Name;}
 }
 public static class DraftText {
  static readonly string[] Small={"nol","satu","dua","tiga","empat","lima","enam","tujuh","delapan","sembilan","sepuluh","sebelas"};
  static readonly string[] Months={"","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"};
  static readonly HashSet<string> MoneyKeys=new HashSet<string>{"harga_transaksi","harga_njop","harga_bphtb","harga_pph"};
  public static bool IsMoney(string key){return MoneyKeys.Contains(key??"");}
  public static string Words(long n){
   if(n<0){if(n==long.MinValue)throw new ArgumentOutOfRangeException("n");return "minus "+Words(-n);}
   if(n<12)return Small[n];if(n<20)return Words(n-10)+" belas";
   if(n<100)return Words(n/10)+" puluh"+(n%10==0?"":" "+Words(n%10));
   if(n<200)return "seratus"+(n==100?"":" "+Words(n-100));
   if(n<1000)return Words(n/100)+" ratus"+(n%100==0?"":" "+Words(n%100));
   if(n<2000)return "seribu"+(n==1000?"":" "+Words(n-1000));
   foreach(var unit in new[]{Tuple.Create(1000000000000L,"triliun"),Tuple.Create(1000000000L,"miliar"),Tuple.Create(1000000L,"juta"),Tuple.Create(1000L,"ribu")})
    if(n>=unit.Item1)return Words(n/unit.Item1)+" "+unit.Item2+(n%unit.Item1==0?"":" "+Words(n%unit.Item1));
   throw new ArgumentOutOfRangeException("n");
  }
  // API amounts use invariant dot decimals. This deliberately never interprets a NIK/date as money.
  public static string Rupiah(string raw,bool withWords){
   decimal amount;
   if(!decimal.TryParse(raw,NumberStyles.AllowLeadingSign|NumberStyles.AllowDecimalPoint,CultureInfo.InvariantCulture,out amount)||(amount < -999999999999999.99m || amount > 999999999999999.99m)||decimal.Truncate(amount*100)!=amount*100)
    throw new ArgumentException("Nominal tidak valid atau lebih dari dua angka desimal. Periksa data PPAT.");
   decimal absolute=Math.Abs(amount);long whole=(long)decimal.Truncate(absolute);int cents=(int)((absolute-whole)*100);
   string result=(amount<0?"-":"")+"Rp"+absolute.ToString("N2",CultureInfo.GetCultureInfo("id-ID"));
   if(withWords)result+=" ("+(amount<0?"minus ":"")+Words(whole)+" rupiah"+(cents==0?"":" "+Words(cents)+" sen")+")";
   return result;
  }
  public static List<PersonItem> People(IEnumerable<FieldItem> fields){
   return fields.Where(f=>Regex.IsMatch(f.Key??"",@"^penghadap_\d+\.nama_client$")).Select(f=>new PersonItem {Prefix=f.Key.Substring(0,f.Key.Length-"nama_client".Length),Name=f.Value}).ToList();
  }
  public static List<FieldItem> PersonFields(IEnumerable<FieldItem> fields,string prefix){
   return fields.Where(f=>(f.Key??"").StartsWith(prefix,StringComparison.Ordinal)&&!f.Key.Substring(prefix.Length).StartsWith("mewakili.",StringComparison.Ordinal)).ToList();
  }
  public static string Name(IEnumerable<FieldItem> selected,string prefix,string salutation){
   string raw=Value(selected,prefix+"nama_client");if(raw=="")return "";
   var existing=Regex.Match(raw,@"^(Tuan|Nyonya|Nona|Tn\.?|Ny\.?|Nn\.?)\s+",RegexOptions.IgnoreCase);
   string stored="";
   if(existing.Success){string title=existing.Groups[1].Value.ToLowerInvariant().TrimEnd('.');stored=title=="tn"||title=="tuan"?"Tuan":title=="ny"||title=="nyonya"?"Nyonya":"Nona";raw=raw.Substring(existing.Length);}
   string titleText=salutation=="Sesuai nama"?stored:salutation=="Tanpa sapaan"?"":salutation;
   return (titleText==""?"":titleText+" ")+raw.ToUpperInvariant();
  }
  public static string BoldName(IEnumerable<FieldItem> selected,string prefix){
   return Regex.Replace(Value(selected,prefix+"nama_client"),@"^(Tuan|Nyonya|Nona|Tn\.?|Ny\.?|Nn\.?)\s+","",RegexOptions.IgnoreCase).ToUpperInvariant();
  }
  static string Value(IEnumerable<FieldItem> fields,string key){var field=fields.FirstOrDefault(f=>f.Key==key);return field==null?"":Regex.Replace((field.Value??"").Trim(),@"\s+"," ");}
  static string PrefixLabel(string label,string raw){return Regex.IsMatch(raw,@"^"+Regex.Escape(label)+@"\b",RegexOptions.IgnoreCase)?raw:label+" "+raw;}
  public static string Birth(string raw){
   if(String.IsNullOrWhiteSpace(raw))return "";
   var match=Regex.Match(raw,@"(?<date>\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{4})\s*$");
   if(!match.Success){if(Regex.IsMatch(raw,@"\d"))return "";return "lahir di "+raw.Trim(' ',',');}
   DateTime date;
   if(!DateTime.TryParseExact(match.Groups["date"].Value,new[]{"yyyy-M-d","yyyy/M/d","d-M-yyyy","d/M/yyyy","d.M.yyyy"},CultureInfo.InvariantCulture,DateTimeStyles.None,out date))return "";
   string place=raw.Substring(0,match.Index).Trim(' ',',',';','-');
   return "lahir "+(place==""?"":"di "+place+", ")+"pada tanggal "+date.ToString("dd-MM-yyyy",CultureInfo.InvariantCulture)+" ("+Words(date.Day)+" "+Months[date.Month]+" "+Words(date.Year)+")";
  }
  public static string Narrative(IEnumerable<FieldItem> fields,string prefix,string salutation){
   var values=fields.ToList();bool isCompany=Value(values,prefix+"jenis_client").Equals("Badan Hukum",StringComparison.OrdinalIgnoreCase);string name=Name(values,prefix,isCompany?"Tanpa sapaan":salutation);if(name=="")return "";
   Func<string,string> get=k=>Value(values,prefix+k);var clauses=new List<string>{name};
   bool company=get("jenis_client").Equals("Badan Hukum",StringComparison.OrdinalIgnoreCase);
   if(!company){
    string birth=Birth(get("tempat_tgl_lahir"));if(get("tempat_tgl_lahir")!=""&&birth=="")throw new ArgumentException("Tanggal lahir tidak dikenali. Perbaiki data client atau hilangkan centang tanggal lahir lalu tinjau narasi.");if(birth!="")clauses.Add(birth);
    if(get("pekerjaan")!="")clauses.Add(get("pekerjaan"));
    string citizen=get("kewarganegaraan");string normalized=Regex.Replace(citizen.ToUpperInvariant(),@"\s+","");
    if(citizen!="")clauses.Add(normalized=="WNI"||normalized=="WARGANEGARAINDONESIA"?"Warga Negara Indonesia":normalized=="WNA"||normalized=="WARGANEGARAASING"?"Warga Negara Asing":citizen);
   }
   string street=get("alamat");if(street=="")street=get("alamat_client");
   var address=new List<string>();
   Action<string> add=part=>{if(part!=""&&!address.Any(p=>p.Equals(part,StringComparison.OrdinalIgnoreCase)))address.Add(part);};
   foreach(var region in new[]{Tuple.Create("provinsi","Provinsi"),Tuple.Create("kabupaten_kota","")}){
    string value=get(region.Item1);if(value!=""&&street.IndexOf(value,StringComparison.OrdinalIgnoreCase)<0)add(region.Item2==""?value:PrefixLabel(region.Item2,value));
   }
   add(street);
   string rt=get("rt_rw");if(rt!=""&&street.IndexOf(rt,StringComparison.OrdinalIgnoreCase)<0){var parts=Regex.Match(rt,@"^(?:RT\.?\s*)?(\d+)\s*[/,\-]\s*(?:RW\.?\s*)?(\d+)$",RegexOptions.IgnoreCase);add(parts.Success?"Rukun Tetangga "+parts.Groups[1].Value+", Rukun Warga "+parts.Groups[2].Value:"RT/RW "+rt);}
   foreach(var region in new[]{Tuple.Create("kel_desa","Kelurahan/Desa"),Tuple.Create("kecamatan","Kecamatan")}){
    string value=get(region.Item1);if(value!=""&&street.IndexOf(value,StringComparison.OrdinalIgnoreCase)<0){
     if(region.Item1=="kel_desa"&&Regex.IsMatch(value,@"^(Desa|Kelurahan)\b",RegexOptions.IgnoreCase))add(value);else add(PrefixLabel(region.Item2,value));
    }
   }
   if(address.Count>0)clauses.Add((company?"beralamat di ":"bertempat tinggal di ")+String.Join(", ",address));
   string identity=get("no_identitas");
   if(identity!="")clauses.Add(!company&&Regex.IsMatch(identity,@"^\d{16}$")?"pemegang Kartu Tanda Penduduk dengan Nomor Induk Kependudukan "+identity:"pemegang identitas dengan nomor "+identity);
   return String.Join(", ",clauses)+".";
  }
 }
}
