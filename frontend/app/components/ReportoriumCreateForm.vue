<script setup lang="ts">
type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type AktaOption = {
  id_akta: string | number
  nama_akta?: string
  apht?: string | null
}

type ClientOption = {
  id_client: string | number
  nama_client?: string
  nama_pencarian?: string
}

type AsistenOption = {
  id_user: string | number
  nama_lengkap?: string
}

type PenghadapRow = {
  id_client: string
  nama_client: string
  status_kedudukan: string
  id_mewakili: string
  mewakili: string
}

type RowRecord = Record<string, unknown>

const props = defineProps<{ path: string }>()
const emit = defineEmits<{ saved: [message?: string] }>()

const business = useLegacyBusiness()
const { user } = useSession()

const inputClass
  = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100'
const textAreaClass
  = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100'
const secondaryButtonClass
  = 'h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50'

const createPaths = [
  '/buku_akta',
  '/buku_legalisasi',
  '/buku_waarmerking',
  '/buku_ppat',
  '/buku_surat_notaris',
  '/buku_surat_ppat',
  '/tanda_terima',
  '/tanda_terima_masuk',
]

const moduleTitleMap: Record<string, string> = {
  '/buku_akta': 'Buku Akta Notaris',
  '/buku_legalisasi': 'Buku Legalisasi',
  '/buku_waarmerking': 'Buku Waarmerking',
  '/buku_ppat': 'Buku PPAT',
  '/buku_surat_notaris': 'Buku Surat Notaris',
  '/buku_surat_ppat': 'Buku Surat PPAT',
  '/tanda_terima': 'Tanda Terima Keluar',
  '/tanda_terima_masuk': 'Tanda Terima Masuk',
}

const today = () => new Date().toISOString().slice(0, 10)
const normalizeDateInput = (value: unknown): string => {
  const text = String(value || '').trim()
  if (!text) return ''
  if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return text
  if (/^\d{4}-\d{2}-\d{2}T/.test(text)) return text.slice(0, 10)
  if (/^\d{4}-\d{2}-\d{2}\s/.test(text)) return text.slice(0, 10)
  if (/^\d{2}\/\d{2}\/\d{4}$/.test(text)) {
    const [day, month, year] = text.split('/')
    return `${year}-${month}-${day}`
  }
  return ''
}
const isNumeric = (value: unknown) => {
  const normalized = String(value ?? '').trim()
  return normalized !== '' && !Number.isNaN(Number(normalized))
}

const toMessage = (payload: unknown, fallback: string): string => {
  if (payload && typeof payload === 'object' && 'message' in payload) {
    const msg = String((payload as ApiEnvelope).message || '').trim()
    if (msg) {
      return msg
    }
  }
  return fallback
}

const toArray = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) {
    return payload as T[]
  }
  if (payload && typeof payload === 'object' && Array.isArray((payload as ApiEnvelope<T[]>).data)) {
    return (payload as ApiEnvelope<T[]>).data || []
  }
  return []
}

const toData = <T>(payload: unknown): T => {
  if (payload && typeof payload === 'object' && 'data' in payload) {
    return ((payload as ApiEnvelope<T>).data || {}) as T
  }
  return (payload || {}) as T
}

const visible = computed(() => createPaths.includes(props.path))
const isNotaris = computed(() => props.path === '/buku_akta')
const isLegalisasi = computed(() => props.path === '/buku_legalisasi')
const isWaarmerking = computed(() => props.path === '/buku_waarmerking')
const isPpat = computed(() => props.path === '/buku_ppat')
const isPpatLike = computed(() => isPpat.value)
const isSuratNotaris = computed(() => props.path === '/buku_surat_notaris')
const isSuratPpat = computed(() => props.path === '/buku_surat_ppat')
const isSurat = computed(() => isSuratNotaris.value || isSuratPpat.value)
const isTandaTerimaKeluar = computed(() => props.path === '/tanda_terima')
const isTandaTerimaMasuk = computed(() => props.path === '/tanda_terima_masuk')
const isTandaTerima = computed(() => isTandaTerimaKeluar.value || isTandaTerimaMasuk.value)
const needsPenghadap = computed(() => isNotaris.value || isLegalisasi.value || isWaarmerking.value || isPpatLike.value)
const tandaTerimaStatus = computed(() => (isTandaTerimaKeluar.value ? 'Keluar' : 'Masuk'))
const aphtMode = computed(() => String(ppatForm.apht || '').toUpperCase() === 'TRUE')
const requireAsisten = computed(() => {
  const level = String(user.value?.level_user || '')
  return isNotaris.value && (level === 'Admin' || level === 'Super Admin')
})
const canCreate = computed(() => String(user.value?.level_user || '') !== 'Arsip')

const aktaOptions = ref<AktaOption[]>([])
const asistenOptions = ref<AsistenOption[]>([])
const clientOptions = ref<ClientOption[]>([])
const suratClientOptions = ref<ClientOption[]>([])
const clientCache = reactive<Record<string, ClientOption>>({})

const loadingAkta = ref(false)
const loadingAsisten = ref(false)
const loadingClient = ref(false)
const loadingSuratClient = ref(false)
const submitting = ref(false)
const showDialog = ref(false)
const mode = ref<'create' | 'edit'>('create')
const notarisMinDate = ref(today())

const message = ref('')
const error = ref('')

const notarisForm = reactive({
  id_buku_notaris: '',
  no_akta: '',
  jenis_akta: '',
  judul_pekerjaan: '',
  tgl_akta: today(),
  nama_asisten: '',
  sudah_tanda_tangan: false,
})

const legalisasiForm = reactive({
  id_buku_legalisasi: '',
  no_legalisasi: '',
  judul_surat: '',
  keterangan_surat: '',
})

const waarmerkingForm = reactive({
  id_buku_warmerking: '',
  no_warmerking: '',
  judul_surat: '',
  keterangan_surat: '',
})

const ppatForm = reactive({
  id_buku_ppat: '',
  no_akta: '',
  jenis_akta: '',
  sudah_tanda_tangan: false,
  apht: '',
  no_hak_milik: '',
  luas_tanah: '',
  luas_bangunan: '',
  harga_transaksi: '',
  nop: '',
  harga_njop: '',
  tgl_bphtb: today(),
  harga_bphtb: '',
  tgl_pph: today(),
  harga_pph: '',
  keterangan: '',
})

const suratForm = reactive({
  id_surat_notaris: '',
  id_surat_ppat: '',
  no_surat: '',
  id_client: '',
  pengirim: '',
  keterangan: '',
})

const tandaTerimaForm = reactive({
  id: '',
  tgl_terima: today(),
  nama_pengirim: '',
  nama_penerima: '',
  up_penerima: '',
  lokasi: '',
  keterangan_tanda_terima: '',
  status: '',
  isi_diterimas: [] as Array<{ id?: string, isi_diterima: string }>,
})

const penghadapRows = ref<PenghadapRow[]>([])
const penghadapDraft = reactive({
  status_kedudukan: '',
})
const penghadapInput = ref('')
const mewakiliInput = ref('')
const suratClientQuery = ref('')
const isiDiterimaInput = ref('')

let lookupTimer: ReturnType<typeof setTimeout> | undefined
let suratLookupTimer: ReturnType<typeof setTimeout> | undefined

const modeLabel = computed(() => (mode.value === 'edit' ? 'Edit Data' : 'Create Data'))
const openFormLabel = computed(() => 'Ambil Nomor')
const submitLabel = computed(() => {
  if (submitting.value) return mode.value === 'edit' ? 'Menyimpan Perubahan...' : 'Menyimpan...'
  return mode.value === 'edit' ? 'Simpan Perubahan' : 'Simpan Data'
})
const moduleTitle = computed(() => moduleTitleMap[props.path] || props.path)
const dialogWidthClass = computed(() => {
  if (isPpat.value) return 'max-w-5xl'
  if (isNotaris.value || isLegalisasi.value || isWaarmerking.value) return 'max-w-4xl'
  return 'max-w-3xl'
})

const penghadapDatalistId = computed(() => `penghadap-options-${props.path.replace(/[^a-zA-Z0-9]/g, '-')}`)

const resetMessage = () => {
  message.value = ''
  error.value = ''
}

const cacheClientOptions = (items: ClientOption[]) => {
  items.forEach((item) => {
    const id = String(item.id_client || '')
    if (id) {
      clientCache[id] = item
    }
  })
}

const clientDisplayName = (item: ClientOption | undefined) => {
  if (!item) return ''
  return String(item.nama_client || item.nama_pencarian || item.id_client || '')
}

const clientOptionLabel = (item: ClientOption) => clientDisplayName(item)

const parseClientId = (value: string) => {
  const trimmed = value.trim()
  if (!trimmed) return ''
  if (clientCache[trimmed]) return trimmed

  const match = Object.values(clientCache).find(
    item => clientDisplayName(item).toLowerCase() === trimmed.toLowerCase(),
  )
  return match ? String(match.id_client || '') : ''
}

const resolveClientName = (id: string) => {
  const option = clientCache[id]
  return clientDisplayName(option) || id
}

const queueClientLookup = (query: string) => {
  if (lookupTimer) {
    clearTimeout(lookupTimer)
  }

  lookupTimer = setTimeout(async () => {
    const value = query.trim()
    if (value.length < 2) {
      clientOptions.value = []
      return
    }

    loadingClient.value = true
    try {
      const result = toArray<ClientOption>(await business.order.getDaftarClient({ nama_penghadap: value }))
      clientOptions.value = result
      cacheClientOptions(result)
    } catch {
      clientOptions.value = []
    } finally {
      loadingClient.value = false
    }
  }, 300)
}

const loadAkta = async (tipe: 'NOTARIS' | 'PPAT') => {
  loadingAkta.value = true
  try {
    aktaOptions.value = toArray<AktaOption>(await business.order.getDaftarAkta({ judul_akta: '', tipe }))
  } catch {
    aktaOptions.value = []
  } finally {
    loadingAkta.value = false
  }
}

const loadNotarisMinDate = async () => {
  const baseline = today()
  notarisMinDate.value = baseline

  try {
    const result = toData<RowRecord>(await business.bukuNotaris.CekTglAktaNotarisTerakhir())
    const latest = normalizeDateInput(result.tgl_akta)
    if (latest) {
      notarisMinDate.value = latest > baseline ? latest : baseline
    }
  } catch {
    notarisMinDate.value = baseline
  }

  if (mode.value !== 'edit' && (!notarisForm.tgl_akta || notarisForm.tgl_akta < notarisMinDate.value)) {
    notarisForm.tgl_akta = notarisMinDate.value
  }
}

const loadAsisten = async () => {
  loadingAsisten.value = true
  try {
    asistenOptions.value = toArray<AsistenOption>(await business.dashboard.getDaftarAsisten())
  } catch {
    asistenOptions.value = []
  } finally {
    loadingAsisten.value = false
  }
}

const searchClientOptions = async (query: string) => {
  const keyword = query.trim()
  if (keyword.length < 2) {
    return [] as ClientOption[]
  }
  const result = toArray<ClientOption>(await business.order.getDaftarClient({ nama_penghadap: keyword }))
  cacheClientOptions(result)
  return result
}

const searchSuratClient = async () => {
  resetMessage()
  const keyword = suratClientQuery.value.trim()
  if (keyword.length < 2) {
    suratClientOptions.value = []
    return
  }
  loadingSuratClient.value = true
  try {
    suratClientOptions.value = await searchClientOptions(keyword)
  } catch (e) {
    error.value = (e as { data?: { message?: string } })?.data?.message || 'Gagal memuat client autocomplete.'
    suratClientOptions.value = []
  } finally {
    loadingSuratClient.value = false
  }
}

const queueSuratClientLookup = (query: string) => {
  if (suratLookupTimer) {
    clearTimeout(suratLookupTimer)
  }

  suratLookupTimer = setTimeout(() => {
    void searchSuratClient()
  }, 250)
}

const syncApht = () => {
  const selected = aktaOptions.value.find(item => String(item.id_akta) === String(ppatForm.jenis_akta))
  ppatForm.apht = String(selected?.apht || '').toUpperCase()
}

const mapPenghadapRows = (payload: unknown): PenghadapRow[] => {
  const list = Array.isArray(payload) ? payload : []
  return list
    .map((item) => {
      const row = (item || {}) as Record<string, unknown>
      const idClient = String(row.id_client || '')
      const idMewakili = String(row.id_mewakili || '')
      if (idClient) {
        clientCache[idClient] = {
          id_client: idClient,
          nama_client: String(row.nama_client || ''),
          nama_pencarian: String(row.nama_client || ''),
        }
      }
      if (idMewakili) {
        clientCache[idMewakili] = {
          id_client: idMewakili,
          nama_client: String(row.mewakili || ''),
          nama_pencarian: String(row.mewakili || ''),
        }
      }
      return {
        id_client: idClient,
        nama_client: String(row.nama_client || idClient || '-'),
        status_kedudukan: String(row.status_kedudukan || ''),
        id_mewakili: idMewakili,
        mewakili: String(row.mewakili || (idMewakili ? resolveClientName(idMewakili) : '-')),
      }
    })
    .filter(row => row.id_client)
}

const resetAll = () => {
  resetMessage()

  notarisForm.id_buku_notaris = ''
  notarisForm.no_akta = ''
  notarisForm.jenis_akta = ''
  notarisForm.judul_pekerjaan = ''
  notarisForm.tgl_akta = today()
  notarisForm.nama_asisten = ''
  notarisForm.sudah_tanda_tangan = false

  legalisasiForm.id_buku_legalisasi = ''
  legalisasiForm.no_legalisasi = ''
  legalisasiForm.judul_surat = ''
  legalisasiForm.keterangan_surat = ''

  waarmerkingForm.id_buku_warmerking = ''
  waarmerkingForm.no_warmerking = ''
  waarmerkingForm.judul_surat = ''
  waarmerkingForm.keterangan_surat = ''

  ppatForm.id_buku_ppat = ''
  ppatForm.no_akta = ''
  ppatForm.jenis_akta = ''
  ppatForm.sudah_tanda_tangan = false
  ppatForm.apht = ''
  ppatForm.no_hak_milik = ''
  ppatForm.luas_tanah = ''
  ppatForm.luas_bangunan = ''
  ppatForm.harga_transaksi = ''
  ppatForm.nop = ''
  ppatForm.harga_njop = ''
  ppatForm.tgl_bphtb = today()
  ppatForm.harga_bphtb = ''
  ppatForm.tgl_pph = today()
  ppatForm.harga_pph = ''
  ppatForm.keterangan = ''

  suratForm.id_surat_notaris = ''
  suratForm.id_surat_ppat = ''
  suratForm.no_surat = ''
  suratForm.id_client = ''
  suratForm.pengirim = user.value?.id_user ? String(user.value.id_user) : ''
  suratForm.keterangan = ''

  tandaTerimaForm.id = ''
  tandaTerimaForm.tgl_terima = today()
  tandaTerimaForm.nama_pengirim = ''
  tandaTerimaForm.nama_penerima = ''
  tandaTerimaForm.up_penerima = ''
  tandaTerimaForm.lokasi = ''
  tandaTerimaForm.keterangan_tanda_terima = ''
  tandaTerimaForm.status = tandaTerimaStatus.value
  tandaTerimaForm.isi_diterimas = []

  penghadapRows.value = []
  penghadapDraft.status_kedudukan = ''
  penghadapInput.value = ''
  mewakiliInput.value = ''
  clientOptions.value = []
  suratClientQuery.value = ''
  suratClientOptions.value = []
  isiDiterimaInput.value = ''
}

const ensureDependencies = async () => {
  if (!visible.value) {
    return
  }

  if (isNotaris.value) {
    await loadNotarisMinDate()
    await loadAkta('NOTARIS')
    if (requireAsisten.value) {
      await loadAsisten()
    }
  }

  if (isPpatLike.value) {
    await loadAkta('PPAT')
  }

  if (isSurat.value) {
    await loadAsisten()
  }
}

const openForCreate = async () => {
  if (!canCreate.value) {
    error.value = 'Role Arsip tidak dapat membuat data baru.'
    return
  }
  mode.value = 'create'
  resetAll()
  await ensureDependencies()
  showDialog.value = true
}

const hydrateFromEdit = async (row: RowRecord) => {
  if (isNotaris.value) {
    const data = toData<RowRecord>(await business.bukuNotaris.EditAktaNotaris({ id_buku_notaris: row.id_buku_notaris }))
    notarisForm.id_buku_notaris = String(data.id_buku_notaris || '')
    notarisForm.no_akta = String(data.no_akta || '')
    notarisForm.jenis_akta = String(data.jenis_akta || '')
    notarisForm.judul_pekerjaan = String(data.judul_pekerjaan || '')
    notarisForm.tgl_akta = String(data.tgl_akta || today())
    notarisForm.nama_asisten = String(data.nama_asisten || '')
    penghadapRows.value = mapPenghadapRows(data.penghadap_notaris || data.daftarpenghadap)
    return
  }

  if (isLegalisasi.value) {
    const data = toData<RowRecord>(await business.bukuLegalisasi.EditLegalisasi({ id_buku_legalisasi: row.id_buku_legalisasi }))
    legalisasiForm.id_buku_legalisasi = String(data.id_buku_legalisasi || '')
    legalisasiForm.no_legalisasi = String(data.no_legalisasi || '')
    legalisasiForm.judul_surat = String(data.judul_surat || '')
    legalisasiForm.keterangan_surat = String(data.keterangan_surat || '')
    penghadapRows.value = mapPenghadapRows(data.penghadap_legalisasi || data.daftarpenghadap)
    return
  }

  if (isWaarmerking.value) {
    const data = toData<RowRecord>(await business.bukuWarmerking.EditWarmerking({ id_buku_warmerking: row.id_buku_warmerking }))
    waarmerkingForm.id_buku_warmerking = String(data.id_buku_warmerking || '')
    waarmerkingForm.no_warmerking = String(data.no_warmerking || '')
    waarmerkingForm.judul_surat = String(data.judul_surat || '')
    waarmerkingForm.keterangan_surat = String(data.keterangan_surat || '')
    penghadapRows.value = mapPenghadapRows(data.penghadap_warmerking || data.daftarpenghadap)
    return
  }

  if (isPpatLike.value) {
    const id = row.id_buku_ppat || row.id
    const data = toData<RowRecord>(await business.bukuPpat.EditAktaPPAT({ id_buku_ppat: id }))
    ppatForm.id_buku_ppat = String(data.id_buku_ppat || '')
    ppatForm.no_akta = String(data.no_akta || '')
    ppatForm.jenis_akta = String(data.id_akta || data.jenis_akta || '')
    ppatForm.apht = String(data.apht || '')
    ppatForm.no_hak_milik = String(data.no_hak_milik || '')
    ppatForm.luas_tanah = String(data.luas_tanah || '')
    ppatForm.luas_bangunan = String(data.luas_bangunan || '')
    ppatForm.harga_transaksi = String(data.harga_transaksi || '')
    ppatForm.nop = String(data.nop || '')
    ppatForm.harga_njop = String(data.harga_njop || '')
    ppatForm.tgl_bphtb = String(data.tgl_bphtb || today())
    ppatForm.harga_bphtb = String(data.harga_bphtb || '')
    ppatForm.tgl_pph = String(data.tgl_pph || today())
    ppatForm.harga_pph = String(data.harga_pph || '')
    ppatForm.keterangan = String(data.keterangan || '')
    penghadapRows.value = mapPenghadapRows(data.penghadap || data.daftarpenghadap)
    return
  }

  if (isSuratNotaris.value) {
    const data = toData<RowRecord>(await business.surat.EditSuratNotaris({ id_surat_notaris: row.id_surat_notaris }))
    suratForm.id_surat_notaris = String(data.id_surat_notaris || '')
    suratForm.no_surat = String(data.no_surat || '')
    suratForm.id_client = String(data.id_client || '')
    suratClientQuery.value = resolveClientName(suratForm.id_client)
    suratForm.pengirim = String(data.id_user || '')
    suratForm.keterangan = String(data.keterangan || '')
    return
  }

  if (isSuratPpat.value) {
    const data = toData<RowRecord>(await business.surat.EditSuratPPAT({ id_surat_ppat: row.id_surat_ppat }))
    suratForm.id_surat_ppat = String(data.id_surat_ppat || '')
    suratForm.no_surat = String(data.no_surat || '')
    suratForm.id_client = String(data.id_client || '')
    suratClientQuery.value = resolveClientName(suratForm.id_client)
    suratForm.pengirim = String(data.id_user || '')
    suratForm.keterangan = String(data.keterangan || '')
    return
  }

  if (isTandaTerima.value) {
    const id = String(row.id || '')
    const detail = id ? toData<RowRecord>(await business.tandaTerima.show(id)) : row
    const resolvedTanggalTerima = normalizeDateInput(detail.created_at || detail.tgl_terima || row.created_at || row.tgl_terima)
    const isiDiterimasPayload = detail.isi_diterimas || detail.isiDiterimas || row.isi_diterimas || row.isiDiterimas || []

    tandaTerimaForm.id = String(detail.id || row.id || '')
    tandaTerimaForm.tgl_terima = resolvedTanggalTerima || today()
    tandaTerimaForm.nama_pengirim = String(detail.nama_pengirim || '')
    tandaTerimaForm.nama_penerima = String(detail.nama_penerima || '')
    tandaTerimaForm.up_penerima = String(detail.up_penerima || '')
    tandaTerimaForm.lokasi = String(detail.lokasi || '')
    tandaTerimaForm.keterangan_tanda_terima = String(detail.keterangan_tanda_terima || '')
    tandaTerimaForm.status = String(detail.status || tandaTerimaStatus.value)
    tandaTerimaForm.isi_diterimas = toArray<{ id?: string | number, isi_diterima?: string }>(isiDiterimasPayload)
      .map(item => ({
        id: item.id !== undefined && item.id !== null ? String(item.id) : undefined,
        isi_diterima: String(item.isi_diterima || ''),
      }))
      .filter(item => item.isi_diterima !== '')
  }
}

const openForEdit = async (row: RowRecord) => {
  mode.value = 'edit'
  resetAll()
  await ensureDependencies()
  await hydrateFromEdit(row)
  showDialog.value = true
}

defineExpose({
  openForCreate,
  openForEdit,
})

const openDialog = () => {
  void openForCreate()
}

const closeDialog = () => {
  if (submitting.value) {
    return
  }
  showDialog.value = false
}

const addPenghadap = () => {
  resetMessage()
  const idClient = parseClientId(penghadapInput.value)
  const idMewakili = parseClientId(mewakiliInput.value)

  if (!idClient || !penghadapDraft.status_kedudukan.trim()) {
    error.value = 'Nama penghadap dan status kedudukan wajib diisi.'
    return
  }

  const namaClient = resolveClientName(idClient)
  if (!namaClient) {
    error.value = 'Penghadap tidak valid. Pilih dari daftar autocomplete.'
    return
  }

  penghadapRows.value.push({
    id_client: idClient,
    nama_client: namaClient,
    status_kedudukan: penghadapDraft.status_kedudukan.trim(),
    id_mewakili: idMewakili,
    mewakili: idMewakili ? resolveClientName(idMewakili) : '-',
  })

  penghadapInput.value = ''
  mewakiliInput.value = ''
  penghadapDraft.status_kedudukan = ''
}

const setSuratClientQuery = (value: string) => {
  suratClientQuery.value = value
  suratForm.id_client = parseClientId(value)
  queueSuratClientLookup(value)
}

const setPenghadapInput = (value: string) => {
  penghadapInput.value = value
}

const setMewakiliInput = (value: string) => {
  mewakiliInput.value = value
}

const setPenghadapStatus = (value: string) => {
  penghadapDraft.status_kedudukan = value
}

const removePenghadap = (index: number) => {
  penghadapRows.value.splice(index, 1)
}

const addIsiDiterima = () => {
  const value = isiDiterimaInput.value.trim()
  if (!value) {
    error.value = 'Isi dokumen diterima tidak boleh kosong.'
    return
  }
  resetMessage()
  tandaTerimaForm.isi_diterimas.push({ isi_diterima: value })
  isiDiterimaInput.value = ''
}

const setIsiDiterimaInput = (value: string) => {
  isiDiterimaInput.value = value
}

const removeIsiDiterima = (index: number) => {
  tandaTerimaForm.isi_diterimas.splice(index, 1)
}

const validate = () => {
  if (isNotaris.value) {
    if (!notarisForm.jenis_akta || !notarisForm.judul_pekerjaan.trim() || !notarisForm.tgl_akta) return 'Data notaris wajib diisi.'
    if (mode.value === 'create' && !notarisForm.sudah_tanda_tangan) return 'Dokumen wajib tanda tangan terlebih dahulu sebelum ambil nomor.'
    if (requireAsisten.value && !notarisForm.nama_asisten) return 'Nama asisten wajib dipilih.'
    if (!penghadapRows.value.length) return 'Minimal 1 penghadap wajib diisi.'
  }

  if (isLegalisasi.value) {
    if (!legalisasiForm.judul_surat.trim()) return 'Judul legalisasi wajib diisi.'
    if (!penghadapRows.value.length) return 'Minimal 1 penghadap wajib diisi.'
  }

  if (isWaarmerking.value) {
    if (!waarmerkingForm.judul_surat.trim()) return 'Judul waarmerking wajib diisi.'
    if (!penghadapRows.value.length) return 'Minimal 1 penghadap wajib diisi.'
  }

  if (isPpatLike.value) {
    if (!ppatForm.jenis_akta || !String(ppatForm.no_hak_milik || '').trim()) return 'Jenis akta dan no hak milik wajib diisi.'
    if (mode.value === 'create' && !ppatForm.sudah_tanda_tangan) return 'Dokumen wajib tanda tangan terlebih dahulu sebelum ambil nomor.'
    if (!isNumeric(ppatForm.luas_tanah) || !isNumeric(ppatForm.luas_bangunan) || !isNumeric(ppatForm.harga_transaksi)) return 'Luas tanah, luas bangunan, dan harga transaksi harus angka.'
    if (!aphtMode.value) {
      if (!String(ppatForm.nop || '').trim() || !isNumeric(ppatForm.harga_njop)) return 'NOP dan harga NJOP wajib diisi.'
      if (!ppatForm.tgl_bphtb || !isNumeric(ppatForm.harga_bphtb) || !ppatForm.tgl_pph || !isNumeric(ppatForm.harga_pph)) return 'Data BPHTB/PPH wajib diisi.'
    }
    if (!penghadapRows.value.length) return 'Minimal 1 penghadap wajib diisi.'
  }

  if (isSurat.value && (!suratForm.id_client || !suratForm.pengirim)) {
    return 'Client tujuan wajib dipilih dari autocomplete dan pengirim wajib dipilih.'
  }

  if (isTandaTerima.value) {
    if (!tandaTerimaForm.nama_pengirim.trim() || !tandaTerimaForm.nama_penerima.trim() || !tandaTerimaForm.up_penerima.trim()) return 'Nama pengirim, penerima, dan UP wajib diisi.'
    if (!tandaTerimaForm.tgl_terima) return 'Tanggal terima wajib diisi.'
    if (!tandaTerimaForm.isi_diterimas.length) return 'Minimal 1 item dokumen wajib diisi.'
  }

  return ''
}

const penghadapPayload = () => penghadapRows.value.map(row => ({
  id_client: row.id_client,
  status_kedudukan: row.status_kedudukan,
  id_mewakili: row.id_mewakili || '',
}))

const submit = async () => {
  if (submitting.value) return
  resetMessage()
  const validationError = validate()
  if (validationError) {
    error.value = validationError
    return
  }

  submitting.value = true
  try {
    let response: unknown

    if (isNotaris.value) {
      response = await business.bukuNotaris.SimpanNomorNotaris({
        data_buku: {
          id_buku_notaris: notarisForm.id_buku_notaris || undefined,
          no_akta: notarisForm.no_akta || undefined,
          jenis_akta: notarisForm.jenis_akta,
          judul_pekerjaan: notarisForm.judul_pekerjaan,
          tgl_akta: notarisForm.tgl_akta,
          nama_asisten: notarisForm.nama_asisten || undefined,
          sudah_tanda_tangan: notarisForm.sudah_tanda_tangan,
        },
        penghadap: penghadapPayload(),
      })
    } else if (isLegalisasi.value) {
      response = await business.bukuLegalisasi.SimpanNomorLegalisasi({
        data_buku: {
          id_buku_legalisasi: legalisasiForm.id_buku_legalisasi || undefined,
          no_legalisasi: legalisasiForm.no_legalisasi || undefined,
          judul_surat: legalisasiForm.judul_surat,
          keterangan_surat: legalisasiForm.keterangan_surat,
        },
        penghadap: penghadapPayload(),
      })
    } else if (isWaarmerking.value) {
      response = await business.bukuWarmerking.SimpanNomorWarmerking({
        data_buku: {
          id_buku_warmerking: waarmerkingForm.id_buku_warmerking || undefined,
          no_warmerking: waarmerkingForm.no_warmerking || undefined,
          judul_surat: waarmerkingForm.judul_surat,
          keterangan_surat: waarmerkingForm.keterangan_surat,
        },
        penghadap: penghadapPayload(),
      })
    } else if (isPpat.value) {
      response = await business.bukuPpat.SimpanNomorPPAT({
        id_buku_ppat: ppatForm.id_buku_ppat || undefined,
        no_akta: ppatForm.no_akta || undefined,
        jenis_akta: ppatForm.jenis_akta,
        sudah_tanda_tangan: ppatForm.sudah_tanda_tangan,
        apht: ppatForm.apht || 'FALSE',
        no_hak_milik: ppatForm.no_hak_milik,
        luas_tanah: ppatForm.luas_tanah,
        luas_bangunan: ppatForm.luas_bangunan,
        harga_transaksi: ppatForm.harga_transaksi,
        nop: ppatForm.nop,
        harga_njop: ppatForm.harga_njop,
        tgl_bphtb: ppatForm.tgl_bphtb,
        harga_bphtb: ppatForm.harga_bphtb,
        tgl_pph: ppatForm.tgl_pph,
        harga_pph: ppatForm.harga_pph,
        keterangan: ppatForm.keterangan,
        penghadap: penghadapPayload(),
      })
    } else if (isSuratNotaris.value) {
      response = await business.surat.SimpanNomorSuratNotaris({
        id_surat_notaris: suratForm.id_surat_notaris || undefined,
        no_surat: suratForm.no_surat || undefined,
        id_client: suratForm.id_client,
        pengirim: suratForm.pengirim,
        keterangan: suratForm.keterangan,
      })
    } else if (isSuratPpat.value) {
      response = await business.surat.SimpanNomorSuratPPAT({
        id_surat_ppat: suratForm.id_surat_ppat || undefined,
        no_surat: suratForm.no_surat || undefined,
        id_client: suratForm.id_client,
        pengirim: suratForm.pengirim,
        keterangan: suratForm.keterangan,
      })
    } else if (isTandaTerima.value) {
      const payload = {
        id: tandaTerimaForm.id || undefined,
        tgl_terima: tandaTerimaForm.tgl_terima,
        status: tandaTerimaForm.status || tandaTerimaStatus.value,
        nama_pengirim: tandaTerimaForm.nama_pengirim,
        nama_penerima: tandaTerimaForm.nama_penerima,
        up_penerima: tandaTerimaForm.up_penerima,
        lokasi: tandaTerimaForm.lokasi,
        keterangan_tanda_terima: tandaTerimaForm.keterangan_tanda_terima,
        isi_diterimas: tandaTerimaForm.isi_diterimas,
      }

      if (mode.value === 'edit' && tandaTerimaForm.id) {
        response = await business.tandaTerima.update(tandaTerimaForm.id, payload)
      } else {
        response = await business.tandaTerima.create(payload)
      }
    } else {
      throw new Error('Path tidak didukung')
    }

    const okMessage = toMessage(response, mode.value === 'edit' ? 'Data berhasil diperbarui.' : 'Data berhasil disimpan.')
    emit('saved', okMessage)
    showDialog.value = false
    resetAll()
    message.value = okMessage
  } catch (e) {
    error.value = (e as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan data.'
  } finally {
    submitting.value = false
  }
}

watch(() => ppatForm.jenis_akta, syncApht)
watch([() => notarisForm.tgl_akta, notarisMinDate], () => {
  if (mode.value !== 'edit' && notarisForm.tgl_akta && notarisForm.tgl_akta < notarisMinDate.value) {
    notarisForm.tgl_akta = notarisMinDate.value
  }
})

watch(penghadapInput, (value) => {
  queueClientLookup(value)
})

watch(mewakiliInput, (value) => {
  queueClientLookup(value)
})

watch(
  () => props.path,
  async () => {
    mode.value = 'create'
    resetAll()
    await ensureDependencies()
  },
  { immediate: true },
)
</script>

<template>
  <div v-if="visible">
    <SurfaceCard class="p-6 sm:p-7">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="display-kicker">{{ modeLabel }}</p>
          <h3 class="mt-2 text-2xl font-semibold text-slate-900">Ambil Nomor Buku Reportorium</h3>
          <p class="mt-2 text-sm text-slate-500">Input data ditampilkan melalui dialog agar halaman utama tetap ringkas.</p>
        </div>
        <button
          v-if="canCreate"
          type="button"
          class="inline-flex h-11 items-center gap-2 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white transition hover:bg-slate-800"
          @click="openDialog"
        >
          <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M9.25 2a.75.75 0 0 0-.75.75v.5h-2A2.5 2.5 0 0 0 4 5.75v8.5A2.5 2.5 0 0 0 6.5 16.75h7a2.5 2.5 0 0 0 2.5-2.5v-8.5a2.5 2.5 0 0 0-2.5-2.5h-2v-.5a.75.75 0 0 0-1.5 0v.5h-1v-.5A.75.75 0 0 0 9.25 2Z" />
            <path d="M10 7a.75.75 0 0 1 .75.75V9h1.25a.75.75 0 0 1 0 1.5H10.75v1.25a.75.75 0 0 1-1.5 0V10.5H8a.75.75 0 0 1 0-1.5h1.25V7.75A.75.75 0 0 1 10 7Z" />
          </svg>
          {{ openFormLabel }}
        </button>
        <p v-else class="text-xs font-semibold uppercase tracking-wider text-slate-500">
          Mode Arsip (Read Only)
        </p>
      </div>
      <p v-if="message" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ message }}</p>
    </SurfaceCard>

    <Teleport to="body">
      <div v-if="showDialog" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-5">
        <div class="absolute inset-0 bg-slate-900/50" @click="closeDialog" />

        <div :class="dialogWidthClass" class="relative z-10 flex max-h-[92vh] w-full flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
          <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 sm:px-6">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ modeLabel }}</p>
              <h3 class="text-lg font-semibold text-slate-900">{{ moduleTitle }}</h3>
            </div>
            <button
              type="button"
              class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
              @click="closeDialog"
            >
              Tutup
            </button>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-6 sm:py-6">
            <p v-if="error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

            <ReportoriumModuleFields
              :path="props.path"
              :input-class="inputClass"
              :text-area-class="textAreaClass"
              :secondary-button-class="secondaryButtonClass"
              :loading-akta="loadingAkta"
              :loading-asisten="loadingAsisten"
              :loading-surat-client="loadingSuratClient"
              :akta-options="aktaOptions"
              :asisten-options="asistenOptions"
              :surat-client-options="suratClientOptions"
              :require-asisten="requireAsisten"
              :is-edit="mode === 'edit'"
              :apht-mode="aphtMode"
              :tanda-terima-status="tandaTerimaStatus"
              :notaris-form="notarisForm"
              :notaris-min-date="notarisMinDate"
              :legalisasi-form="legalisasiForm"
              :waarmerking-form="waarmerkingForm"
              :ppat-form="ppatForm"
              :surat-form="suratForm"
              :tanda-terima-form="tandaTerimaForm"
              :surat-client-query="suratClientQuery"
              :client-display-name="clientDisplayName"
              @update:surat-client-query="setSuratClientQuery"
              @search-surat-client="searchSuratClient"
            />

            <ReportoriumPenghadapSection
              :visible="needsPenghadap"
              :input-class="inputClass"
              :secondary-button-class="secondaryButtonClass"
              :loading-client="loadingClient"
              :penghadap-datalist-id="penghadapDatalistId"
              :penghadap-input="penghadapInput"
              :mewakili-input="mewakiliInput"
              :status-kedudukan="penghadapDraft.status_kedudukan"
              :client-options="clientOptions"
              :rows="penghadapRows"
              :client-option-label="clientOptionLabel"
              @update:penghadap-input="setPenghadapInput"
              @update:mewakili-input="setMewakiliInput"
              @update:status-kedudukan="setPenghadapStatus"
              @add="addPenghadap"
              @remove="removePenghadap"
            />

            <ReportoriumTandaTerimaItems
              :visible="isTandaTerima"
              :input-class="inputClass"
              :secondary-button-class="secondaryButtonClass"
              :isi-input="isiDiterimaInput"
              :items="tandaTerimaForm.isi_diterimas"
              @update:isi-input="setIsiDiterimaInput"
              @add="addIsiDiterima"
              @remove="removeIsiDiterima"
            />

            <div class="mt-6 flex flex-wrap justify-end gap-2">
              <button type="button" :class="secondaryButtonClass" :disabled="submitting" @click="closeDialog">
                Batal
              </button>
              <button
                type="button"
                class="h-11 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white transition hover:bg-slate-800"
                :disabled="submitting"
                @click="submit"
              >
                {{ submitLabel }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
