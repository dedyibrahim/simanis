# Historical SIGANDI licensed-edition guide only.
# Standalone SIMANIS packages use word-addin/PANDUAN.txt and SIMANIS-DATA.md.
"""Build the illustrated Indonesian desktop and Word guide for SIGANDI 1.4.8."""
from pathlib import Path
from xml.sax.saxutils import escape

from reportlab.pdfgen import canvas
from reportlab.lib.colors import HexColor, Color, white
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.platypus import Paragraph
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.lib.utils import ImageReader
from reportlab.graphics.barcode import qr
from reportlab.graphics.shapes import Drawing
from reportlab.graphics import renderPDF

ROOT = Path(__file__).resolve().parents[1]
VERSION = '1.4.8'
TOTAL_PAGES = 20
OUT = ROOT / f'output/pdf/Panduan SIGANDI v{VERSION}.pdf'
OUT.parent.mkdir(parents=True, exist_ok=True)
FONTS = Path('C:/Windows/Fonts')
for name, filename in [('UI', 'segoeui.ttf'), ('UI-Bold', 'segoeuib.ttf'), ('UI-Light', 'segoeuil.ttf')]:
    pdfmetrics.registerFont(TTFont(name, str(FONTS / filename)))
pdfmetrics.registerFontFamily('UI', normal='UI', bold='UI-Bold', italic='UI', boldItalic='UI-Bold')
W, H = A4
M = 44
CW = W - 2*M
GREEN = '#16845b'
FOREST = '#123b2e'
INK = '#203d31'
MUTED = '#60766a'
PALE = '#eef6f1'
LINE = '#d8e6dd'
LOGO = ImageReader(str(ROOT / 'assets/app_icon.png'))
c = canvas.Canvas(str(OUT), pagesize=A4, pageCompression=1)
c.setTitle(f'Panduan Instalasi dan Penggunaan SIGANDI v{VERSION}')
c.setAuthor('Radina.net')
c.setSubject('Panduan pengguna Windows: instalasi, aktivasi, pengaturan garis, PDF dan pencetakan')
c.setCreator('SIGANDI - Radina.net')
page_number = 0
bottoms = []

def rect(x, top, width, height, fill, radius=0, stroke=None):
    c.setFillColor(HexColor(fill))
    if stroke:
        c.setStrokeColor(HexColor(stroke))
    if radius:
        c.roundRect(x, H-top-height, width, height, radius, fill=1, stroke=bool(stroke))
    else:
        c.rect(x, H-top-height, width, height, fill=1, stroke=bool(stroke))

def line(x1, top1, x2, top2, color=LINE, width=1):
    c.setStrokeColor(HexColor(color))
    c.setLineWidth(width)
    c.line(x1,H-top1,x2,H-top2)

def text(value, x, top, size=10, font='UI', color=INK, align='left'):
    c.setFont(font,size)
    c.setFillColor(HexColor(color))
    method = {'left':c.drawString,'right':c.drawRightString,'center':c.drawCentredString}[align]
    method(x,H-top-size*0.83,value)

def p(value, top, x=M, width=CW, size=10.4, color=INK, bold=False, gap=10, leading=None):
    style = ParagraphStyle('body', fontName='UI-Bold' if bold else 'UI', fontSize=size,
                           leading=leading or size*1.49, textColor=HexColor(color))
    paragraph = Paragraph(value,style)
    _, height = paragraph.wrap(width, H)
    assert top+height <= H-57, (page_number, value[:65], top, height)
    paragraph.drawOn(c,x,H-top-height)
    bottoms.append((page_number,top+height))
    return top+height+gap

def small_heading(value, top, x=M, width=CW):
    return p(value,top,x,width,size=13.2,bold=True,gap=9)

def note(title, body, top, x=M, width=CW):
    # Short instruction strip with a clear label; no dense boxed prose.
    line(x,top,x,top+43,GREEN,2)
    y=p(title,top,x+13,width-13,size=10.2,bold=True,gap=4)
    return p(body,y,x+13,width-13,size=9.6,color=MUTED,gap=15)

def badge(value, x, top, fill=GREEN, size=22):
    rect(x,top,size,size,fill,radius=size/2)
    text(str(value),x+size/2,top+(size-11)/2-0.5,11,'UI-Bold','#ffffff','center')

def step(number, title, body, top, x=M, width=CW):
    badge(number,x,top+1)
    y=p(title,top,x+35,width-35,size=11.3,bold=True,gap=4)
    return p(body,y,x+35,width-35,size=10.2,gap=16)

def chip(label,x,top,width=None,fill=PALE,color=GREEN):
    width=width or pdfmetrics.stringWidth(label,'UI-Bold',9)+22
    rect(x,top,width,23,fill,radius=5)
    text(label,x+width/2,top+6,9,'UI-Bold',color,'center')
    return width

def bullet(body,top,x=M,width=CW):
    rect(x,top+6,4,4,GREEN,radius=2)
    return p(body,top,x+15,width-15,size=10.2,gap=9)

def start(section,title,description):
    global page_number
    page_number += 1
    c.bookmarkPage(f'p{page_number}')
    c.addOutlineEntry(title,f'p{page_number}',level=0,closed=False)
    rect(0,0,W,H,'#ffffff')
    c.drawImage(LOGO,M, H-47,20,20,mask='auto')
    text('SIGANDI',M+28,29,11,'UI-Bold',FOREST)
    text(f'PANDUAN PENGGUNA  /  v{VERSION}',W-M,31,8,'UI',MUTED,'right')
    line(M,62,W-M,62)
    text(section.upper(),M,82,9,'UI-Bold',GREEN)
    y=p(title,105,size=27,bold=True,gap=12,leading=31)
    y=p(description,y,size=10.5,color=MUTED,gap=24)
    return y

def finish():
    line(M,H-43,W-M,H-43)
    text(f'Radina.net  |  SIGANDI v{VERSION}',M,H-29,8,'UI',MUTED)
    text('Daftar isi',W-M-40,H-29,8,'UI',GREEN,'right')
    c.linkRect('', 'p2',(W-M-105,14,W-M-38,38),relative=0,thickness=0)
    text(f'{page_number:02d}',W-M,H-31,11,'UI-Bold',GREEN,'right')
    c.showPage()

def doc_lines(x,top,width,count=6,pitch=13,color='#92ab9b'):
    for i in range(count):
        fraction=[1,.89,.95,.76,.96,.65][i%6]
        line(x,top+i*pitch,x+width*fraction,top+i*pitch,color,.8)

def arrow(x1,top1,x2,top2,color=GREEN):
    line(x1,top1,x2,top2,color,1.4)
    import math
    angle=math.atan2(top2-top1,x2-x1)
    for a in (angle+2.65,angle-2.65):
        line(x2,top2,x2+6*math.cos(a),top2+6*math.sin(a),color,1.4)

def table(headers, rows, top, widths, x=M, size=9.7):
    def measure(value,width,bold=False):
        sty=ParagraphStyle('cell',fontName='UI-Bold' if bold else 'UI',fontSize=size,
                           leading=size*1.38,textColor=white if bold else HexColor(INK))
        obj=Paragraph(value,sty)
        _,height=obj.wrap(width-22,H)
        return obj,height
    y=top
    for row_number,row in enumerate([headers]+rows):
        cells=[measure(v,w,row_number==0) for v,w in zip(row,widths)]
        height=max(h for obj,h in cells)+20
        assert y+height < H-57, (page_number,'table overflow',y,height)
        rect(x,y,sum(widths),height,FOREST if row_number==0 else ('#f1f7f3' if row_number%2 else '#ffffff'))
        xx=x
        for (obj,h),width in zip(cells,widths):
            obj.drawOn(c,xx+11,H-y-(height-h)/2-h)
            xx+=width
        line(x,y+height,x+sum(widths),y+height,LINE,.6)
        y+=height
    return y+18

# 01 / Cover
page_number=1
c.bookmarkPage('p1')
c.addOutlineEntry('Panduan SIGANDI','p1',0,False)
rect(0,0,W,H,FOREST)
rect(W-108,0,108,H,'#164a37')
for i in range(7):
    line(W-84+i*10,0,W-84+i*10,H,'#20543f',.4)
c.drawImage(LOGO,M,H-91,42,42,mask='auto')
text('SIGANDI',M+56,49,23,'UI-Bold','#ffffff')
text('Sistem Garis Akta Notaris Digital',M+57,80,9,'UI','#bed8c9')
chip(f'PANDUAN PENGGUNA  /  v{VERSION}',M,144,width=226,fill='#215641',color='#d9efdf')
text('Panduan',M,210,48,'UI-Light','#ffffff')
text('Instalasi dan',M,271,42,'UI-Bold','#ffffff')
text('Penggunaan',M,325,42,'UI-Bold','#ffffff')
text('Desktop Windows + Add-in Microsoft Word 0.4.9',M,396,12,'UI','#c3dece')
# A native vector document illustration, deliberately using generic text.
rect(338,460,172,213,'#0c2f22',radius=8)
rect(327,447,172,213,'#ffffff',radius=7)
text('AKTA CONTOH',413,469,9,'UI-Bold',FOREST,'center')
doc_lines(357,501,118,8,14)
for i, fraction in enumerate([1,.89,.95,.76,.96,.65,1,.89]):
    start_x=357+118*fraction+3
    if start_x<475:
        c.setDash(2,1)
        line(start_x,501+i*14,475,501+i*14,'#111111',.7)
        c.setDash()
line(347,494,347,611,GREEN,1.9)
line(347,494,375,487,GREEN,1.9)
line(347,611,375,618,GREEN,1.9)
text('Notaris di Kota Contoh',413,630,6.6,'UI',MUTED,'center')
for i,(n,label) in enumerate([('01','Pasang aplikasi'),('02','Isi garis dan baris kosong'),('03','Periksa dan simpan PDF')]):
    yy=480+i*55
    text(n,M,yy,11,'UI-Bold','#72c89c')
    text(label,M+28,yy,10.5,'UI','#e4f1e9')
rect(0,H-114,W,114,'#f2f7f3')
text('RADINA.NET',M,H-86,10,'UI-Bold',GREEN)
text('Petunjuk langkah demi langkah',M,H-62,16,'UI-Bold',FOREST)
text(f'Edisi 1 November 2026  |  {TOTAL_PAGES} halaman',M,H-33,9,'UI',MUTED)
c.showPage()

# 02 / Quick start and clickable contents
y=start('Mulai di sini','Alur kerja singkat','Ikuti urutan ini untuk menghasilkan PDF bergaris yang siap diperiksa dan dicetak.')
steps=[('Buka dokumen','Pilih PDF asli atau dokumen Word.'),('Buat garis','Garis vertikal, lalu Isi ---- bila diperlukan.'),('Periksa hasil','Tinjau isi, footer, dan tanda tangan.'),('Simpan PDF','Simpan dengan nama baru, lalu buka hasilnya.')]
for i,(title,body) in enumerate(steps):
    xx=M+i*(CW/4)
    badge(i+1,xx,y)
    yy=p(title,y+34,xx,CW/4-15,size=10.3,bold=True,gap=6)
    p(body,yy,xx,CW/4-15,size=9.2,color=MUTED,gap=0)
    if i<3:
        arrow(xx+35,y+11,xx+CW/4-20,y+11,'#92b7a1')
y+=126
y=note('Yang baru pada v1.4.8','Shortcut Ctrl+F6-F11 tampil pada menu Word. Garis tabel berjarak 6 pt dan sel tabel tidak diisi ----. <b>Internet diperlukan setiap menjalankan fitur berlisensi</b>; lisensi terhapus atau dicabut server ditolak.',y)
y=small_heading('Daftar isi',y)
contents=[('Instalasi aplikasi',3),('Aktivasi dan aturan lisensi',4),('Ruang kerja desktop',5),('Buka dokumen di desktop',6),('Isi ujung baris dengan ----',7),('Baris kosong dan footer',8),('Koreksi garis desktop',9),('Penutup dan tanda tangan',10),('Halaman dan booklet',11),('Simpan, kompres, cetak',12),('Solusi kendala desktop',13),('Pintasan dan bantuan',14),('Word: pemasangan add-in',15),('Word: alur membuat garis',16),('Word: warna dan penutup',17),('Word: margin akta',18),('Word: koreksi dan kendala',19),('Lisensi: status dan solusi',20)]
for i,(title,pg) in enumerate(contents):
    column=i//6
    xx=M+column*(CW/3+5)
    ww=CW/3-12
    yy=y+(i%6)*44
    text(f'{i+1:02d}',xx,yy+3,9,'UI-Bold',GREEN)
    p(title,yy+2,xx+23,ww-23,size=8.6,leading=11,gap=0)
    text(f'{pg:02d}',xx+ww,yy+29,8,'UI-Bold',GREEN,'right')
    line(xx,yy+40,xx+ww,yy+40)
    c.linkRect('',f'p{pg}',(xx,H-yy-42,xx+ww,H-yy+2),thickness=0)
y+=6*44+17
p(f'Panduan ini mengacu pada SIGANDI v{VERSION}. Semua gambar merupakan ilustrasi dengan data contoh. Klik daftar isi atau bookmark PDF untuk berpindah bagian.',y,size=9.5,color=MUTED)
finish()

# 03 / Installation
y=start('01 / Persiapan','Instalasi aplikasi','Simpan pekerjaan, lalu tutup SIGANDI dan semua jendela Microsoft Word sebelum menjalankan installer.')
y=small_heading('Yang perlu disiapkan',y)
for item in ['Komputer Windows 64-bit dan ruang penyimpanan untuk aplikasi serta dokumen.',
             'Lisensi SIGANDI dari Radina.net dan internet saat aktivasi serta menjalankan fitur berlisensi.',
             'Microsoft Word atau LibreOffice bila akan membuka DOC/DOCX. Membuka PDF tidak memerlukan konversi Word.']:
    y=bullet(item,y)
y+=8
y=step(1,'Buka file installer',f'Klik dua kali <b>GarisAktaEditor_Setup_v{VERSION}.exe</b> dari paket resmi yang Anda terima.',y)
y=step(2,'Ikuti petunjuk instalasi','Klik <b>Next</b> bila ditampilkan. Gunakan lokasi pemasangan bawaan, atau pilih lokasi yang sesuai.',y)
y=step(3,'Pilih pintasan desktop','Aktifkan <b>Create a desktop shortcut</b> jika ingin membuka aplikasi dari desktop.',y)
y=step(4,'Pasang desktop dan add-in Word','Klik <b>Install</b>, tunggu selesai, lalu klik <b>Finish</b>. Installer memasang desktop dan add-in Word untuk akun Windows pemasang. Buka Word kembali untuk melihat tab <b>SIGANDI</b>.',y)
y+=1
y=note('Ingin mencoba desktop tanpa instalasi?','Jalankan <b>GarisAktaEditor.exe</b> dari folder portabel. Lisensi dan internet tetap diperlukan. Menjalankan EXE portabel saja tidak otomatis memasang add-in Word.',y)
p(f'Memperbarui versi lama: simpan pekerjaan, tutup aplikasi, lalu pasang installer baru pada lokasi sebelumnya. Periksa tulisan <b>v{VERSION}</b> di kanan atas aplikasi agar tidak memakai jendela versi lama.',y,size=9.5,color=MUTED)
finish()

# 04 / Activation
y=start('02 / Akses aplikasi','Aktivasi lisensi','Siapkan key lisensi yang diberikan Radina.net. Contoh format di bawah hanya menunjukkan bentuk key, bukan key aktivasi.')
chip('XXXXX-XXXXX-XXXXX-XXXXX-XXXXX',M,y,width=340)
y+=45
y=step(1,'Hubungkan komputer ke internet','Buka SIGANDI. Pada penggunaan pertama, aplikasi akan menampilkan petunjuk aktivasi.',y)
y=step(2,'Masukkan key lisensi','Salin key dengan lengkap ke kolom <b>Aktivasi Lisensi</b>, lalu konfirmasi. Hindari karakter yang tertinggal saat menyalin.',y)
y=step(3,'Pastikan aktivasi berhasil','Tunggu pesan bahwa lisensi valid dan tersimpan. Bila ada tanggal berlaku, periksa tanggal tersebut.',y)
y=step(4,'Buka kembali menu lisensi bila perlu','Pilih tab <b>Dokumen</b> lalu tombol <b>Lisensi</b> untuk memasukkan atau memeriksa key melalui proses aktivasi.',y)
y+=6
y=small_heading('Setelah aktivasi',y)
y=p('Desktop dan Word memakai satu aktivasi pada komputer serta akun Windows yang sama. Sejak v1.4.5, server wajib menyetujui setiap tindakan berlisensi. Lisensi yang dihapus, dinonaktifkan, dicabut atau expired ditolak walaupun bukti aktivasi lama masih tersimpan.',y)
y=small_heading('Tentang dokumen dan koneksi',y+5)
y=p('Pengolahan dokumen berlangsung di komputer. Aktivasi dan pemeriksaan lisensi memakai internet. Bila server tidak dapat dihubungi, fitur berlisensi diblokir hingga pemeriksaan berhasil. Dokumen dan garis lama tetap ada. Lihat status serta solusi lisensi di halaman 20.',y)
note('Aktivasi atau mesin Word belum berhasil?','Periksa key dan internet bila aktivasi ditolak, lalu hubungi Radina.net jika tetap gagal. Untuk DOC/DOCX, pastikan Word atau LibreOffice dapat digunakan; pengguna PDF dapat memilih <b>Buka PDF</b>.',y)
finish()

# 05 / Workspace map
y=start('03 / Navigasi','Mengenal ruang kerja','Tombol utama selalu dekat dengan dokumen. Panel kiri membantu Anda meninjau dan menyusun halaman.')
ux=M; uy=y; uw=CW; uh=309
rect(ux,uy,uw,uh,'#eef4f0',radius=7,stroke=LINE)
rect(ux,uy,uw,25,FOREST)
text('SIGANDI',ux+12,uy+7,9,'UI-Bold','#ffffff')
text(f'v{VERSION}',ux+uw-10,uy+8,6.5,'UI','#bad8c7','right')
rect(ux,uy+25,uw,25,'#ffffff')
for i,t in enumerate(['Dokumen','Kelola Halaman','Pengaturan Garis']):
    text(t,ux+11+i*91,uy+34,7,'UI-Bold',GREEN)
text('Cetak',ux+uw-114,uy+34,7,'UI-Bold',GREEN)
rect(ux+uw-77,uy+28,69,19,GREEN,radius=3)
text('Simpan PDF',ux+uw-42,uy+34,7,'UI-Bold','#ffffff','center')
rect(ux,uy+50,uw,33,'#ffffff')
for i,t in enumerate(['Buka PDF','Buka Word','Lisensi','Panduan']):
    chip(t,ux+8+i*60,uy+56,width=56)
chip('Buat Garis',ux+uw-84,uy+56,width=75,fill=GREEN,color='#ffffff')
rect(ux+8,uy+93,90,uh-104,'#ffffff',radius=4)
text('Halaman',ux+17,uy+103,7.5,'UI-Bold')
for i in range(2):
    rect(ux+30,uy+124+i*80,45,61,'#f9fbfa',stroke=LINE)
    doc_lines(ux+38,uy+137+i*80,29,5,7)
rect(ux+107,uy+93,uw-115,24,'#ffffff',radius=3)
text('Akta_contoh.pdf',ux+117,uy+101,7,'UI-Bold')
text('1 / 2    -    +    Sesuaikan',ux+uw-15,uy+101,6.5,'UI',MUTED,'right')
rect(ux+209,uy+129,188,168,'#ffffff',radius=2)
text('AKTA CONTOH',ux+303,uy+141,7.4,'UI-Bold',FOREST,'center')
doc_lines(ux+231,uy+163,143,8,13)
line(ux+223,uy+158,ux+223,uy+263,GREEN,1.5)
line(ux+223,uy+158,ux+246,uy+152,GREEN,1.5)
for n,xx,yy in [(1,ux+274,uy+28),(2,ux+uw-29,uy+86),(3,ux+5,uy+145),(4,ux+uw-20,uy+120),(5,ux+385,uy+270)]:
    badge(n,xx,yy,size=18)
text('Skema antarmuka; ukuran dan jarak disederhanakan.',M,uy+uh+10,8.3,'UI',MUTED)
y=uy+uh+38
items=[('Tab alat','Pengaturan Garis memuat warna, Kemiringan, Tambah Garis, dan <b>Isi ----</b>.'),('Aksi utama','Buat Garis, Kompres PDF, Simpan PDF, dan Cetak.'),('Panel halaman','Klik thumbnail untuk berpindah; geser untuk menyusun ulang.'),('Navigasi dan zoom','Gunakan panah halaman, - / +, atau Sesuaikan.'),('Area editor','Klik garis vertikal untuk memilih, menggeser, atau mengubah panjangnya.')]
for i,(title,body) in enumerate(items):
    badge(i+1,M,y,size=18)
    y=p(f'<b>{title}</b> - {body}',y,M+29,CW-29,size=9.9,gap=12)
finish()

# 06 / Open and generate
y=start('04 / Proses utama','Buka dokumen dan buat garis','Mulai dari dokumen sumber yang bersih. Untuk mengubah redaksi akta, edit file Word asli, lalu muat kembali ke SIGANDI.')
col=(CW-26)/2
left=small_heading('Dari PDF',y,M,col)
left=p('<b>Dokumen &gt; Buka PDF</b><br/>Pilih PDF sumber, lalu tunggu halaman dan thumbnail muncul.',left,M,col)
right=small_heading('Dari Word',y,M+col+26,col)
right=p('<b>Dokumen &gt; Buka Word</b><br/>Pilih DOC atau DOCX. Tunggu konversi melalui Microsoft Word atau LibreOffice sampai PDF tampil.',right,M+col+26,col)
y=max(left,right)+12
line(M,y,W-M,y); y+=22
y=step(1,'Tampilkan halaman dengan nyaman','Klik <b>Sesuaikan</b> untuk menampilkan satu halaman. Gunakan <b>+</b> bila perlu melihat posisi garis dengan lebih dekat.',y)
y=step(2,'Periksa garis vertikal otomatis','Garis dapat langsung tampil saat dokumen dibuka. Bila perlu menghitung ulang, klik <b>Buat Garis</b> di kanan toolbar dan tunggu seluruh halaman selesai.',y)
y=step(3,'Tinjau semua halaman','Periksa awal isi akta, paragraf menjorok, daftar bernomor, pergantian halaman, dan bagian tanda tangan. Jangan hanya menilai halaman pertama.',y)
y=step(4,'Tambahkan pengisi, lalu lakukan koreksi','Gunakan <b>Pengaturan Garis &gt; Isi ----</b> untuk strip mendatar; lihat halaman 7-8. Koreksi garis vertikal dijelaskan di halaman 9.',y)
y=note('Urutan yang disarankan','Jalankan <b>Buat Garis</b> sebelum koreksi manual. Menjalankannya kembali menghitung ulang dan mengganti garis di editor.',y)
p('PDF teks dan PDF scan dapat diproses. Untuk scan, gunakan halaman yang tajam, tegak, dan tidak terpotong; hasil deteksi tetap perlu diperiksa.',y,size=9.7,color=MUTED)
finish()

# 07 / Horizontal leaders
y=start('05 / Pengisi mendatar','Isi ujung baris dengan ----','Gunakan pengisi mendatar untuk menutup sisa ruang setelah teks. Fitur ini diatur terpisah dari garis vertikal.')
fig_top=y
rect(M,fig_top,CW,185,'#ffffff',radius=7,stroke=LINE)
text('Tutup sisa ujung baris',M+16,fig_top+12,12,'UI-Bold',FOREST)
rect(M+15,fig_top+36,CW-30,25,PALE,radius=4)
text('Isi akta berakhir di sini. --------------------------------',M+26,fig_top+43,9.5,'UI',INK)
for yy,label in [(74,'Batas kanan otomatis mengikuti isi dokumen'),(96,'Isi juga baris kosong di area isi akta')]:
    rect(M+18,fig_top+yy,11,11,GREEN,radius=2)
    line(M+20,fig_top+yy+5,M+23,fig_top+yy+8,'#ffffff',1.2)
    line(M+23,fig_top+yy+8,M+27,fig_top+yy+3,'#ffffff',1.2)
    text(label,M+38,fig_top+yy+1,9.4,'UI',INK)
text('Margin kanan manual (mm)',M+18,fig_top+123,9.3,'UI',MUTED)
rect(W-M-72,fig_top+118,54,21,'#f1f3f2',radius=3,stroke=LINE)
text('20',W-M-45,fig_top+123,9,'UI',MUTED,'center')
rect(M+16,fig_top+151,104,23,'#ffffff',radius=4,stroke=LINE)
text('Hapus pengisi',M+68,fig_top+157,9,'UI-Bold',GREEN,'center')
rect(W-M-132,fig_top+151,116,23,GREEN,radius=4)
text('Terapkan ----',W-M-74,fig_top+157,9,'UI-Bold','#ffffff','center')
text('Skema dialog Isi Ujung Baris; opsi baris kosong aktif secara bawaan.',M,fig_top+195,8.3,'UI',MUTED)
y=fig_top+221
y=step(1,'Buka dialog pengisi','Pilih tab <b>Pengaturan Garis</b>, lalu klik <b>Isi ----</b>. Dokumen harus sudah terbuka.',y)
y=step(2,'Tentukan batas kanan','Gunakan opsi otomatis terlebih dahulu. Untuk mengatur sendiri, hilangkan centang otomatis lalu isi <b>Margin kanan manual (mm)</b> antara <b>5 dan 80</b>.',y)
y=step(3,'Pilih pengisian baris kosong','Aktifkan <b>Isi juga baris kosong di area isi akta</b> bila ruang kosong pada baris isi perlu ditutup. Lihat contoh pada halaman 8.',y)
y=step(4,'Terapkan dan periksa','Klik <b>Terapkan ----</b>. Pengaturan berlaku untuk seluruh halaman. Lihat jumlah ujung baris pada bar status, lalu periksa batas kanan dan bagian khusus.',y)
note('Menghapus atau membatalkan pengisi','Buka dialog yang sama dan pilih <b>Hapus pengisi</b>, atau gunakan <b>Ctrl+Z</b>. Pengisi yang sudah menyatu dalam PDF ekspor lama perlu diproses kembali dari sumber yang bersih.',y)
finish()

# 08 / Blank rows, white text and excluded areas
y=start('06 / Ruang kosong','Baris kosong, footer, teks putih','Periksa ruang yang benar-benar terlihat pada halaman. Beberapa dokumen Word menyimpan tulisan berwarna putih yang tampak seperti area kosong.')
fig_top=y
col=(CW-20)/2
for index,label in enumerate(['SEBELUM','SESUDAH']):
    xx=M+index*(col+20)
    rect(xx,fig_top,col,137,PALE if index==0 else '#ffffff',radius=6,stroke=LINE)
    text(label,xx+13,fig_top+13,9,'UI-Bold',MUTED if index==0 else GREEN)
    text('- Dibuat dengan',xx+13,fig_top+46,10,'UI',INK)
    for n in range(3):
        yy=fig_top+52+n*29
        if index==0:
            text('--',xx+col-27,yy-5,9,'UI',MUTED)
        else:
            x0=xx+13+(pdfmetrics.stringWidth('- Dibuat dengan','UI',10)+2 if n==0 else 0)
            c.setDash(2,1)
            line(x0,yy,xx+col-13,yy,'#111111',.7)
            c.setDash()
y=fig_top+150
y=p('Strip mengikuti ujung <b>teks yang terlihat</b>. Baris isi kosong dapat ikut diisi jika opsi baris kosong aktif. Di Word, paragraf kosong nyata di dalam area isi juga diperiksa. Ilustrasi memakai data contoh.',y,size=9.6,color=MUTED,gap=18)
y=table(['Bagian dokumen','Perilaku pengisi'],[
    ('Baris -, --, atau ---','Strip pendek yang berdiri sendiri di dekat paragraf isi ikut diperpanjang sampai batas kanan.'),
    ('Baris kosong dari Word','Baris yang posisinya masih tersimpan dalam hasil konversi dapat diisi. Ruang sebelum isi dan area gambar dilewati.'),
    ('Teks putih / tak terlihat','Pada kertas putih, teks putih tidak menggeser ujung pengisi. Teks abu-abu dan huruf putih di bidang berwarna tetap diperlakukan sebagai teks yang terlihat.'),
    ('Footer dan tanda tangan','Nomor halaman, footer yang dikenali, serta blok Notaris/PPAT dikecualikan. Periksa lagi bila tata letaknya berbeda.')
],y,[139,CW-139],size=9.6)
y=note('PDF scan memerlukan lapisan teks','Garis vertikal tetap mendukung scan. Untuk pengisi <b>----</b>, gunakan PDF dengan teks yang dapat dipilih atau lakukan OCR terlebih dahulu. Baris kosong yang tidak memiliki posisi dalam PDF tidak ditebak dari area putih.',y)
p('Pengisi menutup ruang kosong secara visual. Tetap periksa dokumen akhir; isian ini tidak mengunci PDF dari penyuntingan.',y,size=9.5,color=MUTED)
finish()

# 09 / Manual editing
y=start('07 / Koreksi','Rapikan garis vertikal','Klik garis vertikal yang ingin diperbaiki. Garis terpilih menjadi hijau dengan penanda pada kedua ujungnya.')
fig_top=y
rect(M,fig_top,CW,142,PALE,radius=7)
doc_lines(M+156,fig_top+29,210,6,17)
gx=M+136
line(gx,fig_top+21,gx,fig_top+124,'#00a663',2.3)
for yy in (fig_top+21,fig_top+124):
    rect(gx-3,yy-3,6,6,'#00a663')
text('Ujung atas',M+12,fig_top+13,9,'UI-Bold')
arrow(M+78,fig_top+22,gx-8,fig_top+22)
text('Badan garis',M+12,fig_top+65,9,'UI-Bold')
arrow(M+83,fig_top+74,gx-8,fig_top+74)
text('Ujung bawah',M+12,fig_top+113,9,'UI-Bold')
arrow(M+87,fig_top+122,gx-8,fig_top+122)
text('Tarik ujung untuk mengubah panjang',W-M-10,fig_top+125,8,'UI',MUTED,'right')
y=fig_top+163
y=table(['Yang ingin dilakukan','Cara mengerjakan'],[
    ('Menggeser garis','Tarik bagian tengah garis ke posisi yang sesuai.'),
    ('Mengubah panjang','Tarik penanda ujung atas atau ujung bawah.'),
    ('Menggeser dengan presisi','Pilih garis, lalu tekan tombol panah. Gunakan Shift + panah untuk langkah lebih besar.'),
    ('Menambah garis','Klik <b>Pengaturan Garis &gt; Tambah Garis</b>, lalu klik posisinya di halaman. Klik kanan pada halaman juga menambah garis.'),
    ('Menghapus atau membatalkan','Pilih garis vertikal lalu tekan <b>Delete</b>. Gunakan <b>Undo</b> / Ctrl+Z atau <b>Redo</b> / Ctrl+Y untuk mengulang koreksi.')
],y,[151,CW-151],size=9.8)
y=small_heading('Memilih warna garis',y)
p('Pada tab <b>Pengaturan Garis</b>, pilih <b>Hitam</b> atau <b>Merah</b>. Warna dipakai oleh garis vertikal dan penutupnya. Pengisi mendatar tetap hitam, termasuk hasil PDF. Untuk menghapus seluruh pengisi ----, gunakan <b>Hapus pengisi</b> pada dialog Isi Ujung Baris.',y)
finish()

# 10 / Caps and signatures
y=start('08 / Hasil yang tepat','Garis penutup dan tanda tangan','Periksa letak garis miring pada awal dan akhir isi. Bagian judul, nomor akta, serta blok tanda tangan perlu mendapat perhatian khusus.')
y=small_heading('Mengatur kemiringan',y)
y=p('Buka <b>Pengaturan Garis &gt; Kemiringan</b>. Pilih mode <b>Bersilangan</b> atau <b>Tidak Bersilangan</b>, lalu pilih salah satu preset. Tombol <b>Custom</b> tersedia jika Anda memerlukan pengaturan lain.',y)
y=p('<b>DX</b> mengatur panjang mendatar, sedangkan <b>DY</b> mengatur selisih tinggi atau kemiringan. Pengaturan berlaku pada garis pembuka dan penutup di dokumen.',y)
yt=y+4
for i,(label,dy) in enumerate([('Pilihan 1',0),('Pilihan 2',-8),('Pilihan 3',-16)]):
    xx=M+i*(CW/3)
    rect(xx,yt,CW/3-12,83,PALE,radius=5)
    text(label,xx+12,yt+12,9,'UI-Bold')
    line(xx+28,yt+65,xx+28,yt+43,FOREST,1.2)
    line(xx+28,yt+43,xx+109,yt+43+dy,GREEN,1.7)
y=yt+108
y=small_heading(f'Bagian yang perlu diperiksa pada v{VERSION}',y)
y=bullet('<b>Awal akta:</b> garis dimulai pada paragraf isi, misalnya "Pada hari ini", bukan pada judul atau baris "Nomor".',y)
y=bullet('<b>Akhir isi:</b> garis penutup tetap mengikuti akhir isi akta sebelum ruang tanda tangan.',y)
y=bullet('<b>Tanda tangan:</b> blok yang dikenali dari jabatan Notaris/PPAT, ruang kosong, dan nama terpisah dikecualikan dari garis otomatis. Periksa kembali bila formatnya berbeda.',y)
yt=y+4
rect(M,yt,CW,126,'#f7faf8',radius=6,stroke=LINE)
text('Akhir isi akta',M+32,yt+17,9,'UI-Bold')
line(M+20,yt+13,M+20,yt+38,FOREST,1.3)
line(M+20,yt+38,M+61,yt+47,GREEN,1.4)
text('Area tanda tangan',M+CW-24,yt+16,8.5,'UI-Bold',GREEN,'right')
text('Notaris di Kota Contoh',M+325,yt+59,10,'UI',INK,'center')
text('(NAMA NOTARIS)',M+325,yt+104,10,'UI-Bold',INK,'center')
y=yt+142
p('Jika garis lama sudah menyatu ke PDF hasil ekspor, buka kembali <b>Word atau PDF sumber yang bersih</b> dan buat garis ulang. Koreksi editor bekerja pada garis yang dibuat di sesi tersebut.',y,size=9.7,color=MUTED)
finish()

# 11 / Pages and booklet
y=start('09 / Susun dokumen','Halaman dan format booklet','Gunakan tab Kelola Halaman. Periksa urutan dokumen setelah menyisipkan, menghapus, atau memindahkan halaman.')
y=table(['Kontrol','Fungsi'],[
    ('Thumbnail di panel kiri','Klik untuk berpindah. Geser thumbnail ke posisi lain untuk menyusun ulang.'),
    ('Sisip PDF','Masukkan PDF lain setelah halaman yang sedang aktif.'),
    ('Gabung PDF','Pilih PDF tambahan, lalu tentukan posisi penggabungannya pada dialog.'),
    ('Hapus','Hapus halaman aktif setelah memeriksa pesan konfirmasi.')
],y,[145,CW-145])
y=small_heading('Buat Booklet',y)
y=p('Selesaikan garis vertikal dan pengisi <b>----</b> pada dokumen asli, lalu klik <b>Buat Booklet</b>. Aplikasi menampilkan dua halaman per lembar dalam urutan lipat. Halaman kosong dapat ditambahkan agar jumlahnya sesuai kelipatan empat.',y)
yt=y+4
for i,(left,right,label) in enumerate([('8','1','Sisi depan'),('2','7','Sisi belakang')]):
    xx=M+i*185
    rect(xx,yt,158,63,PALE,radius=4,stroke=LINE)
    line(xx+79,yt+5,xx+79,yt+58)
    text(left,xx+39,yt+19,21,'UI-Bold',FOREST,'center')
    text(right,xx+119,yt+19,21,'UI-Bold',FOREST,'center')
    text(label,xx+79,yt+69,8.5,'UI',MUTED,'center')
text('Contoh booklet',W-M,yt+15,8.5,'UI-Bold',GREEN,'right')
text('8 halaman',W-M,yt+32,8.5,'UI',MUTED,'right')
y=yt+99
y=p('Pengisi ikut pada susunan booklet. Klik <b>Simpan PDF</b> untuk menyimpannya. Jika ingin mengubah opsi <b>Isi ----</b> atau susunan halaman, klik <b>Unbooklet</b> terlebih dahulu untuk kembali ke dokumen asli.',y)
y=small_heading('Scan A3 ke A4',y+2)
y=p('Tombol <b>A3 ke A4</b> memotong PDF scan booklet menjadi halaman tunggal dan mengurutkannya. Pilih sumber, tentukan nama hasil, lalu periksa urutan. Fungsi ini ditujukan untuk scan dengan susunan booklet yang sesuai.',y)
p('Untuk cetak booklet, lakukan uji beberapa lembar terlebih dahulu. Cocokkan pengaturan kertas dan cetak dua sisi dengan printer yang dipakai.',y,size=9.6,color=MUTED)
finish()

# 12 / Export compression print
y=start('10 / Hasil akhir','Simpan, kompres, dan cetak','Simpan hasil dengan nama baru agar dokumen sumber tetap tersedia untuk koreksi berikutnya. Garis vertikal dan pengisi mendatar ikut dalam hasil PDF.')
y=step(1,'Simpan PDF hasil akhir','Klik <b>Simpan PDF</b> atau tekan <b>Ctrl+S</b>. Pilih folder, beri nama seperti <b>Akta_final_garis.pdf</b>, lalu simpan.',y)
y=step(2,'Buka hasil di pembaca PDF','Periksa jumlah halaman, ketajaman teks, garis pembuka/penutup, dan bagian tanda tangan pada file yang benar-benar tersimpan.',y)
y=step(3,'Kompres bila diperlukan','Klik <b>Kompres PDF</b>, masukkan target ukuran dalam MB, lalu tentukan nama hasil. Aplikasi memberi informasi apakah target tercapai.',y)
y=p('Target yang terlalu kecil dapat mengurangi ketajaman. Periksa juga apakah teks masih dapat dipilih pada hasil kompresi. Simpan salinan hasil berkualitas baik sebagai arsip.',y,M+35,CW-35,size=9.7,color=MUTED,gap=18)
y=step(4,'Cetak hasil yang sudah diperiksa','Untuk memilih printer dan ukuran kertas secara terkontrol, buka PDF hasil simpan di pembaca PDF, lalu tekan <b>Ctrl+P</b>. Periksa pratinjau sebelum mencetak.',y)
y=note('Tombol Cetak di SIGANDI','Tombol <b>Cetak</b> atau Ctrl+P menyiapkan PDF bergaris untuk dicetak. Bergantung komponen Windows, perintah dapat membuka dialog atau langsung dikirim ke printer bawaan. Bila otomatis gagal, aplikasi berusaha membuka PDF siap cetak.',y)
y=small_heading('Sebelum menutup aplikasi',y)
for item in ['Pastikan PDF hasil akhir sudah tersimpan dan dapat dibuka.',
             'Simpan Word/PDF sumber yang bersih untuk pemrosesan ulang.',
             'Gunakan nama atau folder yang membedakan sumber, hasil akhir, dan hasil kompresi.']:
    y=bullet(item,y)
finish()

# 13 / Review and problems
y=start('11 / Pemeriksaan','Periksa hasil dan atasi kendala','Periksa PDF yang sudah tersimpan, terutama sebelum dibagikan atau dicetak. Gunakan dokumen asli yang bersih saat memproses ulang.')
checks=['Judul dan Nomor tidak menjadi awal garis; paragraf menjorok tercakup.',
        'Pengisi ---- rapi sampai batas kanan; baris kosong yang dituju terisi.',
        'Footer dan tanda tangan bersih; penutup berada di akhir isi.',
        'Urutan halaman serta hasil simpan dan cetak sudah sesuai.']
for item in checks:
    rect(M,y+3,8,8,'#ffffff',stroke='#8aad99')
    y=p(item,y,M+20,CW-20,size=9.8,gap=7)
y+=10
y=table(['Kendala','Langkah yang dapat dilakukan'],[
    ('Strip tidak terisi','Pilih <b>Pengaturan Garis &gt; Isi ---- &gt; Terapkan ----</b>. Buat Garis mengatur garis vertikal. PDF scan perlu OCR untuk pengisi mendatar.'),
    ('Baris kosong terlewat','Centang opsi baris kosong. Jika posisi baris tidak tersimpan dalam PDF, beri tanda <b>-</b> pada baris itu di Word asli, lalu buka ulang dan terapkan pengisi.'),
    ('Strip hanya di kanan','Periksa versi aktif di kanan atas. Gunakan versi terbaru dan muat sumber asli agar teks putih tidak menggeser ujung pengisi.'),
    ('Footer / tanda tangan ikut bergaris','Gunakan versi terbaru dan sumber bersih. Koreksi garis vertikal yang keliru secara manual. Jika format khusus belum dikenali, kirim contoh yang sudah disamarkan ke bantuan.'),
    ('Word tidak terbuka','Pastikan Word atau LibreOffice dapat digunakan. Buka sumber Word untuk memeriksa filenya, atau ekspor ke PDF terlebih dahulu.'),
    ('Garis lama tetap terlihat','Hapus pengisi dan Delete hanya mengatur garis editor. Untuk garis yang sudah menyatu pada ekspor lama, proses ulang Word/PDF sumber yang bersih.'),
    ('Simpan atau cetak gagal','Pilih nama/folder hasil lain dan tutup PDF hasil yang sedang dipakai aplikasi lain. Untuk cetak, buka PDF tersimpan lalu cetak dari pembaca PDF.')
],y,[142,CW-142],size=9.3)
p('Lisensi bermasalah: internet diperlukan saat aktivasi dan menjalankan fitur. Lihat halaman 20 untuk penolakan server, lisensi terhapus atau expired. Jangan kirim key melalui gambar dokumen.',y,size=9.4,color=MUTED)
finish()

# 14 / Shortcuts and help
y=start('12 / Referensi','Pintasan desktop dan bantuan','Pintasan di halaman ini berlaku di aplikasi desktop. Shortcut khusus SIGANDI di Word tersedia pada halaman 16.')
y=table(['Pintasan','Tindakan'],[
    ('Ctrl+O','Buka PDF'),('Ctrl+G','Buat garis otomatis'),('Ctrl+S atau Ctrl+E','Simpan / ekspor PDF'),
    ('Ctrl+Z / Ctrl+Y','Undo / Redo koreksi dan pengaturan pengisi'),('Delete','Hapus garis vertikal terpilih'),
    ('Tombol panah / Shift + panah','Geser garis terpilih / geser dengan langkah lebih besar'),
    ('Page Up / Page Down','Halaman sebelumnya / berikutnya'),('Ctrl+0','Sesuaikan halaman ke area editor'),
    ('Ctrl+Shift+C','Kompres PDF'),('Ctrl+P','Cetak melalui SIGANDI'),('F1','Buka bantuan, ketentuan penggunaan, dan kontak')
],y,[184,CW-184],size=9.5)
y=small_heading('Hubungi Radina.net',y+1)
right_width=105
y2=p('Siapkan <b>versi aplikasi</b>, versi Windows, urutan langkah yang menimbulkan masalah, serta pesan kesalahan. Bila mengirim gambar contoh, tutup data pribadi atau informasi dokumen yang tidak diperlukan.',y,M,CW-right_width-20,size=9.8)
text('WhatsApp  +62 877 2417 0145',M,y2+2,12,'UI-Bold',GREEN)
c.linkURL('https://wa.me/6287724170145',(M,H-y2-20,M+260,H-y2+3),relative=0,thickness=0)
text('Pilih tombol Panduan di tab Dokumen untuk kontak bantuan.',M,y2+27,8.8,'UI',MUTED)
widget=qr.QrCodeWidget('https://wa.me/6287724170145')
bounds=widget.getBounds(); side=78
d=Drawing(side,side,transform=[side/(bounds[2]-bounds[0]),0,0,side/(bounds[3]-bounds[1]),0,0])
d.add(widget)
renderPDF.draw(d,c,W-M-side,H-y-side+5)
text('Pindai untuk bantuan',W-M-side/2,y+side+9,7.7,'UI',MUTED,'center')
finish()
# 15 / Word installation
y=start('13 / Microsoft Word','Pasang tab SIGANDI di Word','Word add-in 0.4.9 tersedia untuk Microsoft Word desktop Windows dengan VBA. Word web dan Word mobile tidak didukung.')
y=step(1,'Tutup Word sebelum instalasi','Simpan dokumen, lalu tutup semua jendela Word. Jangan menutup paksa dokumen yang belum disimpan.',y)
y=step(2,'Jalankan installer terbaru',f'Gunakan <b>GarisAktaEditor_Setup_v{VERSION}.exe</b>. Installer memasang desktop, helper lisensi dan template Word untuk akun Windows yang menjalankannya.',y)
y=step(3,'Buka Word kembali','Tab <b>SIGANDI</b> dimuat dari folder Startup Word. Satu aktivasi desktop berlaku juga untuk Word pada komputer dan akun yang sama.',y)
y=step(4,'Periksa lisensi','Pilih <b>SIGANDI &gt; Lisensi &gt; Informasi Lisensi</b>. Bila perlu, pilih <b>Aktivasi / Kelola Lisensi</b> dan hubungkan komputer ke internet.',y)
y=small_heading('Bila tab belum muncul',y)
y=p('Buka <b>File &gt; Options &gt; Add-ins</b>. Pada Manage, pilih <b>Word Add-ins &gt; Go</b>, lalu periksa centang <b>SIGANDI-Word.dotm</b>. Bila Word pernah menonaktifkannya, periksa daftar <b>Disabled Items</b>. Gunakan satu versi aktif saja.',y)
y=note('Pemasangan manual bersifat pilihan','Paket DOTM dapat dimuat melalui <b>Word Add-ins &gt; Go &gt; Add</b>. File harus berada di lokasi tetap. Fungsi berlisensi tetap memerlukan helper desktop 1.4.8 atau lebih baru; memasang DOTM saja tidak cukup.',y)
p('Installer tidak mengubah Trust Center. Bila kebijakan kantor memblokir macro, hubungi administrator. Untuk melepas add-in, simpan dan tutup Word, lalu uninstall SIGANDI; jangan menghapus template milik aplikasi lain.',y,size=9.6,color=MUTED)
finish()

# 16 / Word workflow and function keys
y=start('14 / Microsoft Word','Shortcut dan alur kerja Word','Kombinasi tombol terlihat langsung pada label menu SIGANDI. Shortcut berlaku saat add-in dimuat; tombol Ctrl tetap ditekan bersama tombol fungsi.')
y=step(1,'Tandai isi akta','Blok isi utama, lalu pilih <b>Tandai Area Isi (Ctrl+F7)</b>. Jangan sertakan judul utama, Nomor, footer, atau tanda tangan. Sertakan seluruh tabel jika berada dalam isi.',y)
y=step(2,'Buat dan periksa garis','Gunakan <b>Buat Semua (Ctrl+F6)</b>. Jangan mengetik atau berpindah dokumen selama proses. Periksa awal/akhir isi, daftar bernomor, tabel dan judul bagian.',y)
y=table(['Shortcut Word','Menu / tindakan'],[
    ('Ctrl+F6','<b>Buat Semua</b>: vertikal, penutup dan pengisi di luar tabel.'),
    ('Ctrl+F7','<b>Tandai Area Isi</b>: simpan batas isi yang sedang diblok.'),
    ('Ctrl+F8','<b>Garis Vertikal</b>: perbarui garis paragraf dan sisi luar tabel.'),
    ('Ctrl+F9','<b>Isi ---- di Luar Tabel</b>: ujung baris, paragraf kosong dan judul bagian.'),
    ('Ctrl+F10','<b>Perbarui Garis</b>: hitung ulang setelah isi/tata letak berubah.'),
    ('Ctrl+F11','<b>Simpan PDF</b>: pilih lokasi penyimpanan PDF hasil akhir.')
],y,[112,CW-112],size=9.4)
y=note('Tabel tetap utuh','Sel tabel tidak diberi pengisi ----. Garis vertikal berada <b>6 pt di luar sisi kiri tabel</b>; teks, padding dan border asli tidak diubah. Setelah mengubah ukuran, isi atau posisi tabel, tekan <b>Ctrl+F10</b>.',y)
p('Simpan DOCX seperti biasa. Ctrl+Z membatalkan satu proses; Hapus Garis SIGANDI menghapus garis buatan add-in. Pengaturan shortcut bawaan Word pada kombinasi yang sama digantikan selama add-in aktif.',y,size=9.4,color=MUTED)
finish()

# 17 / Word line styling
y=start('15 / Microsoft Word','Warna, ketebalan dan penutup','Pengaturan bentuk dapat diterapkan tanpa membaca ulang semua baris. Gunakan Perbarui Garis ketika teks, margin atau jumlah halaman berubah.')
y=table(['Pengaturan Word','Cara menggunakan'],[
    ('Warna Garis','Pilih <b>Hitam, Merah, Hijau atau Abu-abu</b>. Pilihan mengubah garis vertikal dan penutup. Pengisi mendatar ---- tetap hitam.'),
    ('Ketebalan (pt)','Masukkan <b>0,1 sampai 4 pt</b>. Desimal koma atau titik diterima. Nilai di luar rentang ditolak.'),
    ('Bersilangan','Centang untuk penutup yang melintasi sambungan vertikal; hapus centang untuk sambungan biasa.'),
    ('Panjang atas / bawah','Mengatur jarak mendatar penutup, masing-masing <b>1-150 pt</b>.'),
    ('Miring atas / bawah','Mengatur selisih tinggi, masing-masing <b>0-30 pt</b>. Nilai 0 menghasilkan penutup datar.'),
    ('Preset dan penerapan','Pilih <b>Landai, Sedang, Tinggi</b> atau isian khusus. Klik <b>Terapkan Penutup</b> untuk memperbarui penutup yang ada.')
],y,[145,CW-145],size=9.6)
y=small_heading('Daftar angka dan Romawi',y)
y=p('Garis pendek pada baris pertama ditempatkan di kiri label nomor, termasuk angka dan Romawi. Baris lanjutan mengikuti indentasi teks yang menjorok ke dalam. Penutup atas mengacu pada baris isi pertama, termasuk bila garis pertamanya pendek.',y)
y=note('Undo dan pilihan berikutnya','Ctrl+Z mengembalikan perubahan pada bentuk. Pilihan warna atau ketebalan untuk pembuatan berikutnya tetap mengikuti pengaturan yang tersimpan. Teks, footer dan bentuk pengguna tidak ikut diwarnai.',y)
p('Panjang penutup dibatasi ruang halaman. Untuk format tanda tangan yang berbeda, tetap batasi area isi secara manual agar blok penandatanganan tidak ikut diproses.',y,size=9.6,color=MUTED)
finish()

# 18 / Word margins
y=start('16 / Microsoft Word','Margin akta dan ukuran kertas','Gunakan format yang disepakati kantor. Add-in memakai pengaturan dokumen sebagai awal dan tidak mengubah margin otomatis saat Word dibuka.')
y=step(1,'Buka Halaman Akta','Pilih <b>SIGANDI &gt; Halaman Akta &gt; Atur Margin dan Kertas</b>. Dialog Page Setup Word terbuka.',y)
y=step(2,'Atur kertas dan empat margin','Isi margin atas, bawah, kiri dan kanan. Pilih ukuran kertas pada tab Paper. Untuk ukuran khusus, masukkan lebar serta tinggi yang digunakan kantor.',y)
y=step(3,'Periksa cakupan bagian','Pastikan pengaturan ditujukan ke bagian yang benar. Dokumen dengan beberapa section dapat mempunyai kertas dan margin berbeda.',y)
y=step(4,'Simpan dan gunakan Preset Kantor','<b>Simpan Preset Kantor</b> menyimpan ukuran kertas dan empat margin bagian aktif. Terapkan ke <b>Bagian Aktif</b> atau <b>Seluruh Dokumen</b> sesuai kebutuhan.',y)
y=step(5,'Buat ulang garis','Perubahan tata letak menghapus garis SIGANDI yang posisinya sudah tidak sesuai. Periksa batas area isi, lalu klik <b>Perbarui Garis</b>. Ctrl+Z dapat membatalkan perubahan.',y)
y=note('Ruang untuk catatan dan renvoi','Sesuaikan margin serta ruang catatan dengan jenis akta dan format kantor. Panduan ini tidak menetapkan satu kombinasi margin atau ukuran kertas sebagai ketentuan wajib untuk semua akta.',y)
p('Jika garis masih mengikuti tata letak lama, jangan memindahkan semua garis satu per satu. Selesaikan pengaturan paragraf dan kertas, lalu perbarui garis dari area isi yang benar.',y,size=9.7,color=MUTED)
finish()

# 19 / Word troubleshooting
y=start('17 / Microsoft Word','Koreksi dan solusi kendala','Periksa sumber, cakupan area isi dan versi add-in sebelum mengulangi pemrosesan. Simpan salinan sebelum mengubah dokumen penting.')
y=table(['Gejala','Langkah yang disarankan'],[
    ('Garis tidak sejajar nomor','Gunakan Word add-in <b>0.4.9</b>. Periksa indentasi paragraf serta daftar otomatis, lalu klik <b>Perbarui Garis</b>.'),
    ('Footer / tanda tangan bergaris','Tandai ulang hanya isi akta. Header/footer asli Word dilewati; teks footer yang diketik di isi utama perlu dikeluarkan dari area.'),
    ('Pesan "Teks berubah"','Versi terbaru memperbaiki jangkar kanvas yang dapat mengganti huruf. Bila gagal, Ctrl+Z untuk membatalkan proses, periksa teks, lalu ulangi tanpa mengetik atau berpindah dokumen.'),
    ('Word tampak lambat','Tunggu status proses selesai. Dokumen panjang memerlukan waktu sesuai tata letak dan komputer. Gunakan Batalkan Proses jika perlu; jangan membuka proses kedua bersamaan.'),
    ('Pengisi tidak muncul','Pastikan baris berada dalam area isi dan gunakan tombol <b>Isi ---- di Luar Tabel (Ctrl+F9)</b>. Ruang Before/After serta area gambar bukan baris kosong yang dapat diisi.'),
    ('Template tidak ditemukan','Hapus centang referensi preview lama yang filenya sudah dipindah. Tutup Word, lalu jalankan installer terbaru agar template tetap tersedia di Startup.'),
    ('Helper / lisensi ditolak','Pastikan desktop <b>1.4.8+</b> terpasang, internet tersedia dan lisensi masih aktif di server. Lihat halaman 20.')
],y,[139,CW-139],size=9.4)
y=small_heading('Batas cakupan add-in',y)
p('Paragraf isi utama satu kolom dan tabel dalam area isi didukung. Pilih seluruh tabel; garis kiri dibuat 6 pt di luarnya dan sel tabel tidak diisi ----. Textbox, gambar inline, mirror margins dan bagian multikolom dilewati. Add-in tidak melakukan OCR. Kompres PDF dan booklet tersedia di desktop. Garis merupakan tampilan visual dan tidak mengunci dokumen dari penyuntingan.',y,size=9.7)
finish()

# 20 / Current licensing policy
y=start('18 / Lisensi bersama','Status lisensi dan penolakan','Berlaku untuk desktop 1.4.8 dan Word add-in 0.4.9. Perbarui semua komputer: versi lama masih dapat memiliki aturan pemeriksaan yang berbeda.')
y=note('Internet diperlukan untuk fitur berlisensi','Server diperiksa pada setiap tindakan berlisensi. Persetujuan lama dan cache tampilan status bukan pengganti izin server. Putus koneksi atau server tidak tersedia akan memblokir fitur hingga pemeriksaan berhasil.',y)
y=table(['Status / kondisi','Dampak dan tindakan'],[
    ('Aktif di server','Fitur berlisensi dapat dijalankan setelah pemeriksaan berhasil. Masa berlaku serta batas perangkat mengikuti lisensi.'),
    ('Key dihapus / tidak ditemukan','Cache aktivasi lama tidak meloloskan fitur. Hubungi penyedia untuk memastikan key masih terdaftar.'),
    ('Nonaktif / revoked / expired','Pembuatan dan pembaruan garis, perubahan garis, penerapan margin dan ekspor melalui SIGANDI ditolak. Perpanjang atau aktifkan lisensi yang sah.'),
    ('Internet / server bermasalah','Periksa koneksi dan coba ulang. Aplikasi tidak memberikan izin offline sebagai pengganti pemeriksaan yang gagal.'),
    ('Dokumen dan garis lama','Tetap tersimpan dan dapat dibuka di Word. Bantuan, penandaan area, hapus garis dan menu aktivasi tetap tersedia di add-in.')
],y,[139,CW-139],size=9.5)
y=small_heading('Periksa atau perbarui aktivasi',y)
y=p('Di Word, buka <b>SIGANDI &gt; Lisensi &gt; Informasi Lisensi</b> untuk status, pemilik, masa berlaku dan perangkat. Pilih <b>Aktivasi / Kelola Lisensi</b> untuk aktivasi atau pemeriksaan ulang. Di desktop, gunakan tombol <b>Lisensi</b> pada tab Dokumen.',y)
p('Bila key ditolak padahal sudah diperpanjang, periksa key, komputer/akun yang digunakan dan statusnya di server bersama penyedia lisensi. Jangan membagikan key lengkap melalui tangkapan layar. Pembaruan lisensi tidak menghapus dokumen akta Anda.',y,size=9.6,color=MUTED)
finish()
assert page_number == TOTAL_PAGES
c.save()
print(OUT)
print(f'{page_number} pages; bookmarks and internal links included')
