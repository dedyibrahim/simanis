<script setup lang="ts">
definePageMeta({
  middleware: 'auth',
})

type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type RekananMaster = {
  id: number
  nama_ppat: string
  alamat?: string | null
  no_hp?: string | null
  aktif?: boolean | number
}

type RekananKeluar = {
  id_buku_ppat: string
  no_akta: string | number
  tanggal_akta?: string
  nama_akta?: string | null
  pengambil?: string | null
  keterangan?: string | null
  ppat_rekanan_keluar_id?: number | null
  nama_ppat_rekanan?: string | null
  rekanan_keluar_catatan?: string | null
}

type RekananKedalam = {
  id: number
  ppat_rekanan_id: number
  nama_ppat?: string
  no_akta: string
  tanggal_akta: string
  id_akta?: string | null
  nama_akta?: string | null
  nama_akta_manual?: string | null
  pihak_mengalihkan?: string | null
  pihak_menerima?: string | null
  no_hak_milik?: string | null
  luas_tanah?: string | number | null
  luas_bangunan?: string | number | null
  harga_transaksi?: string | null
  nop?: string | null
  harga_njop?: string | null
  tgl_bphtb?: string | null
  harga_bphtb?: string | null
  tgl_pph?: string | null
  harga_pph?: string | null
  keterangan?: string | null
  pembuat?: string | null
}

type AktaOption = {
  id_akta: string | number
  nama_akta?: string
  pekerjaan_milik?: string
}

const business = useLegacyBusiness()
const route = useRoute()
const router = useRouter()
const { isDark } = useThemeMode()

useHead({
  title: 'PPAT Rekanan | SIMANIS',
})

type RekananTab = 'master' | 'keluar' | 'kedalam'

const normalizeTab = (value: unknown): RekananTab => {
  const tab = String(value || '').toLowerCase()
  return tab === 'master' || tab === 'kedalam' ? tab : 'keluar'
}

const activeTab = ref<RekananTab>(normalizeTab(route.query.tab))
const period = ref(new Date().toISOString().slice(0, 7))
const search = ref('')
const loading = ref(false)
const saving = ref(false)
const message = ref('')
const error = ref('')

const masters = ref<RekananMaster[]>([])
const keluarRows = ref<RekananKeluar[]>([])
const kedalamRows = ref<RekananKedalam[]>([])
const aktaOptions = ref<AktaOption[]>([])

const masterDialog = reactive({
  open: false,
  mode: 'create' as 'create' | 'edit',
  id: 0,
  nama_ppat: '',
  alamat: '',
  no_hp: '',
  aktif: true,
})

const keluarDialog = reactive({
  open: false,
  row: null as RekananKeluar | null,
  ppat_rekanan_id: '',
  catatan: '',
})

const kedalamDialog = reactive({
  open: false,
  mode: 'create' as 'create' | 'edit',
  id: 0,
  ppat_rekanan_id: '',
  no_akta: '',
  tanggal_akta: new Date().toISOString().slice(0, 10),
  id_akta: '',
  nama_akta_manual: '',
  pihak_mengalihkan: '',
  pihak_menerima: '',
  no_hak_milik: '',
  luas_tanah: '',
  luas_bangunan: '',
  harga_transaksi: '',
  nop: '',
  harga_njop: '',
  tgl_bphtb: '',
  harga_bphtb: '',
  tgl_pph: '',
  harga_pph: '',
  keterangan: '',
})

const activeMasters = computed(() => masters.value.filter(item => Boolean(item.aktif)))
const pageClass = computed(() =>
  isDark.value
    ? 'bg-slate-950 text-slate-100'
    : 'bg-transparent text-slate-900',
)
const surfaceClass = computed(() =>
  isDark.value
    ? 'border-white/10 bg-slate-900/80 shadow-2xl'
    : 'border-slate-200 bg-white shadow-sm',
)
const panelClass = computed(() =>
  isDark.value
    ? 'border-white/10 bg-slate-900/80 shadow-2xl'
    : 'border-slate-200 bg-white shadow-sm',
)
const panelHeaderClass = computed(() =>
  isDark.value ? 'border-white/10' : 'border-slate-200 bg-slate-50',
)
const inputClass = computed(() =>
  isDark.value
    ? 'border-white/10 bg-slate-950 text-white placeholder:text-slate-500 focus:border-blue-400'
    : 'border-slate-200 bg-white text-slate-800 placeholder:text-slate-400 focus:border-blue-400 focus:ring-2 focus:ring-blue-100',
)
const ghostButtonClass = computed(() =>
  isDark.value
    ? 'border-white/10 text-white hover:bg-white/10'
    : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50',
)
const tableHeadClass = computed(() =>
  isDark.value
    ? 'bg-slate-950/80 text-slate-400'
    : 'bg-slate-50 text-slate-500',
)
const tableDivideClass = computed(() => isDark.value ? 'divide-white/10' : 'divide-slate-100')
const rowHoverClass = computed(() => isDark.value ? 'hover:bg-white/5' : 'hover:bg-slate-50')
const primaryTextClass = computed(() => isDark.value ? 'text-white' : 'text-slate-900')
const secondaryTextClass = computed(() => isDark.value ? 'text-slate-300' : 'text-slate-600')
const mutedTextClass = computed(() => isDark.value ? 'text-slate-400' : 'text-slate-500')
const emptyTextClass = computed(() => isDark.value ? 'text-slate-400' : 'text-slate-500')
const activeBadgeClass = computed(() =>
  isDark.value ? 'bg-emerald-500/15 text-emerald-200' : 'bg-emerald-50 text-emerald-700',
)
const inactiveBadgeClass = computed(() =>
  isDark.value ? 'bg-slate-500/20 text-slate-300' : 'bg-slate-100 text-slate-600',
)
const rekananMarkedTextClass = computed(() => isDark.value ? 'text-amber-200' : 'text-amber-700')
const messageClass = computed(() =>
  isDark.value
    ? 'border-emerald-400/30 bg-emerald-500/10 text-emerald-200'
    : 'border-emerald-200 bg-emerald-50 text-emerald-700',
)
const errorClass = computed(() =>
  isDark.value
    ? 'border-red-400/30 bg-red-500/10 text-red-200'
    : 'border-red-200 bg-red-50 text-red-700',
)

const toArray = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && Array.isArray((payload as ApiEnvelope<T[]>).data)) {
    return (payload as ApiEnvelope<T[]>).data || []
  }
  return []
}

const responseMessage = (payload: unknown, fallback: string) => {
  if (payload && typeof payload === 'object') {
    const msg = String((payload as ApiEnvelope).message || '').trim()
    if (msg) return msg
  }
  return fallback
}

const formatDate = (value?: string | null) => {
  if (!value) return '-'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleDateString('id-ID')
}

const getRekananName = (id: string | number | null | undefined) =>
  masters.value.find(item => Number(item.id) === Number(id))?.nama_ppat || '-'

const getJenisAktaName = (row: RekananKedalam) =>
  row.nama_akta || row.nama_akta_manual || aktaOptions.value.find(item => String(item.id_akta) === String(row.id_akta || ''))?.nama_akta || '-'

const loadMaster = async () => {
  const response = await business.bukuRekanan.getMaster({ search: search.value }) as ApiEnvelope<RekananMaster[]>
  masters.value = toArray<RekananMaster>(response)
}

const loadAktaOptions = async () => {
  const response = await business.order.getDaftarAkta({ judul_akta: '', tipe: 'PPAT' }) as ApiEnvelope<AktaOption[]>
  aktaOptions.value = toArray<AktaOption>(response)
}

const loadKeluar = async () => {
  const response = await business.bukuRekanan.getKeluar({ date: period.value, search: search.value }) as ApiEnvelope<RekananKeluar[]>
  keluarRows.value = toArray<RekananKeluar>(response)
}

const loadKedalam = async () => {
  const response = await business.bukuRekanan.getKedalam({ date: period.value, search: search.value }) as ApiEnvelope<RekananKedalam[]>
  kedalamRows.value = toArray<RekananKedalam>(response)
}

const loadData = async () => {
  loading.value = true
  error.value = ''
  message.value = ''
  try {
    await loadMaster()
    if (!aktaOptions.value.length) {
      await loadAktaOptions()
    }
    if (activeTab.value === 'keluar') {
      await loadKeluar()
    }
    if (activeTab.value === 'kedalam') {
      await loadKedalam()
    }
  } catch (err) {
    error.value = (err as { data?: { message?: string } })?.data?.message || 'Gagal memuat data PPAT rekanan.'
  } finally {
    loading.value = false
  }
}

const switchTab = async (tab: RekananTab) => {
  activeTab.value = tab
  await router.replace({ path: '/ppat-rekanan', query: { ...route.query, tab } })
  await loadData()
}

const openCreateMaster = () => {
  masterDialog.mode = 'create'
  masterDialog.id = 0
  masterDialog.nama_ppat = ''
  masterDialog.alamat = ''
  masterDialog.no_hp = ''
  masterDialog.aktif = true
  masterDialog.open = true
}

const openEditMaster = (row: RekananMaster) => {
  masterDialog.mode = 'edit'
  masterDialog.id = row.id
  masterDialog.nama_ppat = row.nama_ppat || ''
  masterDialog.alamat = String(row.alamat || '')
  masterDialog.no_hp = String(row.no_hp || '')
  masterDialog.aktif = Boolean(row.aktif)
  masterDialog.open = true
}

const saveMaster = async () => {
  if (saving.value) return
  if (!masterDialog.nama_ppat.trim()) {
    error.value = 'Nama PPAT wajib diisi.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    const payload = {
      nama_ppat: masterDialog.nama_ppat,
      alamat: masterDialog.alamat,
      no_hp: masterDialog.no_hp,
      aktif: masterDialog.aktif,
    }
    const response = masterDialog.mode === 'edit'
      ? await business.bukuRekanan.updateMaster(masterDialog.id, payload)
      : await business.bukuRekanan.createMaster(payload)
    message.value = responseMessage(response, 'Master PPAT rekanan berhasil disimpan.')
    masterDialog.open = false
    await loadData()
  } catch (err) {
    error.value = (err as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan master PPAT rekanan.'
  } finally {
    saving.value = false
  }
}

const deleteMaster = async (row: RekananMaster) => {
  if (!window.confirm(`Hapus/nonaktifkan PPAT rekanan ${row.nama_ppat}?`)) return
  saving.value = true
  error.value = ''
  try {
    const response = await business.bukuRekanan.deleteMaster(row.id)
    message.value = responseMessage(response, 'Master PPAT rekanan berhasil dihapus.')
    await loadData()
  } catch (err) {
    error.value = (err as { data?: { message?: string } })?.data?.message || 'Gagal menghapus master PPAT rekanan.'
  } finally {
    saving.value = false
  }
}

const openKeluarDialog = (row: RekananKeluar) => {
  keluarDialog.row = row
  keluarDialog.ppat_rekanan_id = row.ppat_rekanan_keluar_id ? String(row.ppat_rekanan_keluar_id) : ''
  keluarDialog.catatan = String(row.rekanan_keluar_catatan || '')
  keluarDialog.open = true
}

const saveKeluar = async () => {
  if (!keluarDialog.row || saving.value) return
  if (!keluarDialog.ppat_rekanan_id) {
    error.value = 'Pilih PPAT rekanan terlebih dahulu.'
    return
  }
  saving.value = true
  error.value = ''
  try {
    const response = await business.bukuRekanan.markKeluar({
      id_buku_ppat: keluarDialog.row.id_buku_ppat,
      ppat_rekanan_id: keluarDialog.ppat_rekanan_id,
      catatan: keluarDialog.catatan,
    })
    message.value = responseMessage(response, 'Nomor PPAT berhasil ditandai rekanan keluar.')
    keluarDialog.open = false
    await loadData()
  } catch (err) {
    error.value = (err as { data?: { message?: string } })?.data?.message || 'Gagal menandai rekanan keluar.'
  } finally {
    saving.value = false
  }
}

const unmarkKeluar = async (row: RekananKeluar) => {
  if (!window.confirm(`Lepas tanda rekanan keluar untuk Akta PPAT No ${row.no_akta}?`)) return
  saving.value = true
  error.value = ''
  try {
    const response = await business.bukuRekanan.unmarkKeluar({ id_buku_ppat: row.id_buku_ppat })
    message.value = responseMessage(response, 'Tanda rekanan keluar berhasil dilepas.')
    await loadData()
  } catch (err) {
    error.value = (err as { data?: { message?: string } })?.data?.message || 'Gagal melepas tanda rekanan keluar.'
  } finally {
    saving.value = false
  }
}

const resetKedalamDialog = () => {
  kedalamDialog.mode = 'create'
  kedalamDialog.id = 0
  kedalamDialog.ppat_rekanan_id = ''
  kedalamDialog.no_akta = ''
  kedalamDialog.tanggal_akta = new Date().toISOString().slice(0, 10)
  kedalamDialog.id_akta = ''
  kedalamDialog.nama_akta_manual = ''
  kedalamDialog.pihak_mengalihkan = ''
  kedalamDialog.pihak_menerima = ''
  kedalamDialog.no_hak_milik = ''
  kedalamDialog.luas_tanah = ''
  kedalamDialog.luas_bangunan = ''
  kedalamDialog.harga_transaksi = ''
  kedalamDialog.nop = ''
  kedalamDialog.harga_njop = ''
  kedalamDialog.tgl_bphtb = ''
  kedalamDialog.harga_bphtb = ''
  kedalamDialog.tgl_pph = ''
  kedalamDialog.harga_pph = ''
  kedalamDialog.keterangan = ''
}

const openCreateKedalam = () => {
  resetKedalamDialog()
  kedalamDialog.open = true
}

const openEditKedalam = (row: RekananKedalam) => {
  resetKedalamDialog()
  kedalamDialog.mode = 'edit'
  kedalamDialog.id = row.id
  kedalamDialog.ppat_rekanan_id = String(row.ppat_rekanan_id || '')
  kedalamDialog.no_akta = String(row.no_akta || '')
  kedalamDialog.tanggal_akta = String(row.tanggal_akta || '').slice(0, 10)
  kedalamDialog.id_akta = String(row.id_akta || '')
  kedalamDialog.nama_akta_manual = String(row.nama_akta_manual || '')
  kedalamDialog.pihak_mengalihkan = String(row.pihak_mengalihkan || '')
  kedalamDialog.pihak_menerima = String(row.pihak_menerima || '')
  kedalamDialog.no_hak_milik = String(row.no_hak_milik || '')
  kedalamDialog.luas_tanah = String(row.luas_tanah || '')
  kedalamDialog.luas_bangunan = String(row.luas_bangunan || '')
  kedalamDialog.harga_transaksi = String(row.harga_transaksi || '')
  kedalamDialog.nop = String(row.nop || '')
  kedalamDialog.harga_njop = String(row.harga_njop || '')
  kedalamDialog.tgl_bphtb = String(row.tgl_bphtb || '').slice(0, 10)
  kedalamDialog.harga_bphtb = String(row.harga_bphtb || '')
  kedalamDialog.tgl_pph = String(row.tgl_pph || '').slice(0, 10)
  kedalamDialog.harga_pph = String(row.harga_pph || '')
  kedalamDialog.keterangan = String(row.keterangan || '')
  kedalamDialog.open = true
}

const kedalamPayload = () => ({
  ppat_rekanan_id: kedalamDialog.ppat_rekanan_id,
  no_akta: kedalamDialog.no_akta,
  tanggal_akta: kedalamDialog.tanggal_akta,
  id_akta: kedalamDialog.id_akta || null,
  nama_akta_manual: kedalamDialog.nama_akta_manual,
  pihak_mengalihkan: kedalamDialog.pihak_mengalihkan,
  pihak_menerima: kedalamDialog.pihak_menerima,
  no_hak_milik: kedalamDialog.no_hak_milik,
  luas_tanah: kedalamDialog.luas_tanah || null,
  luas_bangunan: kedalamDialog.luas_bangunan || null,
  harga_transaksi: kedalamDialog.harga_transaksi,
  nop: kedalamDialog.nop,
  harga_njop: kedalamDialog.harga_njop,
  tgl_bphtb: kedalamDialog.tgl_bphtb || null,
  harga_bphtb: kedalamDialog.harga_bphtb,
  tgl_pph: kedalamDialog.tgl_pph || null,
  harga_pph: kedalamDialog.harga_pph,
  keterangan: kedalamDialog.keterangan,
})

const saveKedalam = async () => {
  if (saving.value) return
  if (!kedalamDialog.ppat_rekanan_id || !kedalamDialog.no_akta || !kedalamDialog.tanggal_akta) {
    error.value = 'PPAT rekanan, no akta, dan tanggal akta wajib diisi.'
    return
  }

  saving.value = true
  error.value = ''
  try {
    const response = kedalamDialog.mode === 'edit'
      ? await business.bukuRekanan.updateKedalam(kedalamDialog.id, kedalamPayload())
      : await business.bukuRekanan.createKedalam(kedalamPayload())
    message.value = responseMessage(response, 'Akta PPAT rekanan kedalam berhasil disimpan.')
    kedalamDialog.open = false
    await loadData()
  } catch (err) {
    error.value = (err as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan akta PPAT rekanan kedalam.'
  } finally {
    saving.value = false
  }
}

const deleteKedalam = async (row: RekananKedalam) => {
  if (!window.confirm(`Hapus akta rekanan kedalam No ${row.no_akta}?`)) return
  saving.value = true
  error.value = ''
  try {
    const response = await business.bukuRekanan.deleteKedalam(row.id)
    message.value = responseMessage(response, 'Akta PPAT rekanan kedalam berhasil dihapus.')
    await loadData()
  } catch (err) {
    error.value = (err as { data?: { message?: string } })?.data?.message || 'Gagal menghapus akta PPAT rekanan kedalam.'
  } finally {
    saving.value = false
  }
}

watch([period, search], () => {
  void loadData()
})

watch(
  () => route.query.tab,
  (tab) => {
    const nextTab = normalizeTab(tab)
    if (nextTab === activeTab.value) return
    activeTab.value = nextTab
    void loadData()
  },
)

onMounted(() => {
  void loadData()
})
</script>

<template>
  <div class="min-h-screen px-2 py-6 sm:px-4 lg:px-6" :class="pageClass">
    <section class="rounded-[2rem] border p-6" :class="surfaceClass">
      <div>
        <div>
          <p class="text-xs font-black uppercase tracking-[0.45em] text-blue-500">PPAT Rekanan</p>
          <h1 class="mt-2 text-3xl font-black">Kontrol Rekanan PPAT</h1>
          <p class="mt-2 max-w-3xl text-sm font-medium" :class="secondaryTextClass">
            Kelola master PPAT rekanan, tandai nomor PPAT kantor yang dipakai PPAT lain, dan catat nomor PPAT rekanan yang masuk ke database.
          </p>
        </div>
      </div>

      <div class="mt-6 grid gap-3 lg:grid-cols-[180px_1fr_auto]">
        <input v-model="period" type="month" class="h-11 rounded-2xl border px-4 text-sm font-bold focus:outline-none" :class="inputClass" :disabled="activeTab === 'master'" />
        <input v-model="search" type="search" class="h-11 rounded-2xl border px-4 text-sm font-bold focus:outline-none" :class="inputClass" placeholder="Cari nama PPAT, nomor, jenis akta, pihak, atau keterangan..." />
        <div class="flex gap-2">
          <button type="button" class="h-11 rounded-2xl border px-4 text-sm font-extrabold transition" :class="ghostButtonClass" @click="loadData">Refresh</button>
          <button v-if="activeTab === 'master'" type="button" class="h-11 rounded-2xl bg-emerald-500 px-4 text-sm font-extrabold text-white hover:bg-emerald-400" @click="openCreateMaster">Tambah Rekanan</button>
          <button v-if="activeTab === 'kedalam'" type="button" class="h-11 rounded-2xl bg-emerald-500 px-4 text-sm font-extrabold text-white hover:bg-emerald-400" @click="openCreateKedalam">Tambah Kedalam</button>
        </div>
      </div>

      <div v-if="message" class="mt-4 rounded-2xl border px-4 py-3 text-sm font-bold" :class="messageClass">{{ message }}</div>
      <div v-if="error" class="mt-4 rounded-2xl border px-4 py-3 text-sm font-bold" :class="errorClass">{{ error }}</div>
    </section>

    <section class="mt-6 overflow-hidden rounded-[2rem] border" :class="panelClass">
      <div class="border-b px-6 py-4" :class="panelHeaderClass">
        <p class="text-sm font-black" :class="primaryTextClass">
          {{ activeTab === 'master' ? 'Master Data Rekanan' : activeTab === 'keluar' ? 'Nomor PPAT Dipakai Rekanan' : 'Akta PPAT Rekanan Kedalam' }}
        </p>
      </div>

      <div v-if="loading" class="p-6 text-sm font-bold" :class="secondaryTextClass">Memuat data...</div>

      <div v-else-if="activeTab === 'master'" class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="text-xs uppercase tracking-widest" :class="tableHeadClass">
            <tr>
              <th class="px-6 py-4">Nama PPAT</th>
              <th class="px-6 py-4">Alamat</th>
              <th class="px-6 py-4">No HP</th>
              <th class="px-6 py-4">Status</th>
              <th class="px-6 py-4 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y" :class="tableDivideClass">
            <tr v-for="row in masters" :key="row.id" :class="rowHoverClass">
              <td class="px-6 py-4 font-extrabold" :class="primaryTextClass">{{ row.nama_ppat }}</td>
              <td class="px-6 py-4" :class="secondaryTextClass">{{ row.alamat || '-' }}</td>
              <td class="px-6 py-4" :class="secondaryTextClass">{{ row.no_hp || '-' }}</td>
              <td class="px-6 py-4">
                <span class="rounded-full px-3 py-1 text-xs font-black" :class="row.aktif ? activeBadgeClass : inactiveBadgeClass">
                  {{ row.aktif ? 'Aktif' : 'Nonaktif' }}
                </span>
              </td>
              <td class="px-6 py-4">
                <div class="flex justify-end gap-2">
                  <button class="rounded-xl border border-blue-400/30 px-3 py-1.5 text-xs font-bold text-blue-600 hover:bg-blue-500/10" @click="openEditMaster(row)">Edit</button>
                  <button class="rounded-xl border border-red-400/30 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-500/10" @click="deleteMaster(row)">Hapus</button>
                </div>
              </td>
            </tr>
            <tr v-if="!masters.length">
              <td colspan="5" class="px-6 py-8 text-center" :class="emptyTextClass">Belum ada master PPAT rekanan.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else-if="activeTab === 'keluar'" class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="text-xs uppercase tracking-widest" :class="tableHeadClass">
            <tr>
              <th class="px-6 py-4">No Akta</th>
              <th class="px-6 py-4">Tanggal</th>
              <th class="px-6 py-4">Jenis</th>
              <th class="px-6 py-4">Pengambil</th>
              <th class="px-6 py-4">Rekanan</th>
              <th class="px-6 py-4 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y" :class="tableDivideClass">
            <tr v-for="row in keluarRows" :key="row.id_buku_ppat" :class="[rowHoverClass, row.ppat_rekanan_keluar_id ? 'bg-amber-500/10' : '']">
              <td class="px-6 py-4">
                <span class="rounded-xl bg-blue-500 px-3 py-1 text-xs font-black text-white">No {{ row.no_akta }}</span>
              </td>
              <td class="px-6 py-4" :class="secondaryTextClass">{{ formatDate(row.tanggal_akta) }}</td>
              <td class="px-6 py-4 font-bold" :class="primaryTextClass">{{ row.nama_akta || '-' }}</td>
              <td class="px-6 py-4" :class="secondaryTextClass">{{ row.pengambil || '-' }}</td>
              <td class="px-6 py-4">
                <div v-if="row.ppat_rekanan_keluar_id">
                  <p class="font-extrabold" :class="rekananMarkedTextClass">{{ row.nama_ppat_rekanan || getRekananName(row.ppat_rekanan_keluar_id) }}</p>
                  <p class="text-xs" :class="mutedTextClass">{{ row.rekanan_keluar_catatan || 'Dipakai oleh PPAT rekanan' }}</p>
                </div>
                <span v-else :class="mutedTextClass">Nomor kantor sendiri</span>
              </td>
              <td class="px-6 py-4">
                <div class="flex justify-end gap-2">
                  <button class="rounded-xl border border-amber-400/30 px-3 py-1.5 text-xs font-bold text-amber-200 hover:bg-amber-500/10" @click="openKeluarDialog(row)">
                    {{ row.ppat_rekanan_keluar_id ? 'Ubah Rekanan' : 'Tandai Rekanan' }}
                  </button>
                  <button v-if="row.ppat_rekanan_keluar_id" class="rounded-xl border border-red-400/30 px-3 py-1.5 text-xs font-bold text-red-200 hover:bg-red-500/10" @click="unmarkKeluar(row)">Lepas</button>
                </div>
              </td>
            </tr>
            <tr v-if="!keluarRows.length">
              <td colspan="6" class="px-6 py-8 text-center" :class="emptyTextClass">Tidak ada nomor PPAT pada periode ini.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
          <thead class="text-xs uppercase tracking-widest" :class="tableHeadClass">
            <tr>
              <th class="px-6 py-4">PPAT Rekanan</th>
              <th class="px-6 py-4">No Akta</th>
              <th class="px-6 py-4">Tanggal</th>
              <th class="px-6 py-4">Jenis</th>
              <th class="px-6 py-4">Pihak</th>
              <th class="px-6 py-4">Objek</th>
              <th class="px-6 py-4 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y" :class="tableDivideClass">
            <tr v-for="row in kedalamRows" :key="row.id" :class="rowHoverClass">
              <td class="px-6 py-4 font-extrabold" :class="primaryTextClass">{{ row.nama_ppat || getRekananName(row.ppat_rekanan_id) }}</td>
              <td class="px-6 py-4"><span class="rounded-xl bg-violet-500 px-3 py-1 text-xs font-black text-white">{{ row.no_akta }}</span></td>
              <td class="px-6 py-4" :class="secondaryTextClass">{{ formatDate(row.tanggal_akta) }}</td>
              <td class="px-6 py-4" :class="secondaryTextClass">{{ getJenisAktaName(row) }}</td>
              <td class="px-6 py-4" :class="secondaryTextClass">
                <p>{{ row.pihak_mengalihkan || '-' }}</p>
                <p class="text-xs" :class="mutedTextClass">ke {{ row.pihak_menerima || '-' }}</p>
              </td>
              <td class="px-6 py-4" :class="secondaryTextClass">{{ row.no_hak_milik || row.nop || '-' }}</td>
              <td class="px-6 py-4">
                <div class="flex justify-end gap-2">
                  <button class="rounded-xl border border-blue-400/30 px-3 py-1.5 text-xs font-bold text-blue-600 hover:bg-blue-500/10" @click="openEditKedalam(row)">Edit</button>
                  <button class="rounded-xl border border-red-400/30 px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-500/10" @click="deleteKedalam(row)">Hapus</button>
                </div>
              </td>
            </tr>
            <tr v-if="!kedalamRows.length">
              <td colspan="7" class="px-6 py-8 text-center" :class="emptyTextClass">Belum ada akta PPAT rekanan kedalam pada periode ini.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="masterDialog.open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4">
      <div class="w-full max-w-2xl rounded-[2rem] bg-white p-6 text-slate-900 shadow-2xl">
        <h2 class="text-2xl font-black">{{ masterDialog.mode === 'edit' ? 'Edit PPAT Rekanan' : 'Tambah PPAT Rekanan' }}</h2>
        <div class="mt-5 grid gap-3">
          <input v-model="masterDialog.nama_ppat" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="Nama PPAT" />
          <textarea v-model="masterDialog.alamat" rows="3" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-bold" placeholder="Alamat" />
          <input v-model="masterDialog.no_hp" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="No HP" />
          <label class="flex items-center gap-2 text-sm font-bold">
            <input v-model="masterDialog.aktif" type="checkbox" />
            Aktif
          </label>
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button class="rounded-2xl border border-slate-200 px-4 py-2 text-sm font-bold" @click="masterDialog.open = false">Batal</button>
          <button class="rounded-2xl bg-blue-600 px-4 py-2 text-sm font-black text-white" :disabled="saving" @click="saveMaster">Simpan</button>
        </div>
      </div>
    </div>

    <div v-if="keluarDialog.open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4">
      <div class="w-full max-w-2xl rounded-[2rem] bg-white p-6 text-slate-900 shadow-2xl">
        <h2 class="text-2xl font-black">Tandai Rekanan Keluar</h2>
        <p class="mt-2 text-sm text-slate-500">Akta PPAT No {{ keluarDialog.row?.no_akta }} akan ditandai dipakai PPAT lain.</p>
        <div class="mt-5 grid gap-3">
          <select v-model="keluarDialog.ppat_rekanan_id" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold">
            <option value="">Pilih PPAT rekanan</option>
            <option v-for="item in activeMasters" :key="item.id" :value="String(item.id)">{{ item.nama_ppat }}</option>
          </select>
          <textarea v-model="keluarDialog.catatan" rows="3" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-bold" placeholder="Catatan opsional" />
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button class="rounded-2xl border border-slate-200 px-4 py-2 text-sm font-bold" @click="keluarDialog.open = false">Batal</button>
          <button class="rounded-2xl bg-amber-500 px-4 py-2 text-sm font-black text-white" :disabled="saving" @click="saveKeluar">Simpan Tanda</button>
        </div>
      </div>
    </div>

    <div v-if="kedalamDialog.open" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 p-4">
      <div class="mx-auto my-6 w-full max-w-5xl rounded-[2rem] bg-white p-6 text-slate-900 shadow-2xl">
        <h2 class="text-2xl font-black">{{ kedalamDialog.mode === 'edit' ? 'Edit Rekanan Kedalam' : 'Tambah Rekanan Kedalam' }}</h2>
        <div class="mt-5 grid gap-3 md:grid-cols-2">
          <select v-model="kedalamDialog.ppat_rekanan_id" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold">
            <option value="">Pilih PPAT rekanan</option>
            <option v-for="item in activeMasters" :key="item.id" :value="String(item.id)">{{ item.nama_ppat }}</option>
          </select>
          <input v-model="kedalamDialog.no_akta" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="No Akta PPAT Rekanan" />
          <input v-model="kedalamDialog.tanggal_akta" type="date" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" />
          <select v-model="kedalamDialog.id_akta" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold">
            <option value="">Pilih jenis akta SIMANIS / isi manual</option>
            <option v-for="item in aktaOptions" :key="String(item.id_akta)" :value="String(item.id_akta)">{{ item.nama_akta || item.id_akta }}</option>
          </select>
          <input v-model="kedalamDialog.nama_akta_manual" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold md:col-span-2" placeholder="Nama akta manual jika tidak ada di master" />
          <textarea v-model="kedalamDialog.pihak_mengalihkan" rows="2" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-bold" placeholder="Pihak mengalihkan" />
          <textarea v-model="kedalamDialog.pihak_menerima" rows="2" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-bold" placeholder="Pihak menerima" />
          <input v-model="kedalamDialog.no_hak_milik" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="No hak milik / sertifikat" />
          <input v-model="kedalamDialog.nop" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="NOP" />
          <input v-model="kedalamDialog.luas_tanah" type="number" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="Luas tanah" />
          <input v-model="kedalamDialog.luas_bangunan" type="number" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="Luas bangunan" />
          <input v-model="kedalamDialog.harga_transaksi" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="Harga transaksi" />
          <input v-model="kedalamDialog.harga_njop" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="Harga NJOP" />
          <input v-model="kedalamDialog.tgl_bphtb" type="date" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" />
          <input v-model="kedalamDialog.harga_bphtb" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="Harga BPHTB" />
          <input v-model="kedalamDialog.tgl_pph" type="date" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" />
          <input v-model="kedalamDialog.harga_pph" class="h-12 rounded-2xl border border-slate-200 px-4 text-sm font-bold" placeholder="Harga PPH" />
          <textarea v-model="kedalamDialog.keterangan" rows="3" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm font-bold md:col-span-2" placeholder="Keterangan" />
        </div>
        <div class="mt-6 flex justify-end gap-2">
          <button class="rounded-2xl border border-slate-200 px-4 py-2 text-sm font-bold" @click="kedalamDialog.open = false">Batal</button>
          <button class="rounded-2xl bg-blue-600 px-4 py-2 text-sm font-black text-white" :disabled="saving" @click="saveKedalam">Simpan</button>
        </div>
      </div>
    </div>
  </div>
</template>
