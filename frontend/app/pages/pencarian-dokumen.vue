<script setup lang="ts">
import {
  AdjustmentsHorizontalIcon,
  ArchiveBoxIcon,
  ArrowDownTrayIcon,
  ArrowLeftIcon,
  ArrowPathIcon,
  ArrowsUpDownIcon,
  BuildingOffice2Icon,
  ClockIcon,
  DocumentIcon,
  DocumentTextIcon,
  EyeIcon,
  FolderIcon,
  FolderPlusIcon,
  FunnelIcon,
  HomeIcon,
  ListBulletIcon,
  MagnifyingGlassIcon,
  MagnifyingGlassMinusIcon,
  MagnifyingGlassPlusIcon,
  MoonIcon,
  PhotoIcon,
  PresentationChartBarIcon,
  SparklesIcon,
  Squares2X2Icon,
  SunIcon,
  TableCellsIcon,
  UserCircleIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type DownloadRequestPayload = {
  request_id?: string | number
  request_ids?: Array<string | number>
  batch_id?: string
  approved?: boolean
  approved_count?: number
  pending_count?: number
  request_status?: 'pending' | 'approved' | 'rejected' | string
}

type RowRecord = Record<string, unknown>
type AktaType = 'Akta Notaris' | 'Legalisasi' | 'Waarmerking' | 'Akta PPAT'
type ClientTypeFilter = 'all' | 'perorangan' | 'badan_hukum'
type BookFilter = 'all' | AktaType
type ResultSort = 'relevance' | 'name_asc' | 'name_desc' | 'most_documents'
type ResultViewMode = 'grid' | 'list'

type SearchClient = RowRecord & {
  id_client?: string | number
  no_identitas?: string
  nama_client?: string
  jenis_client?: string
  akta_notaris?: string | number
  legalisasi?: string | number
  warmerking?: string | number
  waarmerking?: string | number
  akta_ppat?: string | number
}

type ClientDocument = RowRecord & {
  id_berkas?: string | number
  nama_dokumen?: string
  nama_folder?: string
  nama_berkas?: string
  deskripsi?: string
  id_client?: string | number
  nama_client?: string
  jenis_client?: string
  no_identitas?: string
  created_at?: string
}

type StandardDocument = RowRecord & {
  nama_dokumen?: string
  nama_berkas?: string
}

type BookConfig = {
  list: (payload: RowRecord) => Promise<unknown>
  listDocuments: (payload: RowRecord) => Promise<unknown>
  idField: string
  numberField: string
  columns: Array<{ key: string; label: string; badge?: boolean }>
  assetUrl: (fileName: string) => string
}

type DownloadCartItem = {
  key: string
  module_path: string
  row_id: string
  file_name: string
  file_category: string
  label: string
  display_name: string
}

definePageMeta({
  layout: false,
  middleware: 'auth',
})

useHead({
  title: 'Pencarian Dokumen',
})

const route = useRoute()
const router = useRouter()

const business = useLegacyBusiness()
const { token, user } = useSession()
const { isDark, toggleTheme } = useThemeMode()

const userInitial = computed(() => String(user.value?.name || user.value?.email || 'S').trim().charAt(0).toUpperCase())
const showingLatest = computed(() => !searchQuery.value.trim())

const searchQuery = ref(typeof route.query.q === 'string' ? route.query.q : '')
const loading = ref(false)
const errorMessage = ref('')
const responseMessage = ref('')
const hasSearchRun = ref(false)
const searchResults = ref<SearchClient[]>([])
const documentResults = ref<ClientDocument[]>([])
const clientTypeFilter = ref<ClientTypeFilter>('all')
const bookFilter = ref<BookFilter>('all')
const resultSort = ref<ResultSort>('relevance')
const resultViewMode = ref<ResultViewMode>('grid')
const activeSidebar = ref<'all' | 'recent' | ClientTypeFilter | AktaType>('recent')
const showOnlyWithDocuments = ref(false)
const resultPage = ref(1)
const resultPerPage = ref(9)
const quickKeywords = ['PT', 'CV', 'Yayasan', 'Jual Beli', 'Hibah']
const bookTypeOptions: AktaType[] = ['Akta Notaris', 'Legalisasi', 'Waarmerking', 'Akta PPAT']
const resultSortOptions: Array<{ value: ResultSort; label: string }> = [
  { value: 'relevance', label: 'Relevansi' },
  { value: 'name_asc', label: 'Nama A-Z' },
  { value: 'name_desc', label: 'Nama Z-A' },
  { value: 'most_documents', label: 'Dokumen Terbanyak' },
]

const clientDocumentDialog = reactive({
  open: false,
  loading: false,
  error: '',
  search: '',
  client: null as SearchClient | null,
  documents: [] as ClientDocument[],
})

const bookDialog = reactive({
  open: false,
  loading: false,
  error: '',
  type: 'Akta Notaris' as AktaType,
  client: null as SearchClient | null,
  rows: [] as RowRecord[],
})

const bookDocumentDialog = reactive({
  open: false,
  loading: false,
  error: '',
  type: 'Akta Notaris' as AktaType,
  title: '',
  documents: [] as StandardDocument[],
  previewUrl: '',
  previewTitle: '',
  rowId: '',
  modulePath: '',
  fileCategory: '',
})

const downloadRequestLoading = ref(false)
const downloadCart = ref<DownloadCartItem[]>([])
const downloadCartOpen = ref(false)
const directPreview = reactive({
  open: false,
  title: '',
  url: '',
  extension: '',
  zoom: 1,
  rotation: 0,
  document: null as ClientDocument | null,
})
const documentContextMenu = reactive({ open: false, x: 0, y: 0, document: null as ClientDocument | null })

const bookDownloadMetaMap: Record<AktaType, { modulePath: string; fileCategory: string }> = {
  'Akta Notaris': {
    modulePath: '/buku_akta',
    fileCategory: 'standard_notaris',
  },
  Legalisasi: {
    modulePath: '/buku_legalisasi',
    fileCategory: 'standard_legalisasi',
  },
  Waarmerking: {
    modulePath: '/buku_waarmerking',
    fileCategory: 'standard_waarmerking',
  },
  'Akta PPAT': {
    modulePath: '/buku_ppat',
    fileCategory: 'standard_ppat',
  },
}

const expandedBookRows = reactive<Record<string, boolean>>({})

const clearExpandedBookRows = () => {
  Object.keys(expandedBookRows).forEach((key) => {
    delete expandedBookRows[key]
  })
}

const unwrapPayload = (payload: unknown): unknown => {
  if (payload && typeof payload === 'object' && 'data' in payload) {
    const envelope = payload as ApiEnvelope
    responseMessage.value = envelope.message || ''
    return envelope.data
  }
  return payload
}

const toRowArray = (payload: unknown): RowRecord[] => {
  if (Array.isArray(payload)) {
    return payload.filter(item => item && typeof item === 'object') as RowRecord[]
  }

  if (payload && typeof payload === 'object') {
    const objectPayload = payload as RowRecord
    const dataClient = objectPayload.data_client
    if (Array.isArray(dataClient)) {
      return dataClient.filter(item => item && typeof item === 'object') as RowRecord[]
    }

    const firstArray = Object.values(objectPayload).find(value => Array.isArray(value))
    if (Array.isArray(firstArray)) {
      return firstArray.filter(item => item && typeof item === 'object') as RowRecord[]
    }
  }

  return []
}

const toString = (value: unknown, fallback = '-') => {
  const normalized = String(value ?? '').trim()
  return normalized || fallback
}

const toDisplayCount = (value: unknown) => {
  if (value === null || value === undefined || value === '') return '0'
  return String(value)
}

const toNumericCount = (value: unknown) => {
  const normalized = Number(value)
  return Number.isFinite(normalized) ? normalized : 0
}

const truncateText = (value: unknown, maxLength = 40) => {
  const text = toString(value, '')
  if (!text) return '-'
  if (text.length <= maxLength) return text
  return `${text.slice(0, maxLength)}...`
}

const displayFileName = (title: unknown, storedFileName: unknown) => {
  const stored = toString(storedFileName, '')
  const base = toString(title, stored || 'dokumen')
  const extension = stored.includes('.') ? stored.split('.').pop()?.toLowerCase() || '' : ''
  const currentExtension = base.includes('.') ? base.split('.').pop()?.toLowerCase() || '' : ''
  return extension && currentExtension !== extension ? `${base}.${extension}` : base
}

const resolveClientType = (client: SearchClient) => {
  const normalized = toString(client.jenis_client, '').toLowerCase()
  if (normalized.includes('badan')) return 'badan_hukum'
  if (normalized.includes('perorangan')) return 'perorangan'
  return 'unknown'
}

const countByBook = (client: SearchClient, type: AktaType) => {
  if (type === 'Akta Notaris') return toNumericCount(client.akta_notaris)
  if (type === 'Legalisasi') return toNumericCount(client.legalisasi)
  if (type === 'Waarmerking') return toNumericCount(client.warmerking ?? client.waarmerking)
  return toNumericCount(client.akta_ppat)
}

const totalBookCount = (client: SearchClient) =>
  countByBook(client, 'Akta Notaris')
  + countByBook(client, 'Legalisasi')
  + countByBook(client, 'Waarmerking')
  + countByBook(client, 'Akta PPAT')

const bookButtonClass = (type: AktaType) => {
  if (type === 'Akta Notaris') return 'flex w-full items-center justify-between rounded-xl border border-blue-200 bg-blue-50/70 px-3 py-2 text-sm text-blue-800 transition hover:border-blue-300 hover:bg-blue-100'
  if (type === 'Legalisasi') return 'flex w-full items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50/70 px-3 py-2 text-sm text-emerald-800 transition hover:border-emerald-300 hover:bg-emerald-100'
  if (type === 'Waarmerking') return 'flex w-full items-center justify-between rounded-xl border border-amber-200 bg-amber-50/70 px-3 py-2 text-sm text-amber-800 transition hover:border-amber-300 hover:bg-amber-100'
  return 'flex w-full items-center justify-between rounded-xl border border-rose-200 bg-rose-50/70 px-3 py-2 text-sm text-rose-800 transition hover:border-rose-300 hover:bg-rose-100'
}

const bookButtonCompactClass = (type: AktaType) => {
  if (type === 'Akta Notaris') return 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-2.5 text-xs font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100'
  if (type === 'Legalisasi') return 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 text-xs font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100'
  if (type === 'Waarmerking') return 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 text-xs font-semibold text-amber-700 transition hover:border-amber-300 hover:bg-amber-100'
  return 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-2.5 text-xs font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-100'
}

const resultViewButtonClass = (mode: ResultViewMode) => {
  const active = resultViewMode.value === mode
  return active
    ? 'inline-flex h-9 items-center gap-1 rounded-lg border border-slate-300 bg-slate-900 px-3 text-xs font-semibold text-white'
    : 'inline-flex h-9 items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-600 hover:bg-slate-50'
}

const filteredSearchResults = computed(() => {
  let list = [...searchResults.value]

  if (clientTypeFilter.value !== 'all') {
    list = list.filter(client => resolveClientType(client) === clientTypeFilter.value)
  }

  if (bookFilter.value !== 'all') {
    list = list.filter(client => countByBook(client, bookFilter.value as AktaType) > 0)
  }

  if (showOnlyWithDocuments.value) {
    list = list.filter(client => totalBookCount(client) > 0)
  }

  if (resultSort.value === 'name_asc') {
    list = [...list].sort((a, b) =>
      toString(a.nama_client, '').localeCompare(toString(b.nama_client, ''), 'id-ID'),
    )
  } else if (resultSort.value === 'name_desc') {
    list = [...list].sort((a, b) =>
      toString(b.nama_client, '').localeCompare(toString(a.nama_client, ''), 'id-ID'),
    )
  } else if (resultSort.value === 'most_documents') {
    list = [...list].sort((a, b) =>
      totalBookCount(b) - totalBookCount(a)
      || toString(a.nama_client, '').localeCompare(toString(b.nama_client, ''), 'id-ID'),
    )
  }

  return list
})

const filteredDocumentResults = computed(() => {
  if (clientTypeFilter.value === 'all') return documentResults.value
  return documentResults.value.filter(document => resolveClientType(document as SearchClient) === clientTypeFilter.value)
})

const paginatedDocumentResults = computed(() => {
  const start = (resultPage.value - 1) * resultPerPage.value
  return filteredDocumentResults.value.slice(start, start + resultPerPage.value)
})

const activeResultCount = computed(() =>
  bookFilter.value === 'all' ? filteredDocumentResults.value.length : filteredSearchResults.value.length,
)

const workspaceTitle = computed(() => {
  if (bookFilter.value !== 'all') return bookFilter.value
  if (clientTypeFilter.value === 'perorangan') return 'Dokumen Perorangan'
  if (clientTypeFilter.value === 'badan_hukum') return 'Dokumen Badan Hukum'
  return showingLatest.value ? 'Dokumen Terbaru' : 'Hasil Pencarian'
})

const workspaceSubtitle = computed(() => {
  if (bookFilter.value !== 'all') return `Pilih client untuk melihat data ${bookFilter.value}.`
  if (clientTypeFilter.value !== 'all') return `Menampilkan file untuk ${clientTypeFilter.value === 'perorangan' ? 'client perorangan' : 'badan hukum'}.`
  return showingLatest.value ? 'File terbaru yang tersedia di penyimpanan.' : `Hasil untuk kata kunci “${searchQuery.value}”.`
})

const selectedBookType = computed<AktaType | null>(() =>
  bookFilter.value === 'all' ? null : bookFilter.value,
)

const selectedBookCount = (client: SearchClient) =>
  selectedBookType.value ? countByBook(client, selectedBookType.value) : 0

const documentResultKey = (document: ClientDocument, index: number) =>
  `${toString(document.id_berkas, 'document')}-${index}`

const documentExtension = (document: ClientDocument) => {
  const fileName = toString(document.nama_berkas, '')
  return fileName.includes('.') ? fileName.split('.').pop()?.toUpperCase() || 'FILE' : 'FILE'
}

const documentIsImage = (document: ClientDocument) =>
  ['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'BMP'].includes(documentExtension(document))

const documentIsPdf = (document: ClientDocument) => documentExtension(document) === 'PDF'

const documentAppearance = (document: ClientDocument) => {
  const extension = documentExtension(document)
  if (extension === 'PDF') return { icon: DocumentTextIcon, color: 'text-red-600', background: 'bg-red-50', badge: 'bg-red-600' }
  if (['XLS', 'XLSX', 'CSV', 'ODS'].includes(extension)) return { icon: TableCellsIcon, color: 'text-emerald-600', background: 'bg-emerald-50', badge: 'bg-emerald-600' }
  if (['PPT', 'PPTX', 'ODP'].includes(extension)) return { icon: PresentationChartBarIcon, color: 'text-orange-500', background: 'bg-orange-50', badge: 'bg-orange-500' }
  if (['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'BMP'].includes(extension)) return { icon: PhotoIcon, color: 'text-violet-600', background: 'bg-violet-50', badge: 'bg-violet-600' }
  if (['ZIP', 'RAR', '7Z', 'TAR', 'GZ'].includes(extension)) return { icon: ArchiveBoxIcon, color: 'text-amber-600', background: 'bg-amber-50', badge: 'bg-amber-600' }
  return { icon: DocumentTextIcon, color: 'text-blue-600', background: 'bg-blue-50', badge: 'bg-blue-600' }
}

const directPreviewKind = computed(() => {
  if (['JPG', 'JPEG', 'PNG', 'GIF', 'WEBP', 'BMP'].includes(directPreview.extension)) return 'image'
  if (directPreview.extension === 'PDF') return 'pdf'
  return 'other'
})

const resetDirectPreviewTransform = () => {
  directPreview.zoom = 1
  directPreview.rotation = 0
}

const closeDirectPreview = () => {
  directPreview.open = false
  directPreview.document = null
  resetDirectPreviewTransform()
}

const changeDirectPreviewZoom = (amount: number) => {
  directPreview.zoom = Math.min(3, Math.max(0.25, Number((directPreview.zoom + amount).toFixed(2))))
}

const openDirectPreview = (document: ClientDocument) => {
  const url = getClientDocumentUrl(document)
  if (!url) {
    errorMessage.value = 'File dokumen tidak tersedia.'
    return
  }
  directPreview.title = displayFileName(document.nama_dokumen, document.nama_berkas)
  directPreview.url = toIframePreviewUrl(url)
  directPreview.extension = documentExtension(document)
  directPreview.document = document
  resetDirectPreviewTransform()
  directPreview.open = true
  documentContextMenu.open = false
}

const setDirectDocumentContext = (document: ClientDocument) => {
  clientDocumentDialog.client = {
    id_client: document.id_client,
    nama_client: document.nama_client,
    jenis_client: document.jenis_client,
    no_identitas: document.no_identitas,
  }
}

const requestDirectDocumentDownload = async (document: ClientDocument) => {
  setDirectDocumentContext(document)
  documentContextMenu.open = false
  await requestClientDocumentDownload(document)
}

const addDirectDocumentToCart = (document: ClientDocument) => {
  setDirectDocumentContext(document)
  documentContextMenu.open = false
  addClientDocumentToCart(document)
}

const openDocumentContextMenu = (event: MouseEvent, document: ClientDocument) => {
  event.preventDefault()
  documentContextMenu.document = document
  documentContextMenu.x = Math.min(event.clientX, window.innerWidth - 230)
  documentContextMenu.y = Math.min(event.clientY, window.innerHeight - 170)
  documentContextMenu.open = true
}

const totalPages = computed(() =>
  Math.max(1, Math.ceil(activeResultCount.value / resultPerPage.value)),
)

const paginatedSearchResults = computed(() => {
  const start = (resultPage.value - 1) * resultPerPage.value
  const end = start + resultPerPage.value
  return filteredSearchResults.value.slice(start, end)
})

const resultStart = computed(() => {
  if (!activeResultCount.value) return 0
  return (resultPage.value - 1) * resultPerPage.value + 1
})

const resultEnd = computed(() =>
  Math.min(resultStart.value + resultPerPage.value - 1, activeResultCount.value),
)

const searchStats = computed(() => {
  const list = filteredSearchResults.value
  return {
    perorangan: list.filter(client => resolveClientType(client) === 'perorangan').length,
    badan_hukum: list.filter(client => resolveClientType(client) === 'badan_hukum').length,
    with_docs: list.filter(client => totalBookCount(client) > 0).length,
  }
})

const goToPrevPage = () => {
  if (resultPage.value > 1) {
    resultPage.value -= 1
  }
}

const goToNextPage = () => {
  if (resultPage.value < totalPages.value) {
    resultPage.value += 1
  }
}

const applyQuickKeyword = (keyword: string) => {
  activeSidebar.value = 'all'
  clientTypeFilter.value = 'all'
  bookFilter.value = 'all'
  searchQuery.value = keyword
  void submitSearch()
}

const clearSearchQuery = () => {
  searchQuery.value = ''
  void submitSearch()
}

const selectAllDocuments = () => {
  activeSidebar.value = 'all'
  clientTypeFilter.value = 'all'
  bookFilter.value = 'all'
  resultPage.value = 1
}

const selectRecentDocuments = () => {
  activeSidebar.value = 'recent'
  selectAllDocuments()
  resultSort.value = 'relevance'
  clearSearchQuery()
}

const selectClientType = (type: Exclude<ClientTypeFilter, 'all'>) => {
  activeSidebar.value = type
  clientTypeFilter.value = type
  bookFilter.value = 'all'
  resultPage.value = 1
}

const selectBookFilter = (type: AktaType) => {
  activeSidebar.value = type
  bookFilter.value = type
  clientTypeFilter.value = 'all'
  resultPage.value = 1
}

const sidebarNavClass = (active: boolean) => [
  'flex w-full items-center gap-3 rounded-lg px-4 py-2.5 text-left font-medium transition',
  active ? 'drive-nav-active' : 'drive-nav-item',
]

const clientCardKey = (client: SearchClient, index: number) =>
  `${toString(client.id_client, '')}-${toString(client.no_identitas, '')}-${index}`

const clientTypeBadgeClass = (client: SearchClient) => {
  const type = resolveClientType(client)
  if (type === 'perorangan') return 'inline-flex rounded-lg border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700'
  if (type === 'badan_hukum') return 'inline-flex rounded-lg border border-violet-200 bg-violet-50 px-2 py-0.5 text-xs font-semibold text-violet-700'
  return 'inline-flex rounded-lg border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600'
}

const rowIdentity = (row: RowRecord, index: number) =>
  String(
    row.id
    || row.id_buku_notaris
    || row.id_buku_legalisasi
    || row.id_buku_warmerking
    || row.id_buku_ppat
    || row.no_akta
    || row.no_legalisasi
    || row.no_warmerking
    || index,
  )

const formatDateOnly = (value: unknown) => {
  const raw = toString(value, '')
  if (!raw) return '-'
  const parsed = new Date(raw)
  if (Number.isNaN(parsed.getTime())) return raw
  return parsed.toISOString().slice(0, 10)
}

const bookConfigs: Record<AktaType, BookConfig> = {
  'Akta Notaris': {
    list: payload => business.pencarian.getPencarianBukuNotaris(payload),
    listDocuments: payload => business.dokumen.StandarDokumenNotaris(payload),
    idField: 'id_buku_notaris',
    numberField: 'no_akta',
    columns: [
      { key: 'nama_akta', label: 'Jenis Akta' },
      { key: 'judul_pekerjaan', label: 'Judul Pekerjaan' },
      { key: 'tgl_akta', label: 'Tgl Akta' },
      { key: 'pengambil', label: 'Pengambil Nomor' },
      { key: 'no_akta', label: 'No Akta', badge: true },
    ],
    assetUrl: fileName => business.assets.berkasNotaris(fileName),
  },
  Legalisasi: {
    list: payload => business.pencarian.getPencarianBukuLegalisasi(payload),
    listDocuments: payload => business.dokumen.StandarDokumenLegalisasis(payload),
    idField: 'id_buku_legalisasi',
    numberField: 'no_legalisasi',
    columns: [
      { key: 'judul_surat', label: 'Judul Surat' },
      { key: 'tgl_surat', label: 'Tanggal Surat' },
      { key: 'pengambil', label: 'Pengambil Nomor' },
      { key: 'no_legalisasi', label: 'No Legalisasi', badge: true },
    ],
    assetUrl: fileName => business.assets.berkasLegalisasi(fileName),
  },
  Waarmerking: {
    list: payload => business.pencarian.getPencarianBukuWarmerking(payload),
    listDocuments: payload => business.dokumen.StandarDokumenWarmerkings(payload),
    idField: 'id_buku_warmerking',
    numberField: 'no_warmerking',
    columns: [
      { key: 'judul_surat', label: 'Judul Surat' },
      { key: 'tgl_didaftarkan', label: 'Tanggal Permintaan' },
      { key: 'pengambil', label: 'Pengambil Nomor' },
      { key: 'no_warmerking', label: 'No Waarmerking', badge: true },
    ],
    assetUrl: fileName => business.assets.berkasWarmerking(fileName),
  },
  'Akta PPAT': {
    list: payload => business.pencarian.getPencarianBukuPPAT(payload),
    listDocuments: payload => business.dokumen.StandarDokumenPPAT(payload),
    idField: 'id_buku_ppat',
    numberField: 'no_akta',
    columns: [
      { key: 'nama_akta', label: 'Nama Akta' },
      { key: 'tanggal_akta', label: 'Tgl Akta' },
      { key: 'pengambil', label: 'Pengambil Nomor' },
      { key: 'no_akta', label: 'No Akta', badge: true },
    ],
    assetUrl: fileName => business.assets.berkasPpat(fileName),
  },
}

const cardActionItems = (client: SearchClient) => [
  { type: 'Akta Notaris' as AktaType, label: 'Akta Notaris', count: client.akta_notaris },
  { type: 'Legalisasi' as AktaType, label: 'Akta Legalisasi', count: client.legalisasi },
  { type: 'Waarmerking' as AktaType, label: 'Akta Waarmerking', count: client.warmerking ?? client.waarmerking },
  { type: 'Akta PPAT' as AktaType, label: 'Akta PPAT', count: client.akta_ppat },
]

const filteredClientDocuments = computed(() => {
  const keyword = clientDocumentDialog.search.trim().toLowerCase()
  if (!keyword) {
    return clientDocumentDialog.documents
  }

  return clientDocumentDialog.documents.filter((item) => {
    const value = `${toString(item.nama_dokumen, '')} ${toString(item.deskripsi, '')}`.toLowerCase()
    return value.includes(keyword)
  })
})

const bookColumns = computed(() => bookConfigs[bookDialog.type].columns)

const bookDialogTitle = computed(() => {
  const clientName = toString(bookDialog.client?.nama_client, '-')
  return `Dokumen ${bookDialog.type} ${clientName}`
})

const clientDocumentTitle = computed(() => `Dokumen ${toString(clientDocumentDialog.client?.nama_client, '-')}`)

const ppatDetailPairs = (row: RowRecord): Array<[string, unknown]> => [
  ['No Hak Milik', row.no_hak_milik],
  ['Luas Tanah', row.luas_tanah],
  ['Luas Bangunan', row.luas_bangunan],
  ['Harga Transaksi', row.harga_transaksi],
  ['NOP', row.nop],
  ['NJOP', row.harga_njop],
  ['Tanggal BPHTB', row.tgl_bphtb],
  ['Pajak BPHTB', row.harga_bphtb],
  ['Tanggal PPH', row.tgl_pph],
  ['Pajak PPH', row.harga_pph],
  ['Keterangan', row.keterangan],
]

const getClientDocumentUrl = (item: ClientDocument) => {
  const folder = toString(item.nama_folder, '')
  const fileName = toString(item.nama_berkas, '')
  if (!folder || !fileName) {
    return ''
  }
  return business.assets.berkasClient(folder, fileName)
}

const toIframePreviewUrl = (url: string) => {
  const raw = String(url || '').trim()
  if (!raw) return ''
  const withoutHash = raw.split('#')[0] || raw
  const isPdf = /\.pdf(\?|$)/i.test(withoutHash)
  if (!isPdf) return raw
  return `${withoutHash}#toolbar=0&navpanes=0&scrollbar=1&view=FitH`
}

const getBookDocumentUrl = (item: StandardDocument) => {
  const fileName = toString(item.nama_berkas, '')
  if (!fileName) {
    return ''
  }
  return bookConfigs[bookDocumentDialog.type].assetUrl(fileName)
}

const emitDownloadRequestEvent = (detail: Record<string, unknown>) => {
  if (!import.meta.client) return
  window.dispatchEvent(new CustomEvent('simanis:download-request-created', { detail }))
}

const downloadCartCount = computed(() => downloadCart.value.length)

const downloadCartKey = (payload: Pick<DownloadCartItem, 'module_path' | 'row_id' | 'file_name' | 'file_category'>) =>
  `${payload.module_path}|${payload.row_id}|${payload.file_category}|${payload.file_name}`

const isInDownloadCart = (key: string) => downloadCart.value.some(item => item.key === key)

const addDownloadCartItem = (item: Omit<DownloadCartItem, 'key'>) => {
  const key = downloadCartKey(item)
  if (isInDownloadCart(key)) {
    responseMessage.value = 'Dokumen sudah ada di keranjang download.'
    downloadCartOpen.value = true
    return
  }

  downloadCart.value = [...downloadCart.value, { ...item, key }]
  responseMessage.value = 'Dokumen ditambahkan ke keranjang download.'
  errorMessage.value = ''
  downloadCartOpen.value = true
}

const removeDownloadCartItem = (key: string) => {
  downloadCart.value = downloadCart.value.filter(item => item.key !== key)
}

const clearDownloadCart = () => {
  downloadCart.value = []
}

const downloadApprovedFile = async (requestId: string | number, fileName: string) => {
  if (!import.meta.client) return

  const response = await fetch(business.documentAccess.downloadUrl(requestId), {
    method: 'GET',
    headers: {
      Accept: 'application/octet-stream',
      ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
    },
  })

  if (!response.ok) {
    const contentType = String(response.headers.get('content-type') || '').toLowerCase()
    let reason = 'Gagal download file.'
    if (contentType.includes('application/json')) {
      const json = await response.json() as ApiEnvelope
      reason = json.message || reason
    }
    throw new Error(reason)
  }

  const blob = await response.blob()
  const safeFileName = String(fileName || '').trim() || `dokumen-${requestId}`
  const blobUrl = window.URL.createObjectURL(blob)
  const link = window.document.createElement('a')
  link.href = blobUrl
  link.download = safeFileName
  window.document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(blobUrl)
}

const downloadApprovedFiles = async (requestIds: Array<string | number>, fileName: string) => {
  if (!import.meta.client || !requestIds.length) return
  if (requestIds.length === 1) {
    const requestId = requestIds[0]
    if (requestId !== undefined) {
      await downloadApprovedFile(requestId, fileName)
    }
    return
  }

  const response = await fetch(business.documentAccess.downloadBulkUrl(requestIds), {
    method: 'GET',
    headers: {
      Accept: 'application/zip,application/octet-stream',
      ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
    },
  })

  if (!response.ok) {
    const contentType = String(response.headers.get('content-type') || '').toLowerCase()
    let reason = 'Gagal download file.'
    if (contentType.includes('application/json')) {
      const json = await response.json() as ApiEnvelope
      reason = json.message || reason
    }
    throw new Error(reason)
  }

  const blob = await response.blob()
  const safeFileName = String(fileName || '').trim() || 'dokumen-simanis.zip'
  const blobUrl = window.URL.createObjectURL(blob)
  const link = window.document.createElement('a')
  link.href = blobUrl
  link.download = safeFileName
  window.document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(blobUrl)
}

const requestDownloadWithApproval = async (payload: Record<string, unknown>, fileName: string) => {
  if (downloadRequestLoading.value) return

  downloadRequestLoading.value = true
  errorMessage.value = ''

  try {
    const response = await business.documentAccess.requestDownload(payload) as ApiEnvelope<DownloadRequestPayload>
    const data = response.data || {}
    const status = String(data.request_status || '').toLowerCase()

    responseMessage.value = response.message || 'Request download diproses.'
    emitDownloadRequestEvent({
      status,
      file_name: fileName,
      module_path: payload.module_path,
      request_id: data.request_id,
      message: response.message || 'Permintaan download berhasil dikirim.',
    })

    if (data.approved && data.request_id) {
      await downloadApprovedFile(data.request_id, fileName)
      responseMessage.value = 'Download berhasil diproses.'
    }
  } catch (error) {
    const rawMessage = (error as { data?: { message?: string }; message?: string })?.data?.message
      || (error as { message?: string })?.message
    errorMessage.value = rawMessage || 'Gagal memproses request download.'
  } finally {
    downloadRequestLoading.value = false
  }
}

const submitDownloadCart = async () => {
  if (downloadRequestLoading.value || !downloadCart.value.length) return

  downloadRequestLoading.value = true
  errorMessage.value = ''

  const documents = downloadCart.value.map(item => ({
    module_path: item.module_path,
    row_id: item.row_id,
    file_name: item.file_name,
    display_name: item.display_name,
    file_category: item.file_category,
  }))

  try {
    const response = await business.documentAccess.requestDownload({
      documents,
      batch_label: `Keranjang download ${new Date().toLocaleString('id-ID')}`,
    }) as ApiEnvelope<DownloadRequestPayload>
    const data = response.data || {}
    const requestIds = Array.isArray(data.request_ids)
      ? data.request_ids
      : (data.request_id ? [data.request_id] : [])
    const pendingCount = Number(data.pending_count || 0)

    responseMessage.value = response.message || 'Request download massal diproses.'
    emitDownloadRequestEvent({
      status: data.request_status || (pendingCount > 0 ? 'pending' : 'approved'),
      file_name: `${downloadCart.value.length} dokumen`,
      request_id: data.request_id,
      request_ids: requestIds,
      batch_id: data.batch_id,
      message: response.message || 'Permintaan download massal berhasil dikirim.',
    })

    if (data.approved && requestIds.length) {
      await downloadApprovedFiles(requestIds, `dokumen-simanis-${Date.now()}.zip`)
      responseMessage.value = 'Download massal berhasil diproses.'
    }

    clearDownloadCart()
    downloadCartOpen.value = false
  } catch (error) {
    const rawMessage = (error as { data?: { message?: string }; message?: string })?.data?.message
      || (error as { message?: string })?.message
    errorMessage.value = rawMessage || 'Gagal memproses request download massal.'
  } finally {
    downloadRequestLoading.value = false
  }
}

const addClientDocumentToCart = (document: ClientDocument) => {
  const clientId = toString(clientDocumentDialog.client?.id_client, '')
  const clientName = toString(clientDocumentDialog.client?.nama_client, 'Client')
  const folder = toString(document.nama_folder, '')
  const fileName = toString(document.nama_berkas, '')
  const rowDocId = toString(document.id_berkas, '')

  if (!clientId || !folder || !fileName) {
    errorMessage.value = 'Data dokumen client tidak valid untuk masuk keranjang.'
    return
  }

  const rowId = rowDocId ? `${clientId}:${rowDocId}` : `${clientId}:${fileName}`
  const displayName = displayFileName(document.nama_dokumen, fileName)
  addDownloadCartItem({
    module_path: '/pencarian-dokumen',
    row_id: rowId,
    file_name: `${folder}/${fileName}`,
    file_category: 'client_document',
    label: `${clientName} - ${displayName}`,
    display_name: displayName,
  })
}

const addBookDocumentToCart = (document: StandardDocument) => {
  const fileName = toString(document.nama_berkas, '')
  if (!fileName || !bookDocumentDialog.rowId || !bookDocumentDialog.modulePath || !bookDocumentDialog.fileCategory) {
    errorMessage.value = 'Data dokumen buku tidak valid untuk masuk keranjang.'
    return
  }

  const displayName = displayFileName(document.nama_dokumen, fileName)
  addDownloadCartItem({
    module_path: bookDocumentDialog.modulePath,
    row_id: bookDocumentDialog.rowId,
    file_name: fileName,
    file_category: bookDocumentDialog.fileCategory,
    label: `${bookDocumentDialog.title} - ${displayName}`,
    display_name: displayName,
  })
}

const requestClientDocumentDownload = async (document: ClientDocument) => {
  const clientId = toString(clientDocumentDialog.client?.id_client, '')
  const folder = toString(document.nama_folder, '')
  const fileName = toString(document.nama_berkas, '')
  const rowDocId = toString(document.id_berkas, '')

  if (!clientId || !folder || !fileName) {
    errorMessage.value = 'Data dokumen client tidak valid untuk request download.'
    return
  }

  const rowId = rowDocId ? `${clientId}:${rowDocId}` : `${clientId}:${fileName}`
  const displayName = displayFileName(document.nama_dokumen, fileName)

  await requestDownloadWithApproval({
    module_path: '/pencarian-dokumen',
    row_id: rowId,
    file_name: `${folder}/${fileName}`,
    display_name: displayName,
    file_category: 'client_document',
  }, displayName)
}

const requestBookDocumentDownload = async (document: StandardDocument) => {
  const fileName = toString(document.nama_berkas, '')
  if (!fileName || !bookDocumentDialog.rowId || !bookDocumentDialog.modulePath || !bookDocumentDialog.fileCategory) {
    errorMessage.value = 'Data dokumen buku tidak valid untuk request download.'
    return
  }

  const displayName = displayFileName(document.nama_dokumen, fileName)
  await requestDownloadWithApproval({
    module_path: bookDocumentDialog.modulePath,
    row_id: bookDocumentDialog.rowId,
    file_name: fileName,
    display_name: displayName,
    file_category: bookDocumentDialog.fileCategory,
  }, displayName)
}

const barcodeImageUrl = (row: RowRecord) => {
  const barcode = toString(row.barcode, '')
  return barcode ? `data:image/svg+xml;base64,${barcode}` : ''
}

const downloadBarcode = (row: RowRecord) => {
  if (!import.meta.client) {
    return
  }
  const barcode = toString(row.barcode, '')
  if (!barcode) {
    return
  }

  const anchor = window.document.createElement('a')
  const noAkta = toString(row.no_akta, 'akta').replace(/[^a-zA-Z0-9-_]+/g, '_')
  const tanggalAkta = toString(row.tanggal_akta, toString(row.tgl_akta, 'tanggal')).replace(/[^a-zA-Z0-9-_]+/g, '_')
  anchor.href = `data:image/svg+xml;base64,${barcode}`
  anchor.download = `Akta_${noAkta}_${tanggalAkta}.svg`
  anchor.click()
}

const getPenghadapRows = (row: RowRecord) => {
  const list = row.daftarpenghadap
  return Array.isArray(list) ? (list as RowRecord[]) : []
}

const rowExpandKey = (row: RowRecord, index: number) => `search-${rowIdentity(row, index)}`

const isBookRowExpanded = (row: RowRecord, index: number) => Boolean(expandedBookRows[rowExpandKey(row, index)])

const toggleBookRowExpand = (row: RowRecord, index: number) => {
  const key = rowExpandKey(row, index)
  expandedBookRows[key] = !expandedBookRows[key]
}

const runSearch = async (keyword: string) => {
  const query = keyword.trim()
  hasSearchRun.value = true
  errorMessage.value = ''
  responseMessage.value = ''
  searchResults.value = []
  documentResults.value = []

  loading.value = true
  try {
    const response = await business.pencarian.SearchData({ query }) as ApiEnvelope<RowRecord>
    const payload = unwrapPayload(response) as RowRecord
    searchResults.value = Array.isArray(payload?.data_client) ? payload.data_client as SearchClient[] : []
    documentResults.value = Array.isArray(payload?.documents) ? payload.documents as ClientDocument[] : []
    resultPage.value = 1
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal melakukan pencarian dokumen.'
  } finally {
    loading.value = false
  }
}

const submitSearch = async () => {
  const query = searchQuery.value.trim()
  const isDashboardSearch = route.path === '/dashboard'
  await router.replace({
    path: isDashboardSearch ? '/dashboard' : '/pencarian-dokumen',
    query: isDashboardSearch
      ? (query ? { view: 'documents', q: query } : { view: 'documents' })
      : (query ? { q: query } : {}),
  })
}

const closeClientDocumentDialog = () => {
  clientDocumentDialog.open = false
  clientDocumentDialog.loading = false
  clientDocumentDialog.error = ''
  clientDocumentDialog.search = ''
  clientDocumentDialog.client = null
  clientDocumentDialog.documents = []
}

const loadClientDocuments = async (clientId: unknown) => {
  const id = toString(clientId, '')
  clientDocumentDialog.loading = true
  clientDocumentDialog.error = ''
  clientDocumentDialog.documents = []

  if (!id) {
    clientDocumentDialog.loading = false
    clientDocumentDialog.error = 'ID client tidak tersedia.'
    return
  }

  try {
    const response = await business.client.getDataDokumenClient({ id_client: id }) as ApiEnvelope<ClientDocument[]>
    const payload = unwrapPayload(response)
    clientDocumentDialog.documents = Array.isArray(payload) ? payload as ClientDocument[] : []
  } catch (error) {
    clientDocumentDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat dokumen client.'
  } finally {
    clientDocumentDialog.loading = false
  }
}

const openClientDocumentDialog = async (client: SearchClient) => {
  clientDocumentDialog.open = true
  clientDocumentDialog.search = ''
  clientDocumentDialog.client = { ...client }
  await loadClientDocuments(client.id_client)
}

const openClientDocumentDialogById = async (idClient: unknown, namaClient: unknown) => {
  clientDocumentDialog.open = true
  clientDocumentDialog.search = ''
  clientDocumentDialog.client = {
    id_client: toString(idClient, ''),
    nama_client: toString(namaClient, '-'),
  }
  await loadClientDocuments(idClient)
}

const closeBookDialog = () => {
  bookDialog.open = false
  bookDialog.loading = false
  bookDialog.error = ''
  bookDialog.client = null
  bookDialog.rows = []
  clearExpandedBookRows()
}

const loadBookRows = async () => {
  const config = bookConfigs[bookDialog.type]
  const idClient = toString(bookDialog.client?.id_client, '')

  bookDialog.loading = true
  bookDialog.error = ''
  bookDialog.rows = []
  clearExpandedBookRows()

  if (!idClient) {
    bookDialog.loading = false
    bookDialog.error = 'ID client tidak tersedia.'
    return
  }

  try {
    const response = await config.list({ id_client: idClient }) as ApiEnvelope<RowRecord[]>
    const payload = unwrapPayload(response)
    bookDialog.rows = toRowArray(payload)
  } catch (error) {
    bookDialog.error = (error as { data?: { message?: string } })?.data?.message || `Gagal memuat data ${bookDialog.type}.`
  } finally {
    bookDialog.loading = false
  }
}

const openBookDialog = async (type: AktaType, client: SearchClient) => {
  bookDialog.type = type
  bookDialog.client = { ...client }
  bookDialog.open = true
  await loadBookRows()
}

const closeBookDocumentDialog = () => {
  bookDocumentDialog.open = false
  bookDocumentDialog.loading = false
  bookDocumentDialog.error = ''
  bookDocumentDialog.title = ''
  bookDocumentDialog.documents = []
  bookDocumentDialog.previewUrl = ''
  bookDocumentDialog.previewTitle = ''
  bookDocumentDialog.rowId = ''
  bookDocumentDialog.modulePath = ''
  bookDocumentDialog.fileCategory = ''
}

const openBookDocumentPreview = (document: StandardDocument) => {
  const url = getBookDocumentUrl(document)
  if (!url) return
  const fileName = toString(document.nama_berkas, '')
  const previewDocument = document as ClientDocument
  directPreview.title = displayFileName(document.nama_dokumen, fileName)
  directPreview.url = toIframePreviewUrl(url)
  directPreview.extension = documentExtension(previewDocument)
  directPreview.document = null
  resetDirectPreviewTransform()
  directPreview.open = true
}

const openBookDocumentDialog = async (row: RowRecord) => {
  const config = bookConfigs[bookDialog.type]
  const idValue = row[config.idField]
  const id = toString(idValue, '')

  bookDocumentDialog.open = true
  bookDocumentDialog.loading = true
  bookDocumentDialog.error = ''
  bookDocumentDialog.type = bookDialog.type
  bookDocumentDialog.documents = []
  bookDocumentDialog.previewUrl = ''
  bookDocumentDialog.previewTitle = ''
  bookDocumentDialog.rowId = id
  bookDocumentDialog.modulePath = bookDownloadMetaMap[bookDialog.type].modulePath
  bookDocumentDialog.fileCategory = bookDownloadMetaMap[bookDialog.type].fileCategory
  bookDocumentDialog.title = `${bookDialog.type} No ${toString(row[config.numberField], '-')}`

  if (!id) {
    bookDocumentDialog.loading = false
    bookDocumentDialog.error = 'ID data buku tidak tersedia.'
    return
  }

  try {
    const response = await config.listDocuments({ id }) as ApiEnvelope<StandardDocument[]>
    const payload = unwrapPayload(response)
    bookDocumentDialog.documents = Array.isArray(payload) ? payload as StandardDocument[] : []
  } catch (error) {
    bookDocumentDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat dokumen.'
  } finally {
    bookDocumentDialog.loading = false
  }
}

watch(
  () => route.query.q,
  (value) => {
    const normalized = typeof value === 'string' ? value : ''
    searchQuery.value = normalized
    if (normalized) activeSidebar.value = 'all'
    void runSearch(normalized)
  },
  { immediate: true },
)

watch([clientTypeFilter, bookFilter, resultSort, showOnlyWithDocuments, resultPerPage], () => {
  resultPage.value = 1
})

watch(
  () => activeResultCount.value,
  () => {
    if (resultPage.value > totalPages.value) {
      resultPage.value = totalPages.value
    }
    if (resultPage.value < 1) {
      resultPage.value = 1
    }
  },
)
</script>

<template>
  <div class="document-search-page drive-workspace min-h-screen" :class="isDark ? 'document-search-dark' : 'document-search-light'">
    <header class="drive-header fixed inset-x-0 top-0 z-40 flex h-16 items-center gap-3 border-b px-4">
      <NuxtLink to="/dashboard" class="flex shrink-0 items-center gap-3 md:w-60" aria-label="Kembali ke SIMANIS">
        <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-600 text-white shadow-sm">
          <FolderIcon class="h-6 w-6" />
        </span>
        <span class="hidden text-lg font-bold text-slate-800 md:inline">Dokumen SIMANIS</span>
      </NuxtLink>

      <form class="mx-auto w-full max-w-3xl" @submit.prevent="submitSearch">
        <label class="drive-global-search flex h-12 items-center rounded-2xl px-4 transition">
          <MagnifyingGlassIcon class="mr-3 h-5 w-5 shrink-0 text-slate-500" />
          <input
            v-model="searchQuery"
            type="search"
            class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[15px] text-slate-800 outline-none placeholder:text-slate-500"
            placeholder="Cari dalam dokumen SIMANIS"
          />
          <button v-if="searchQuery" type="button" class="grid h-8 w-8 place-items-center rounded-full text-slate-500 hover:bg-slate-200" title="Hapus pencarian" @click="clearSearchQuery">
            <XMarkIcon class="h-4 w-4" />
          </button>
        </label>
      </form>

      <div class="ml-1 flex shrink-0 items-center gap-2 md:ml-5">
        <button type="button" class="drive-icon-button grid h-10 w-10 place-items-center rounded-full" :title="isDark ? 'Mode terang' : 'Mode gelap'" @click="toggleTheme">
          <SunIcon v-if="isDark" class="h-5 w-5" />
          <MoonIcon v-else class="h-5 w-5" />
        </button>
        <span class="grid h-9 w-9 place-items-center rounded-full bg-blue-600 text-sm font-bold text-white">{{ userInitial }}</span>
      </div>
    </header>

    <aside class="drive-sidebar fixed bottom-0 left-0 top-16 z-30 hidden w-64 flex-col border-r px-3 py-5 md:flex">
      <NuxtLink to="/dashboard" class="drive-new-button mb-5 inline-flex w-fit items-center gap-3 rounded-2xl px-5 py-3.5 text-sm font-semibold">
        <ArrowLeftIcon class="h-5 w-5" />
        Kembali
      </NuxtLink>

      <nav class="space-y-1 text-sm">
        <button type="button" :class="sidebarNavClass(activeSidebar === 'all')" @click="selectAllDocuments">
          <HomeIcon class="h-5 w-5" /> Semua Dokumen
        </button>
        <button type="button" :class="sidebarNavClass(activeSidebar === 'recent')" @click="selectRecentDocuments">
          <ClockIcon class="h-5 w-5" /> Terbaru
        </button>
      </nav>

      <p class="mb-2 mt-7 px-4 text-xs font-semibold uppercase text-slate-400">Jenis Client</p>
      <nav class="space-y-1 text-sm">
        <button type="button" :class="sidebarNavClass(activeSidebar === 'perorangan')" @click="selectClientType('perorangan')">
          <UserCircleIcon class="h-5 w-5" /> Perorangan
        </button>
        <button type="button" :class="sidebarNavClass(activeSidebar === 'badan_hukum')" @click="selectClientType('badan_hukum')">
          <BuildingOffice2Icon class="h-5 w-5" /> Badan Hukum
        </button>
      </nav>

      <p class="mb-2 mt-7 px-4 text-xs font-semibold uppercase text-slate-400">Kategori Buku</p>
      <nav class="space-y-1 text-sm">
        <button v-for="type in bookTypeOptions" :key="type" type="button" :class="sidebarNavClass(activeSidebar === type)" @click="selectBookFilter(type)">
          <FolderIcon class="h-5 w-5" :class="activeSidebar === type ? 'text-current' : 'text-blue-500'" /> {{ type }}
        </button>
      </nav>

      <div class="mt-auto border-t px-4 pt-5 text-sm">
        <p class="font-semibold text-slate-700">Hasil tersaring</p>
        <p class="mt-1 text-xs text-slate-500">{{ activeResultCount }} dokumen ditemukan</p>
      </div>
    </aside>

    <main class="min-h-screen pt-16 md:pl-64">
      <div class="mx-auto max-w-[1500px] space-y-5 px-5 py-6 lg:px-8">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 class="text-2xl font-semibold text-slate-800">{{ workspaceTitle }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ workspaceSubtitle }}</p>
          </div>
          <div class="flex items-center rounded-full border border-slate-300 p-1">
            <button type="button" :class="resultViewButtonClass('list')" title="Tampilan daftar" @click="resultViewMode = 'list'">
              <ListBulletIcon class="h-4 w-4" /> List
            </button>
            <button type="button" :class="resultViewButtonClass('grid')" title="Tampilan grid" @click="resultViewMode = 'grid'">
              <Squares2X2Icon class="h-4 w-4" /> Grid
            </button>
          </div>
        </div>

        <div v-if="bookFilter === 'all'" class="flex gap-2 overflow-x-auto pb-1">
          <button type="button" class="drive-filter-chip" @click="clientTypeFilter = 'all'; bookFilter = 'all'">Semua</button>
          <button v-for="keyword in quickKeywords" :key="`drive-${keyword}`" type="button" class="drive-filter-chip" @click="applyQuickKeyword(keyword)">{{ keyword }}</button>
        </div>

    <div
      v-if="downloadCartCount"
      class="fixed bottom-6 right-6 z-[90] w-[min(92vw,420px)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/20"
    >
      <button
        type="button"
        class="flex w-full items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 text-left"
        @click="downloadCartOpen = !downloadCartOpen"
      >
        <span>
          <span class="block text-sm font-semibold text-slate-900">Keranjang Download</span>
          <span class="text-xs text-slate-500">{{ downloadCartCount }} dokumen siap direquest</span>
        </span>
        <span class="rounded-full bg-blue-600 px-2.5 py-1 text-xs font-bold text-white">{{ downloadCartCount }}</span>
      </button>

      <div v-if="downloadCartOpen" class="space-y-3 p-4">
        <div class="max-h-56 space-y-2 overflow-y-auto pr-1">
          <div
            v-for="item in downloadCart"
            :key="item.key"
            class="flex items-start justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-slate-800" :title="item.label">{{ item.label }}</p>
              <p class="truncate text-xs text-slate-500" :title="item.display_name">{{ item.display_name }}</p>
            </div>
            <button
              type="button"
              class="shrink-0 rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-600 transition hover:bg-white"
              @click="removeDownloadCartItem(item.key)"
            >
              Hapus
            </button>
          </div>
        </div>
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="inline-flex h-10 flex-1 items-center justify-center rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="downloadRequestLoading"
            @click="submitDownloadCart"
          >
            {{ downloadRequestLoading ? 'Mengirim...' : 'Kirim Request Massal' }}
          </button>
          <button
            type="button"
            class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            :disabled="downloadRequestLoading"
            @click="clearDownloadCart"
          >
            Kosongkan
          </button>
        </div>
      </div>
    </div>

    <SurfaceCard
      class="drive-legacy-hero relative overflow-hidden p-0"
      :class="isDark ? 'border-slate-700/80 shadow-xl shadow-slate-950/60' : 'border-slate-200/80 shadow-xl shadow-blue-100/40'"
    >
      <div
        class="pointer-events-none absolute inset-0 bg-gradient-to-br"
        :class="isDark ? 'from-slate-900 via-slate-900/95 to-slate-800/90' : 'from-sky-100 via-white to-indigo-100'"
      />
      <div class="pointer-events-none absolute -left-16 top-8 h-40 w-40 rounded-full blur-3xl" :class="isDark ? 'bg-sky-500/20' : 'bg-sky-300/35'" />
      <div class="pointer-events-none absolute -right-14 -bottom-10 h-40 w-40 rounded-full blur-3xl" :class="isDark ? 'bg-indigo-500/20' : 'bg-indigo-300/30'" />

      <div class="relative space-y-5 p-5 sm:p-7">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div class="flex items-center gap-3">
            <div class="drive-folder-icon inline-flex h-11 w-11 items-center justify-center rounded-xl">
              <MagnifyingGlassIcon class="h-6 w-6" />
            </div>
            <div>
              <p class="display-kicker">Ruang Dokumen</p>
              <h2 class="font-display text-3xl text-slate-900 sm:text-4xl">Pencarian Dokumen</h2>
            </div>
          </div>
          <div class="drive-location inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold">
            <span class="h-2 w-2 rounded-full bg-emerald-500" /> Semua lokasi
          </div>
        </div>

        <form class="mx-auto flex max-w-5xl flex-col gap-3 lg:flex-row" @submit.prevent="submitSearch">
          <label class="flex-1">
            <span class="sr-only">Pencarian Dokumen</span>
            <div class="drive-search-field group relative">
              <input
                v-model="searchQuery"
                type="text"
                placeholder="Masukkan nama client, identitas, atau kata kunci dokumen"
                class="h-12 w-full rounded-2xl border border-slate-200 bg-white/95 pl-11 pr-11 text-sm font-medium text-slate-700 shadow-sm transition focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
              <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500" />
              <button
                v-if="searchQuery"
                type="button"
                class="absolute right-2 top-1/2 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                @click="clearSearchQuery"
              >
                <XMarkIcon class="h-4 w-4" />
              </button>
            </div>
          </label>
          <button
            type="submit"
            class="inline-flex h-12 items-center justify-center gap-2 rounded-2xl bg-blue-600 px-6 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="loading"
          >
            <SparklesIcon class="h-4 w-4" />
            {{ loading ? 'Mencari...' : 'Cari Dokumen' }}
          </button>
        </form>

        <div class="flex flex-wrap items-center gap-2 border-t border-slate-200/70 pt-4">
          <p class="inline-flex items-center gap-1 text-xs font-semibold uppercase tracking-wider text-slate-500">
            <FunnelIcon class="h-4 w-4" />
            Kata Kunci Cepat
          </p>
          <button
            v-for="keyword in quickKeywords"
            :key="`quick-${keyword}`"
            type="button"
            class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"
            @click="applyQuickKeyword(keyword)"
          >
            {{ keyword }}
          </button>
        </div>

        <p v-if="responseMessage" class="text-xs text-slate-500">{{ responseMessage }}</p>
      </div>
    </SurfaceCard>

    <SurfaceCard
      class="drive-results-shell border p-5 shadow-sm sm:p-6"
      :class="isDark ? 'border-slate-700/80 bg-gradient-to-b from-slate-900 to-slate-900/70' : 'border-slate-200/80 bg-gradient-to-b from-white to-slate-50/60'"
    >
      <p v-if="loading" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
        Memuat hasil pencarian...
      </p>

      <p v-else-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errorMessage }}
      </p>

      <div
        v-else-if="!hasSearchRun"
        class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-9 text-center text-sm text-slate-600"
      >
        Masukkan kata kunci lalu tekan cari.
      </div>

      <div
        v-else-if="!activeResultCount"
        class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-9 text-center text-sm text-slate-600"
      >
        Pencarian tidak ditemukan.
      </div>

      <div v-else class="space-y-5">
        <div class="drive-toolbar rounded-2xl border border-slate-200 p-4 shadow-sm">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ bookFilter !== 'all' ? 'Client terkait' : showingLatest ? 'Data terbaru' : 'Hasil pencarian' }}</p>
              <p class="mt-1 text-sm text-slate-700">
                Menampilkan {{ resultStart }} - {{ resultEnd }} dari {{ activeResultCount }} data.
              </p>
            </div>
            <span v-if="bookFilter !== 'all'" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white">
              <FolderIcon class="h-4 w-4" /> {{ bookFilter }}
            </span>
          </div>

          <div class="mt-4 grid gap-3 md:grid-cols-3">
            <label v-if="bookFilter !== 'all'" class="flex flex-col gap-1.5">
              <span class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                <FunnelIcon class="h-4 w-4" />
                Jenis Client
              </span>
              <select v-model="clientTypeFilter" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option value="all">Semua</option>
                <option value="perorangan">Perorangan</option>
                <option value="badan_hukum">Badan Hukum</option>
              </select>
            </label>
            <label class="flex flex-col gap-1.5">
              <span class="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                <ArrowsUpDownIcon class="h-4 w-4" />
                Urutkan
              </span>
              <select v-model="resultSort" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option v-for="option in resultSortOptions" :key="`sort-${option.value}`" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
            </label>
            <div class="flex flex-col justify-end gap-2">
              <select v-model.number="resultPerPage" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option :value="9">9 / halaman</option>
                <option :value="12">12 / halaman</option>
                <option :value="18">18 / halaman</option>
              </select>
            </div>
          </div>

        </div>

        <div
          v-if="!activeResultCount"
          class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-600"
        >
          Tidak ada hasil untuk kombinasi filter saat ini.
        </div>

        <div v-else-if="bookFilter === 'all' && resultViewMode === 'grid'" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          <article
            v-for="(document, index) in paginatedDocumentResults"
            :key="documentResultKey(document, index)"
            tabindex="0"
            class="group overflow-hidden rounded-xl border border-slate-200 bg-white outline-none transition hover:border-blue-300 hover:shadow-md focus-visible:ring-2 focus-visible:ring-blue-500"
            @dblclick="openDirectPreview(document)"
            @contextmenu="openDocumentContextMenu($event, document)"
            @keydown.enter.prevent="openDirectPreview(document)"
          >
            <button type="button" class="relative flex h-40 w-full items-center justify-center overflow-hidden bg-slate-100" @click="openDirectPreview(document)">
              <img v-if="documentIsImage(document)" :src="getClientDocumentUrl(document)" :alt="displayFileName(document.nama_dokumen, document.nama_berkas)" class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.02]" loading="lazy" />
              <iframe v-else-if="documentIsPdf(document)" :src="toIframePreviewUrl(getClientDocumentUrl(document))" :title="`Thumbnail ${displayFileName(document.nama_dokumen, document.nama_berkas)}`" class="pointer-events-none h-[210px] w-full border-0 bg-white" loading="lazy" tabindex="-1" />
              <span v-else class="grid h-24 w-20 place-items-center rounded-lg border border-current/15" :class="[documentAppearance(document).background, documentAppearance(document).color]">
                <component :is="documentAppearance(document).icon" class="h-12 w-12" />
              </span>
              <span class="absolute bottom-2 right-2 rounded-md px-2 py-1 text-[10px] font-bold text-white shadow" :class="documentAppearance(document).badge">{{ documentExtension(document) }}</span>
            </button>
            <div class="p-3">
              <div class="flex items-start gap-2">
                <component :is="documentAppearance(document).icon" class="mt-0.5 h-5 w-5 shrink-0" :class="documentAppearance(document).color" />
                <div class="min-w-0">
                  <p class="truncate text-sm font-semibold text-slate-800" :title="displayFileName(document.nama_dokumen, document.nama_berkas)">{{ displayFileName(document.nama_dokumen, document.nama_berkas) }}</p>
                  <p class="mt-1 truncate text-xs text-slate-500">{{ toString(document.nama_client, 'Tanpa client') }}</p>
                </div>
              </div>
            </div>
          </article>
        </div>

        <div v-else-if="bookFilter === 'all'" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <button
            v-for="(document, index) in paginatedDocumentResults"
            :key="documentResultKey(document, index)"
            type="button"
            class="grid w-full grid-cols-[minmax(0,2fr)_minmax(130px,1fr)_90px] items-center gap-4 border-b border-slate-100 px-4 py-3 text-left last:border-0 hover:bg-blue-50/60"
            @click="openDirectPreview(document)"
            @contextmenu="openDocumentContextMenu($event, document)"
          >
            <span class="flex min-w-0 items-center gap-3"><span class="grid h-9 w-8 shrink-0 place-items-center rounded-md" :class="[documentAppearance(document).background, documentAppearance(document).color]"><component :is="documentAppearance(document).icon" class="h-5 w-5" /></span><span class="truncate text-sm font-semibold text-slate-800">{{ displayFileName(document.nama_dokumen, document.nama_berkas) }}</span></span>
            <span class="truncate text-xs text-slate-500">{{ toString(document.nama_client, 'Tanpa client') }}</span>
            <span class="w-fit rounded-md px-2 py-1 text-[10px] font-bold text-white" :class="documentAppearance(document).badge">{{ documentExtension(document) }}</span>
          </button>
        </div>

        <div v-else-if="resultViewMode === 'grid'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <article
            v-for="(client, index) in paginatedSearchResults"
            :key="clientCardKey(client, index)"
            class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
          >
            <div class="pointer-events-none absolute -right-8 -top-8 h-20 w-20 rounded-full bg-slate-100/70"></div>
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                  {{ toString(client.no_identitas) }}
                </p>
                <h3 class="mt-1 text-sm font-semibold text-slate-900">
                  {{ truncateText(client.nama_client, 42) }}
                </h3>
                <p class="mt-1 text-xs text-slate-500">
                  ID Client: {{ toString(client.id_client) }}
                </p>
                <div class="mt-2">
                  <span :class="clientTypeBadgeClass(client)">
                    {{ resolveClientType(client) === 'perorangan' ? 'Perorangan' : resolveClientType(client) === 'badan_hukum' ? 'Badan Hukum' : 'Tidak diketahui' }}
                  </span>
                </div>
              </div>
              <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                <UserCircleIcon v-if="resolveClientType(client) === 'perorangan'" class="h-6 w-6" />
                <BuildingOffice2Icon v-else class="h-6 w-6" />
              </div>
            </div>

            <div class="mt-3 flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600">
              <span>{{ selectedBookType }}</span>
              <span>{{ selectedBookCount(client) }} data</span>
            </div>

            <button
              v-if="selectedBookType"
              type="button"
              class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
              @click="openBookDialog(selectedBookType, client)"
            >
              <FolderIcon class="h-4 w-4" /> Buka {{ selectedBookType }}
            </button>
          </article>
        </div>

        <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
              <thead class="bg-slate-100/80">
                <tr>
                  <th class="w-14 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nama Client</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No Identitas</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Jenis</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Jumlah</th>
                  <th class="w-[180px] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100 bg-white">
                <tr v-for="(client, index) in paginatedSearchResults" :key="clientCardKey(client, index)" class="hover:bg-slate-50/80">
                  <td class="px-3 py-2 text-sm text-slate-600">{{ resultStart + index }}</td>
                  <td class="px-3 py-2 text-sm font-semibold text-slate-800">{{ toString(client.nama_client) }}</td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ toString(client.no_identitas) }}</td>
                  <td class="px-3 py-2 text-sm">
                    <span :class="clientTypeBadgeClass(client)">
                      {{ resolveClientType(client) === 'perorangan' ? 'Perorangan' : resolveClientType(client) === 'badan_hukum' ? 'Badan Hukum' : 'Tidak diketahui' }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-sm font-semibold text-slate-800">{{ selectedBookCount(client) }} data</td>
                  <td class="px-3 py-2">
                    <div v-if="selectedBookType" class="flex flex-wrap gap-1.5">
                      <button
                        type="button"
                        class="inline-flex h-9 items-center gap-2 rounded-lg bg-blue-600 px-3 text-xs font-semibold text-white hover:bg-blue-700"
                        @click="openBookDialog(selectedBookType, client)"
                      >
                        <FolderIcon class="h-4 w-4" /> Buka
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="activeResultCount" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
          <p class="text-xs text-slate-600">
            Halaman {{ resultPage }} dari {{ totalPages }}.
          </p>
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="resultPage <= 1"
              @click="goToPrevPage"
            >
              Prev
            </button>
            <span class="text-xs font-semibold text-slate-700">{{ resultPage }} / {{ totalPages }}</span>
            <button
              type="button"
              class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="resultPage >= totalPages"
              @click="goToNextPage"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </SurfaceCard>
      </div>
    </main>

    <Teleport to="body">
      <button v-if="documentContextMenu.open" type="button" class="fixed inset-0 z-[98] cursor-default" aria-label="Tutup menu dokumen" @click="documentContextMenu.open = false" @contextmenu.prevent="documentContextMenu.open = false" />
      <div
        v-if="documentContextMenu.open && documentContextMenu.document"
        class="fixed z-[99] w-56 overflow-hidden rounded-xl border border-slate-200 bg-white p-1.5 shadow-2xl"
        :style="{ left: `${documentContextMenu.x}px`, top: `${documentContextMenu.y}px` }"
        role="menu"
        @contextmenu.prevent
      >
        <p class="truncate border-b border-slate-100 px-3 py-2 text-xs font-semibold text-slate-500">{{ displayFileName(documentContextMenu.document.nama_dokumen, documentContextMenu.document.nama_berkas) }}</p>
        <button type="button" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700" @click="openDirectPreview(documentContextMenu.document)"><EyeIcon class="h-5 w-5" /> Pratinjau</button>
        <button type="button" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700" @click="requestDirectDocumentDownload(documentContextMenu.document)"><ArrowDownTrayIcon class="h-5 w-5" /> Download</button>
        <button type="button" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700" @click="addDirectDocumentToCart(documentContextMenu.document)"><FolderPlusIcon class="h-5 w-5" /> Tambah ke keranjang</button>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="directPreview.open" class="fixed inset-0 z-[110] flex h-screen w-screen flex-col bg-slate-950/95 backdrop-blur-sm" @keydown.esc="closeDirectPreview">
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 bg-slate-950/90 px-4 text-white sm:px-5">
          <DocumentIcon class="h-6 w-6 shrink-0 text-blue-400" />
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold">{{ directPreview.title }}</p>
            <p class="text-xs text-slate-400">{{ directPreview.extension }}</p>
          </div>

          <div v-if="directPreviewKind === 'image'" class="hidden items-center gap-1 rounded-lg bg-white/5 p-1 sm:flex">
            <button type="button" class="grid h-9 w-9 place-items-center rounded-md hover:bg-white/10" title="Perkecil" @click="changeDirectPreviewZoom(-0.25)"><MagnifyingGlassMinusIcon class="h-5 w-5" /></button>
            <button type="button" class="h-9 min-w-16 rounded-md px-2 text-xs font-semibold hover:bg-white/10" title="Ukuran asli" @click="resetDirectPreviewTransform">{{ Math.round(directPreview.zoom * 100) }}%</button>
            <button type="button" class="grid h-9 w-9 place-items-center rounded-md hover:bg-white/10" title="Perbesar" @click="changeDirectPreviewZoom(0.25)"><MagnifyingGlassPlusIcon class="h-5 w-5" /></button>
            <button type="button" class="grid h-9 w-9 place-items-center rounded-md hover:bg-white/10" title="Putar" @click="directPreview.rotation = (directPreview.rotation + 90) % 360"><ArrowPathIcon class="h-5 w-5" /></button>
          </div>

          <button v-if="directPreview.document" type="button" class="grid h-10 w-10 place-items-center rounded-lg hover:bg-white/10" title="Download" @click="requestDirectDocumentDownload(directPreview.document)"><ArrowDownTrayIcon class="h-5 w-5" /></button>
          <button type="button" class="grid h-10 w-10 place-items-center rounded-lg hover:bg-white/10" title="Tutup" @click="closeDirectPreview"><XMarkIcon class="h-6 w-6" /></button>
        </div>

        <div v-if="directPreviewKind === 'image'" class="relative min-h-0 flex-1 overflow-auto bg-slate-900 p-5 sm:p-8">
          <div class="flex min-h-full min-w-full items-center justify-center">
            <img
              :src="directPreview.url"
              :alt="directPreview.title"
              class="block max-h-[calc(100vh-8rem)] max-w-[calc(100vw-3rem)] select-none object-contain shadow-2xl transition-transform duration-200"
              :style="{ transform: `scale(${directPreview.zoom}) rotate(${directPreview.rotation}deg)` }"
              draggable="false"
            />
          </div>
        </div>
        <iframe v-else-if="directPreviewKind === 'pdf'" :src="directPreview.url" :title="directPreview.title" class="min-h-0 flex-1 border-0 bg-slate-800" />
        <div v-else class="flex min-h-0 flex-1 flex-col items-center justify-center gap-4 p-8 text-center text-white">
          <DocumentIcon class="h-20 w-20 text-slate-500" />
          <p class="text-lg font-semibold">Preview belum tersedia untuk format {{ directPreview.extension }}.</p>
          <button v-if="directPreview.document" type="button" class="inline-flex h-11 items-center gap-2 rounded-lg bg-blue-600 px-5 text-sm font-semibold hover:bg-blue-500" @click="requestDirectDocumentDownload(directPreview.document)"><ArrowDownTrayIcon class="h-5 w-5" /> Download file</button>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="clientDocumentDialog.open" class="fixed inset-0 z-[100] flex h-screen w-screen items-stretch justify-stretch">
        <button
          type="button"
          class="absolute inset-0 bg-slate-900/60"
          @click="closeClientDocumentDialog"
        />
        <div class="document-fullscreen-dialog relative z-10 flex h-full w-full flex-col overflow-hidden bg-white" :class="isDark ? 'document-dialog-dark' : 'document-dialog-light'">
          <div class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:px-8">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Dokumen Client</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ clientDocumentTitle }}</h3>
            </div>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-600 transition hover:bg-slate-50"
              @click="closeClientDocumentDialog"
            >
              <XMarkIcon class="h-5 w-5" />
            </button>
          </div>

          <div class="shrink-0 border-b border-slate-200 px-5 py-3 sm:px-8">
            <input
              v-model="clientDocumentDialog.search"
              type="text"
              placeholder="Cari nama dokumen / deskripsi..."
              class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-8">
            <p
              v-if="clientDocumentDialog.loading"
              class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600"
            >
              Memuat dokumen client...
            </p>
            <p
              v-else-if="clientDocumentDialog.error"
              class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
            >
              {{ clientDocumentDialog.error }}
            </p>
            <p
              v-else-if="!filteredClientDocuments.length"
              class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600"
            >
              Dokumen client tidak tersedia.
            </p>

            <div v-else class="space-y-3">
              <details
                v-for="(document, index) in filteredClientDocuments"
                :key="`${toString(document.id_berkas, 'doc')}-${index}`"
                class="overflow-hidden rounded-xl border border-slate-200 bg-white"
              >
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-800">
                  {{ toString(document.nama_dokumen) }}
                </summary>
                <div class="space-y-3 border-t border-slate-100 px-4 py-3">
                  <div class="flex flex-wrap items-center gap-2">
                    <span
                      v-if="getClientDocumentUrl(document)"
                      class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-semibold text-slate-600"
                    >
                      Preview internal
                    </span>
                    <button
                      v-if="getClientDocumentUrl(document)"
                      type="button"
                      class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 transition hover:border-amber-300 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                      :disabled="downloadRequestLoading"
                      @click="requestClientDocumentDownload(document)"
                    >
                      {{ downloadRequestLoading ? 'Memproses...' : 'Request Download' }}
                    </button>
                    <button
                      v-if="getClientDocumentUrl(document)"
                      type="button"
                      class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100"
                      @click="addClientDocumentToCart(document)"
                    >
                      Tambah Keranjang
                    </button>
                    <span class="text-xs text-slate-500">
                      File: {{ toString(document.nama_berkas) }}
                    </span>
                  </div>
                  <iframe
                    v-if="getClientDocumentUrl(document)"
                    :src="toIframePreviewUrl(getClientDocumentUrl(document))"
                    class="h-[calc(100vh-250px)] min-h-[520px] w-full rounded-lg border border-slate-200"
                    frameborder="0"
                  />
                </div>
              </details>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="bookDialog.open" class="fixed inset-0 z-[100] flex h-screen w-screen items-stretch justify-stretch">
        <button
          type="button"
          class="absolute inset-0 bg-slate-900/60"
          @click="closeBookDialog"
        />
        <div class="document-fullscreen-dialog relative z-10 flex h-full w-full flex-col overflow-hidden bg-white" :class="isDark ? 'document-dialog-dark' : 'document-dialog-light'">
          <div class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:px-8">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Data Buku</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ bookDialogTitle }}</h3>
            </div>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-600 transition hover:bg-slate-50"
              @click="closeBookDialog"
            >
              <XMarkIcon class="h-5 w-5" />
            </button>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto p-5 sm:p-8">
            <p v-if="bookDialog.loading" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
              Memuat data {{ bookDialog.type }}...
            </p>
            <p v-else-if="bookDialog.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {{ bookDialog.error }}
            </p>
            <p v-else-if="!bookDialog.rows.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
              Data {{ bookDialog.type }} tidak tersedia untuk client ini.
            </p>

            <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              <article v-for="(row, index) in bookDialog.rows" :key="rowIdentity(row, index)" class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 p-4">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <p class="text-xs font-semibold uppercase text-blue-600">{{ bookDialog.type }}</p>
                      <h4 class="mt-1 truncate text-base font-semibold text-slate-900">{{ toString(row[bookConfigs[bookDialog.type].numberField], `Data ${index + 1}`) }}</h4>
                    </div>
                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-blue-50 text-blue-600"><FolderIcon class="h-5 w-5" /></span>
                  </div>
                  <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3">
                    <div v-for="column in bookColumns" :key="`${rowIdentity(row, index)}-${column.key}`" class="min-w-0">
                      <dt class="text-[10px] font-semibold uppercase text-slate-400">{{ column.label }}</dt>
                      <dd class="mt-1 truncate text-sm text-slate-700" :title="toString(row[column.key])">{{ column.key.includes('tgl') || column.key.includes('tanggal') ? formatDateOnly(row[column.key]) : toString(row[column.key]) }}</dd>
                    </div>
                  </dl>
                </div>
                <div v-if="isBookRowExpanded(row, index)" class="space-y-3 border-b border-slate-100 bg-slate-50 p-4">
                  <p class="text-xs font-semibold uppercase text-slate-500">Data Penghadap</p>
                  <div v-if="getPenghadapRows(row).length" class="space-y-2">
                    <button v-for="(penghadap, pIndex) in getPenghadapRows(row)" :key="`${rowIdentity(row, index)}-penghadap-${pIndex}`" type="button" class="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-left" @click="openClientDocumentDialogById(penghadap.id_client, penghadap.nama_client)">
                      <span class="min-w-0"><span class="block truncate text-sm font-semibold text-slate-800">{{ toString(penghadap.nama_client) }}</span><span class="text-xs text-slate-500">{{ toString(penghadap.status_kedudukan) }}</span></span>
                      <UserCircleIcon class="h-5 w-5 shrink-0 text-slate-400" />
                    </button>
                  </div>
                  <p v-else class="text-xs text-slate-500">Data penghadap tidak tersedia.</p>
                  <div v-if="bookDialog.type === 'Akta PPAT'" class="grid gap-2 sm:grid-cols-2">
                    <div v-for="pair in ppatDetailPairs(row)" :key="`${rowIdentity(row, index)}-${pair[0]}`" class="rounded-lg border border-slate-200 bg-white px-3 py-2"><p class="text-[10px] font-semibold uppercase text-slate-400">{{ pair[0] }}</p><p class="mt-1 text-xs text-slate-700">{{ toString(pair[1]) }}</p></div>
                  </div>
                </div>
                <div class="flex gap-2 p-3">
                  <button type="button" class="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="toggleBookRowExpand(row, index)">{{ isBookRowExpanded(row, index) ? 'Tutup Detail' : 'Lihat Detail' }}</button>
                  <button type="button" class="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-lg bg-blue-600 text-xs font-semibold text-white hover:bg-blue-700" @click="openBookDocumentDialog(row)"><DocumentIcon class="h-4 w-4" /> Lihat Dokumen</button>
                </div>
              </article>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="bookDocumentDialog.open" class="fixed inset-0 z-[105] flex h-screen w-screen items-stretch justify-stretch">
        <button
          type="button"
          class="absolute inset-0 bg-slate-900/65"
          @click="closeBookDocumentDialog"
        />
        <div class="document-fullscreen-dialog relative z-10 flex h-full w-full flex-col overflow-hidden bg-white" :class="isDark ? 'document-dialog-dark' : 'document-dialog-light'">
          <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Dokumen Buku</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ bookDocumentDialog.title }}</h3>
            </div>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-600 transition hover:bg-slate-50"
              @click="closeBookDocumentDialog"
            >
              <XMarkIcon class="h-5 w-5" />
            </button>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto p-5 sm:p-8">
            <p v-if="bookDocumentDialog.loading" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
              Memuat dokumen...
            </p>
            <p v-else-if="bookDocumentDialog.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {{ bookDocumentDialog.error }}
            </p>
            <p v-else-if="!bookDocumentDialog.documents.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
              Dokumen belum ditambahkan.
            </p>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
              <article v-for="(document, index) in bookDocumentDialog.documents" :key="`${toString(document.nama_berkas, 'berkas')}-${index}`" class="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:border-blue-300 hover:shadow-md">
                <button type="button" class="relative flex h-40 w-full items-center justify-center overflow-hidden bg-slate-100" @click="openBookDocumentPreview(document)">
                  <img v-if="documentIsImage(document as ClientDocument)" :src="getBookDocumentUrl(document)" :alt="displayFileName(document.nama_dokumen, document.nama_berkas)" class="h-full w-full object-cover transition group-hover:scale-[1.02]" loading="lazy" />
                  <iframe v-else-if="documentIsPdf(document as ClientDocument)" :src="toIframePreviewUrl(getBookDocumentUrl(document))" :title="`Thumbnail ${displayFileName(document.nama_dokumen, document.nama_berkas)}`" class="pointer-events-none h-[210px] w-full border-0 bg-white" loading="lazy" tabindex="-1" />
                  <span v-else class="grid h-24 w-20 place-items-center rounded-lg border border-current/15" :class="[documentAppearance(document as ClientDocument).background, documentAppearance(document as ClientDocument).color]"><component :is="documentAppearance(document as ClientDocument).icon" class="h-12 w-12" /></span>
                  <span class="absolute bottom-2 right-2 rounded-md px-2 py-1 text-[10px] font-bold text-white shadow" :class="documentAppearance(document as ClientDocument).badge">{{ documentExtension(document as ClientDocument) }}</span>
                </button>
                <div class="p-3">
                  <div class="flex min-w-0 items-start gap-2"><component :is="documentAppearance(document as ClientDocument).icon" class="mt-0.5 h-5 w-5 shrink-0" :class="documentAppearance(document as ClientDocument).color" /><p class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800" :title="displayFileName(document.nama_dokumen, document.nama_berkas)">{{ displayFileName(document.nama_dokumen, document.nama_berkas) }}</p></div>
                  <div class="mt-3 flex gap-2">
                    <button type="button" class="inline-flex h-9 flex-1 items-center justify-center gap-1 rounded-lg bg-blue-600 text-xs font-semibold text-white hover:bg-blue-700" @click="openBookDocumentPreview(document)"><EyeIcon class="h-4 w-4" /> Preview</button>
                    <button type="button" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50" title="Request download" :disabled="downloadRequestLoading" @click="requestBookDocumentDownload(document)"><ArrowDownTrayIcon class="h-4 w-4" /></button>
                    <button type="button" class="grid h-9 w-9 place-items-center rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50" title="Tambah ke keranjang" @click="addBookDocumentToCart(document)"><FolderPlusIcon class="h-4 w-4" /></button>
                  </div>
                </div>
              </article>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style>
.document-search-page {
  --drive-surface: #ffffff;
  --drive-muted: #f8fafc;
  --drive-border: #dbe4ef;
  background:
    linear-gradient(rgb(var(--simanis-accent-rgb) / 0.035) 1px, transparent 1px),
    linear-gradient(90deg, rgb(var(--simanis-accent-rgb) / 0.035) 1px, transparent 1px),
    #f8fafc;
  background-size: 32px 32px;
  color: #1e293b;
}

.drive-header,
.drive-sidebar {
  border-color: var(--drive-border);
  background: var(--drive-surface);
}

.drive-header {
  background:
    linear-gradient(90deg, rgb(var(--simanis-accent-rgb) / 0.08), transparent 42%),
    var(--drive-surface);
}

.drive-header .bg-blue-600,
.drive-workspace .bg-blue-600 {
  background: linear-gradient(135deg, var(--simanis-accent-grad-from), var(--simanis-accent-grad-to)) !important;
}

.drive-global-search {
  background: #f1f5f9;
}

.drive-global-search:focus-within {
  background: var(--drive-surface);
  border-color: rgb(var(--simanis-accent-rgb) / 0.45);
  box-shadow: 0 2px 10px var(--simanis-accent-shadow);
}

.drive-icon-button,
.drive-nav-item {
  color: #475569;
}

.drive-icon-button:hover,
.drive-nav-item:hover {
  background: #f1f5f9;
}

.drive-new-button {
  color: #334155;
  background: var(--drive-surface);
  box-shadow: 0 1px 3px 1px rgb(60 64 67 / 0.18);
}

.drive-new-button:hover {
  background: #f8fafc;
  box-shadow: 0 2px 6px 2px rgb(60 64 67 / 0.16);
}

.drive-nav-active {
  color: var(--simanis-accent-700);
  background: var(--simanis-accent-100);
}

.drive-filter-chip {
  white-space: nowrap;
  border: 1px solid #cbd5e1;
  border-radius: 0.5rem;
  padding: 0.5rem 1rem;
  color: #475569;
  background: var(--drive-surface);
  font-size: 0.875rem;
  font-weight: 600;
}

.drive-filter-chip:hover {
  color: var(--simanis-accent-700);
  border-color: var(--simanis-accent-500);
  background: var(--simanis-accent-50);
}

.drive-legacy-hero {
  display: none !important;
}

.drive-results-shell {
  border-color: transparent !important;
  background: transparent !important;
  box-shadow: none !important;
}

.document-search-page .drive-folder-icon {
  color: #2563eb;
  background: #dbeafe;
  border: 1px solid #bfdbfe;
}

.document-search-page .drive-location {
  color: #475569;
  background: #f1f5f9;
}

.document-search-page .drive-search-field input {
  border-color: #cbd5e1;
  background: #ffffff;
  color: #0f172a;
}

.document-search-dark {
  --drive-surface: #111827;
  --drive-muted: #17233d;
  --drive-border: #334155;
  background:
    linear-gradient(rgb(var(--simanis-accent-rgb) / 0.07) 1px, transparent 1px),
    linear-gradient(90deg, rgb(var(--simanis-accent-rgb) / 0.07) 1px, transparent 1px),
    #020817;
  background-size: 36px 36px;
}

.document-search-dark .drive-global-search,
.document-search-dark .drive-icon-button:hover,
.document-search-dark .drive-nav-item:hover {
  background: #17233d;
}

.document-search-dark .drive-nav-item {
  color: #aebdd3;
}

.document-search-dark .drive-global-search input,
.document-search-dark .drive-header .text-slate-800,
.document-search-dark .drive-sidebar .text-slate-700 {
  color: #e2e8f0 !important;
}

.document-search-dark .drive-new-button,
.document-search-dark .drive-filter-chip {
  color: #cbd5e1;
  border-color: #334155;
  background: #111827;
}

.document-search-dark .drive-nav-active {
  color: #ffffff;
  background: linear-gradient(90deg, rgb(var(--simanis-accent-rgb) / 0.48), rgb(var(--simanis-accent-rgb) / 0.18));
}

.document-search-dark .drive-folder-icon {
  color: #93c5fd;
  background: rgb(37 99 235 / 0.18);
  border-color: rgb(96 165 250 / 0.35);
}

.document-search-dark .drive-location,
.document-search-dark .drive-search-field input,
.document-search-dark .bg-white,
.document-search-dark .bg-slate-50,
.document-search-dark .bg-slate-50\/60,
.document-search-dark .bg-slate-100\/80 {
  background-color: var(--drive-surface) !important;
}

.document-search-dark .drive-location,
.document-search-dark .text-slate-900,
.document-search-dark .text-slate-800,
.document-search-dark .text-slate-700,
.document-search-dark .text-slate-600 {
  color: #e2e8f0 !important;
}

.document-search-dark .drive-search-field input {
  border-color: var(--drive-border);
}

.document-search-dark .text-slate-500 {
  color: #94a3b8 !important;
}

.document-search-dark .border-slate-100,
.document-search-dark .border-slate-200,
.document-search-dark .border-slate-300,
.document-search-dark .divide-slate-100 > :not([hidden]) ~ :not([hidden]),
.document-search-dark .divide-slate-200 > :not([hidden]) ~ :not([hidden]) {
  border-color: var(--drive-border) !important;
}

.document-search-dark .drive-toolbar {
  background: var(--drive-muted);
  border-color: var(--drive-border);
}

.document-fullscreen-dialog.document-dialog-light {
  color: #0f172a;
  background:
    radial-gradient(circle at top left, rgb(var(--simanis-accent-rgb) / 0.1), transparent 32rem),
    color-mix(in srgb, #ffffff 94%, var(--simanis-accent-50) 6%) !important;
}

.document-fullscreen-dialog.document-dialog-dark {
  color: #e2e8f0;
  background: color-mix(in srgb, #020617 92%, var(--simanis-accent-grad-from) 8%) !important;
}

.document-dialog-dark .bg-white,
.document-dialog-dark .bg-slate-50,
.document-dialog-dark .bg-slate-50\/60,
.document-dialog-dark .bg-slate-50\/70,
.document-dialog-dark .bg-slate-100\/70,
.document-dialog-dark .bg-slate-100\/80 {
  background-color: color-mix(in srgb, #0f172a 90%, var(--simanis-accent-grad-from) 10%) !important;
}

.document-dialog-dark .text-slate-900,
.document-dialog-dark .text-slate-800,
.document-dialog-dark .text-slate-700,
.document-dialog-dark .text-slate-600 {
  color: #e2e8f0 !important;
}

.document-dialog-dark .text-slate-500 {
  color: #94a3b8 !important;
}

.document-dialog-dark .border-slate-100,
.document-dialog-dark .border-slate-200,
.document-dialog-dark .border-slate-300,
.document-dialog-dark .divide-slate-100 > :not([hidden]) ~ :not([hidden]),
.document-dialog-dark .divide-slate-200 > :not([hidden]) ~ :not([hidden]) {
  border-color: #334155 !important;
}

.document-dialog-dark input {
  color: #f8fafc !important;
  border-color: #334155 !important;
  background: #0f172a !important;
}

.document-dialog-dark input::placeholder {
  color: #64748b !important;
}

.document-dialog-light > div:first-child,
.document-dialog-light > div:nth-child(2) {
  background: color-mix(in srgb, #ffffff 90%, var(--simanis-accent-50) 10%);
}
</style>
