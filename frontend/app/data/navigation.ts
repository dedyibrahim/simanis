import type { AppIcon } from '~/utils/icons'

export interface NavigationItem {
  title: string
  path: string
  icon: AppIcon
  hint: string
  adminOnly?: boolean
}

export interface NavigationSection {
  title: string
  items: NavigationItem[]
}

export const navigationSections: NavigationSection[] = [
  {
    title: 'Ringkasan',
    items: [
      { title: 'Dashboard', path: '/dashboard', icon: 'dashboard', hint: 'Ikhtisar performa kerja kantor' },
      { title: 'Pencarian Dokumen', path: '/pencarian-dokumen', icon: 'search', hint: 'Cari data lintas buku' },
      { title: 'Riwayat Reportorium', path: '/riwayat-reportorium', icon: 'report', hint: 'Riwayat kerja reportorium per asisten' },
      { title: 'Jadwal Notaris', path: '/jadwal-notaris', icon: 'calendar', hint: 'Agenda dan signing' },
      { title: 'Peminjaman Minuta', path: '/peminjaman-minuta', icon: 'briefcase', hint: 'Status pinjam minuta' },
    ],
  },
  {
    title: 'Data Pesanan',
    items: [
      { title: 'Pesanan Masuk', path: '/order_masuk', icon: 'queue', hint: 'Order aktif yang sedang diproses' },
      { title: 'Pesanan Selesai', path: '/order_selesai', icon: 'done', hint: 'Riwayat order yang selesai' },
      { title: 'Invoice Tax', path: '/invoice_tax', icon: 'invoice', hint: 'Billing dengan komponen pajak' },
      { title: 'Invoice Non Tax', path: '/invoice_non_tax', icon: 'invoice', hint: 'Billing tanpa komponen pajak' },
    ],
  },
  {
    title: 'Buku Reportorium',
    items: [
      { title: 'Buku Akta', path: '/buku_akta', icon: 'document', hint: 'Akta notaris dan dokumen' },
      { title: 'Buku Legalisasi', path: '/buku_legalisasi', icon: 'document', hint: 'Daftar legalisasi' },
      { title: 'Buku Waarmerking', path: '/buku_waarmerking', icon: 'document', hint: 'Daftar waarmerking' },
      { title: 'Buku PPAT', path: '/buku_ppat', icon: 'bank', hint: 'Akta PPAT dan dokumen' },
    ],
  },
  {
    title: 'Surat',
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
    title: 'Kontrol Admin',
    items: [
      { title: 'Kontrol Pekerjaan', path: '/admin-kontrol-pekerjaan', icon: 'settings', hint: 'Alihkan tugas reportorium', adminOnly: true },
      { title: 'Persetujuan Dokumen', path: '/admin-persetujuan-dokumen', icon: 'settings', hint: 'Approval download dokumen', adminOnly: true },
    ],
  },
  {
    title: 'Setting',
    items: [
      { title: 'Data Client Perorangan', path: '/perorangan', icon: 'users', hint: 'Master client individu' },
      { title: 'Data Client Badan Hukum', path: '/badan_hukum', icon: 'office', hint: 'Master client entitas' },
      { title: 'Data Layanan', path: '/data_layanan', icon: 'folder', hint: 'Master layanan kantor' },
      { title: 'Data Dokumen', path: '/data_dokumen', icon: 'folder', hint: 'Master dokumen standar' },
      { title: 'Pengaturan Laporan', path: '/pages/report-settings', icon: 'settings', hint: 'Header, invoice, dan backup SQL', adminOnly: true },
      { title: 'Account Settings', path: '/pages/account-settings', icon: 'settings', hint: 'Profil dan security user' },
    ],
  },
]

export const findNavigationItem = (path: string) =>
  navigationSections.flatMap(section => section.items).find(item => item.path === path)
