using System;
using System.Collections.Generic;
using System.Linq;
using Simanis.Word;
class DraftTests {
 static void Check(bool ok,string message){if(!ok)throw new Exception(message);}
 static FieldItem F(string key,string value){return new FieldItem {Key=key,Label=key,Value=value};}
 static void Main(){
  foreach(var item in new Dictionary<long,string>{{0,"nol"},{11,"sebelas"},{19,"sembilan belas"},{100,"seratus"},{101,"seratus satu"},{1000,"seribu"},{1001,"seribu satu"},{1100,"seribu seratus"},{1987,"seribu sembilan ratus delapan puluh tujuh"},{1000000,"satu juta"},{1000000000,"satu miliar"},{1000000000000,"satu triliun"}})Check(DraftText.Words(item.Key)==item.Value,"Words: "+item.Key);
  Check(DraftText.Rupiah("125000000",true)=="Rp125.000.000,00 (seratus dua puluh lima juta rupiah)","Whole rupiah");
  Check(DraftText.Rupiah("1234.56",true)=="Rp1.234,56 (seribu dua ratus tiga puluh empat rupiah lima puluh enam sen)","Sen precision");
  Check(DraftText.Rupiah("0",true)=="Rp0,00 (nol rupiah)","Zero");
  Check(DraftText.Rupiah("-11.01",true)=="-Rp11,01 (minus sebelas rupiah satu sen)","Negative");
  Check(DraftText.Rupiah("125000000",false)=="Rp125.000.000,00","Toggle");
  foreach(var invalid in new[]{"1.234,56","1.001","not money","1000000000000000","-79228162514264337593543950335"}){bool rejected=false;try{DraftText.Rupiah(invalid,true);}catch(ArgumentException){rejected=true;}Check(rejected,"Invalid amount accepted: "+invalid);}
  Check(!DraftText.IsMoney("penghadap_1.no_identitas")&&!DraftText.IsMoney("no_hak_milik"),"Identity converted as money");
  string p="penghadap_1.";
  var fields=new List<FieldItem>{F(p+"nama_client","Siti Contoh"),F(p+"tempat_tgl_lahir","Medan, 30-08-1987"),F(p+"pekerjaan","Wiraswasta"),F(p+"kewarganegaraan","WNI"),F(p+"provinsi","DKI Jakarta"),F(p+"alamat","Jalan Contoh 25"),F(p+"rt_rw","007/010"),F(p+"kel_desa","Sunter Agung"),F(p+"kecamatan","Tanjung Priok"),F(p+"no_identitas","0000000000000001"),F("penghadap_2.nama_client","Budi Contoh"),F(p+"mewakili.nama_client","Perseroan Contoh")};
  var own=DraftText.PersonFields(fields,p);string text=DraftText.Narrative(own,p,"Nyonya");
  Check(text.StartsWith("Nyonya SITI CONTOH, lahir di Medan, pada tanggal 30-08-1987 (tiga puluh Agustus seribu sembilan ratus delapan puluh tujuh)"),"Birth narrative");
  Check(text.Contains("Warga Negara Indonesia")&&text.Contains("Rukun Tetangga 007, Rukun Warga 010")&&text.Contains("Nomor Induk Kependudukan 0000000000000001"),"Address/identity preservation");
  Check(!text.Contains("BUDI")&&!text.Contains("Penghadap")&&!text.Contains("Perseroan"),"Another participant/representation leaked");
  Check(DraftText.People(fields).Count==2,"Participant grouping");
  Check(DraftText.Narrative(new[]{F(p+"nama_client","Budi")},p,"Sesuai nama")=="BUDI.","Missing data invented");
  Check(DraftText.Narrative(new[]{F(p+"nama_client","Ny. Siti")},p,"Sesuai nama")=="Nyonya SITI.","Stored salutation");
  Check(DraftText.Narrative(new[]{F(p+"nama_client","Siti")},p,"Sesuai nama")=="SITI.","Invented marital salutation");
  Check(DraftText.Narrative(new[]{F(p+"nama_client","PT Contoh"),F(p+"jenis_client","Badan Hukum"),F(p+"no_identitas","0000000000000001")},p,"Nyonya")=="PT CONTOH, pemegang identitas dengan nomor 0000000000000001.","Legal entity misidentified as person");
  Check(DraftText.Birth("Jakarta, 29-02-2000").Contains("dua puluh sembilan Februari dua ribu"),"Leap date");
  Check(DraftText.Birth("Jakarta, 31-02-2000")=="","Invalid date fabricated");
  Check(DraftText.Birth("1987-08-30").StartsWith("lahir pada tanggal 30-08-1987"),"ISO birthday");
  Console.WriteLine("DRAFT_NARRATIVE_PARTICIPANTS_DATES_RUPIAH_PRECISION_OK");
 }
}
