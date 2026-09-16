import type { AppIcon } from '~/utils/icons'

export interface NavigationItem {
  title: string
  path: string
  icon: AppIcon
  hint: string
  adminOnly?: boolean
  superAdminOnly?: boolean
}

export interface NavigationSection {
  title: string
  items: NavigationItem[]
}

export const navigationSections: NavigationSection[] = [
  {
    title: 'Operasional Kantor',
    items: [
      { title: 'Dashboard', path: '/dashboard', icon: 'dashboard', hint: 'Ikhtisar performa kerja kantor' },
      { title: 'Pencarian Dokumen', path: '/pencarian-dokumen', icon: 'search', hint: 'Cari client dan dokumen lintas buku' },
      { title: 'Riwayat Reportorium', path: '/riwayat-reportorium', icon: 'report', hint: 'Riwayat kerja reportorium per asisten' },
      { title: 'Dokumen Scan', path: '/dokumen-scan', icon: 'folder', hint: 'Hasil scan dari scanner kantor' },
      { title: 'Peminjaman Minuta', path: '/peminjaman-minuta', icon: 'briefcase', hint: 'Status pinjam minuta' },
    ],
  },
  {
    title: 'Informasi Pekerjaan',
    items: [
      { title: 'Workflow Pekerjaan', path: '/pekerjaan', icon: 'briefcase', hint: 'Alur pekerjaan dari draft sampai invoice' },
      { title: 'Pesanan Masuk', path: '/order_masuk', icon: 'queue', hint: 'Order aktif yang sedang diproses' },
      { title: 'Pesanan Selesai', path: '/order_selesai', icon: 'done', hint: 'Riwayat order yang selesai' },
      { title: 'Invoice Tax', path: '/invoice_tax', icon: 'invoice', hint: 'Billing dengan komponen pajak' },
      { title: 'Invoice Non Tax', path: '/invoice_non_tax', icon: 'invoice', hint: 'Billing tanpa komponen pajak' },
    ],
  },
  {
    title: 'Buku Reportorium',
    items: [
      { title: 'Buku Akta Notaris', path: '/buku_akta', icon: 'document', hint: 'Akta notaris dan dokumen' },
      { title: 'Buku Legalisasi', path: '/buku_legalisasi', icon: 'document', hint: 'Daftar legalisasi' },
      { title: 'Buku Waarmerking', path: '/buku_waarmerking', icon: 'document', hint: 'Daftar waarmerking' },
      { title: 'Buku PPAT', path: '/buku_ppat', icon: 'bank', hint: 'Akta PPAT dan dokumen' },
      { title: 'Garis Otomatis Akta', path: '/garis-otomatis-akta', icon: 'document', hint: 'Upload DOC/DOCX akta dan buat PDF bergaris' },
    ],
  },
  {
    title: 'Buku Surat',
    items: [
      { title: 'Surat Notaris', path: '/buku_surat_notaris', icon: 'clipboard', hint: 'Surat keluar notaris' },
      { title: 'Surat PPAT', path: '/buku_surat_ppat', icon: 'clipboard', hint: 'Surat keluar PPAT' },
    ],
  },
  {
    title: 'Tanda Terima',
    items: [
      { title: 'Tanda Terima Keluar', path: '/tanda_terima', icon: 'report', hint: 'Dokumen keluar' },
      { title: 'Tanda Terima Masuk', path: '/tanda_terima_masuk', icon: 'report', hint: 'Dokumen masuk' },
    ],
  },
  {
    title: 'PPAT Rekanan',
    items: [
      { title: 'Master Rekanan', path: '/ppat-rekanan?tab=master', icon: 'bank', hint: 'Master PPAT rekanan' },
      { title: 'Rekanan Keluar', path: '/ppat-rekanan?tab=keluar', icon: 'bank', hint: 'Nomor PPAT kantor dipakai PPAT lain' },
      { title: 'Rekanan Kedalam', path: '/ppat-rekanan?tab=kedalam', icon: 'bank', hint: 'Nomor PPAT rekanan masuk database' },
    ],
  },
  {
    title: 'Data Klien',
    items: [
      { title: 'OCR KTP', path: '/ocr-ktp', icon: 'document', hint: 'Scan KTP dan koreksi data client' },
      { title: 'Data Client Perorangan', path: '/perorangan', icon: 'users', hint: 'Master client individu' },
      { title: 'Data Client Badan Hukum', path: '/badan_hukum', icon: 'office', hint: 'Master client entitas' },
    ],
  },
  {
    title: 'Kontrol Admin',
    items: [
      { title: 'Kontrol Pekerjaan', path: '/admin-kontrol-pekerjaan', icon: 'settings', hint: 'Alihkan tugas reportorium', adminOnly: true },
      { title: 'Kontrol Anomali', path: '/admin-kontrol-anomali', icon: 'settings', hint: 'Trace nomor ganda dan data tidak wajar', adminOnly: true },
      { title: 'Rekonsiliasi Nomor', path: '/admin-rekonsiliasi-nomor', icon: 'settings', hint: 'Bandingkan nomor production dengan data matang 2016-2021', adminOnly: true },
      { title: 'Persetujuan Dokumen', path: '/admin-persetujuan-dokumen', icon: 'settings', hint: 'Approval download dokumen', adminOnly: true },
    ],
  },
  {
    title: 'Pengaturan',
    items: [
      { title: 'Data Layanan', path: '/data_layanan', icon: 'folder', hint: 'Master layanan kantor', adminOnly: true },
      { title: 'Data Dokumen', path: '/data_dokumen', icon: 'folder', hint: 'Master dokumen standar', adminOnly: true },
      { title: 'Pengaturan Laporan', path: '/pages/report-settings', icon: 'settings', hint: 'Header, invoice, dan backup SQL', adminOnly: true },
      { title: 'Google Calendar', path: '/settings-google-calendar', icon: 'calendar', hint: 'Sinkron jadwal SIMANIS ke Google Calendar', adminOnly: true },
      { title: 'Status Sinkronisasi', path: '/settings-sinkronisasi', icon: 'settings', hint: 'Kesehatan server, database, dan arsip', adminOnly: true },
      { title: 'WhatsApp Gateway', path: '/settings-whatsapp-gateway', icon: 'settings', hint: 'Kontrol bothWA, WAHA, dan QR login', adminOnly: true },
      { title: 'Blast WA', path: '/settings-whatsapp-broadcast', icon: 'users', hint: 'Pengumuman WhatsApp untuk asisten', superAdminOnly: true },
      { title: 'Account Settings', path: '/pages/account-settings', icon: 'settings', hint: 'Profil dan security user' },
    ],
  },
]

export const findNavigationItem = (path: string) =>
  navigationSections.flatMap(section => section.items).find(item => item.path === path)
