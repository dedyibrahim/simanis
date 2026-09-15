<script setup lang="ts">
type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type ClientPageData = {
  rows?: ClientRow[]
  pagination?: {
    page?: number
    per_page?: number
    total?: number
    filtered?: number
    last_page?: number
  }
}

type ClientType = 'Perorangan' | 'Badan Hukum'
type ClientSearchField = 'all' | 'name' | 'identity' | 'creator' | 'contact'
type ClientSortKey = 'id_client' | 'nama_client' | 'no_identitas' | 'pembuat_client'
type SortDirection = 'asc' | 'desc'
type BantekSearchField = 'all' | 'no_bantek' | 'lokasi' | 'client'

type ClientRow = Record<string, unknown> & {
  id_client?: string | number
  nama_client?: string
  no_identitas?: string
  jenis_client?: string
  alamat_client?: string
  contact_number?: string | number
  email?: string
  pembuat_client?: string
}

type ClientIdentityCheck = {
  exists?: boolean
  client?: ClientRow | null
}

type ClientDocument = Record<string, unknown> & {
  id_berkas?: string | number
  nama_dokumen?: string
  nama_folder?: string
  nama_berkas?: string
}

type BantekRow = Record<string, unknown> & {
  no_bantek?: string
  lokasi_bantek?: string
  clients_list?: Array<string | number>
  clients_detail?: ClientRow[]
}

const props = defineProps<{
  clientType: ClientType
}>()

const business = useLegacyBusiness()

const activeTab = ref<'client' | 'bantek'>('client')

const loadingClient = ref(false)
const loadingBantek = ref(false)
const clientSearch = ref('')
const bantekSearch = ref('')
const clientSearchField = ref<ClientSearchField>('all')
const bantekSearchField = ref<BantekSearchField>('all')
const message = ref('')
const errorMessage = ref('')

const bantekSearchFieldOptions: Array<{ value: BantekSearchField; label: string }> = [
  { value: 'all', label: 'Semua' },
  { value: 'no_bantek', label: 'No Bantek' },
  { value: 'lokasi', label: 'Lokasi' },
  { value: 'client', label: 'Client' },
]

const clients = ref<ClientRow[]>([])
const clientOptionRows = ref<ClientRow[]>([])
const banteks = ref<BantekRow[]>([])

const clientDialogOpen = ref(false)
const savingClient = ref(false)
const editingClientIndex = ref(-1)
const clientDialogError = ref('')
const clientIdentityInputRef = ref<HTMLInputElement | null>(null)
const checkingClientIdentity = ref(false)
const clientIdentityMatch = ref<ClientRow | null>(null)
const clientForm = reactive<ClientRow>({
  no_identitas: '',
  nama_client: '',
  jenis_client: props.clientType,
  alamat_client: '',
  contact_number: '',
  email: '',
})

const documentViewerOpen = ref(false)
const loadingDocumentViewer = ref(false)
const documentViewerError = ref('')
const documentViewerSearch = ref('')
const documentViewerClient = ref<ClientRow | null>(null)
const documentViewerRows = ref<ClientDocument[]>([])

const uploadDialogOpen = ref(false)
const uploadingDocument = ref(false)
const uploadDialogError = ref('')
const uploadDialogClient = ref<ClientRow | null>(null)
const uploadDialogRows = ref<ClientDocument[]>([])
const uploadFiles = ref<File[]>([])
const editingDocumentIndex = ref(-1)
const editingDocumentName = ref('')
const documentActionLoading = ref(false)
const uploadInputRef = ref<HTMLInputElement | null>(null)
const previewDialog = reactive({
  open: false,
  url: '',
  title: '',
})

const bantekCreateOpen = ref(false)
const creatingBantek = ref(false)
const bantekCreateForm = reactive({
  lokasi_bantek: '',
  id_client: '' as string | number | '',
})

const bantekLinkOpen = ref(false)
const linkingBantek = ref(false)
const bantekLinkForm = reactive({
  no_bantek: '',
  lokasi_bantek: '',
  id_clients: [] as Array<string | number>,
})
const bantekLinkClientSearch = ref('')

const clientCurrentPage = ref(1)
const clientPerPage = ref(10)
const clientPerPageOptions = [10, 25, 50, 100]
const clientSortKey = ref<ClientSortKey>('id_client')
const clientSortDirection = ref<SortDirection>('desc')
const clientTotal = ref(0)
const clientFilteredTotal = ref(0)
const clientLastPage = ref(1)
let clientSearchTimer: ReturnType<typeof setTimeout> | undefined
let clientDialogToastTimer: ReturnType<typeof setTimeout> | undefined
let clientRequestSequence = 0
let suspendClientReload = false

const expandedBantekRows = reactive<Record<string, boolean>>({})
const bantekActionLoading = ref(false)

const toString = (value: unknown, fallback = '-') => {
  const normalized = String(value ?? '').trim()
  return normalized || fallback
}

const isBusinessClient = () => props.clientType === 'Badan Hukum'

const sanitizeBusinessText = (value: unknown) =>
  String(value ?? '')
    .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
    .replace(/\s+/g, ' ')
    .trimStart()
    .toUpperCase()

const sanitizeBusinessIdentity = (value: unknown) =>
  String(value ?? '')
    .replace(/[^\p{L}\p{N}]+/gu, '')
    .trim()
    .toUpperCase()

const normalizeClientIdentityInput = () => {
  if (isBusinessClient()) {
    clientForm.no_identitas = sanitizeBusinessIdentity(clientForm.no_identitas)
  }
  clearIdentityMatchOnInput()
}

const normalizeClientNameInput = () => {
  if (isBusinessClient()) {
    clientForm.nama_client = sanitizeBusinessText(clientForm.nama_client)
  }
}

const toIframePreviewUrl = (url: string) => {
  const raw = String(url || '').trim()
  if (!raw) return ''
  const withoutHash = raw.split('#')[0] || raw
  const isPdf = /\.pdf(\?|$)/i.test(withoutHash)
  if (!isPdf) return raw
  return `${withoutHash}#toolbar=0&navpanes=0&scrollbar=1&view=FitH`
}

const toList = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) {
    return payload as T[]
  }
  if (payload && typeof payload === 'object' && Array.isArray((payload as ApiEnvelope<T[]>).data)) {
    return (payload as ApiEnvelope<T[]>).data || []
  }
  return []
}

const setFormFromClient = (client?: ClientRow) => {
  const source = client || {}
  clientForm.id_client = source.id_client || ''
  clientForm.no_identitas = toString(source.no_identitas, '')
  clientForm.nama_client = toString(source.nama_client, '')
  clientForm.jenis_client = props.clientType
  clientForm.alamat_client = toString(source.alamat_client, '')
  clientForm.contact_number = toString(source.contact_number, '')
  clientForm.email = toString(source.email, '')
}

const resetClientForm = () => {
  editingClientIndex.value = -1
  setFormFromClient()
}

const filteredClients = computed(() => clients.value)
const paginatedClients = computed(() => clients.value)
const clientTotalPages = computed(() => Math.max(1, clientLastPage.value))

const clientStartItem = computed(() => {
  if (!clientFilteredTotal.value) return 0
  return (clientCurrentPage.value - 1) * clientPerPage.value + 1
})

const clientEndItem = computed(() =>
  Math.min(clientStartItem.value + clients.value.length - 1, clientFilteredTotal.value),
)

const clientVisiblePages = computed(() => {
  const total = clientTotalPages.value
  const current = clientCurrentPage.value
  const first = Math.max(1, Math.min(current - 2, total - 4))
  const last = Math.min(total, first + 4)
  return Array.from({ length: last - first + 1 }, (_, index) => first + index)
})

const filteredBanteks = computed(() => {
  const keyword = bantekSearch.value.trim().toLowerCase()
  if (!keyword) {
    return banteks.value
  }

  return banteks.value.filter((item) => {
    const clientsDetail = Array.isArray(item.clients_detail) ? item.clients_detail : []
    const clientMatch = clientsDetail.some(client =>
      [client.nama_client, client.no_identitas].some(field => toString(field, '').toLowerCase().includes(keyword)),
    )

    if (bantekSearchField.value === 'client') {
      return clientMatch
    }
    if (bantekSearchField.value === 'no_bantek') {
      return toString(item.no_bantek, '').toLowerCase().includes(keyword)
    }
    if (bantekSearchField.value === 'lokasi') {
      return toString(item.lokasi_bantek, '').toLowerCase().includes(keyword)
    }

    return [
      toString(item.no_bantek, ''),
      toString(item.lokasi_bantek, ''),
    ].some(field => field.toLowerCase().includes(keyword)) || clientMatch
  })
})

const filteredViewerDocuments = computed(() => {
  const keyword = documentViewerSearch.value.trim().toLowerCase()
  if (!keyword) {
    return documentViewerRows.value
  }
  return documentViewerRows.value.filter((item) =>
    toString(item.nama_dokumen, '').toLowerCase().includes(keyword),
  )
})

const workspaceTitle = computed(() =>
  props.clientType === 'Perorangan'
    ? 'Daftar Client Perorangan'
    : 'Daftar Client Badan Hukum',
)

const identityLabel = computed(() =>
  props.clientType === 'Perorangan' ? 'NIK' : 'NPWP',
)

const identityPlaceholder = computed(() =>
  props.clientType === 'Perorangan'
    ? 'Masukkan 16 digit NIK'
    : 'Masukkan NPWP tanpa titik, strip, atau simbol',
)

const identityMaxLength = computed(() =>
  props.clientType === 'Perorangan' ? 16 : 30,
)

const clientNamePlaceholder = computed(() =>
  props.clientType === 'Perorangan'
    ? 'Masukkan nama lengkap client'
    : 'Masukkan nama badan hukum',
)

const clientSearchPlaceholder = computed(() =>
  `Cari nama client, ${identityLabel.value}, pembuat, email, atau kontak...`,
)

const clientSearchFieldOptions = computed<Array<{ value: ClientSearchField; label: string }>>(() => [
  { value: 'all', label: 'Semua' },
  { value: 'name', label: 'Nama' },
  { value: 'identity', label: identityLabel.value },
  { value: 'creator', label: 'Pembuat' },
  { value: 'contact', label: 'Kontak' },
])

const clientFormTitle = computed(() =>
  editingClientIndex.value === -1
    ? `Tambahkan Client ${props.clientType}`
    : `Edit Client ${props.clientType}`,
)

const clientIdentityBlocked = computed(() => Boolean(clientIdentityMatch.value))

const clearStatus = () => {
  message.value = ''
  errorMessage.value = ''
}

const clearClientDialogError = () => {
  clientDialogError.value = ''
}

const showClientDialogError = (value: string) => {
  clientDialogError.value = value
}

const applyIdentityMatchPreview = (client: ClientRow) => {
  clientForm.nama_client = toString(client.nama_client, '')
  clientForm.alamat_client = toString(client.alamat_client, '')
  clientForm.contact_number = toString(client.contact_number, '')
  clientForm.email = toString(client.email, '')
}

const bantekRowKey = (item: BantekRow, index: number) => `bantek-${toString(item.no_bantek, String(index))}`

const isBantekExpanded = (item: BantekRow, index: number) => Boolean(expandedBantekRows[bantekRowKey(item, index)])

const toggleBantekExpand = (item: BantekRow, index: number) => {
  const key = bantekRowKey(item, index)
  expandedBantekRows[key] = !expandedBantekRows[key]
}

const closeClientDialog = () => {
  clientDialogOpen.value = false
  clearClientDialogError()
  clientIdentityMatch.value = null
  resetClientForm()
}

const loadClients = async () => {
  const requestSequence = ++clientRequestSequence
  loadingClient.value = true
  clearStatus()
  try {
    const response = await business.client.getDataClient({
      jenis_client: props.clientType,
      server_side: true,
      page: clientCurrentPage.value,
      per_page: clientPerPage.value,
      search: clientSearch.value.trim(),
      search_field: clientSearchField.value,
      sort_by: clientSortKey.value,
      sort_direction: clientSortDirection.value,
    }) as ApiEnvelope<ClientPageData>
    if (requestSequence !== clientRequestSequence) return

    const pageData = response.data || {}
    const pagination = pageData.pagination || {}
    clients.value = Array.isArray(pageData.rows) ? pageData.rows : []
    clientCurrentPage.value = Number(pagination.page || 1)
    clientTotal.value = Number(pagination.total || 0)
    clientFilteredTotal.value = Number(pagination.filtered || 0)
    clientLastPage.value = Number(pagination.last_page || 1)
  } catch (error) {
    if (requestSequence !== clientRequestSequence) return

    clients.value = []
    clientTotal.value = 0
    clientFilteredTotal.value = 0
    clientLastPage.value = 1
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat data client.'
  } finally {
    if (requestSequence === clientRequestSequence) {
      loadingClient.value = false
    }
  }
}

const loadClientOptions = async () => {
  if (clientOptionRows.value.length) return

  try {
    const response = await business.client.getDataClient({
      jenis_client: props.clientType,
    }) as ApiEnvelope<ClientRow[]>
    clientOptionRows.value = toList<ClientRow>(response)
  } catch {
    clientOptionRows.value = []
  }
}

const loadBanteks = async () => {
  loadingBantek.value = true
  clearStatus()
  try {
    const response = await business.bantek.list() as ApiEnvelope<BantekRow[]>
    banteks.value = toList<BantekRow>(response)
  } catch (error) {
    banteks.value = []
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat data bantek.'
  } finally {
    loadingBantek.value = false
  }
}

const openCreateClientDialog = () => {
  clearStatus()
  clearClientDialogError()
  clientIdentityMatch.value = null
  resetClientForm()
  clientDialogOpen.value = true
  nextTick(() => clientIdentityInputRef.value?.focus())
}

const openEditClientDialog = (client: ClientRow, index: number) => {
  clearStatus()
  clearClientDialogError()
  clientIdentityMatch.value = null
  editingClientIndex.value = index
  setFormFromClient(client)
  clientDialogOpen.value = true
  nextTick(() => clientIdentityInputRef.value?.focus())
}

const clearIdentityMatchOnInput = () => {
  if (!clientIdentityMatch.value) return
  clientIdentityMatch.value = null
}

const checkClientIdentity = async () => {
  if (isBusinessClient()) {
    clientForm.no_identitas = sanitizeBusinessIdentity(clientForm.no_identitas)
  }
  const identity = toString(clientForm.no_identitas, '').trim()
  clientForm.no_identitas = identity
  clientIdentityMatch.value = null

  if (!identity || checkingClientIdentity.value) return

  checkingClientIdentity.value = true
  clearClientDialogError()

  try {
    const response = await business.client.checkClientIdentity({
      no_identitas: identity,
      jenis_client: props.clientType,
      id_client: clientForm.id_client || undefined,
    }) as ApiEnvelope<ClientIdentityCheck>

    const matchedClient = response.data?.exists ? response.data?.client : null
    if (matchedClient) {
      clientIdentityMatch.value = matchedClient
      applyIdentityMatchPreview(matchedClient)
      showClientDialogError(`${identityLabel.value} sudah terdaftar atas nama ${toString(matchedClient.nama_client, 'client ini')}. Tidak perlu input ulang.`)
    }
  } catch (error) {
    showClientDialogError((error as { data?: { message?: string } })?.data?.message || `Gagal mengecek ${identityLabel.value}.`)
  } finally {
    checkingClientIdentity.value = false
  }
}

const saveClient = async () => {
  if (savingClient.value || clientIdentityBlocked.value) {
    if (clientIdentityBlocked.value) {
      showClientDialogError(`${identityLabel.value} sudah terdaftar. Data tidak perlu disimpan ulang.`)
    }
    return
  }
  savingClient.value = true
  clearStatus()

  try {
    if (isBusinessClient()) {
      clientForm.no_identitas = sanitizeBusinessIdentity(clientForm.no_identitas)
      clientForm.nama_client = sanitizeBusinessText(clientForm.nama_client).trim()
    }
    const response = await business.client.SimpanClientBaru({
      id_client: clientForm.id_client || undefined,
      no_identitas: clientForm.no_identitas,
      nama_client: clientForm.nama_client,
      jenis_client: props.clientType,
      alamat_client: clientForm.alamat_client,
      contact_number: clientForm.contact_number,
      email: clientForm.email,
    }) as ApiEnvelope

    message.value = response.message || 'Data client berhasil disimpan.'
    closeClientDialog()
    clientOptionRows.value = []
    await loadClients()
  } catch (error) {
    showClientDialogError((error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan data client.')
  } finally {
    savingClient.value = false
  }
}

const clientDocumentUrl = (row: ClientDocument) => {
  const folder = toString(row.nama_folder, '')
  const fileName = toString(row.nama_berkas, '')
  if (!folder || !fileName) return ''
  return business.assets.berkasClient(folder, fileName)
}

const openPreviewDialog = (url: string, title: string) => {
  const normalized = toIframePreviewUrl(url)
  if (!normalized) return
  previewDialog.url = normalized
  previewDialog.title = toString(title, 'Preview Dokumen')
  previewDialog.open = true
}

const closePreviewDialog = () => {
  previewDialog.open = false
  previewDialog.url = ''
  previewDialog.title = ''
}

const loadViewerDocuments = async (clientId: string | number | '') => {
  loadingDocumentViewer.value = true
  documentViewerError.value = ''
  documentViewerRows.value = []

  if (!clientId) {
    loadingDocumentViewer.value = false
    documentViewerError.value = 'ID client tidak tersedia.'
    return
  }

  try {
    const response = await business.client.getDataDokumenClient({ id_client: clientId }) as ApiEnvelope<ClientDocument[]>
    documentViewerRows.value = toList<ClientDocument>(response)
  } catch (error) {
    documentViewerError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat dokumen client.'
  } finally {
    loadingDocumentViewer.value = false
  }
}

const openDocumentViewer = async (client: ClientRow) => {
  documentViewerClient.value = { ...client }
  documentViewerSearch.value = ''
  documentViewerOpen.value = true
  await loadViewerDocuments((client.id_client as string | number | '') || '')
}

const closeDocumentViewer = () => {
  documentViewerOpen.value = false
  documentViewerRows.value = []
  documentViewerClient.value = null
  documentViewerSearch.value = ''
  documentViewerError.value = ''
}

const loadUploadDocuments = async () => {
  uploadDialogRows.value = []
  uploadDialogError.value = ''
  const idClient = uploadDialogClient.value?.id_client as string | number | undefined
  if (!idClient) {
    uploadDialogError.value = 'ID client tidak tersedia.'
    return
  }

  try {
    const response = await business.client.getDataDokumenClient({ id_client: idClient }) as ApiEnvelope<ClientDocument[]>
    uploadDialogRows.value = toList<ClientDocument>(response)
  } catch (error) {
    uploadDialogError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat dokumen upload.'
  }
}

const openUploadDialog = async (client: ClientRow) => {
  uploadDialogClient.value = { ...client }
  uploadDialogOpen.value = true
  uploadDialogError.value = ''
  uploadFiles.value = []
  editingDocumentIndex.value = -1
  editingDocumentName.value = ''
  await loadUploadDocuments()
}

const closeUploadDialog = () => {
  uploadDialogOpen.value = false
  uploadDialogError.value = ''
  uploadDialogClient.value = null
  uploadDialogRows.value = []
  uploadFiles.value = []
  editingDocumentIndex.value = -1
  editingDocumentName.value = ''
  if (uploadInputRef.value) {
    uploadInputRef.value.value = ''
  }
}

const onUploadFileChange = (event: Event) => {
  const target = event.target as HTMLInputElement
  uploadFiles.value = target.files ? Array.from(target.files) : []
}

const submitUpload = async () => {
  const idClient = uploadDialogClient.value?.id_client as string | number | undefined
  if (!idClient || !uploadFiles.value.length || uploadingDocument.value) {
    return
  }

  uploadingDocument.value = true
  uploadDialogError.value = ''

  try {
    const formData = new FormData()
    uploadFiles.value.forEach((file) => {
      formData.append('dokumens[]', file, file.name)
    })
    formData.append('id_client', String(idClient))

    const response = await business.client.UploadDokumenClient(formData) as ApiEnvelope
    message.value = response.message || 'Dokumen berhasil diupload.'
    uploadFiles.value = []
    if (uploadInputRef.value) {
      uploadInputRef.value.value = ''
    }
    await loadUploadDocuments()
    await loadViewerDocuments(idClient)
  } catch (error) {
    uploadDialogError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal upload dokumen.'
  } finally {
    uploadingDocument.value = false
  }
}

const startEditUploadDocument = (index: number) => {
  editingDocumentIndex.value = index
  editingDocumentName.value = toString(uploadDialogRows.value[index]?.nama_dokumen, '')
}

const cancelEditUploadDocument = () => {
  editingDocumentIndex.value = -1
  editingDocumentName.value = ''
}

const saveUploadDocumentName = async (index: number) => {
  const row = uploadDialogRows.value[index]
  if (!row || documentActionLoading.value) {
    return
  }

  documentActionLoading.value = true
  uploadDialogError.value = ''

  try {
    const payload = {
      ...row,
      nama_dokumen: editingDocumentName.value,
    }
    const response = await business.client.UpdateDokumenClient(payload) as ApiEnvelope
    message.value = response.message || 'Nama dokumen berhasil diupdate.'
    editingDocumentIndex.value = -1
    editingDocumentName.value = ''
    await loadUploadDocuments()
  } catch (error) {
    uploadDialogError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal update dokumen.'
  } finally {
    documentActionLoading.value = false
  }
}

const deleteUploadDocument = async (index: number) => {
  const row = uploadDialogRows.value[index]
  if (!row || documentActionLoading.value) {
    return
  }

  documentActionLoading.value = true
  uploadDialogError.value = ''
  try {
    const response = await business.client.DeleteDokumenClient(row) as ApiEnvelope
    message.value = response.message || 'Dokumen berhasil dihapus.'
    await loadUploadDocuments()
  } catch (error) {
    uploadDialogError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus dokumen.'
  } finally {
    documentActionLoading.value = false
  }
}

const openUploadDocumentFile = (row: ClientDocument) => {
  const url = clientDocumentUrl(row)
  if (!url) return
  openPreviewDialog(url, toString(row.nama_dokumen, toString(row.nama_berkas, 'Preview Dokumen')))
}

const openCreateBantek = () => {
  bantekCreateOpen.value = true
  bantekCreateForm.lokasi_bantek = ''
  bantekCreateForm.id_client = ''
}

const closeCreateBantek = () => {
  bantekCreateOpen.value = false
  bantekCreateForm.lokasi_bantek = ''
  bantekCreateForm.id_client = ''
}

const saveCreateBantek = async () => {
  if (creatingBantek.value) return
  creatingBantek.value = true
  clearStatus()
  try {
    const response = await business.bantek.create({
      lokasi_bantek: bantekCreateForm.lokasi_bantek,
      id_client: bantekCreateForm.id_client || undefined,
    }) as ApiEnvelope
    message.value = response.message || 'Bantek berhasil dibuat.'
    closeCreateBantek()
    await loadBanteks()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal membuat bantek.'
  } finally {
    creatingBantek.value = false
  }
}

const openLinkBantek = (row: BantekRow) => {
  bantekLinkForm.no_bantek = toString(row.no_bantek, '')
  bantekLinkForm.lokasi_bantek = toString(row.lokasi_bantek, '')
  bantekLinkForm.id_clients = Array.isArray(row.clients_list)
    ? Array.from(new Set(row.clients_list.map(value => String(value))))
    : []
  bantekLinkClientSearch.value = ''
  bantekLinkOpen.value = true
}

const closeLinkBantek = () => {
  bantekLinkOpen.value = false
  bantekLinkForm.no_bantek = ''
  bantekLinkForm.lokasi_bantek = ''
  bantekLinkForm.id_clients = []
  bantekLinkClientSearch.value = ''
}

const saveLinkBantek = async () => {
  if (linkingBantek.value || !bantekLinkForm.id_clients.length) {
    return
  }

  linkingBantek.value = true
  clearStatus()
  try {
    const response = await business.bantek.addClients({
      no_bantek: bantekLinkForm.no_bantek,
      lokasi_bantek: bantekLinkForm.lokasi_bantek,
      id_clients: bantekLinkForm.id_clients,
    }) as ApiEnvelope

    message.value = response.message || 'Client berhasil dipautkan ke bantek.'
    closeLinkBantek()
    await loadBanteks()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan data bantek.'
  } finally {
    linkingBantek.value = false
  }
}

const removeClientFromBantek = async (bantek: BantekRow, client: ClientRow) => {
  if (bantekActionLoading.value) {
    return
  }

  if (import.meta.client && !window.confirm(`Hapus ${toString(client.nama_client)} dari ${toString(bantek.no_bantek)}?`)) {
    return
  }

  bantekActionLoading.value = true
  clearStatus()

  try {
    const response = await business.bantek.removeClient({
      no_bantek: bantek.no_bantek,
      id_client: client.id_client,
    }) as ApiEnvelope

    message.value = response.message || 'Client berhasil dihapus dari bantek.'
    await loadBanteks()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus client dari bantek.'
  } finally {
    bantekActionLoading.value = false
  }
}

const printBantekLabel = (noBantek: unknown) => {
  const value = toString(noBantek, '')
  if (!value) {
    return
  }
  const url = business.bantek.CetakLabelBantek(value)
  openPreviewDialog(url, `Label Bantek ${value}`)
}

const clientOptions = computed(() =>
  clientOptionRows.value.flatMap((client) => {
    const id = client.id_client as string | number | undefined
    if (id === undefined || id === null || id === '') {
      return []
    }
    return [{
      id,
      name: toString(client.nama_client),
      no_identitas: toString(client.no_identitas),
    }]
  }),
)

const selectedLinkClientIdSet = computed(() =>
  new Set(bantekLinkForm.id_clients.map(value => String(value))),
)

const filteredLinkClientOptions = computed(() => {
  const keyword = bantekLinkClientSearch.value.trim().toLowerCase()
  const candidates = clientOptions.value.filter(option => !selectedLinkClientIdSet.value.has(String(option.id)))
  const filtered = keyword
    ? candidates.filter(option =>
      `${option.name} ${option.no_identitas}`.toLowerCase().includes(keyword),
    )
    : candidates
  return filtered.slice(0, 12)
})

const selectedLinkClients = computed(() => {
  const map = new Map(clientOptions.value.map(option => [String(option.id), option]))
  return bantekLinkForm.id_clients
    .map(id => map.get(String(id)))
    .filter((option): option is { id: string | number; name: string; no_identitas: string } => Boolean(option))
})

const addClientToBantekLink = (id: string | number) => {
  if (selectedLinkClientIdSet.value.has(String(id))) {
    return
  }
  bantekLinkForm.id_clients.push(id)
  bantekLinkClientSearch.value = ''
}

const removeClientFromBantekLinkSelection = (id: string | number) => {
  bantekLinkForm.id_clients = bantekLinkForm.id_clients.filter(value => String(value) !== String(id))
}

const clearBantekLinkSelection = () => {
  bantekLinkForm.id_clients = []
}

const clearClientSearch = () => {
  clientSearch.value = ''
}

const resetClientTable = () => {
  suspendClientReload = true
  clientSearch.value = ''
  clientSearchField.value = 'all'
  clientSortKey.value = 'nama_client'
  clientSortDirection.value = 'asc'
  clientCurrentPage.value = 1
  void nextTick(() => {
    suspendClientReload = false
    void loadClients()
  })
}

const sortClientsBy = (key: ClientSortKey) => {
  if (clientSortKey.value === key) {
    clientSortDirection.value = clientSortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    clientSortKey.value = key
    clientSortDirection.value = 'asc'
  }
  clientCurrentPage.value = 1
  void loadClients()
}

const clientSortIcon = (key: ClientSortKey) => {
  if (clientSortKey.value !== key) return '-'
  return clientSortDirection.value === 'asc' ? '^' : 'v'
}

const clearBantekSearch = () => {
  bantekSearch.value = ''
}

const clientSearchFieldButtonClass = (value: ClientSearchField) => {
  const active = clientSearchField.value === value
  return active
    ? 'rounded-lg border border-blue-300 bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white'
    : 'rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50'
}

const bantekSearchFieldButtonClass = (value: BantekSearchField) => {
  const active = bantekSearchField.value === value
  return active
    ? 'rounded-lg border border-indigo-300 bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white'
    : 'rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50'
}

const goToPrevClientPage = () => {
  if (clientCurrentPage.value > 1) {
    clientCurrentPage.value -= 1
    void loadClients()
  }
}

const goToNextClientPage = () => {
  if (clientCurrentPage.value < clientTotalPages.value) {
    clientCurrentPage.value += 1
    void loadClients()
  }
}

const goToClientPage = (page: number) => {
  if (page === clientCurrentPage.value || page < 1 || page > clientTotalPages.value) return
  clientCurrentPage.value = page
  void loadClients()
}

const initialize = async () => {
  await Promise.all([loadClients(), loadBanteks()])
}

watch(
  () => props.clientType,
  () => {
    setFormFromClient()
    clientCurrentPage.value = 1
    clientOptionRows.value = []
    void initialize()
  },
)

watch(clientSearch, () => {
  if (suspendClientReload) return
  clientCurrentPage.value = 1
  if (clientSearchTimer) clearTimeout(clientSearchTimer)
  clientSearchTimer = setTimeout(() => {
    void loadClients()
  }, 350)
})

watch(clientSearchField, () => {
  if (suspendClientReload) return
  clientCurrentPage.value = 1
  void loadClients()
})

watch(clientPerPage, () => {
  if (suspendClientReload) return
  clientCurrentPage.value = 1
  void loadClients()
})

watch(activeTab, (tab) => {
  if (tab === 'bantek') {
    void loadClientOptions()
  }
})

watch(clientDialogError, (value) => {
  if (clientDialogToastTimer) {
    clearTimeout(clientDialogToastTimer)
    clientDialogToastTimer = undefined
  }

  if (!value) return

  clientDialogToastTimer = setTimeout(() => {
    clientDialogError.value = ''
    clientDialogToastTimer = undefined
  }, 3500)
})

onMounted(() => {
  setFormFromClient()
  void initialize()
})

onBeforeUnmount(() => {
  if (clientSearchTimer) clearTimeout(clientSearchTimer)
  if (clientDialogToastTimer) clearTimeout(clientDialogToastTimer)
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="display-kicker">Data Client</p>
          <h2 class="mt-2 text-2xl font-semibold text-slate-900">{{ workspaceTitle }}</h2>
        </div>
        <div class="inline-flex rounded-xl border border-slate-200 bg-slate-100 p-1">
          <button
            type="button"
            class="rounded-lg px-3 py-2 text-sm font-semibold transition"
            :class="activeTab === 'client' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-800'"
            @click="activeTab = 'client'"
          >
            Daftar Client
          </button>
          <button
            type="button"
            class="rounded-lg px-3 py-2 text-sm font-semibold transition"
            :class="activeTab === 'bantek' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-800'"
            @click="activeTab = 'bantek'"
          >
            Data Bantek
          </button>
        </div>
      </div>
      <p v-if="message" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ message }}
      </p>
      <p v-if="errorMessage" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errorMessage }}
      </p>
    </SurfaceCard>

    <SurfaceCard v-if="activeTab === 'client'" class="overflow-hidden p-0">
      <div class="space-y-4 border-b border-slate-200 bg-slate-50 px-6 py-5">
        <div class="space-y-1">
          <p class="text-sm font-semibold text-slate-800">{{ workspaceTitle }}</p>
          <p class="text-xs text-slate-500">Cari, filter, urutkan, dan atur jumlah data langsung dari tabel.</p>
        </div>

        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
          <label class="block">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Pencarian Cepat</span>
            <div class="group relative mt-1">
              <input
                v-model="clientSearch"
                type="text"
                :placeholder="clientSearchPlaceholder"
                class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-9 text-sm text-slate-700 transition focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
              <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.473 9.765l3.63 3.63a.75.75 0 1 0 1.06-1.06l-3.63-3.63A5.5 5.5 0 0 0 9 3.5Zm-4 5.5a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
              </svg>
              <button
                v-if="clientSearch"
                type="button"
                class="absolute right-2 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                @click="clearClientSearch"
              >
                x
              </button>
            </div>
          </label>

          <button
            type="button"
            class="inline-flex h-11 items-center justify-center rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800"
            @click="openCreateClientDialog"
          >
            Tambah Client
          </button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="option in clientSearchFieldOptions"
              :key="`client-search-${option.value}`"
              type="button"
              :class="clientSearchFieldButtonClass(option.value)"
              @click="clientSearchField = option.value"
            >
              {{ option.label }}
            </button>
            <button
              v-if="clientSearch || clientSearchField !== 'all' || clientSortKey !== 'nama_client' || clientSortDirection !== 'asc'"
              type="button"
              class="rounded-lg border border-slate-300 bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200"
              @click="resetClientTable"
            >
              Reset
            </button>
          </div>
          <p class="text-xs text-slate-500">
            {{ clientFilteredTotal }} dari {{ clientTotal }} data client.
          </p>
        </div>
      </div>

      <div class="p-6">
        <p v-if="loadingClient" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Memuat data client...
        </p>
        <div v-else-if="!filteredClients.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          {{ clientTotal ? 'Tidak ada data yang cocok dengan pencarian atau filter.' : 'Data client belum tersedia.' }}
        </div>

        <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 bg-white">
              <thead class="bg-slate-100">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                    <button type="button" class="inline-flex items-center gap-1.5 hover:text-blue-700" @click="sortClientsBy('nama_client')">
                      Nama Client <span aria-hidden="true">{{ clientSortIcon('nama_client') }}</span>
                    </button>
                  </th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                    <button type="button" class="inline-flex items-center gap-1.5 hover:text-blue-700" @click="sortClientsBy('no_identitas')">
                      {{ identityLabel }} <span aria-hidden="true">{{ clientSortIcon('no_identitas') }}</span>
                    </button>
                  </th>
                  <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                    <button type="button" class="inline-flex items-center gap-1.5 hover:text-blue-700" @click="sortClientsBy('pembuat_client')">
                      Pembuat <span aria-hidden="true">{{ clientSortIcon('pembuat_client') }}</span>
                    </button>
                  </th>
                  <th class="w-[280px] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(client, index) in paginatedClients" :key="toString(client.id_client, String(index))" class="align-top transition hover:bg-sky-50/40">
                  <td class="px-4 py-3 text-sm text-slate-600">{{ clientStartItem + index }}</td>
                  <td class="px-4 py-3 text-sm font-medium text-slate-700">{{ toString(client.nama_client) }}</td>
                  <td class="px-4 py-3 text-sm text-slate-700">{{ toString(client.no_identitas) }}</td>
                  <td class="px-4 py-3 text-sm text-slate-700">{{ toString(client.pembuat_client) }}</td>
                  <td class="px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        class="inline-flex h-8 items-center whitespace-nowrap rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-200"
                        @click="openEditClientDialog(client, index)"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        class="inline-flex h-8 items-center whitespace-nowrap rounded-lg border border-violet-200 bg-violet-50 px-3 text-xs font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-200"
                        @click="openDocumentViewer(client)"
                      >
                        Dokumen
                      </button>
                      <button
                        type="button"
                        class="inline-flex h-8 items-center whitespace-nowrap rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-xs font-semibold text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-200"
                        @click="openUploadDialog(client)"
                      >
                        Upload
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
            <p class="text-xs text-slate-600">
              Menampilkan {{ clientStartItem }} - {{ clientEndItem }} dari {{ clientFilteredTotal }} data
            </p>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <label class="text-xs font-semibold uppercase tracking-wider text-slate-500">Per halaman</label>
              <select
                v-model.number="clientPerPage"
                class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              >
                <option v-for="size in clientPerPageOptions" :key="`size-${size}`" :value="size">
                  {{ size }}
                </option>
              </select>
              <button
                type="button"
                class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="clientCurrentPage <= 1"
                @click="goToPrevClientPage"
              >
                Sebelumnya
              </button>
              <button
                v-for="page in clientVisiblePages"
                :key="`client-page-${page}`"
                type="button"
                class="h-8 min-w-8 rounded-lg border px-2 text-xs font-semibold transition"
                :class="page === clientCurrentPage
                  ? 'border-blue-600 bg-blue-600 text-white'
                  : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100'"
                :aria-current="page === clientCurrentPage ? 'page' : undefined"
                @click="goToClientPage(page)"
              >
                {{ page }}
              </button>
              <button
                type="button"
                class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="clientCurrentPage >= clientTotalPages"
                @click="goToNextClientPage"
              >
                Berikutnya
              </button>
            </div>
          </div>
        </div>
      </div>
    </SurfaceCard>

    <SurfaceCard v-else class="overflow-hidden p-0">
      <div class="space-y-4 border-b border-slate-200 bg-slate-50 px-6 py-5">
        <div class="space-y-1">
          <p class="text-sm font-semibold text-slate-800">Data Bantek &amp; Label</p>
          <p class="text-xs text-slate-500">Gunakan pencarian untuk mempercepat pengelolaan bantek dan client terkait.</p>
        </div>

        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
          <label class="block">
            <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Pencarian Cepat</span>
            <div class="group relative mt-1">
              <input
                v-model="bantekSearch"
                type="text"
                placeholder="Cari no bantek, lokasi, atau nama client..."
                class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-9 text-sm text-slate-700 transition focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
              />
              <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 group-focus-within:text-indigo-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.473 9.765l3.63 3.63a.75.75 0 1 0 1.06-1.06l-3.63-3.63A5.5 5.5 0 0 0 9 3.5Zm-4 5.5a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
              </svg>
              <button
                v-if="bantekSearch"
                type="button"
                class="absolute right-2 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                @click="clearBantekSearch"
              >
                x
              </button>
            </div>
          </label>

          <button
            type="button"
            class="inline-flex h-11 items-center justify-center rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
            @click="openCreateBantek"
          >
            Buat Bantek
          </button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="option in bantekSearchFieldOptions"
              :key="`bantek-search-${option.value}`"
              type="button"
              :class="bantekSearchFieldButtonClass(option.value)"
              @click="bantekSearchField = option.value"
            >
              {{ option.label }}
            </button>
          </div>
          <p class="text-xs text-slate-500">
            Menampilkan {{ filteredBanteks.length }} data bantek.
          </p>
        </div>
      </div>

      <div class="p-6">
        <p v-if="loadingBantek" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Memuat data bantek...
        </p>
        <div v-else-if="!filteredBanteks.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Data bantek belum tersedia.
        </div>
        <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 bg-white">
              <thead class="bg-slate-100">
                <tr>
                <th class="w-14 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                <th class="w-20 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Detail</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No Bantek</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Lokasi</th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Daftar Client / PT</th>
                <th class="w-[210px] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <template v-for="(bantek, index) in filteredBanteks" :key="bantekRowKey(bantek, index)">
                <tr class="align-top transition hover:bg-sky-50/40">
                  <td class="px-4 py-3 text-sm text-slate-600">{{ index + 1 }}</td>
                  <td class="px-4 py-3">
                    <button
                      type="button"
                      class="inline-flex h-8 items-center rounded-lg border border-slate-300 bg-white px-2.5 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-200"
                      @click="toggleBantekExpand(bantek, index)"
                    >
                      {{ isBantekExpanded(bantek, index) ? 'Tutup' : 'Expand' }}
                    </button>
                  </td>
                  <td class="px-4 py-3 text-sm font-medium text-slate-700">{{ toString(bantek.no_bantek) }}</td>
                  <td class="px-4 py-3 text-sm text-slate-700">{{ toString(bantek.lokasi_bantek) }}</td>
                  <td class="px-4 py-3 text-sm text-slate-700">
                    <div v-if="Array.isArray(bantek.clients_detail) && bantek.clients_detail.length" class="space-y-1.5">
                      <p v-for="(client, cIndex) in bantek.clients_detail" :key="`${bantekRowKey(bantek, index)}-${cIndex}`" class="text-xs leading-5">
                        - {{ toString(client.nama_client) }}
                      </p>
                    </div>
                    <span v-else class="text-xs text-slate-500">Belum ada client</span>
                  </td>
                  <td class="px-4 py-3">
                    <button
                      type="button"
                      class="inline-flex h-8 items-center whitespace-nowrap rounded-lg border border-amber-200 bg-amber-50 px-3 text-xs font-semibold text-amber-700 transition hover:border-amber-300 hover:bg-amber-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-200"
                      @click="openLinkBantek(bantek)"
                    >
                      Masukan Client
                    </button>
                  </td>
                </tr>

                <tr v-if="isBantekExpanded(bantek, index)" class="bg-slate-50/70">
                  <td colspan="100%" class="px-4 py-4">
                    <div class="grid gap-4 lg:grid-cols-[1fr_auto]">
                      <div class="space-y-3 rounded-xl border border-slate-200 bg-white p-4">
                        <p class="text-sm font-semibold text-slate-800">Detail Bantek {{ toString(bantek.no_bantek) }}</p>
                        <p class="text-sm text-slate-700">Lokasi: {{ toString(bantek.lokasi_bantek) }}</p>
                        <div class="space-y-2">
                          <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Daftar Client</p>
                          <div v-if="Array.isArray(bantek.clients_detail) && bantek.clients_detail.length" class="space-y-2">
                            <div
                              v-for="(client, cIndex) in bantek.clients_detail"
                              :key="`${bantekRowKey(bantek, index)}-detail-${cIndex}`"
                              class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"
                            >
                              <p class="text-xs text-slate-700">
                                {{ toString(client.nama_client) }} <span class="text-slate-500">({{ toString(client.no_identitas) }})</span>
                              </p>
                              <button
                                type="button"
                                class="rounded-md border border-red-200 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="bantekActionLoading"
                                @click="removeClientFromBantek(bantek, client)"
                              >
                                Hapus
                              </button>
                            </div>
                          </div>
                          <p v-else class="text-xs text-slate-500">Tidak ada client dalam bantek ini.</p>
                        </div>
                      </div>

                      <div class="self-center">
                        <button
                          type="button"
                          class="h-10 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white transition hover:bg-emerald-700"
                          @click="printBantekLabel(bantek.no_bantek)"
                        >
                          Cetak Label
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>
                </template>
            </tbody>
          </table>
        </div>
      </div>
      </div>
    </SurfaceCard>

    <Teleport to="body">
      <div v-if="clientDialogOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeClientDialog" />
        <div class="relative z-10 w-full max-w-3xl rounded-2xl border border-slate-200 bg-white p-5">
          <div class="flex items-start justify-between gap-3">
            <h3 class="text-lg font-semibold text-slate-900">{{ clientFormTitle }}</h3>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeClientDialog">Tutup</button>
          </div>
          <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="-translate-y-1 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="-translate-y-1 opacity-0"
          >
            <div
              v-if="clientDialogError"
              class="absolute left-5 right-5 top-16 z-20 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 shadow-xl shadow-red-950/10"
            >
              {{ clientDialogError }}
            </div>
          </Transition>
          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ identityLabel }}</span>
              <input
                ref="clientIdentityInputRef"
                v-model="clientForm.no_identitas"
                type="text"
                :inputmode="props.clientType === 'Perorangan' ? 'numeric' : 'text'"
                :maxlength="identityMaxLength"
                :placeholder="identityPlaceholder"
                class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                @input="normalizeClientIdentityInput"
                @change="checkClientIdentity"
                @blur="checkClientIdentity"
              />
              <span v-if="checkingClientIdentity" class="text-xs font-semibold text-blue-600">Mengecek {{ identityLabel }}...</span>
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Nama Client</span>
              <input
                v-model="clientForm.nama_client"
                type="text"
                :placeholder="clientNamePlaceholder"
                :disabled="clientIdentityBlocked"
                class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100 disabled:text-slate-500"
                @input="normalizeClientNameInput"
              />
            </label>
            <div v-if="clientIdentityMatch" class="md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
              <p class="font-semibold">{{ identityLabel }} sudah ada di data client.</p>
              <p class="mt-1">
                {{ toString(clientIdentityMatch.nama_client) }}
                <span v-if="clientIdentityMatch.id_client">- {{ clientIdentityMatch.id_client }}</span>
                <span v-if="clientIdentityMatch.pembuat_client">, dibuat oleh {{ clientIdentityMatch.pembuat_client }}</span>
              </p>
            </div>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Contact Number</span>
              <input v-model="clientForm.contact_number" type="text" :disabled="clientIdentityBlocked" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100 disabled:text-slate-500" />
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Email</span>
              <input v-model="clientForm.email" type="email" :disabled="clientIdentityBlocked" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100 disabled:text-slate-500" />
            </label>
            <label class="md:col-span-2 flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Alamat Client</span>
              <textarea v-model="clientForm.alamat_client" rows="4" :disabled="clientIdentityBlocked" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100 disabled:text-slate-500"></textarea>
            </label>
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeClientDialog">Batal</button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="savingClient || checkingClientIdentity || clientIdentityBlocked" @click="saveClient">
              {{ savingClient ? 'Menyimpan...' : 'Simpan Client' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="documentViewerOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeDocumentViewer" />
        <div class="relative z-10 flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <h3 class="text-lg font-semibold text-slate-900">Dokumen {{ toString(documentViewerClient?.nama_client) }}</h3>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeDocumentViewer">Tutup</button>
          </div>
          <div class="border-b border-slate-200 px-5 py-3">
            <input v-model="documentViewerSearch" type="text" placeholder="Cari Nama Dokumen / Deskripsi..." class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
          </div>
          <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <p v-if="loadingDocumentViewer" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">Memuat dokumen client...</p>
            <p v-else-if="documentViewerError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ documentViewerError }}</p>
            <p v-else-if="!filteredViewerDocuments.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">Dokumen client tidak tersedia.</p>
            <div v-else class="space-y-3">
              <details v-for="(row, index) in filteredViewerDocuments" :key="`${toString(row.id_berkas, 'doc')}-${index}`" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-800">{{ toString(row.nama_dokumen) }}</summary>
                <div class="space-y-3 border-t border-slate-100 px-4 py-3">
                  <button
                    v-if="clientDocumentUrl(row)"
                    type="button"
                    class="inline-flex rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                    @click="openUploadDocumentFile(row)"
                  >
                    Lihat Detail
                  </button>
                  <iframe v-if="clientDocumentUrl(row)" :src="toIframePreviewUrl(clientDocumentUrl(row))" class="h-[520px] w-full rounded-lg border border-slate-200" frameborder="0"></iframe>
                </div>
              </details>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="uploadDialogOpen" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/65" @click="closeUploadDialog" />
        <div class="relative z-10 flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <h3 class="text-lg font-semibold text-slate-900">Upload Dokumen {{ toString(uploadDialogClient?.nama_client) }}</h3>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeUploadDialog">Tutup</button>
          </div>
          <div class="border-b border-slate-200 px-5 py-4">
            <input ref="uploadInputRef" type="file" multiple class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700" @change="onUploadFileChange" />
            <div class="mt-3 flex justify-end">
              <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="uploadingDocument || !uploadFiles.length" @click="submitUpload">
                {{ uploadingDocument ? 'Mengupload...' : 'Upload Dokumen' }}
              </button>
            </div>
            <p v-if="uploadDialogError" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ uploadDialogError }}</p>
          </div>
          <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div v-if="!uploadDialogRows.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
              Belum ada dokumen untuk client ini.
            </div>
            <div v-else class="overflow-hidden rounded-xl border border-slate-200">
              <div class="max-h-[46vh] overflow-y-auto">
              <table class="w-full table-fixed divide-y divide-slate-200 bg-white">
                <thead class="bg-slate-100/80">
                  <tr>
                    <th class="w-[40%] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nama Dokumen</th>
                    <th class="w-[38%] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">File</th>
                    <th class="w-[22%] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="(row, index) in uploadDialogRows" :key="`${toString(row.id_berkas, 'berkas')}-${index}`">
                    <td class="px-3 py-2 text-sm text-slate-700 align-top">
                      <input v-if="editingDocumentIndex === index" v-model="editingDocumentName" type="text" class="h-9 w-full rounded-lg border border-slate-200 px-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
                      <span v-else class="block break-words">{{ toString(row.nama_dokumen) }}</span>
                    </td>
                    <td class="px-3 py-2 text-sm text-slate-700 align-top">
                      <span class="block w-full overflow-hidden text-ellipsis whitespace-nowrap" :title="toString(row.nama_berkas)">
                        {{ toString(row.nama_berkas) }}
                      </span>
                    </td>
                    <td class="px-3 py-2 align-top">
                      <div class="flex flex-wrap gap-1.5">
                        <button type="button" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="openUploadDocumentFile(row)">Lihat</button>
                        <button v-if="editingDocumentIndex !== index" type="button" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="startEditUploadDocument(index)">Edit</button>
                        <button v-else type="button" class="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="documentActionLoading" @click="saveUploadDocumentName(index)">Simpan</button>
                        <button v-if="editingDocumentIndex === index" type="button" class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="cancelEditUploadDocument">Batal</button>
                        <button type="button" class="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="documentActionLoading" @click="deleteUploadDocument(index)">Hapus</button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="previewDialog.open" class="fixed inset-0 z-[74] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/70" @click="closePreviewDialog" />
        <div class="relative z-10 flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
          <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Preview Dokumen</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ previewDialog.title }}</h3>
            </div>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closePreviewDialog">
              Tutup
            </button>
          </div>
          <div class="min-h-0 flex-1 bg-slate-100/70 p-4">
            <iframe
              v-if="previewDialog.url"
              :src="previewDialog.url"
              class="h-full min-h-[72vh] w-full rounded-xl border border-slate-200 bg-white"
              frameborder="0"
            />
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="bantekCreateOpen" class="fixed inset-0 z-[75] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/65" @click="closeCreateBantek" />
        <div class="relative z-10 w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-5">
          <h3 class="text-lg font-semibold text-slate-900">Buat Bantek Baru</h3>
          <div class="mt-4 space-y-4">
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Lokasi Bantek</span>
              <input v-model="bantekCreateForm.lokasi_bantek" type="text" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pautkan Client Awal (Opsional)</span>
              <select v-model="bantekCreateForm.id_client" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option value="">Pilih Client...</option>
                <option v-for="option in clientOptions" :key="String(option.id)" :value="option.id">
                  {{ option.name }} ({{ option.no_identitas }})
                </option>
              </select>
            </label>
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeCreateBantek">Batal</button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="creatingBantek" @click="saveCreateBantek">
              {{ creatingBantek ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="bantekLinkOpen" class="fixed inset-0 z-[76] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/65" @click="closeLinkBantek" />
        <div class="relative z-10 w-full max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <h3 class="text-lg font-semibold text-slate-900">Masukan Client ke Bantek {{ bantekLinkForm.no_bantek }}</h3>
          <div class="mt-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700">
            Gunakan pencarian untuk memilih client, lalu simpan.
          </div>
          <div class="mt-4 grid gap-4 md:grid-cols-[1.1fr_1fr]">
            <div class="space-y-3">
              <label class="flex flex-col gap-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Cari Client</span>
                <input
                  v-model="bantekLinkClientSearch"
                  type="text"
                  placeholder="Ketik nama client / NPWP"
                  class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>
              <div class="rounded-xl border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2">
                  <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Hasil Pencarian</p>
                  <p class="text-xs text-slate-500">{{ filteredLinkClientOptions.length }} ditampilkan</p>
                </div>
                <div class="max-h-64 overflow-y-auto p-2">
                  <button
                    v-for="option in filteredLinkClientOptions"
                    :key="`link-${String(option.id)}`"
                    type="button"
                    class="mb-1 flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50"
                    @click="addClientToBantekLink(option.id)"
                  >
                    <span class="truncate">{{ option.name }} ({{ option.no_identitas }})</span>
                    <span class="ml-2 rounded-md border border-slate-300 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-500">Tambah</span>
                  </button>
                  <p v-if="!filteredLinkClientOptions.length" class="px-2 py-3 text-xs text-slate-500">
                    Tidak ada client yang cocok.
                  </p>
                </div>
              </div>
            </div>

            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Client Terpilih</p>
                <button
                  v-if="selectedLinkClients.length"
                  type="button"
                  class="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-50"
                  @click="clearBantekLinkSelection"
                >
                  Bersihkan
                </button>
              </div>
              <div class="min-h-64 rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div v-if="selectedLinkClients.length" class="space-y-2">
                  <div
                    v-for="option in selectedLinkClients"
                    :key="`selected-${String(option.id)}`"
                    class="flex items-start justify-between rounded-lg border border-slate-200 bg-white px-3 py-2"
                  >
                    <p class="text-sm text-slate-700">
                      {{ option.name }}
                      <span class="block text-xs text-slate-500">{{ option.no_identitas }}</span>
                    </p>
                    <button
                      type="button"
                      class="rounded-md border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-700 hover:bg-red-100"
                      @click="removeClientFromBantekLinkSelection(option.id)"
                    >
                      Hapus
                    </button>
                  </div>
                </div>
                <p v-else class="px-2 py-3 text-xs text-slate-500">
                  Belum ada client yang dipilih.
                </p>
              </div>
            </div>
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeLinkBantek">Batal</button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="linkingBantek || !bantekLinkForm.id_clients.length" @click="saveLinkBantek">
              {{ linkingBantek ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
