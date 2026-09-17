<script setup lang="ts">
import { getModuleByPath } from '~/data/modules'

type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type DownloadRequestPayload = {
  request_id?: string | number
  approved?: boolean
  request_status?: 'pending' | 'approved' | 'rejected' | string
}

type RowRecord = Record<string, unknown>
type ReportoriumAction = 'expand' | 'edit' | 'upload' | 'delete' | 'print'
type ReportoriumColumn = {
  key: string
  label: string
}
type OrderModulePath = '/order_masuk' | '/order_selesai' | '/invoice_tax' | '/invoice_non_tax'
type OrderAction = 'edit' | 'complete' | 'invoice' | 'detail' | 'print'
type OrderRow = RowRecord & {
  id_order?: string | number
  nama_pesanan?: string
  nama_lengkap?: string
  created_at?: string
  keterangan_order?: string
  jenis_invoice?: string
  no_inv?: string
  ket_noinv?: string
  detail_order?: RowRecord[]
  total_invoice?: RowRecord[]
}
type OrderColumn = {
  key: string
  label: string
}
type OrderDetailDraft = {
  id_pekerjaan: string | number | ''
  jenis_pekerjaan: string
  nama_pekerjaan: string
  no_pekerjaan: string
  tanggal_pekerjaan: string
  pembuat: string
  harga: number
}
type AssistantOption = {
  id_user?: string | number
  nama_lengkap?: string
}
type AktaMassalPreview = {
  period?: string
  tanggal_akta?: string
  judul_pekerjaan?: string
  jumlah?: number
  nomor_terakhir?: number
  nomor_mulai?: number
  nomor_selesai?: number
  duplicate_numbers?: number[]
  can_save?: boolean
  preview_rows?: Array<{ no_akta: number, tgl_akta: string, judul_pekerjaan: string }>
  rows?: Array<{ no_akta: number, tgl_akta: string, judul_pekerjaan: string }>
}
definePageMeta({
  middleware: 'auth',
})

const route = useRoute()
const business = useLegacyBusiness()
const { user, token, setSession } = useSession()
const runtimeConfig = useRuntimeConfig()

const moduleEntry = computed(() => getModuleByPath(route.path))

if (!moduleEntry.value) {
  throw createError({
    statusCode: 404,
    statusMessage: 'Halaman modul tidak ditemukan.',
  })
}

const adminOnlyMasterPaths = ['/data_layanan', '/data_dokumen']
if (adminOnlyMasterPaths.includes(moduleEntry.value.path)) {
  const role = String(user.value?.level_user || '').trim().toLowerCase()
  const allowed = role === 'admin' || role === 'super admin' || role === 'superadmin'
  if (!allowed) {
    await navigateTo('/dashboard', { replace: true })
  }
}

useHead({
  title: moduleEntry.value.title,
})

const monthFilter = ref(new Date().toISOString().slice(0, 7))
const searchFilter = ref(typeof route.query.q === 'string' ? route.query.q : '')
const loading = ref(false)
const errorMessage = ref('')
const responseMessage = ref('')
const rows = ref<Array<Record<string, unknown>>>([])
const downloadRequestLoading = ref(false)

type UserRow = RowRecord & {
  id?: string | number
  id_user?: string | number
  nama_lengkap?: string
  name?: string
  username?: string
  level_user?: string
  email?: string
  email_verified_at?: string
  phone?: string
  login_otp_enabled?: boolean | number
  foto?: string
  created_at?: string
  updated_at?: string
}

type LayananRow = RowRecord & {
  id_akta?: string | number
  nama_akta?: string
  pekerjaan_milik?: string
  apht?: string | boolean | null
  created_at?: string
  updated_at?: string
}

type DokumenRow = RowRecord & {
  id_dokumen?: string | number
  nama_dokumen?: string
  created_at?: string
  updated_at?: string
}

type UserForm = {
  id_user: string | number | ''
  nama_lengkap: string
  email: string
  level_user: string
  phone: string
  password: string
  password_confirmation: string
  login_otp_enabled: boolean
}

type LayananForm = {
  id_akta: string | number | ''
  nama_akta: string
  pekerjaan_milik: string
  apht: 'TRUE' | 'FALSE'
}

type DokumenForm = {
  id_dokumen: string | number | ''
  nama_dokumen: string
}

type ReportSettingForm = {
  logo_path: string
  header_label: string
  office_name: string
  office_address: string
  office_email: string
  office_phone: string
  office_city: string
  signatory_title: string
  signatory_name: string
  invoice_bank_account_1: string
  invoice_bank_account_2: string
  invoice_bank_account_3: string
}

type DatabaseBackupRow = {
  file_name: string
  display_name: string
  size_bytes: number
  size_human: string
  created_at: string
  created_at_label: string
  month_key: string
  eligible_for_prune: boolean
}

type DatabaseBackupSummary = {
  policy: {
    frequency: string
    retention_months: number
    format: string
    directory: string
    schedule_time: string
  }
  scheduler: {
    command: string
    next_run_at: string
    next_run_at_label: string
    requires_schedule_run: boolean
  }
  last_backup: DatabaseBackupRow | null
  total_backups: number
  total_size_bytes: number
  total_size_human: string
  backups: DatabaseBackupRow[]
}

const isAccountSettingsPage = computed(() => moduleEntry.value?.path === '/pages/account-settings')
const isReportSettingsPage = computed(() => moduleEntry.value?.path === '/pages/report-settings')
const isMasterLayananModule = computed(() => moduleEntry.value?.path === '/data_layanan')
const isMasterDokumenModule = computed(() => moduleEntry.value?.path === '/data_dokumen')
const isMasterCrudModule = computed(() =>
  isAccountSettingsPage.value || isMasterLayananModule.value || isMasterDokumenModule.value,
)
const reportoriumModulePaths = [
  '/buku_akta',
  '/buku_legalisasi',
  '/buku_waarmerking',
  '/buku_ppat',
  '/buku_surat_notaris',
  '/buku_surat_ppat',
  '/tanda_terima',
  '/tanda_terima_masuk',
]
const isReportoriumModule = computed(() => reportoriumModulePaths.includes(moduleEntry.value?.path || ''))
const orderModulePaths: OrderModulePath[] = ['/order_masuk', '/order_selesai', '/invoice_tax', '/invoice_non_tax']
const isOrderModule = computed(() => orderModulePaths.includes((moduleEntry.value?.path || '') as OrderModulePath))
const isOrderMasukModule = computed(() => moduleEntry.value?.path === '/order_masuk')
const isOrderSelesaiModule = computed(() => moduleEntry.value?.path === '/order_selesai')
const isInvoiceModule = computed(() => moduleEntry.value?.path === '/invoice_tax' || moduleEntry.value?.path === '/invoice_non_tax')
const isArsipUser = computed(() => String(user.value?.level_user || '') === 'Arsip')
const currentUserId = computed(() => String(user.value?.id_user || user.value?.id || ''))
const currentUserRole = computed(() => String(user.value?.level_user || '').trim().toLowerCase())
const reportoriumFormRef = ref<{
  openForCreate: () => Promise<void>
  openForEdit: (row: RowRecord) => Promise<void>
} | null>(null)

const pathsWithDateFilter = [
  '/peminjaman-minuta',
  '/jadwal-notaris',
  '/order_masuk',
  '/order_selesai',
  '/invoice_tax',
  '/invoice_non_tax',
  '/buku_akta',
  '/buku_legalisasi',
  '/buku_waarmerking',
  '/buku_ppat',
  '/buku_surat_notaris',
  '/buku_surat_ppat',
  '/tanda_terima',
  '/tanda_terima_masuk',
]

const hasDateFilter = computed(() => pathsWithDateFilter.includes(moduleEntry.value?.path || ''))
const hasSearchFilter = computed(() => moduleEntry.value?.path === '/pencarian-dokumen')
const isSuperAdmin = computed(() => currentUserRole.value === 'super admin' || currentUserRole.value === 'superadmin')
const isAdmin = computed(() => currentUserRole.value === 'admin')
const canManageReportSettings = computed(() => isSuperAdmin.value || isAdmin.value)
const isBukuAktaModule = computed(() => moduleEntry.value?.path === '/buku_akta')
const canCreateAktaMassal = computed(() => isBukuAktaModule.value && isSuperAdmin.value)
const isRestrictedSettingsPage = computed(() =>
  moduleEntry.value?.path === '/pages/report-settings' && !canManageReportSettings.value,
)
const canManageInvoice = computed(() => isAdmin.value)
const canManageUserCrud = computed(() => isSuperAdmin.value || isAdmin.value)
const reportoriumSearch = ref('')
const masterSearch = ref('')
const masterPage = ref(1)
const masterPerPage = ref(10)
const masterPerPageOptions = [10, 20, 50]
const hasReportoriumSearch = computed(() => {
  return isReportoriumModule.value
})
const aktaMassalDialogOpen = ref(false)
const aktaMassalSubmitting = ref(false)
const aktaMassalPreviewing = ref(false)
const aktaMassalError = ref('')
const aktaMassalMessage = ref('')
const aktaMassalPreview = ref<AktaMassalPreview | null>(null)
const aktaMassalForm = reactive({
  period: monthFilter.value,
  tanggal_akta: `${monthFilter.value}-01`,
  judul_pekerjaan: 'Akta Lampau',
  jumlah: 1,
  nomor_mulai: '',
  gunakan_nomor_di_judul: true,
})

const accountRows = computed(() => {
  const allRows = rows.value as UserRow[]
  if (canManageUserCrud.value) return allRows
  return allRows.filter(row => isOwnUserRow(row))
})
const layananRows = computed(() => rows.value as LayananRow[])
const dokumenRows = computed(() => rows.value as DokumenRow[])

const containsKeyword = (value: unknown, keyword: string) =>
  String(value ?? '').toLowerCase().includes(keyword)

const filteredAccountRows = computed(() => {
  const keyword = masterSearch.value.trim().toLowerCase()
  if (!keyword) return accountRows.value
  return accountRows.value.filter(row =>
    [
      row.nama_lengkap || row.name,
      row.email,
      row.level_user,
      row.phone,
      row.username,
    ].some(field => containsKeyword(field, keyword)),
  )
})

const filteredLayananRows = computed(() => {
  const keyword = masterSearch.value.trim().toLowerCase()
  if (!keyword) return layananRows.value
  return layananRows.value.filter(row =>
    [
      row.id_akta,
      row.nama_akta,
      row.pekerjaan_milik,
      row.apht,
    ].some(field => containsKeyword(field, keyword)),
  )
})

const filteredDokumenRows = computed(() => {
  const keyword = masterSearch.value.trim().toLowerCase()
  if (!keyword) return dokumenRows.value
  return dokumenRows.value.filter(row =>
    [
      row.id_dokumen,
      row.nama_dokumen,
    ].some(field => containsKeyword(field, keyword)),
  )
})

const currentMasterTotalRows = computed(() => {
  if (isAccountSettingsPage.value) return filteredAccountRows.value.length
  if (isMasterLayananModule.value) return filteredLayananRows.value.length
  if (isMasterDokumenModule.value) return filteredDokumenRows.value.length
  return 0
})

const currentMasterTotalPages = computed(() =>
  Math.max(1, Math.ceil(currentMasterTotalRows.value / masterPerPage.value)),
)

const paginatedAccountRows = computed(() => {
  const start = (masterPage.value - 1) * masterPerPage.value
  return filteredAccountRows.value.slice(start, start + masterPerPage.value)
})

const paginatedLayananRows = computed(() => {
  const start = (masterPage.value - 1) * masterPerPage.value
  return filteredLayananRows.value.slice(start, start + masterPerPage.value)
})

const paginatedDokumenRows = computed(() => {
  const start = (masterPage.value - 1) * masterPerPage.value
  return filteredDokumenRows.value.slice(start, start + masterPerPage.value)
})

const masterStartItem = computed(() => {
  if (!currentMasterTotalRows.value) return 0
  return (masterPage.value - 1) * masterPerPage.value + 1
})

const masterEndItem = computed(() =>
  Math.min(masterStartItem.value + masterPerPage.value - 1, currentMasterTotalRows.value),
)

const roleOptions = ['Super Admin', 'Admin', 'Asisten', 'Arsip']
const pekerjaanMilikOptions = ['Notaris', 'PPAT']

const masterDetailDialog = reactive({
  open: false,
  title: '',
  row: null as RowRecord | null,
})

const userFormDialog = reactive({
  open: false,
  mode: 'create' as 'create' | 'edit',
  saving: false,
  error: '',
})

const userPhotoUploadInputRef = ref<HTMLInputElement | null>(null)
const userPhotoUploadState = reactive({
  targetIdUser: '',
  uploading: false,
})
const reportSettingsLogoInputRef = ref<HTMLInputElement | null>(null)
const reportSettingsLogoUploading = ref(false)

const userForm = reactive<UserForm>({
  id_user: '',
  nama_lengkap: '',
  email: '',
  level_user: '',
  phone: '',
  password: '',
  password_confirmation: '',
  login_otp_enabled: false,
})

const passwordDialog = reactive({
  open: false,
  saving: false,
  error: '',
  message: '',
})

const passwordDialogForm = reactive({
  currentPassword: '',
  newPassword: '',
  confirmPassword: '',
})

const userPasswordDialog = reactive({
  open: false,
  saving: false,
  error: '',
  targetIdUser: '',
  targetName: '',
})

const userPasswordDialogForm = reactive({
  currentPassword: '',
  newPassword: '',
  confirmPassword: '',
})

const layananFormDialog = reactive({
  open: false,
  mode: 'create' as 'create' | 'edit',
  saving: false,
  error: '',
})

const layananForm = reactive<LayananForm>({
  id_akta: '',
  nama_akta: '',
  pekerjaan_milik: 'Notaris',
  apht: 'FALSE',
})

const dokumenFormDialog = reactive({
  open: false,
  mode: 'create' as 'create' | 'edit',
  saving: false,
  error: '',
})

const dokumenForm = reactive<DokumenForm>({
  id_dokumen: '',
  nama_dokumen: '',
})

const reportSettingsSaving = ref(false)
const reportSettingsError = ref('')
const databaseBackupLoading = ref(false)
const databaseBackupRunning = ref(false)
const databaseBackupPruning = ref(false)
const databaseBackupDownloading = ref('')
const databaseBackupError = ref('')
const reportSettingsForm = reactive<ReportSettingForm>({
  logo_path: '',
  header_label: '',
  office_name: '',
  office_address: '',
  office_email: '',
  office_phone: '',
  office_city: '',
  signatory_title: '',
  signatory_name: '',
  invoice_bank_account_1: '',
  invoice_bank_account_2: '',
  invoice_bank_account_3: '',
})
const createEmptyDatabaseBackupSummary = (): DatabaseBackupSummary => ({
  policy: {
    frequency: 'monthly',
    retention_months: 12,
    format: 'sql',
    directory: '',
    schedule_time: '',
  },
  scheduler: {
    command: 'backup:database-monthly',
    next_run_at: '',
    next_run_at_label: '',
    requires_schedule_run: true,
  },
  last_backup: null,
  total_backups: 0,
  total_size_bytes: 0,
  total_size_human: '0 B',
  backups: [],
})
const databaseBackupSummary = reactive<DatabaseBackupSummary>(createEmptyDatabaseBackupSummary())
const databaseBackupRows = ref<DatabaseBackupRow[]>([])

const userDialogTitle = computed(() =>
  userFormDialog.mode === 'create' ? 'Tambah User Baru' : 'Edit Data User',
)

const layananDialogTitle = computed(() =>
  layananFormDialog.mode === 'create' ? 'Tambah Layanan' : 'Edit Layanan',
)

const dokumenDialogTitle = computed(() =>
  dokumenFormDialog.mode === 'create' ? 'Tambah Dokumen' : 'Edit Dokumen',
)

if (isRestrictedSettingsPage.value) {
  throw createError({
    statusCode: 403,
    statusMessage: 'Halaman ini khusus Admin dan Super Admin.',
  })
}

const resetReportSettingsForm = () => {
  reportSettingsForm.logo_path = ''
  reportSettingsForm.header_label = ''
  reportSettingsForm.office_name = ''
  reportSettingsForm.office_address = ''
  reportSettingsForm.office_email = ''
  reportSettingsForm.office_phone = ''
  reportSettingsForm.office_city = ''
  reportSettingsForm.signatory_title = ''
  reportSettingsForm.signatory_name = ''
  reportSettingsForm.invoice_bank_account_1 = ''
  reportSettingsForm.invoice_bank_account_2 = ''
  reportSettingsForm.invoice_bank_account_3 = ''
}

const resetDatabaseBackupState = () => {
  Object.assign(databaseBackupSummary, createEmptyDatabaseBackupSummary())
  databaseBackupRows.value = []
}

const applyReportSettingsForm = (row?: RowRecord | null) => {
  const source = row || {}
  reportSettingsForm.logo_path = String(source.logo_path || '')
  reportSettingsForm.header_label = String(source.header_label || '')
  reportSettingsForm.office_name = String(source.office_name || '')
  reportSettingsForm.office_address = String(source.office_address || '')
  reportSettingsForm.office_email = String(source.office_email || '')
  reportSettingsForm.office_phone = String(source.office_phone || '')
  reportSettingsForm.office_city = String(source.office_city || '')
  reportSettingsForm.signatory_title = String(source.signatory_title || '')
  reportSettingsForm.signatory_name = String(source.signatory_name || '')
  reportSettingsForm.invoice_bank_account_1 = String(source.invoice_bank_account_1 || '')
  reportSettingsForm.invoice_bank_account_2 = String(source.invoice_bank_account_2 || '')
  reportSettingsForm.invoice_bank_account_3 = String(source.invoice_bank_account_3 || '')
}

const applyDatabaseBackupState = (payload?: Partial<DatabaseBackupSummary> | null) => {
  const source = payload || {}
  databaseBackupSummary.policy = {
    frequency: String(source.policy?.frequency || 'monthly'),
    retention_months: Number(source.policy?.retention_months || 12),
    format: String(source.policy?.format || 'sql'),
    directory: String(source.policy?.directory || ''),
    schedule_time: String(source.policy?.schedule_time || ''),
  }
  databaseBackupSummary.scheduler = {
    command: String(source.scheduler?.command || 'backup:database-monthly'),
    next_run_at: String(source.scheduler?.next_run_at || ''),
    next_run_at_label: String(source.scheduler?.next_run_at_label || ''),
    requires_schedule_run: Boolean(source.scheduler?.requires_schedule_run ?? true),
  }
  databaseBackupSummary.last_backup = source.last_backup
    ? {
        file_name: String(source.last_backup.file_name || ''),
        display_name: String(source.last_backup.display_name || ''),
        size_bytes: Number(source.last_backup.size_bytes || 0),
        size_human: String(source.last_backup.size_human || '0 B'),
        created_at: String(source.last_backup.created_at || ''),
        created_at_label: String(source.last_backup.created_at_label || ''),
        month_key: String(source.last_backup.month_key || ''),
        eligible_for_prune: Boolean(source.last_backup.eligible_for_prune),
      }
    : null
  databaseBackupSummary.total_backups = Number(source.total_backups || 0)
  databaseBackupSummary.total_size_bytes = Number(source.total_size_bytes || 0)
  databaseBackupSummary.total_size_human = String(source.total_size_human || '0 B')
  databaseBackupRows.value = Array.isArray(source.backups)
    ? source.backups.map(item => ({
        file_name: String(item.file_name || ''),
        display_name: String(item.display_name || item.file_name || ''),
        size_bytes: Number(item.size_bytes || 0),
        size_human: String(item.size_human || '0 B'),
        created_at: String(item.created_at || ''),
        created_at_label: String(item.created_at_label || ''),
        month_key: String(item.month_key || ''),
        eligible_for_prune: Boolean(item.eligible_for_prune),
      }))
    : []
}

const loadDatabaseBackupState = async () => {
  if (!canManageReportSettings.value) {
    resetDatabaseBackupState()
    databaseBackupError.value = ''
    return
  }

  databaseBackupLoading.value = true
  databaseBackupError.value = ''

  try {
    const response = await business.settings.getDatabaseBackups() as ApiEnvelope<DatabaseBackupSummary>
    applyDatabaseBackupState(response.data || null)
  } catch (error) {
    resetDatabaseBackupState()
    databaseBackupError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat daftar backup database.'
  } finally {
    databaseBackupLoading.value = false
  }
}

const openReportSettingsLogoPicker = () => {
  if (!canManageReportSettings.value || reportSettingsLogoUploading.value) return
  reportSettingsLogoInputRef.value?.click()
}

const onReportSettingsLogoInputChange = async (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]

  if (!file) {
    target.value = ''
    return
  }

  if (!canManageReportSettings.value) {
    reportSettingsError.value = 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengubah logo laporan.'
    target.value = ''
    return
  }

  reportSettingsLogoUploading.value = true
  reportSettingsError.value = ''

  try {
    const formData = new FormData()
    formData.append('file', file)
    const response = await business.settings.uploadReportLogo(formData) as ApiEnvelope
    responseMessage.value = response.message || 'Logo laporan berhasil diperbarui.'
    await loadModuleData()
  } catch (error) {
    reportSettingsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal upload logo laporan.'
  } finally {
    reportSettingsLogoUploading.value = false
    target.value = ''
  }
}

const runDatabaseBackup = async () => {
  if (!canManageReportSettings.value || databaseBackupRunning.value) return

  databaseBackupRunning.value = true
  databaseBackupError.value = ''

  try {
    const response = await business.settings.runDatabaseBackup() as ApiEnvelope
    responseMessage.value = response.message || 'Backup SQL berhasil diproses.'
    await loadDatabaseBackupState()
  } catch (error) {
    databaseBackupError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal membuat backup SQL.'
  } finally {
    databaseBackupRunning.value = false
  }
}

const pruneOldDatabaseBackups = async () => {
  if (!canManageReportSettings.value || databaseBackupPruning.value) return

  databaseBackupPruning.value = true
  databaseBackupError.value = ''

  try {
    const response = await business.settings.pruneOldDatabaseBackups() as ApiEnvelope
    responseMessage.value = response.message || 'Backup lama berhasil dibersihkan.'
    await loadDatabaseBackupState()
  } catch (error) {
    databaseBackupError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal membersihkan backup lama.'
  } finally {
    databaseBackupPruning.value = false
  }
}

const downloadDatabaseBackup = async (fileName: string) => {
  if (!canManageReportSettings.value || !fileName || databaseBackupDownloading.value) return

  databaseBackupDownloading.value = fileName
  databaseBackupError.value = ''

  try {
    const blob = await business.settings.downloadDatabaseBackup(fileName)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = fileName
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    responseMessage.value = `Backup ${fileName} berhasil diunduh.`
  } catch (error) {
    databaseBackupError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengunduh file backup.'
  } finally {
    databaseBackupDownloading.value = ''
  }
}

const userDisplayName = (row: UserRow) => String(row.nama_lengkap || row.name || '-')
const userIdValue = (row: UserRow) => String(row.id_user || row.id || '')
const isOwnUserRow = (row: UserRow) => userIdValue(row) === currentUserId.value
const canEditUserRow = (row: UserRow) => canManageUserCrud.value || isOwnUserRow(row)
const canUploadPhotoRow = (row: UserRow) => canManageUserCrud.value || isOwnUserRow(row)
const canDeleteUserRow = (row: UserRow) => canManageUserCrud.value && !isOwnUserRow(row)
const isUploadingPhotoForRow = (row: UserRow) =>
  userPhotoUploadState.uploading && userPhotoUploadState.targetIdUser === userIdValue(row)

const masterDetailEntries = computed(() => {
  if (!masterDetailDialog.row) return [] as Array<[string, unknown]>
  const blockedForAccount = [
    'id',
    'id_user',
    'email_verified_at',
    'email_verified',
    'emailverified',
    'foto',
    'password',
    'remember_token',
  ]
  return Object.entries(masterDetailDialog.row).filter(([key]) =>
    !(isAccountSettingsPage.value && blockedForAccount.includes(key)),
  )
})

const toMasterBadgeClass = (value: unknown) => {
  const normalized = String(value ?? '').toUpperCase()
  if (normalized === 'TRUE') {
    return 'inline-flex min-w-[72px] items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700'
  }
  return 'inline-flex min-w-[72px] items-center justify-center rounded-lg border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600'
}

const masterActionButtonClass = (action: 'detail' | 'edit' | 'delete' | 'password' | 'add') => {
  const base = 'inline-flex h-8 items-center rounded-lg border px-3 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50'
  if (action === 'detail') return `${base} border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50`
  if (action === 'edit') return `${base} border-blue-200 bg-blue-50 text-blue-700 hover:border-blue-300 hover:bg-blue-100`
  if (action === 'password') return `${base} border-violet-200 bg-violet-50 text-violet-700 hover:border-violet-300 hover:bg-violet-100`
  if (action === 'delete') return `${base} border-red-200 bg-red-50 text-red-700 hover:border-red-300 hover:bg-red-100`
  return `${base} border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100`
}

const closeMasterDetailDialog = () => {
  masterDetailDialog.open = false
  masterDetailDialog.title = ''
  masterDetailDialog.row = null
}

const openMasterDetailDialog = (row: RowRecord, title: string) => {
  masterDetailDialog.open = true
  masterDetailDialog.title = title
  masterDetailDialog.row = row
}

const resetUserForm = () => {
  userForm.id_user = ''
  userForm.nama_lengkap = ''
  userForm.email = ''
  userForm.level_user = 'Asisten'
  userForm.phone = ''
  userForm.password = ''
  userForm.password_confirmation = ''
  userForm.login_otp_enabled = false
}

const closeUserFormDialog = () => {
  if (userFormDialog.saving) return
  userFormDialog.open = false
  userFormDialog.error = ''
  userFormDialog.mode = 'create'
  resetUserForm()
}

const openCreateUserDialog = () => {
  if (!canManageUserCrud.value) {
    errorMessage.value = 'Akses ditolak. Hanya Admin/Super Admin yang boleh menambah user.'
    return
  }
  userFormDialog.mode = 'create'
  userFormDialog.error = ''
  resetUserForm()
  userFormDialog.open = true
}

const openEditUserDialog = (row: UserRow) => {
  if (!canEditUserRow(row)) {
    errorMessage.value = 'Anda hanya boleh mengubah akun sendiri.'
    return
  }

  userFormDialog.mode = 'edit'
  userFormDialog.error = ''
  userForm.id_user = row.id_user || row.id || ''
  userForm.nama_lengkap = String(row.nama_lengkap || row.name || '')
  userForm.email = String(row.email || '')
  userForm.level_user = String(row.level_user || 'Asisten')
  userForm.phone = String(row.phone || '')
  userForm.password = ''
  userForm.password_confirmation = ''
  userForm.login_otp_enabled = Boolean(row.login_otp_enabled)
  userFormDialog.open = true
}

const saveUserForm = async () => {
  if (userFormDialog.saving) return
  userFormDialog.error = ''

  if (userFormDialog.mode === 'create' && !canManageUserCrud.value) {
    userFormDialog.error = 'Hanya Admin/Super Admin yang boleh menambah user.'
    return
  }

  if (!userForm.nama_lengkap.trim() || !userForm.email.trim() || !userForm.level_user.trim() || !userForm.phone.trim()) {
    userFormDialog.error = 'Nama, email, role, dan phone wajib diisi.'
    return
  }

  if (userFormDialog.mode === 'create') {
    if (!userForm.password || !userForm.password_confirmation) {
      userFormDialog.error = 'Password dan konfirmasi password wajib diisi.'
      return
    }
    if (userForm.password !== userForm.password_confirmation) {
      userFormDialog.error = 'Konfirmasi password tidak sama.'
      return
    }
  } else if (userForm.password || userForm.password_confirmation) {
    if (!userForm.password || !userForm.password_confirmation) {
      userFormDialog.error = 'Isi password dan konfirmasi password jika ingin mengubah password.'
      return
    }
    if (userForm.password !== userForm.password_confirmation) {
      userFormDialog.error = 'Konfirmasi password tidak sama.'
      return
    }
  }

  userFormDialog.saving = true
  try {
    const payload: Record<string, unknown> = {
      id_user: userForm.id_user || undefined,
      nama_lengkap: userForm.nama_lengkap,
      name: userForm.nama_lengkap,
      email: userForm.email,
      phone: userForm.phone,
      password: userForm.password || undefined,
      password_confirmation: userForm.password_confirmation || undefined,
      login_otp_enabled: canManageUserCrud.value ? userForm.login_otp_enabled : undefined,
    }

    payload.level_user = canManageUserCrud.value ? userForm.level_user : currentUserRole.value

    const response = await business.auth.SaveAccount({
      ...payload,
    }) as ApiEnvelope

    responseMessage.value = response.message || (userFormDialog.mode === 'create' ? 'User baru berhasil disimpan.' : 'Data user berhasil diperbarui.')
    closeUserFormDialog()
    await loadModuleData()
  } catch (error) {
    userFormDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan data user.'
  } finally {
    userFormDialog.saving = false
  }
}

const openUploadUserPhoto = (row: UserRow) => {
  if (!canUploadPhotoRow(row) || userPhotoUploadState.uploading) {
    return
  }
  const idUser = userIdValue(row)
  if (!idUser) {
    errorMessage.value = 'ID user tidak valid.'
    return
  }
  userPhotoUploadState.targetIdUser = idUser
  userPhotoUploadInputRef.value?.click()
}

const onUserPhotoInputChange = async (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  const targetIdUser = userPhotoUploadState.targetIdUser

  if (!file || !targetIdUser) {
    target.value = ''
    return
  }

  userPhotoUploadState.uploading = true
  errorMessage.value = ''

  try {
    const formData = new FormData()
    formData.append('id_user', targetIdUser)
    formData.append('file', file)

    const response = await business.auth.UploadFoto(formData) as ApiEnvelope<{ id_user?: string | number; foto?: string }>
    responseMessage.value = response.message || 'Foto profil berhasil diperbarui.'

    const responseData = response.data
    if (
      responseData
      && typeof responseData === 'object'
      && String((responseData as { id_user?: string | number }).id_user || '') === currentUserId.value
      && user.value
    ) {
      setSession({
        ...user.value,
        foto: String((responseData as { foto?: string }).foto || ''),
      })
    }

    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal upload foto user.'
  } finally {
    userPhotoUploadState.uploading = false
    userPhotoUploadState.targetIdUser = ''
    target.value = ''
  }
}

const deleteUserRow = async (row: UserRow) => {
  if (!canDeleteUserRow(row) || !import.meta.client) return
  if (!window.confirm(`Hapus user ${userDisplayName(row)}?`)) return

  try {
    const response = await business.auth.DeleteAccount({ id_user: row.id_user }) as ApiEnvelope
    responseMessage.value = response.message || 'User berhasil dihapus.'
    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus user.'
  }
}

const openPasswordDialog = () => {
  passwordDialog.open = true
  passwordDialog.error = ''
  passwordDialog.message = ''
  passwordDialogForm.currentPassword = ''
  passwordDialogForm.newPassword = ''
  passwordDialogForm.confirmPassword = ''
}

const closePasswordDialog = () => {
  if (passwordDialog.saving) return
  passwordDialog.open = false
  passwordDialog.error = ''
  passwordDialog.message = ''
  passwordDialogForm.currentPassword = ''
  passwordDialogForm.newPassword = ''
  passwordDialogForm.confirmPassword = ''
}

const savePasswordDialog = async () => {
  if (passwordDialog.saving) return
  passwordDialog.error = ''
  passwordDialog.message = ''

  if (!passwordDialogForm.currentPassword || !passwordDialogForm.newPassword || !passwordDialogForm.confirmPassword) {
    passwordDialog.error = 'Semua field password wajib diisi.'
    return
  }

  if (passwordDialogForm.newPassword !== passwordDialogForm.confirmPassword) {
    passwordDialog.error = 'Konfirmasi password tidak sama.'
    return
  }

  passwordDialog.saving = true
  try {
    const response = await business.auth.UpdatePassword({
      last_password: passwordDialogForm.currentPassword,
      new_password: passwordDialogForm.newPassword,
      password_confirmation: passwordDialogForm.confirmPassword,
    }) as ApiEnvelope
    passwordDialog.message = response.message || 'Password berhasil diperbarui.'
    passwordDialogForm.currentPassword = ''
    passwordDialogForm.newPassword = ''
    passwordDialogForm.confirmPassword = ''
  } catch (error) {
    passwordDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperbarui password.'
  } finally {
    passwordDialog.saving = false
  }
}

const resetLayananForm = () => {
  layananForm.id_akta = ''
  layananForm.nama_akta = ''
  layananForm.pekerjaan_milik = 'Notaris'
  layananForm.apht = 'FALSE'
}

const closeLayananFormDialog = () => {
  if (layananFormDialog.saving) return
  layananFormDialog.open = false
  layananFormDialog.error = ''
  layananFormDialog.mode = 'create'
  resetLayananForm()
}

const openCreateLayananDialog = () => {
  layananFormDialog.mode = 'create'
  layananFormDialog.error = ''
  resetLayananForm()
  layananFormDialog.open = true
}

const openEditLayananDialog = (row: LayananRow) => {
  layananFormDialog.mode = 'edit'
  layananFormDialog.error = ''
  layananForm.id_akta = row.id_akta || ''
  layananForm.nama_akta = String(row.nama_akta || '')
  layananForm.pekerjaan_milik = String(row.pekerjaan_milik || 'Notaris')
  layananForm.apht = String(row.apht || 'FALSE').toUpperCase() === 'TRUE' ? 'TRUE' : 'FALSE'
  layananFormDialog.open = true
}

const saveLayananForm = async () => {
  if (layananFormDialog.saving) return
  layananFormDialog.error = ''
  if (!layananForm.nama_akta.trim()) {
    layananFormDialog.error = 'Nama layanan wajib diisi.'
    return
  }

  layananFormDialog.saving = true
  try {
    const response = await business.master.SimpanLayanan({
      id_akta: layananForm.id_akta || undefined,
      nama_akta: layananForm.nama_akta,
      pekerjaan_milik: layananForm.pekerjaan_milik,
      apht: layananForm.apht,
    }) as ApiEnvelope

    responseMessage.value = response.message || (layananFormDialog.mode === 'create' ? 'Layanan berhasil ditambahkan.' : 'Layanan berhasil diperbarui.')
    closeLayananFormDialog()
    await loadModuleData()
  } catch (error) {
    layananFormDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan layanan.'
  } finally {
    layananFormDialog.saving = false
  }
}

const deleteLayananRow = async (row: LayananRow) => {
  if (!import.meta.client) return
  if (!window.confirm(`Hapus layanan ${String(row.nama_akta || row.id_akta || '-') }?`)) return

  try {
    const response = await business.master.DeleteLayanan({ id_akta: row.id_akta }) as ApiEnvelope
    responseMessage.value = response.message || 'Layanan berhasil dihapus.'
    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus layanan.'
  }
}

const resetDokumenForm = () => {
  dokumenForm.id_dokumen = ''
  dokumenForm.nama_dokumen = ''
}

const closeDokumenFormDialog = () => {
  if (dokumenFormDialog.saving) return
  dokumenFormDialog.open = false
  dokumenFormDialog.error = ''
  dokumenFormDialog.mode = 'create'
  resetDokumenForm()
}

const openCreateDokumenDialog = () => {
  dokumenFormDialog.mode = 'create'
  dokumenFormDialog.error = ''
  resetDokumenForm()
  dokumenFormDialog.open = true
}

const openEditDokumenDialog = (row: DokumenRow) => {
  dokumenFormDialog.mode = 'edit'
  dokumenFormDialog.error = ''
  dokumenForm.id_dokumen = row.id_dokumen || ''
  dokumenForm.nama_dokumen = String(row.nama_dokumen || '')
  dokumenFormDialog.open = true
}

const saveDokumenForm = async () => {
  if (dokumenFormDialog.saving) return
  dokumenFormDialog.error = ''
  if (!dokumenForm.nama_dokumen.trim()) {
    dokumenFormDialog.error = 'Nama dokumen wajib diisi.'
    return
  }

  dokumenFormDialog.saving = true
  try {
    const response = await business.master.SimpanDokumenStandar({
      id_dokumen: dokumenForm.id_dokumen || undefined,
      nama_dokumen: dokumenForm.nama_dokumen,
    }) as ApiEnvelope

    responseMessage.value = response.message || (dokumenFormDialog.mode === 'create' ? 'Dokumen berhasil ditambahkan.' : 'Dokumen berhasil diperbarui.')
    closeDokumenFormDialog()
    await loadModuleData()
  } catch (error) {
    dokumenFormDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan dokumen.'
  } finally {
    dokumenFormDialog.saving = false
  }
}

const deleteDokumenRow = async (row: DokumenRow) => {
  if (!import.meta.client) return
  if (!window.confirm(`Hapus dokumen ${String(row.nama_dokumen || row.id_dokumen || '-') }?`)) return

  try {
    const response = await business.master.DeleteDokumenStandar({ id_dokumen: row.id_dokumen }) as ApiEnvelope
    responseMessage.value = response.message || 'Dokumen berhasil dihapus.'
    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus dokumen.'
  }
}

const saveReportSettings = async () => {
  if (reportSettingsSaving.value) return

  reportSettingsError.value = ''
  if (!canManageReportSettings.value) {
    reportSettingsError.value = 'Akses ditolak. Hanya Admin/Super Admin yang boleh mengubah pengaturan laporan.'
    return
  }

  if (
    !reportSettingsForm.header_label.trim()
    || !reportSettingsForm.office_name.trim()
    || !reportSettingsForm.office_address.trim()
    || !reportSettingsForm.office_city.trim()
    || !reportSettingsForm.signatory_title.trim()
    || !reportSettingsForm.signatory_name.trim()
  ) {
    reportSettingsError.value = 'Header, nama kantor, alamat, kota, jabatan penandatangan, dan nama penandatangan wajib diisi.'
    return
  }

  reportSettingsSaving.value = true
  try {
    const response = await business.settings.saveReportSettings({
      ...reportSettingsForm,
    }) as ApiEnvelope

    responseMessage.value = response.message || 'Pengaturan laporan berhasil diperbarui.'
    await loadModuleData()
  } catch (error) {
    reportSettingsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan pengaturan laporan.'
  } finally {
    reportSettingsSaving.value = false
  }
}

const unwrapPayload = (payload: unknown) => {
  if (payload && typeof payload === 'object' && !Array.isArray(payload)) {
    const envelope = payload as ApiEnvelope
    responseMessage.value = envelope.message || ''
    if ('data' in payload) {
      return envelope.data
    }
  }

  responseMessage.value = ''
  return payload
}

const resetUserPasswordDialog = () => {
  userPasswordDialog.error = ''
  userPasswordDialog.targetIdUser = ''
  userPasswordDialog.targetName = ''
  userPasswordDialogForm.currentPassword = ''
  userPasswordDialogForm.newPassword = ''
  userPasswordDialogForm.confirmPassword = ''
}

const closeUserPasswordDialog = () => {
  if (userPasswordDialog.saving) return
  userPasswordDialog.open = false
  resetUserPasswordDialog()
}

const openUserPasswordDialog = (row: UserRow) => {
  if (!isSuperAdmin.value) return
  resetUserPasswordDialog()
  userPasswordDialog.targetIdUser = userIdValue(row)
  userPasswordDialog.targetName = userDisplayName(row)
  userPasswordDialog.open = true
}

const saveUserPasswordDialog = async () => {
  if (userPasswordDialog.saving) return
  userPasswordDialog.error = ''

  if (!userPasswordDialogForm.currentPassword || !userPasswordDialogForm.newPassword || !userPasswordDialogForm.confirmPassword) {
    userPasswordDialog.error = 'Semua field password wajib diisi.'
    return
  }
  if (userPasswordDialogForm.newPassword.length < 8) {
    userPasswordDialog.error = 'Password baru minimal 8 karakter.'
    return
  }
  if (userPasswordDialogForm.newPassword !== userPasswordDialogForm.confirmPassword) {
    userPasswordDialog.error = 'Konfirmasi password tidak sama.'
    return
  }

  userPasswordDialog.saving = true
  try {
    const targetName = userPasswordDialog.targetName
    const response = await business.auth.ResetUserPassword({
      id_user: userPasswordDialog.targetIdUser,
      current_password: userPasswordDialogForm.currentPassword,
      new_password: userPasswordDialogForm.newPassword,
      password_confirmation: userPasswordDialogForm.confirmPassword,
    }) as ApiEnvelope
    responseMessage.value = response.message || `Password ${targetName} berhasil diperbarui.`
    userPasswordDialog.open = false
    resetUserPasswordDialog()
  } catch (error) {
    userPasswordDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperbarui password user.'
  } finally {
    userPasswordDialog.saving = false
  }
}

const toMessage = (payload: unknown, fallback: string): string => {
  if (payload && typeof payload === 'object' && 'message' in payload) {
    const msg = String((payload as ApiEnvelope).message || '').trim()
    if (msg) return msg
  }

  return fallback
}

const toData = <T>(payload: unknown): T => {
  if (payload && typeof payload === 'object' && 'data' in payload) {
    return ((payload as ApiEnvelope<T>).data || {}) as T
  }

  return (payload || {}) as T
}

const toRows = (payload: unknown): Array<Record<string, unknown>> => {
  if (Array.isArray(payload)) {
    return payload.map(item =>
      item && typeof item === 'object'
        ? (item as Record<string, unknown>)
        : ({ value: item } as Record<string, unknown>),
    )
  }

  if (payload && typeof payload === 'object') {
    const objectPayload = payload as Record<string, unknown>
    const firstList = Object.values(objectPayload).find(value => Array.isArray(value))

    if (Array.isArray(firstList)) {
      return firstList.map(item =>
        item && typeof item === 'object'
          ? (item as Record<string, unknown>)
          : ({ value: item } as Record<string, unknown>),
      )
    }

    return [objectPayload]
  }

  if (payload === null || payload === undefined || payload === '') {
    return []
  }

  return [{ value: payload }]
}

const fetchByPath = async (path: string): Promise<unknown> => {
  switch (path) {
    case '/pencarian-dokumen':
      return business.pencarian.SearchData({ query: searchFilter.value })
    case '/peminjaman-minuta':
      return business.peminjamanMinuta.getPeminjamanMinuta({ date: monthFilter.value })
    case '/jadwal-notaris':
      return business.jadwal.getJadwalNotaris({ date: monthFilter.value })
    case '/perorangan':
      return business.client.getDataClient({ jenis_client: 'Perorangan' })
    case '/badan_hukum':
      return business.client.getDataClient({ jenis_client: 'Badan Hukum' })
    case '/data_layanan':
      return business.master.getDataLayanan()
    case '/data_dokumen':
      return business.master.getDataDokumen()
    case '/pages/report-settings':
      return business.settings.getReportSettings()
    case '/order_masuk':
      return business.order.getBukuPesanan({ date: monthFilter.value, status_order: 'Proses' })
    case '/order_selesai':
      return business.order.getBukuPesanan({ date: monthFilter.value, status_order: 'Selesai' })
    case '/invoice_tax':
      return business.order.getBukuPesanan({ date: monthFilter.value, status_order: 'Selesai', jenis_invoice: 'tax' })
    case '/invoice_non_tax':
      return business.order.getBukuPesanan({ date: monthFilter.value, status_order: 'Selesai', jenis_invoice: 'non tax' })
    case '/buku_akta':
      return business.bukuNotaris.getBukuNotaris({ date: monthFilter.value })
    case '/buku_legalisasi':
      return business.bukuLegalisasi.getBukuLegalisasi({ date: monthFilter.value })
    case '/buku_waarmerking':
      return business.bukuWarmerking.getBukuWarmerking({ date: monthFilter.value })
    case '/buku_ppat':
      return business.bukuPpat.getBukuPPAT({ date: monthFilter.value })
    case '/buku_surat_notaris':
      return business.surat.getBukuSuratNotaris({ date: monthFilter.value })
    case '/buku_surat_ppat':
      return business.surat.getBukuSuratPPAT({ date: monthFilter.value })
    case '/tanda_terima':
      return business.tandaTerima.getKeluar(monthFilter.value)
    case '/tanda_terima_masuk':
      return business.tandaTerima.getMasuk(monthFilter.value)
    case '/pages/account-settings':
      return business.auth.DataUser()
    default:
      return []
  }
}

const loadModuleData = async () => {
  if (!moduleEntry.value) {
    return
  }

  clearExpandedCells()
  clearExpandedRows()
  clearOrderExpandedRows()
  loading.value = true
  errorMessage.value = ''
  responseMessage.value = ''

  try {
    const payload = await fetchByPath(moduleEntry.value.path)
    rows.value = toRows(unwrapPayload(payload))
    if (isReportSettingsPage.value) {
      applyReportSettingsForm(rows.value[0] || null)
      await loadDatabaseBackupState()
    }
  } catch (error) {
    rows.value = []
    if (isReportSettingsPage.value) {
      resetReportSettingsForm()
      resetDatabaseBackupState()
    }
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat data dari backend.'
  } finally {
    loading.value = false
  }
}

const onReportoriumSaved = async (message?: string) => {
  if (message) {
    responseMessage.value = message
  }
  await loadModuleData()
}

const resetAktaMassalState = () => {
  aktaMassalError.value = ''
  aktaMassalMessage.value = ''
  aktaMassalPreview.value = null
}

const openAktaMassalDialog = () => {
  resetAktaMassalState()
  aktaMassalForm.period = monthFilter.value
  aktaMassalForm.tanggal_akta = `${monthFilter.value}-01`
  if (!aktaMassalForm.judul_pekerjaan.trim()) {
    aktaMassalForm.judul_pekerjaan = 'Akta Lampau'
  }
  aktaMassalDialogOpen.value = true
}

const closeAktaMassalDialog = () => {
  if (aktaMassalSubmitting.value || aktaMassalPreviewing.value) {
    return
  }

  if (userFormDialog.mode === 'edit' && !canManageUserCrud.value && userForm.id_user !== currentUserId.value) {
    userFormDialog.error = 'Anda hanya boleh mengubah akun sendiri.'
    return
  }
  aktaMassalDialogOpen.value = false
}

const aktaMassalPayload = () => ({
  period: aktaMassalForm.period,
  tanggal_akta: aktaMassalForm.tanggal_akta,
  judul_pekerjaan: aktaMassalForm.judul_pekerjaan,
  jumlah: Number(aktaMassalForm.jumlah || 0),
  nomor_mulai: aktaMassalForm.nomor_mulai ? Number(aktaMassalForm.nomor_mulai) : undefined,
  gunakan_nomor_di_judul: Boolean(aktaMassalForm.gunakan_nomor_di_judul),
})

const previewAktaMassal = async () => {
  resetAktaMassalState()
  if (!aktaMassalForm.tanggal_akta.startsWith(aktaMassalForm.period)) {
    aktaMassalError.value = 'Tanggal akta harus berada di bulan periode yang dipilih.'
    return
  }

  aktaMassalPreviewing.value = true
  try {
    const response = await business.bukuNotaris.PreviewAktaNotarisMassal(aktaMassalPayload()) as ApiEnvelope<AktaMassalPreview>
    aktaMassalPreview.value = toData<AktaMassalPreview>(response)
    aktaMassalMessage.value = toMessage(response, 'Preview akta massal berhasil dibuat.')
  } catch (error) {
    aktaMassalPreview.value = null
    aktaMassalError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal membuat preview akta massal.'
  } finally {
    aktaMassalPreviewing.value = false
  }
}

const saveAktaMassal = async () => {
  aktaMassalError.value = ''
  aktaMassalMessage.value = ''
  if (!aktaMassalPreview.value?.can_save) {
    aktaMassalError.value = 'Preview belum aman untuk disimpan. Jalankan preview dan pastikan tidak ada duplikat.'
    return
  }

  const confirmed = window.confirm(
    `Simpan ${aktaMassalPreview.value.jumlah || aktaMassalForm.jumlah} akta massal nomor ${aktaMassalPreview.value.nomor_mulai} - ${aktaMassalPreview.value.nomor_selesai}?`,
  )
  if (!confirmed) {
    return
  }

  aktaMassalSubmitting.value = true
  try {
    const response = await business.bukuNotaris.SimpanAktaNotarisMassal(aktaMassalPayload()) as ApiEnvelope<AktaMassalPreview>
    aktaMassalMessage.value = toMessage(response, 'Akta massal berhasil dibuat.')
    responseMessage.value = aktaMassalMessage.value
    aktaMassalDialogOpen.value = false
    await loadModuleData()
  } catch (error) {
    aktaMassalError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan akta massal.'
  } finally {
    aktaMassalSubmitting.value = false
  }
}

const reportUrl = computed(() => {
  switch (moduleEntry.value?.path) {
    case '/buku_akta':
      return business.reports.CetakLaporanNotaris(monthFilter.value)
    case '/buku_legalisasi':
      return business.reports.CetakLaporanLegalisasi(monthFilter.value)
    case '/buku_waarmerking':
      return business.reports.CetakLaporanWarmerking(monthFilter.value)
    case '/buku_ppat':
      return business.reports.CetakLaporanPPAT(monthFilter.value)
    default:
      return ''
  }
})

const reportLabel = computed(() => {
  if (!reportUrl.value) {
    return ''
  }

  return `Cetak Laporan ${moduleEntry.value?.shortTitle || ''}`
})

const canOpenReport = computed(() => Boolean(reportUrl.value) && !isArsipUser.value)

const reportoriumColumnsMap: Record<string, ReportoriumColumn[]> = {
  '/buku_akta': [
    { key: 'nama_akta', label: 'Jenis Akta Notaris' },
    { key: 'judul_pekerjaan', label: 'Judul Akta Notaris' },
    { key: 'tgl_akta', label: 'Tgl Akta Notaris' },
    { key: 'pengambil', label: 'Pengambil Nomor' },
    { key: 'no_akta', label: 'No Akta Notaris' },
  ],
  '/buku_legalisasi': [
    { key: 'judul_surat', label: 'Judul Surat' },
    { key: 'tgl_surat', label: 'Tanggal Surat' },
    { key: 'pengambil', label: 'Pengambil Nomor' },
    { key: 'no_legalisasi', label: 'No Legalisasi' },
  ],
  '/buku_waarmerking': [
    { key: 'judul_surat', label: 'Judul Surat' },
    { key: 'tgl_didaftarkan', label: 'Tanggal Permintaan' },
    { key: 'pengambil', label: 'Pengambil Nomor' },
    { key: 'no_warmerking', label: 'No Warmerking' },
  ],
  '/buku_ppat': [
    { key: 'nama_akta', label: 'Nama Akta' },
    { key: 'tanggal_akta', label: 'Tgl Akta' },
    { key: 'pengambil', label: 'Pengambil Nomor' },
    { key: 'no_akta', label: 'No Akta' },
  ],
  '/buku_surat_notaris': [
    { key: 'no_surat', label: 'No Surat' },
    { key: 'nama_lengkap', label: 'Pengirim' },
    { key: 'nama_client', label: 'Tujuan' },
    { key: 'created_at', label: 'Tanggal Dibuat' },
  ],
  '/buku_surat_ppat': [
    { key: 'no_surat', label: 'No Surat' },
    { key: 'nama_lengkap', label: 'Pengirim' },
    { key: 'nama_client', label: 'Tujuan' },
    { key: 'created_at', label: 'Tanggal Dibuat' },
  ],
  '/tanda_terima': [
    { key: 'nomor_tanda_terima', label: 'No Tanda Terima' },
    { key: 'nama_pengirim', label: 'Nama Pengirim' },
    { key: 'nama_penerima', label: 'Nama Penerima' },
    { key: 'pembuat', label: 'Pembuat' },
    { key: 'created_at', label: 'Tanggal' },
  ],
  '/tanda_terima_masuk': [
    { key: 'nomor_tanda_terima', label: 'No Tanda Terima' },
    { key: 'nama_pengirim', label: 'Nama Pengirim' },
    { key: 'nama_penerima', label: 'Nama Penerima' },
    { key: 'pembuat', label: 'Pembuat' },
    { key: 'created_at', label: 'Tanggal' },
  ],
}

const reportoriumActionMap: Record<string, ReportoriumAction[]> = {
  '/buku_akta': ['expand', 'edit', 'upload', 'delete'],
  '/buku_legalisasi': ['expand', 'edit', 'upload', 'delete'],
  '/buku_waarmerking': ['expand', 'edit', 'upload', 'delete'],
  '/buku_ppat': ['expand', 'edit', 'upload', 'delete'],
  '/buku_surat_notaris': ['edit', 'delete'],
  '/buku_surat_ppat': ['edit', 'delete'],
  '/tanda_terima': ['expand', 'edit', 'delete', 'print'],
  '/tanda_terima_masuk': ['expand', 'edit', 'delete', 'print'],
}

const reportoriumColumns = computed(() => reportoriumColumnsMap[moduleEntry.value?.path || ''] || [])
const reportoriumActions = computed(() => reportoriumActionMap[moduleEntry.value?.path || ''] || [])

const expandedRows = reactive<Record<string, boolean>>({})

const clearExpandedRows = () => {
  Object.keys(expandedRows).forEach((key) => {
    delete expandedRows[key]
  })
}

const rowIdentity = (row: RowRecord, index: number) =>
  String(
    row.id_buku_notaris
    || row.id_buku_legalisasi
    || row.id_buku_warmerking
    || row.id_buku_ppat
    || row.id_surat_notaris
    || row.id_surat_ppat
    || row.id_buku_surat_notaris
    || row.id_buku_surat_ppat
    || row.id_order
    || row.id_client
    || row.id
    || row.no_akta
    || row.no_order
    || index,
  )

const reportoriumRowKey = (row: RowRecord, index: number) =>
  `rp-${moduleEntry.value?.path || 'unknown'}-${rowIdentity(row, index)}-${index}`

const highlightedReportoriumRecordId = computed(() => String(route.query.record_id || '').trim())

const isHighlightedReportoriumRow = (row: RowRecord, index: number) =>
  Boolean(highlightedReportoriumRecordId.value && rowIdentity(row, index) === highlightedReportoriumRecordId.value)

const isPpatRekananKeluarRow = (row: RowRecord) =>
  moduleEntry.value?.path === '/buku_ppat' && Boolean(row.rekanan_keluar || row.ppat_rekanan_keluar_id)

const toggleRowExpand = (row: RowRecord, index: number) => {
  const key = reportoriumRowKey(row, index)
  expandedRows[key] = !expandedRows[key]
}

const isRowExpanded = (row: RowRecord, index: number) => Boolean(expandedRows[reportoriumRowKey(row, index)])

const resolveCellRawValue = (row: RowRecord, key: string): unknown => {
  if (key === 'pembuat') {
    const pembuat = row.pembuat as Record<string, unknown> | undefined
    return pembuat?.nama_lengkap || '-'
  }
  return row[key]
}

const formatDateOnly = (value: unknown) => {
  if (!value) return '-'
  const asString = String(value)
  const parsed = new Date(asString)
  if (Number.isNaN(parsed.getTime())) {
    return asString
  }
  return parsed.toISOString().slice(0, 10)
}

const formatReportoriumCell = (row: RowRecord, key: string) => {
  const raw = resolveCellRawValue(row, key)
  if (key === 'created_at' || key === 'tgl_akta' || key === 'tanggal_akta' || key === 'tgl_surat' || key === 'tgl_didaftarkan') {
    return formatDateOnly(raw)
  }
  if (raw === null || raw === undefined || raw === '') return '-'
  if (typeof raw === 'object') return '-'
  return String(raw)
}

const badgeColumns = ['no_akta', 'no_legalisasi', 'no_warmerking', 'no_surat', 'nomor_tanda_terima']
const isBadgeColumn = (key: string) => badgeColumns.includes(key)

const copyTextToClipboard = async (text: string) => {
  if (navigator?.clipboard?.writeText && window.isSecureContext) {
    await navigator.clipboard.writeText(text)
    return
  }

  const textArea = document.createElement('textarea')
  textArea.value = text
  textArea.setAttribute('readonly', '')
  textArea.style.position = 'fixed'
  textArea.style.left = '-9999px'
  document.body.appendChild(textArea)
  textArea.select()
  document.execCommand('copy')
  document.body.removeChild(textArea)
}

const copyReportoriumCell = async (row: RowRecord, key: string) => {
  const value = formatReportoriumCell(row, key)
  if (!value || value === '-') return

  try {
    await copyTextToClipboard(value)
    const label = key === 'no_surat'
      ? 'No surat'
      : key === 'nomor_tanda_terima'
        ? 'No tanda terima'
        : 'Data'
    responseMessage.value = `${label} berhasil disalin.`
  } catch {
    errorMessage.value = 'Gagal menyalin data.'
  }
}

const getPenghadapRows = (row: RowRecord) => {
  const candidates = [
    row.daftarpenghadap,
    row.penghadap,
    row.penghadap_notaris,
    row.penghadap_legalisasi,
    row.penghadap_warmerking,
  ]
  const list = candidates.find(item => Array.isArray(item))
  return Array.isArray(list) ? (list as RowRecord[]) : []
}

const hasAdditionalDetails = (row: RowRecord) => {
  if (getPenghadapRows(row).length > 0) return true
  if (moduleEntry.value?.path === '/buku_ppat') return true
  if (moduleEntry.value?.path === '/tanda_terima' || moduleEntry.value?.path === '/tanda_terima_masuk') return true
  return false
}

const extractLeadingNumber = (value: unknown) => {
  if (value === null || value === undefined) return 0
  const match = String(value).match(/\d+/)
  return match ? Number(match[0]) : 0
}

const deleteNumberFieldByPath: Record<string, string> = {
  '/buku_akta': 'no_akta',
  '/buku_legalisasi': 'no_legalisasi',
  '/buku_waarmerking': 'no_warmerking',
  '/buku_ppat': 'no_akta',
  '/buku_surat_notaris': 'no_surat',
  '/buku_surat_ppat': 'no_surat',
  '/tanda_terima': 'nomor_tanda_terima',
  '/tanda_terima_masuk': 'nomor_tanda_terima',
}

const maxDeleteNumber = computed(() => {
  const field = deleteNumberFieldByPath[moduleEntry.value?.path || '']
  if (!field || !rows.value.length) return 0
  return Math.max(
    ...rows.value
      .map(row => extractLeadingNumber(row[field]))
      .filter(value => Number.isFinite(value)),
  )
})

const rowOwnerId = (row: RowRecord) => {
  if (row.id_user) return String(row.id_user)
  if (row.pengirim) return String(row.pengirim)
  if (row.pembuat && typeof row.pembuat === 'object') {
    return String((row.pembuat as Record<string, unknown>).id_user || '')
  }
  if (row.pembuat) return String(row.pembuat)
  return ''
}

const canDeleteRow = (row: RowRecord) => {
  const path = moduleEntry.value?.path || ''
  const field = deleteNumberFieldByPath[path]
  if (!field) return false
  if (isSuperAdmin.value) return true
  return extractLeadingNumber(row[field]) === maxDeleteNumber.value
    && rowOwnerId(row) === String(user.value?.id_user || '')
}

const hasAction = (action: ReportoriumAction) => reportoriumActions.value.includes(action)

const suratBookPaths = ['/buku_surat_notaris', '/buku_surat_ppat']
const isSuratBookPath = (path: string) => suratBookPaths.includes(path)
const tandaTerimaPaths = ['/tanda_terima', '/tanda_terima_masuk']
const isTandaTerimaPath = (path: string) => tandaTerimaPaths.includes(path)

const isSuratBookModule = computed(() =>
  isSuratBookPath(moduleEntry.value?.path || ''),
)

const isStickyActionReportoriumModule = computed(() => {
  const path = moduleEntry.value?.path || ''
  return isSuratBookPath(path) || isTandaTerimaPath(path)
})

const reportoriumWrapperClass = computed(() =>
  isStickyActionReportoriumModule.value
    ? 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm'
    : 'overflow-hidden rounded-2xl border border-slate-200',
)

const reportoriumTableClass = computed(() =>
  isStickyActionReportoriumModule.value
    ? 'min-w-[980px] divide-y divide-slate-200'
    : 'min-w-full table-fixed divide-y divide-slate-200',
)

const reportoriumRowClass = computed(() =>
  isStickyActionReportoriumModule.value
    ? 'bg-white transition hover:bg-sky-50/40'
    : 'hover:bg-slate-50/70',
)

const reportoriumActionHeaderClass = computed(() =>
  isStickyActionReportoriumModule.value
    ? 'sticky right-0 z-10 w-[240px] bg-inherit px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600 shadow-[-10px_0_18px_-18px_rgba(15,23,42,0.7)]'
    : 'w-[220px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600',
)

const reportoriumActionCellClass = computed(() =>
  isStickyActionReportoriumModule.value
    ? 'sticky right-0 z-10 w-[240px] bg-inherit px-3 py-3 text-sm shadow-[-10px_0_18px_-18px_rgba(15,23,42,0.7)]'
    : 'px-3 py-3 text-sm',
)

const reportoriumColumnHeaderClass = (key: string) => {
  const base = 'px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600'
  if (!isStickyActionReportoriumModule.value) {
    return base
  }

  if (key === 'no_surat') return `w-[240px] ${base}`
  if (key === 'nomor_tanda_terima') return `w-[220px] ${base}`
  if (key === 'nama_lengkap') return `w-[190px] ${base}`
  if (key === 'nama_client') return `w-[250px] ${base}`
  if (key === 'nama_pengirim' || key === 'nama_penerima') return `w-[190px] ${base}`
  if (key === 'pembuat') return `w-[160px] ${base}`
  if (key === 'created_at') return `w-[140px] ${base}`

  return base
}

const reportoriumCellClass = (key: string) => {
  const base = 'px-3 py-3 text-sm text-slate-700 align-top'
  if (!isStickyActionReportoriumModule.value) {
    return `max-w-[220px] ${base}`
  }

  if (key === 'created_at') return `w-[140px] ${base}`
  if (key === 'no_surat') return `w-[240px] ${base}`
  if (key === 'nomor_tanda_terima') return `w-[220px] ${base}`

  return `max-w-[240px] ${base}`
}

const reportoriumValueClass = (key: string) => {
  if (!isStickyActionReportoriumModule.value) {
    return 'block truncate'
  }

  if (key === 'created_at') {
    return 'block whitespace-nowrap'
  }

  return 'block truncate'
}

const reportoriumExpandButtonClass = computed(() =>
  isStickyActionReportoriumModule.value
    ? 'inline-flex h-8 items-center rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 text-xs font-semibold text-indigo-700 transition hover:border-indigo-300 hover:bg-indigo-100'
    : 'rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50',
)

const reportoriumActionButtonClass = (action: 'edit' | 'upload' | 'delete' | 'print' | 'file') => {
  const base = 'inline-flex h-8 items-center rounded-lg border px-3 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50'

  if (action === 'edit') return `${base} border-blue-200 bg-blue-50 text-blue-700 hover:border-blue-300 hover:bg-blue-100`
  if (action === 'upload') return `${base} border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100`
  if (action === 'delete') return `${base} border-red-200 bg-red-50 text-red-700 hover:border-red-300 hover:bg-red-100`
  if (action === 'print') return `${base} border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100`

  return `${base} border-violet-200 bg-violet-50 text-violet-700 hover:border-violet-300 hover:bg-violet-100`
}

const orderColumnsMap: Record<OrderModulePath, OrderColumn[]> = {
  '/order_masuk': [
    { key: 'nama_pesanan', label: 'Nama Pesanan' },
    { key: 'id_order', label: 'No Order' },
    { key: 'nama_lengkap', label: 'Petugas' },
    { key: 'created_at', label: 'Tgl Order' },
  ],
  '/order_selesai': [
    { key: 'nama_pesanan', label: 'Nama Pesanan' },
    { key: 'nama_lengkap', label: 'Petugas' },
    { key: 'created_at', label: 'Tgl Order' },
    { key: 'no_inv', label: 'No Inv' },
    { key: 'jenis_invoice', label: 'Jenis Invoice' },
  ],
  '/invoice_tax': [
    { key: 'nama_pesanan', label: 'Nama Pesanan' },
    { key: 'nama_lengkap', label: 'Petugas' },
    { key: 'created_at', label: 'Tgl Order' },
    { key: 'no_inv', label: 'No Inv' },
    { key: 'status_invoice', label: 'Status Invoice' },
  ],
  '/invoice_non_tax': [
    { key: 'nama_pesanan', label: 'Nama Pesanan' },
    { key: 'nama_lengkap', label: 'Petugas' },
    { key: 'created_at', label: 'Tgl Order' },
    { key: 'no_inv', label: 'No Inv' },
    { key: 'status_invoice', label: 'Status Invoice' },
  ],
}

const orderActionsMap: Record<OrderModulePath, OrderAction[]> = {
  '/order_masuk': ['edit', 'complete'],
  '/order_selesai': ['invoice', 'detail'],
  '/invoice_tax': ['print', 'detail'],
  '/invoice_non_tax': ['print', 'detail'],
}

const orderRows = computed(() => filteredOrderRows.value as OrderRow[])
const orderColumns = computed(() => {
  const path = moduleEntry.value?.path as OrderModulePath | undefined
  return path ? orderColumnsMap[path] || [] : []
})
const orderActions = computed(() => {
  const path = moduleEntry.value?.path as OrderModulePath | undefined
  return path ? orderActionsMap[path] || [] : []
})
const hasOrderAction = (action: OrderAction) => {
  if (!orderActions.value.includes(action)) return false
  if ((action === 'invoice' || action === 'print') && !canManageInvoice.value) return false
  if (action === 'detail' && isInvoiceModule.value && !canManageInvoice.value) return false
  return true
}

const orderSearchQuery = ref('')
const invoiceStatusFilter = ref<'all' | 'lunas' | 'belum lunas' | 'belum bayar' | 'cancel'>('all')
const invoiceStatusFilterOptions: Array<{ value: 'all' | 'lunas' | 'belum lunas' | 'belum bayar' | 'cancel'; label: string }> = [
  { value: 'all', label: 'Semua Status' },
  { value: 'lunas', label: 'Lunas' },
  { value: 'belum lunas', label: 'Belum Lunas' },
  { value: 'belum bayar', label: 'Belum Bayar' },
  { value: 'cancel', label: 'Cancel' },
]
const normalizedOrderSearchQuery = computed(() => orderSearchQuery.value.trim().toLowerCase())
const filteredOrderRows = computed(() => {
  let list = rows.value as OrderRow[]

  if (normalizedOrderSearchQuery.value) {
    const keyword = normalizedOrderSearchQuery.value
    list = list.filter((row) => {
      const values = [
        row.id_order,
        row.nama_pesanan,
        row.nama_lengkap,
        row.created_at,
        row.no_inv,
        row.jenis_invoice,
        row.ket_noinv,
        row.keterangan_order,
        getInvoiceStatus(row),
      ]
      return values.some(value => String(value || '').toLowerCase().includes(keyword))
    })
  }

  if (isInvoiceModule.value && invoiceStatusFilter.value !== 'all') {
    list = list.filter(row => getInvoiceStatus(row).toLowerCase() === invoiceStatusFilter.value)
  }

  return list
})

const orderActionButtonClass = (action: OrderAction | 'save' | 'add' | 'remove') => {
  const base = 'inline-flex h-8 items-center rounded-lg border px-3 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50'
  if (action === 'edit') return `${base} border-blue-200 bg-blue-50 text-blue-700 hover:border-blue-300 hover:bg-blue-100`
  if (action === 'complete') return `${base} border-emerald-200 bg-emerald-50 text-emerald-700 hover:border-emerald-300 hover:bg-emerald-100`
  if (action === 'invoice') return `${base} border-violet-200 bg-violet-50 text-violet-700 hover:border-violet-300 hover:bg-violet-100`
  if (action === 'detail') return `${base} border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50`
  if (action === 'print') return `${base} border-amber-200 bg-amber-50 text-amber-700 hover:border-amber-300 hover:bg-amber-100`
  if (action === 'add') return `${base} border-indigo-200 bg-indigo-50 text-indigo-700 hover:border-indigo-300 hover:bg-indigo-100`
  if (action === 'remove') return `${base} border-red-200 bg-red-50 text-red-700 hover:border-red-300 hover:bg-red-100`
  return `${base} border-slate-900 bg-slate-950 text-white hover:bg-slate-800`
}

const orderBadgeClass = (status: string) => {
  const value = status.toLowerCase()
  if (value === 'lunas') return 'inline-flex min-w-[96px] items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700'
  if (value === 'belum lunas') return 'inline-flex min-w-[96px] items-center justify-center rounded-lg border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700'
  if (value === 'belum bayar' || value === 'cancel') return 'inline-flex min-w-[96px] items-center justify-center rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs font-semibold text-red-700'
  return 'inline-flex min-w-[96px] items-center justify-center rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-600'
}

const invoiceStatusFilterButtonClass = (value: 'all' | 'lunas' | 'belum lunas' | 'belum bayar' | 'cancel') => {
  const active = invoiceStatusFilter.value === value
  if (value === 'all') {
    return active
      ? 'rounded-lg border border-slate-300 bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white'
      : 'rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50'
  }
  if (value === 'lunas') {
    return active
      ? 'rounded-lg border border-emerald-300 bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white'
      : 'rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100'
  }
  if (value === 'belum lunas') {
    return active
      ? 'rounded-lg border border-amber-300 bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white'
      : 'rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 hover:bg-amber-100'
  }
  return active
    ? 'rounded-lg border border-red-300 bg-red-600 px-3 py-1.5 text-xs font-semibold text-white'
    : 'rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100'
}

const orderRowKey = (row: OrderRow, index: number) => String(row.id_order || row.id || index)
const orderExpandedRows = reactive<Record<string, boolean>>({})
const clearOrderExpandedRows = () => {
  Object.keys(orderExpandedRows).forEach((key) => {
    delete orderExpandedRows[key]
  })
}
const toggleOrderExpand = (row: OrderRow, index: number) => {
  const key = orderRowKey(row, index)
  orderExpandedRows[key] = !orderExpandedRows[key]
}
const isOrderExpanded = (row: OrderRow, index: number) => Boolean(orderExpandedRows[orderRowKey(row, index)])

const toCurrencyNumber = (value: unknown) => {
  const normalized = Number(String(value ?? '').replace(/[^0-9.-]/g, ''))
  return Number.isFinite(normalized) ? normalized : 0
}

const formatCurrency = (value: unknown) => {
  const amount = toCurrencyNumber(value)
  return `Rp ${new Intl.NumberFormat('id-ID').format(amount)}`
}

const getInvoiceStatus = (row: OrderRow) => {
  const invoices = Array.isArray(row.total_invoice) ? row.total_invoice : []
  const firstInvoice = invoices[0] as RowRecord | undefined
  const status = String(firstInvoice?.status_invoice || '').trim()
  return status || '-'
}

const formatOrderCell = (row: OrderRow, key: string) => {
  if (key === 'created_at') {
    return formatDateOnly(row.created_at)
  }
  if (key === 'status_invoice') {
    return getInvoiceStatus(row)
  }
  return String(row[key] || '-')
}

const emptyOrderDetailDraft = (): OrderDetailDraft => ({
  id_pekerjaan: '',
  jenis_pekerjaan: '',
  nama_pekerjaan: '',
  no_pekerjaan: '',
  tanggal_pekerjaan: '',
  pembuat: '',
  harga: 0,
})

const normalizeOrderDetail = (row: RowRecord): OrderDetailDraft => ({
  id_pekerjaan: (row.id_pekerjaan as string | number) || '',
  jenis_pekerjaan: String(row.jenis_pekerjaan || ''),
  nama_pekerjaan: String(row.nama_pekerjaan || ''),
  no_pekerjaan: String(row.no_pekerjaan || ''),
  tanggal_pekerjaan: String(row.tanggal_pekerjaan || ''),
  pembuat: String(row.pembuat || ''),
  harga: toCurrencyNumber(row.harga),
})

const orderDetailRowsFromOrder = (row: OrderRow) =>
  (Array.isArray(row.detail_order) ? row.detail_order : []).map(item => normalizeOrderDetail(item as RowRecord))

const orderEditorOpen = ref(false)
const orderEditorMode = ref<'create' | 'edit'>('create')
const orderEditorLoading = ref(false)
const orderEditorSaving = ref(false)
const assistantOptions = ref<AssistantOption[]>([])
const orderEditorForm = reactive({
  id_order: '' as string | number | '',
  nama_pesanan: '',
  id_user: '' as string | number | '',
  keterangan_order: '',
})

const loadAssistantOptions = async () => {
  if (assistantOptions.value.length) return
  orderEditorLoading.value = true
  try {
    const payload = await business.dashboard.getDaftarAsisten() as ApiEnvelope<AssistantOption[]>
    assistantOptions.value = Array.isArray(payload.data) ? payload.data : []
  } catch {
    assistantOptions.value = []
  } finally {
    orderEditorLoading.value = false
  }
}

const resetOrderEditorForm = () => {
  orderEditorForm.id_order = ''
  orderEditorForm.nama_pesanan = ''
  orderEditorForm.id_user = ''
  orderEditorForm.keterangan_order = ''
}

const openCreateOrderDialog = async () => {
  resetOrderEditorForm()
  orderEditorMode.value = 'create'
  orderEditorOpen.value = true
  await loadAssistantOptions()
}

const openEditOrderDialog = async (row: OrderRow) => {
  orderEditorMode.value = 'edit'
  orderEditorForm.id_order = (row.id_order as string | number) || ''
  orderEditorForm.nama_pesanan = String(row.nama_pesanan || '')
  orderEditorForm.id_user = (row.id_user as string | number) || ''
  orderEditorForm.keterangan_order = String(row.keterangan_order || '')
  orderEditorOpen.value = true
  await loadAssistantOptions()
}

const closeOrderEditorDialog = () => {
  if (orderEditorSaving.value) return
  orderEditorOpen.value = false
}

const saveOrderEditor = async () => {
  if (orderEditorSaving.value) return
  if (!orderEditorForm.nama_pesanan.trim() || !orderEditorForm.id_user) {
    errorMessage.value = 'Nama pesanan dan asisten wajib diisi.'
    return
  }

  orderEditorSaving.value = true
  errorMessage.value = ''
  try {
    const payload: Record<string, unknown> = {
      nama_pesanan: orderEditorForm.nama_pesanan.trim(),
      id_user: orderEditorForm.id_user,
      keterangan_order: orderEditorForm.keterangan_order.trim(),
    }
    if (orderEditorMode.value === 'edit') {
      payload.id_order = orderEditorForm.id_order
    }
    const response = await business.order.SimpanPesanan(payload) as ApiEnvelope
    responseMessage.value = response.message || 'Pesanan berhasil disimpan.'
    orderEditorOpen.value = false
    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan pesanan.'
  } finally {
    orderEditorSaving.value = false
  }
}

const orderWorkflowOpen = ref(false)
const orderWorkflowSaving = ref(false)
const orderWorkflowMode = ref<'complete' | 'invoice' | 'detail'>('detail')
const selectedOrder = ref<OrderRow | null>(null)
const orderDetailDraft = reactive<OrderDetailDraft>(emptyOrderDetailDraft())
const workflowDetails = ref<OrderDetailDraft[]>([])
const invoiceDraft = reactive({
  status_tax: false,
  status_diskon: false,
  nilai_diskon: 0,
  diskon: 0,
  tax: 0,
  grand_total: 0,
})

const resetInvoiceDraft = () => {
  invoiceDraft.status_tax = false
  invoiceDraft.status_diskon = false
  invoiceDraft.nilai_diskon = 0
  invoiceDraft.diskon = 0
  invoiceDraft.tax = 0
  invoiceDraft.grand_total = 0
}

const recalculateInvoiceDraft = () => {
  const subtotal = workflowDetails.value.reduce((total, item) => total + toCurrencyNumber(item.harga), 0)
  const discountValue = invoiceDraft.status_diskon ? subtotal * (toCurrencyNumber(invoiceDraft.nilai_diskon) / 100) : 0
  const subtotalAfterDiscount = subtotal - discountValue
  const taxValue = invoiceDraft.status_tax ? subtotalAfterDiscount * 0.25 : 0
  invoiceDraft.diskon = discountValue
  invoiceDraft.tax = taxValue
  invoiceDraft.grand_total = subtotalAfterDiscount + taxValue
}

const openOrderWorkflow = (row: OrderRow, mode: 'complete' | 'invoice' | 'detail') => {
  selectedOrder.value = row
  orderWorkflowMode.value = mode
  workflowDetails.value = orderDetailRowsFromOrder(row)
  Object.assign(orderDetailDraft, emptyOrderDetailDraft())
  resetInvoiceDraft()
  recalculateInvoiceDraft()
  orderWorkflowOpen.value = true
}

const closeOrderWorkflow = () => {
  if (orderWorkflowSaving.value) return
  orderWorkflowOpen.value = false
}

const addWorkflowDetail = () => {
  if (!orderDetailDraft.jenis_pekerjaan || !orderDetailDraft.nama_pekerjaan) {
    errorMessage.value = 'Jenis pekerjaan dan nama pekerjaan wajib diisi.'
    return
  }
  workflowDetails.value.push({
    id_pekerjaan: orderDetailDraft.id_pekerjaan || '',
    jenis_pekerjaan: orderDetailDraft.jenis_pekerjaan,
    nama_pekerjaan: orderDetailDraft.nama_pekerjaan,
    no_pekerjaan: orderDetailDraft.no_pekerjaan,
    tanggal_pekerjaan: orderDetailDraft.tanggal_pekerjaan,
    pembuat: orderDetailDraft.pembuat,
    harga: toCurrencyNumber(orderDetailDraft.harga),
  })
  Object.assign(orderDetailDraft, emptyOrderDetailDraft())
  recalculateInvoiceDraft()
}

const removeWorkflowDetail = (index: number) => {
  workflowDetails.value.splice(index, 1)
  recalculateInvoiceDraft()
}

const saveOrderWorkflow = async () => {
  if (orderWorkflowSaving.value || !selectedOrder.value) return
  if ((orderWorkflowMode.value === 'complete' || orderWorkflowMode.value === 'invoice') && !workflowDetails.value.length) {
    errorMessage.value = 'Daftar pekerjaan belum diisi.'
    return
  }
  if (orderWorkflowMode.value === 'invoice' && !canManageInvoice.value) {
    errorMessage.value = 'Akses ditolak. Hanya Admin yang boleh membuat invoice.'
    return
  }

  orderWorkflowSaving.value = true
  errorMessage.value = ''
  try {
    const payload: Record<string, unknown> = {
      data_order: selectedOrder.value,
      data_record: workflowDetails.value,
    }
    if (orderWorkflowMode.value === 'invoice') {
      payload.invoice = {
        id_order: selectedOrder.value.id_order,
        status_tax: invoiceDraft.status_tax,
        status_diskon: invoiceDraft.status_diskon,
        nilai_diskon: toCurrencyNumber(invoiceDraft.nilai_diskon),
        diskon: invoiceDraft.diskon,
        tax: invoiceDraft.tax,
        grand_total: invoiceDraft.grand_total,
      }
    }
    const response = await business.order.SimpanDetailOrder(payload) as ApiEnvelope
    responseMessage.value = response.message || 'Data order berhasil diperbarui.'
    orderWorkflowOpen.value = false
    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan detail order.'
  } finally {
    orderWorkflowSaving.value = false
  }
}

const invoiceStatusOpen = ref(false)
const invoiceStatusSaving = ref(false)
const invoiceStatusRow = ref<OrderRow | null>(null)
const invoiceStatusForm = reactive({
  ket_noinv: '',
  status_invoice: 'Belum Bayar',
})

const openInvoiceStatusDialog = (row: OrderRow) => {
  if (!canManageInvoice.value) {
    errorMessage.value = 'Akses ditolak. Hanya Admin yang boleh mengelola invoice.'
    return
  }
  invoiceStatusRow.value = row
  invoiceStatusForm.ket_noinv = String(row.ket_noinv || '')
  const currentStatus = getInvoiceStatus(row)
  invoiceStatusForm.status_invoice = currentStatus === '-' ? 'Belum Bayar' : currentStatus
  invoiceStatusOpen.value = true
}

const closeInvoiceStatusDialog = () => {
  if (invoiceStatusSaving.value) return
  invoiceStatusOpen.value = false
}

const saveInvoiceStatus = async () => {
  if (!invoiceStatusRow.value || invoiceStatusSaving.value) return
  invoiceStatusSaving.value = true
  errorMessage.value = ''
  try {
    const row = invoiceStatusRow.value
    const rawInvoices = Array.isArray(row.total_invoice) ? row.total_invoice : []
    const firstInvoice = { ...(rawInvoices[0] as RowRecord || {}), status_invoice: invoiceStatusForm.status_invoice }
    const payload: Record<string, unknown> = {
      ...row,
      ket_noinv: invoiceStatusForm.ket_noinv,
      total_invoice: [firstInvoice],
    }
    const response = await business.order.UpdateStatusInvoice(payload) as ApiEnvelope
    responseMessage.value = response.message || 'Status invoice berhasil diperbarui.'
    invoiceStatusOpen.value = false
    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperbarui status invoice.'
  } finally {
    invoiceStatusSaving.value = false
  }
}

const canCreateInvoice = (row: OrderRow) => canManageInvoice.value && !String(row.jenis_invoice || '').trim()

const onOrderAction = (action: OrderAction, row: OrderRow) => {
  if ((action === 'invoice' || action === 'print') && !canManageInvoice.value) {
    errorMessage.value = 'Akses ditolak. Hanya Admin yang boleh memproses invoice.'
    return
  }

  if (action === 'edit') {
    void openEditOrderDialog(row)
    return
  }
  if (action === 'complete') {
    openOrderWorkflow(row, 'complete')
    return
  }
  if (action === 'invoice') {
    openOrderWorkflow(row, 'invoice')
    return
  }
  if (action === 'detail') {
    if (isInvoiceModule.value) {
      openInvoiceStatusDialog(row)
      return
    }
    openOrderWorkflow(row, 'detail')
    return
  }
  if (action === 'print') {
    const url = resolveRowPrintUrl(row)
    if (url) openReport(url)
  }
}

const orderWorkflowTitle = computed(() => {
  if (orderWorkflowMode.value === 'complete') return 'Selesaikan Pesanan'
  if (orderWorkflowMode.value === 'invoice') return 'Buat Invoice Pesanan'
  return 'Detail Pesanan'
})

const filteredReportoriumRows = computed(() => {
  if (!isReportoriumModule.value) return rows.value
  if (!hasReportoriumSearch.value) return rows.value
  const keyword = reportoriumSearch.value.trim().toLowerCase()
  if (!keyword) return rows.value

  return rows.value.filter((row) => {
    const values: string[] = []
    const collectValues = (value: unknown) => {
      if (value === null || value === undefined) return
      if (Array.isArray(value)) {
        value.forEach(collectValues)
        return
      }
      if (typeof value === 'object') {
        Object.values(value as Record<string, unknown>).forEach(collectValues)
        return
      }
      values.push(String(value))
    }

    collectValues(row)
    return values.some(value => String(value || '').toLowerCase().includes(keyword))
  })
})

type StandardDocument = Record<string, unknown> & {
  nama_dokumen?: string
  nama_berkas?: string
}

type UploadConfigBasic = {
  mode: 'basic'
  title: string
  idField: string
  multiple: boolean
  fileField: 'dokumens[]' | 'dokumen'
  upload: (formData: FormData) => Promise<unknown>
}

type UploadConfigStandard = {
  mode: 'standard'
  title: string
  idField: string
  multiple: true
  fileField: 'dokumens[]'
  upload: (formData: FormData) => Promise<unknown>
  list: (payload: Record<string, unknown>) => Promise<unknown>
  update: (payload: Record<string, unknown>) => Promise<unknown>
  remove: (payload: Record<string, unknown>) => Promise<unknown>
  assetUrl: (fileName: string) => string
}

type UploadConfig = UploadConfigBasic | UploadConfigStandard

type ExcelUploadConfig = {
  title: string
  upload: (formData: FormData) => Promise<unknown>
}

type ExcelTemplateConfig = {
  title: string
  fileName: string
  headers: string[]
  sampleRow: Array<string | number>
}

const uploadDialog = reactive({
  open: false,
  submitting: false,
  loadingDocuments: false,
  documentActionLoading: false,
  title: '',
  error: '',
  config: null as UploadConfig | null,
  row: null as RowRecord | null,
  files: [] as File[],
  documents: [] as StandardDocument[],
  editingDocumentIndex: -1,
  editingDocumentName: '',
  previewUrl: '',
  previewTitle: '',
})

const reportPreviewDialog = reactive({
  open: false,
  url: '',
  title: '',
})

const uploadDialogHasStandardDocs = computed(() => uploadDialog.config?.mode === 'standard')

const excelUploadInputRef = ref<HTMLInputElement | null>(null)
const excelUploadState = reactive({
  submitting: false,
  error: '',
})

const getExcelUploadConfig = (): ExcelUploadConfig | null => {
  switch (moduleEntry.value?.path) {
    case '/buku_akta':
      return { title: 'Upload Excel Notaris', upload: business.bukuNotaris.UploadExcelNotaris }
    case '/buku_legalisasi':
      return { title: 'Upload Excel Legalisasi', upload: business.bukuLegalisasi.UploadExcelLegalisasi }
    case '/buku_waarmerking':
      return { title: 'Upload Excel Waarmerking', upload: business.bukuWarmerking.UploadExcelWarmerking }
    case '/buku_ppat':
      return { title: 'Upload Excel PPAT', upload: business.bukuPpat.UploadExcelPPAT }
    default:
      return null
  }
}

const canUploadExcel = computed(() => isSuperAdmin.value && Boolean(getExcelUploadConfig()))

const getExcelTemplateConfig = (): ExcelTemplateConfig | null => {
  switch (moduleEntry.value?.path) {
    case '/buku_akta':
      return {
        title: 'Template Excel Notaris',
        fileName: 'template-upload-buku-akta-notaris.xls',
        headers: ['id_sementara', 'no_akta', 'tgl_akta', 'judul_pekerjaan', 'nama_client'],
        sampleRow: ['TEMP001', 1, '2026-07-01', 'Akta Lampau No 1', 'Nama Client'],
      }
    case '/buku_legalisasi':
      return {
        title: 'Template Excel Legalisasi',
        fileName: 'template-upload-buku-legalisasi.xls',
        headers: ['no_legalisasi', 'tgl_surat', 'judul_surat', 'nama_client'],
        sampleRow: [1, '2026-07-01', 'Legalisasi Dokumen', 'Nama Client'],
      }
    case '/buku_waarmerking':
      return {
        title: 'Template Excel Waarmerking',
        fileName: 'template-upload-buku-waarmerking.xls',
        headers: ['no_warmerking', 'tgl_surat', 'tgl_didaftarkan', 'judul_surat', 'nama_client'],
        sampleRow: [1, '2026-07-01', '2026-07-01', 'Waarmerking Dokumen', 'Nama Client'],
      }
    case '/buku_ppat':
      return {
        title: 'Template Excel PPAT',
        fileName: 'template-upload-buku-ppat.xls',
        headers: [
          'no',
          'no_akta',
          'tanggal_akta',
          'bentuk_hukum',
          'pihak_mengalihkan',
          'pihak_menerima',
          'no_hak_milik',
          'tanah_bangunan',
          'luas_tanah',
          'bangunan',
          'harga_transaksi',
          'nop',
          'total_njop',
          'tgl_bphtb',
          'harga_bphtb',
          'tgl_pph',
          'harga_pph',
          'keterangan',
        ],
        sampleRow: [
          1,
          1,
          '2026-07-01',
          'Jual Beli',
          'Nama Pihak Mengalihkan',
          'Nama Pihak Menerima',
          'SHM 001',
          'Tanah dan Bangunan',
          100,
          80,
          100000000,
          '32.71.000.000.000-0000.0',
          100000000,
          '2026-07-01',
          5000000,
          '2026-07-01',
          2500000,
          'Keterangan',
        ],
      }
    default:
      return null
  }
}

const canDownloadExcelTemplate = computed(() => isSuperAdmin.value && Boolean(getExcelTemplateConfig()))

const escapeExcelCell = (value: string | number) => String(value)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')

const downloadExcelTemplate = () => {
  const config = getExcelTemplateConfig()
  if (!config || !import.meta.client) return

  const headerCells = config.headers
    .map((header) => `<th style="background:#dbeafe;border:1px solid #94a3b8;font-weight:bold;">${escapeExcelCell(header)}</th>`)
    .join('')
  const sampleCells = config.sampleRow
    .map((cell) => `<td style="border:1px solid #cbd5e1;">${escapeExcelCell(cell)}</td>`)
    .join('')
  const guideCells = config.headers
    .map(() => '<td style="border:1px solid #cbd5e1;"></td>')
    .join('')
  const workbook = `
    <html>
      <head>
        <meta charset="UTF-8" />
      </head>
      <body>
        <table>
          <tr>${headerCells}</tr>
          <tr>${sampleCells}</tr>
          <tr>${guideCells}</tr>
        </table>
      </body>
    </html>
  `.trim()

  const blob = new Blob([workbook], { type: 'application/vnd.ms-excel;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = config.fileName
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

const triggerExcelUploadSelect = () => {
  if (!canUploadExcel.value || excelUploadState.submitting) return
  excelUploadInputRef.value?.click()
}

const onExcelFileChange = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) {
    return
  }

  const config = getExcelUploadConfig()
  if (!config) {
    input.value = ''
    return
  }

  excelUploadState.error = ''
  excelUploadState.submitting = true

  try {
    const formData = new FormData()
    formData.append('files', file, file.name)
    const response = await config.upload(formData) as ApiEnvelope
    responseMessage.value = response.message || `${config.title} berhasil.`
    await loadModuleData()
  } catch (error) {
    excelUploadState.error = (error as { data?: { message?: string } })?.data?.message || 'Upload excel gagal.'
  } finally {
    excelUploadState.submitting = false
    input.value = ''
  }
}

const getUploadConfig = (): UploadConfig | null => {
  switch (moduleEntry.value?.path) {
    case '/buku_akta':
      return {
        mode: 'standard',
        title: 'Upload Dokumen Akta Notaris',
        idField: 'id_buku_notaris',
        multiple: true,
        fileField: 'dokumens[]',
        upload: business.dokumen.UploadDokumenNotaris,
        list: business.dokumen.StandarDokumenNotaris,
        update: business.dokumen.UpdateDokumenNotaris,
        remove: business.dokumen.DeleteDokumenNotaris,
        assetUrl: business.assets.berkasNotaris,
      }
    case '/buku_legalisasi':
      return {
        mode: 'standard',
        title: 'Upload Dokumen Legalisasi',
        idField: 'id_buku_legalisasi',
        multiple: true,
        fileField: 'dokumens[]',
        upload: business.dokumen.UploadDokumenLegalisasi,
        list: business.dokumen.StandarDokumenLegalisasis,
        update: business.dokumen.UpdateDokumenLegalisasi,
        remove: business.dokumen.DeleteDokumenLegalisasi,
        assetUrl: business.assets.berkasLegalisasi,
      }
    case '/buku_waarmerking':
      return {
        mode: 'standard',
        title: 'Upload Dokumen Waarmerking',
        idField: 'id_buku_warmerking',
        multiple: true,
        fileField: 'dokumens[]',
        upload: business.dokumen.UploadDokumenWarmerking,
        list: business.dokumen.StandarDokumenWarmerkings,
        update: business.dokumen.UpdateDokumenWarmerking,
        remove: business.dokumen.DeleteDokumenWarmerking,
        assetUrl: business.assets.berkasWarmerking,
      }
    case '/buku_ppat':
      return {
        mode: 'standard',
        title: 'Upload Dokumen PPAT',
        idField: 'id_buku_ppat',
        multiple: true,
        fileField: 'dokumens[]',
        upload: business.dokumen.UploadDokumenPPAT,
        list: business.dokumen.StandarDokumenPPAT,
        update: business.dokumen.UpdateDokumenPPAT,
        remove: business.dokumen.DeleteDokumenPPAT,
        assetUrl: business.assets.berkasPpat,
      }
    case '/buku_surat_notaris':
      return {
        mode: 'basic',
        title: 'Upload File Surat Notaris',
        idField: 'id_surat_notaris',
        multiple: false,
        fileField: 'dokumen',
        upload: business.surat.UploadSuratNotaris,
      }
    case '/buku_surat_ppat':
      return {
        mode: 'basic',
        title: 'Upload File Surat PPAT',
        idField: 'id_surat_ppat',
        multiple: false,
        fileField: 'dokumen',
        upload: business.surat.UploadSuratPPAT,
      }
    case '/tanda_terima':
    case '/tanda_terima_masuk':
      return {
        mode: 'basic',
        title: 'Upload Tanda Terima',
        idField: 'id',
        multiple: false,
        fileField: 'dokumen',
        upload: business.tandaTerima.UploadTandaTerima,
      }
    default:
      return null
  }
}

const uploadDialogIdValue = () => {
  if (!uploadDialog.config || !uploadDialog.row) return ''
  return String(uploadDialog.row[uploadDialog.config.idField] || '')
}

const standardDocumentIdFields = [
  'id_dokumen_notaris',
  'id_dokumen_legalisasi',
  'id_dokumen_warmerking',
  'id_dokumen_ppat',
  'id',
]

const standardDocumentKey = (doc: StandardDocument, index: number) => {
  for (const field of standardDocumentIdFields) {
    const value = doc[field]
    if (value !== null && value !== undefined && String(value) !== '') {
      return String(value)
    }
  }
  return `doc-${index}`
}

const parseStandardDocuments = (payload: unknown) => {
  if (Array.isArray(payload)) {
    return payload as StandardDocument[]
  }

  if (payload && typeof payload === 'object' && Array.isArray((payload as ApiEnvelope<StandardDocument[]>).data)) {
    return (payload as ApiEnvelope<StandardDocument[]>).data || []
  }

  return []
}

const loadUploadDocuments = async () => {
  if (!uploadDialog.config || uploadDialog.config.mode !== 'standard' || !uploadDialog.row) {
    uploadDialog.documents = []
    uploadDialog.previewUrl = ''
    uploadDialog.previewTitle = ''
    return
  }

  const id = uploadDialogIdValue()
  if (!id) {
    uploadDialog.documents = []
    uploadDialog.error = 'ID data tidak ditemukan.'
    uploadDialog.previewUrl = ''
    uploadDialog.previewTitle = ''
    return
  }

  uploadDialog.loadingDocuments = true
  uploadDialog.error = ''
  try {
    const payload = await uploadDialog.config.list({ id })
    uploadDialog.documents = parseStandardDocuments(payload)
    if (
      uploadDialog.previewUrl
      && !uploadDialog.documents.some((doc) => {
        const fileName = String(doc.nama_berkas || '').trim()
        if (!fileName) return false
        const url = uploadDialog.config && uploadDialog.config.mode === 'standard'
          ? uploadDialog.config.assetUrl(fileName)
          : ''
        return toIframePreviewUrl(url) === uploadDialog.previewUrl
      })
    ) {
      uploadDialog.previewUrl = ''
      uploadDialog.previewTitle = ''
    }
  } catch (error) {
    uploadDialog.documents = []
    uploadDialog.previewUrl = ''
    uploadDialog.previewTitle = ''
    uploadDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat dokumen.'
  } finally {
    uploadDialog.loadingDocuments = false
  }
}

const beginEditDocument = (index: number) => {
  const row = uploadDialog.documents[index]
  if (!row) return
  uploadDialog.editingDocumentIndex = index
  uploadDialog.editingDocumentName = String(row.nama_dokumen || '')
}

const cancelEditDocument = () => {
  uploadDialog.editingDocumentIndex = -1
  uploadDialog.editingDocumentName = ''
}

const saveDocumentName = async (index: number) => {
  if (!uploadDialog.config || uploadDialog.config.mode !== 'standard') return
  const row = uploadDialog.documents[index]
  if (!row) return

  const name = uploadDialog.editingDocumentName.trim()
  if (!name) {
    uploadDialog.error = 'Nama dokumen tidak boleh kosong.'
    return
  }

  uploadDialog.documentActionLoading = true
  uploadDialog.error = ''
  try {
    const response = await uploadDialog.config.update({
      ...row,
      nama_dokumen: name,
    }) as ApiEnvelope
    responseMessage.value = response.message || 'Nama dokumen berhasil diperbarui.'
    cancelEditDocument()
    await loadUploadDocuments()
  } catch (error) {
    uploadDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperbarui dokumen.'
  } finally {
    uploadDialog.documentActionLoading = false
  }
}

const deleteUploadDocument = async (index: number) => {
  if (!uploadDialog.config || uploadDialog.config.mode !== 'standard') return
  if (!import.meta.client) return
  const row = uploadDialog.documents[index]
  if (!row) return
  if (!window.confirm('Yakin ingin menghapus dokumen ini?')) return

  uploadDialog.documentActionLoading = true
  uploadDialog.error = ''
  try {
    const response = await uploadDialog.config.remove(row) as ApiEnvelope
    responseMessage.value = response.message || 'Dokumen berhasil dihapus.'
    await loadUploadDocuments()
    await loadModuleData()
  } catch (error) {
    uploadDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus dokumen.'
  } finally {
    uploadDialog.documentActionLoading = false
  }
}

const openUploadDocumentFile = (document: StandardDocument) => {
  if (!uploadDialog.config || uploadDialog.config.mode !== 'standard') return
  const fileName = String(document.nama_berkas || '').trim()
  if (!fileName) return
  const url = uploadDialog.config.assetUrl(fileName)
  if (!url) return
  const documentTitle = String(document.nama_dokumen || fileName || 'Dokumen')
  openReport(url, isBukuAktaModule.value ? `Preview Akta Notaris - ${documentTitle}` : `Preview Dokumen - ${documentTitle}`)
}

const openUploadDialog = async (row: RowRecord) => {
  const config = getUploadConfig()
  if (!config) {
    return
  }
  uploadDialog.error = ''
  uploadDialog.files = []
  uploadDialog.documents = []
  uploadDialog.loadingDocuments = false
  uploadDialog.documentActionLoading = false
  uploadDialog.previewUrl = ''
  uploadDialog.previewTitle = ''
  cancelEditDocument()
  uploadDialog.row = row
  uploadDialog.config = config
  uploadDialog.title = config.title
  uploadDialog.open = true
  if (config.mode === 'standard') {
    await loadUploadDocuments()
  }
}

const closeUploadDialog = () => {
  if (uploadDialog.submitting || uploadDialog.documentActionLoading) {
    return
  }
  uploadDialog.open = false
  uploadDialog.error = ''
  uploadDialog.files = []
  uploadDialog.documents = []
  uploadDialog.loadingDocuments = false
  uploadDialog.documentActionLoading = false
  uploadDialog.previewUrl = ''
  uploadDialog.previewTitle = ''
  cancelEditDocument()
  uploadDialog.row = null
  uploadDialog.config = null
}

const onUploadFileChange = (event: Event) => {
  const input = event.target as HTMLInputElement
  const files = input.files ? Array.from(input.files) : []
  uploadDialog.error = ''
  uploadDialog.files = files
}

const submitUpload = async () => {
  if (uploadDialog.submitting || !uploadDialog.config || !uploadDialog.row) {
    return
  }

  const config = uploadDialog.config
  const idValue = uploadDialog.row[config.idField]
  if (!idValue) {
    uploadDialog.error = 'ID data tidak ditemukan.'
    return
  }
  if (!uploadDialog.files.length) {
    uploadDialog.error = 'Pilih file terlebih dahulu.'
    return
  }

  uploadDialog.submitting = true
  uploadDialog.error = ''

  try {
    const formData = new FormData()
    if (config.multiple) {
      uploadDialog.files.forEach((file) => {
        formData.append(config.fileField, file, file.name)
      })
    } else {
      formData.append(config.fileField, uploadDialog.files[0] as File, uploadDialog.files[0]?.name)
    }
    formData.append(config.idField, String(idValue))
    const response = await config.upload(formData) as ApiEnvelope
    responseMessage.value = response.message || 'Upload berhasil.'
    uploadDialog.files = []
    if (config.mode === 'standard') {
      await loadUploadDocuments()
    } else {
      closeUploadDialog()
    }
    await loadModuleData()
  } catch (error) {
    uploadDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Upload gagal.'
  } finally {
    uploadDialog.submitting = false
  }
}

const editReportoriumRow = async (row: RowRecord) => {
  if (isArsipUser.value) return
  if (!reportoriumFormRef.value) {
    errorMessage.value = 'Form reportorium belum siap.'
    return
  }
  await reportoriumFormRef.value.openForEdit(row)
}

const canDeleteTandaTerima = (row: RowRecord) => {
  const pembuat = row.pembuat as Record<string, unknown> | undefined
  return isSuperAdmin.value || String(pembuat?.id_user || '') === String(user.value?.id_user || '')
}

const deleteReportoriumFile = async (row: RowRecord) => {
  const path = moduleEntry.value?.path || ''
  if (!import.meta.client) return

  const confirmed = window.confirm(path === '/tanda_terima' || path === '/tanda_terima_masuk'
    ? 'Yakin ingin menghapus file tanda terima ini?'
    : 'Yakin ingin menghapus file surat ini?')
  if (!confirmed) return

  try {
    let response: ApiEnvelope | undefined
    if (path === '/buku_surat_notaris') {
      response = await business.surat.DeleteSuratNotaris({ id_surat_notaris: row.id_surat_notaris }) as ApiEnvelope
    } else if (path === '/buku_surat_ppat') {
      response = await business.surat.DeleteSuratPPAT({ id_surat_ppat: row.id_surat_ppat }) as ApiEnvelope
    } else if (path === '/tanda_terima' || path === '/tanda_terima_masuk') {
      response = await business.tandaTerima.DeleteTandaTerima({ id: row.id }) as ApiEnvelope
    }

    responseMessage.value = response?.message || 'File berhasil dihapus.'
    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus file.'
  }
}

const deleteReportoriumRow = async (row: RowRecord) => {
  const path = moduleEntry.value?.path || ''
  if (!import.meta.client) return

  let confirmed = false
  if (path === '/buku_akta' || path === '/buku_ppat' || path === '/buku_legalisasi' || path === '/buku_waarmerking') {
    confirmed = window.confirm('Yakin ingin menghapus data ini? Data penghadap dan dokumen terkait juga akan dihapus.')
  } else if (path === '/buku_surat_notaris' || path === '/buku_surat_ppat') {
    confirmed = window.confirm('Yakin ingin menghapus data surat ini? File surat terkait juga akan dihapus.')
  } else if (path === '/tanda_terima' || path === '/tanda_terima_masuk') {
    if (!canDeleteRow(row)) {
      errorMessage.value = 'Hanya Super Admin atau pembuat data nomor terakhir yang dapat menghapus tanda terima.'
      return
    }
    confirmed = window.confirm('Yakin ingin menghapus tanda terima ini? Isi diterima dan file terkait juga akan dihapus.')
  }

  if (!confirmed) {
    return
  }

  try {
    let response: ApiEnvelope | undefined
    if (path === '/buku_akta') {
      response = await business.bukuNotaris.DeleteNomorNotaris(row) as ApiEnvelope
    } else if (path === '/buku_legalisasi') {
      response = await business.bukuLegalisasi.DeleteNomorLegalisasi(row) as ApiEnvelope
    } else if (path === '/buku_waarmerking') {
      response = await business.bukuWarmerking.DeleteNomorWarmerking(row) as ApiEnvelope
    } else if (path === '/buku_ppat') {
      response = await business.bukuPpat.DeleteNomorPPAT(row) as ApiEnvelope
    } else if (path === '/buku_surat_notaris') {
      response = await business.surat.DeleteNomorSuratNotaris({ id_surat_notaris: row.id_surat_notaris }) as ApiEnvelope
    } else if (path === '/buku_surat_ppat') {
      response = await business.surat.DeleteNomorSuratPPAT({ id_surat_ppat: row.id_surat_ppat }) as ApiEnvelope
    } else if (path === '/tanda_terima' || path === '/tanda_terima_masuk') {
      response = await business.tandaTerima.delete(row.id as string | number) as ApiEnvelope
    }

    responseMessage.value = response?.message || 'Data berhasil dihapus.'
    await loadModuleData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus data.'
  }
}

const onReportoriumAction = async (action: ReportoriumAction, row: RowRecord, index: number) => {
  if (action === 'expand') {
    toggleRowExpand(row, index)
    return
  }
  if (action === 'edit') {
    await editReportoriumRow(row)
    return
  }
  if (action === 'upload') {
    await openUploadDialog(row)
    return
  }
  if (action === 'delete') {
    await deleteReportoriumRow(row)
    return
  }
  if (action === 'print') {
    const url = resolveRowPrintUrl(row)
    if (url) {
      openReport(url)
    }
  }
}

const ppatDetailPairs = (row: RowRecord) => [
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
].filter((item) => {
  const value = item[1]
  return value !== null && value !== undefined && String(value) !== ''
})

const barcodeImageUrl = (row: RowRecord) => {
  const barcode = String(row.barcode || '').trim()
  return barcode ? `data:image/svg+xml;base64,${barcode}` : ''
}

const sanitizeFileNamePart = (value: unknown, fallback: string) => {
  const cleaned = String(value || '').trim().replace(/[^a-zA-Z0-9-_]+/g, '_')
  return cleaned || fallback
}

const downloadBarcode = (row: RowRecord) => {
  const barcode = String(row.barcode || '').trim()
  if (!barcode || !import.meta.client) return

  const link = window.document.createElement('a')
  const noAkta = sanitizeFileNamePart(row.no_akta, 'akta')
  const tanggal = sanitizeFileNamePart(row.tanggal_akta || row.tgl_akta, 'tanggal')
  link.href = `data:image/svg+xml;base64,${barcode}`
  link.download = `${noAkta}_${tanggal}.svg`
  link.click()
}

const columns = computed(() => {
  if (isOrderModule.value) {
    return orderColumns.value.map(column => column.key)
  }

  const firstRow = rows.value.at(0)

  if (!firstRow) {
    return []
  }

  return Object.keys(firstRow).slice(0, 10)
})

const resolveColumnLabel = (columnKey: string) => {
  if (!isOrderModule.value) return columnKey
  return orderColumns.value.find(column => column.key === columnKey)?.label || columnKey
}

const formatCell = (value: unknown) => {
  if (value === null || value === undefined || value === '') {
    return '-'
  }

  if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
    return String(value)
  }

  return JSON.stringify(value)
}

const rowKey = (row: Record<string, unknown>, index: number) => rowIdentity(row, index)

const expandedCells = reactive<Record<string, boolean>>({})

const clearExpandedCells = () => {
  Object.keys(expandedCells).forEach((key) => {
    delete expandedCells[key]
  })
}

const isExpandableValue = (value: unknown) => Array.isArray(value) || (value !== null && typeof value === 'object')

const toArrayItems = (value: unknown) => (Array.isArray(value) ? value : [])

const toObjectEntries = (value: unknown): Array<[string, unknown]> => {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return []
  }

  return Object.entries(value as Record<string, unknown>)
}

const expandedCellKey = (row: Record<string, unknown>, index: number, column: string) => `${rowKey(row, index)}:${column}`

const toggleCellExpand = (row: Record<string, unknown>, index: number, column: string) => {
  const key = expandedCellKey(row, index, column)
  expandedCells[key] = !expandedCells[key]
}

const isCellExpanded = (row: Record<string, unknown>, index: number, column: string) =>
  Boolean(expandedCells[expandedCellKey(row, index, column)])

const resolveRowPrintUrl = (row: Record<string, unknown>) => {
  const path = moduleEntry.value?.path || ''

  if (path === '/invoice_tax' || path === '/invoice_non_tax') {
    const id = row.id_order || row.id
    return id ? business.reports.CetakInvoice(id as string | number) : ''
  }

  if (path === '/tanda_terima' || path === '/tanda_terima_masuk') {
    const id = row.id
    return id ? business.reports.CetakTandaTerima(id as string | number) : ''
  }

  return ''
}

const openReport = (url: string, title?: string) => {
  if (!url) {
    return
  }
  reportPreviewDialog.url = toIframePreviewUrl(url)
  reportPreviewDialog.title = String(title || reportLabel.value || 'Preview Laporan')
  reportPreviewDialog.open = true
}

const closeReportPreviewDialog = () => {
  reportPreviewDialog.open = false
  reportPreviewDialog.url = ''
  reportPreviewDialog.title = ''
}

const assetRoot = computed(() =>
  String((runtimeConfig.public as { apiBase?: string; assetBase?: string }).assetBase || (runtimeConfig.public as { apiBase?: string }).apiBase || '')
    .replace(/\/api\/?$/, '')
    .replace(/\/$/, ''),
)

const resolveAssetUrl = (rawPath: unknown) => {
  const path = String(rawPath || '').trim()
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path
  if (!assetRoot.value) return ''
  return `${assetRoot.value}/${path.replace(/^\/+/, '')}`
}

const reportSettingsLogoUrl = computed(() => resolveAssetUrl(reportSettingsForm.logo_path))

const getFilePreviewUrl = (row: RowRecord) => {
  const fileName = String(row.file || '').trim()
  if (!fileName || !assetRoot.value) return ''
  const path = moduleEntry.value?.path || ''
  if (path === '/buku_surat_notaris') return `${assetRoot.value}/suratnotaris/${fileName}`
  if (path === '/buku_surat_ppat') return `${assetRoot.value}/suratppats/${fileName}`
  if (path === '/tanda_terima' || path === '/tanda_terima_masuk') return `${assetRoot.value}/tandaterima/${fileName}`
  return ''
}

const toIframePreviewUrl = (url: string) => {
  const raw = String(url || '').trim()
  if (!raw) return ''
  const withoutHash = raw.split('#')[0] || raw
  const isPdf = /\.pdf(\?|$)/i.test(withoutHash)
  if (!isPdf) return raw
  return `${withoutHash}#toolbar=0&navpanes=0&scrollbar=1&view=FitH`
}

const getFileIframePreviewUrl = (row: RowRecord) => toIframePreviewUrl(getFilePreviewUrl(row))

const resolveDownloadCategoryByPath = (path: string) => {
  if (path === '/buku_akta') return 'standard_notaris'
  if (path === '/buku_legalisasi') return 'standard_legalisasi'
  if (path === '/buku_waarmerking') return 'standard_waarmerking'
  if (path === '/buku_ppat') return 'standard_ppat'
  if (path === '/buku_surat_notaris') return 'surat_notaris'
  if (path === '/buku_surat_ppat') return 'surat_ppat'
  if (path === '/tanda_terima' || path === '/tanda_terima_masuk') return 'tanda_terima'
  return ''
}

const resolveReportoriumRowId = (row: RowRecord, path: string) => {
  if (path === '/buku_surat_notaris') return String(row.id_surat_notaris || '')
  if (path === '/buku_surat_ppat') return String(row.id_surat_ppat || '')
  if (path === '/tanda_terima' || path === '/tanda_terima_masuk') return String(row.id || '')
  return ''
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

const requestDownloadWithApproval = async (payload: Record<string, unknown>, fileName: string) => {
  if (downloadRequestLoading.value) return

  downloadRequestLoading.value = true
  errorMessage.value = ''
  try {
    const response = await business.documentAccess.requestDownload(payload) as ApiEnvelope<DownloadRequestPayload>
    const data = response.data || {}
    responseMessage.value = response.message || 'Request download diproses.'

    if (data.approved && data.request_id) {
      await downloadApprovedFile(data.request_id, fileName)
      responseMessage.value = 'Download berhasil diproses.'
    }
  } catch (error) {
    const rawMessage = (error as { data?: { message?: string }; message?: string })?.data?.message
      || (error as { message?: string })?.message
    errorMessage.value = rawMessage || 'Gagal memproses permintaan download.'
  } finally {
    downloadRequestLoading.value = false
  }
}

const requestReportoriumDownload = async (row: RowRecord) => {
  const path = moduleEntry.value?.path || ''
  const fileName = String(row.file || '').trim()
  if (!path || !fileName) return

  const category = resolveDownloadCategoryByPath(path)
  const rowId = resolveReportoriumRowId(row, path)
  if (!category || !rowId) {
    errorMessage.value = 'Data download tidak valid.'
    return
  }

  await requestDownloadWithApproval({
    module_path: path,
    row_id: rowId,
    file_name: fileName,
    file_category: category,
  }, fileName)
}

const requestStandardDocumentDownload = async (document: StandardDocument) => {
  if (!uploadDialog.config || uploadDialog.config.mode !== 'standard' || !uploadDialog.row) {
    return
  }

  const path = moduleEntry.value?.path || ''
  const fileName = String(document.nama_berkas || '').trim()
  const rowId = uploadDialogIdValue()
  const category = resolveDownloadCategoryByPath(path)

  if (!fileName || !rowId || !category) {
    uploadDialog.error = 'Data dokumen tidak valid untuk download.'
    return
  }

  uploadDialog.error = ''
  await requestDownloadWithApproval({
    module_path: path,
    row_id: rowId,
    file_name: fileName,
    file_category: category,
  }, fileName)
}

watch(
  [() => invoiceDraft.status_tax, () => invoiceDraft.status_diskon, () => invoiceDraft.nilai_diskon, workflowDetails],
  () => {
    if (orderWorkflowMode.value === 'invoice') {
      recalculateInvoiceDraft()
    }
  },
  { deep: true },
)

watch([masterSearch, masterPerPage], () => {
  masterPage.value = 1
})

watch(
  () => currentMasterTotalRows.value,
  () => {
    if (masterPage.value > currentMasterTotalPages.value) {
      masterPage.value = currentMasterTotalPages.value
    }
    if (masterPage.value < 1) {
      masterPage.value = 1
    }
  },
)

watch(
  () => [route.path, route.query.q, route.query.date, route.query.record_id] as const,
  () => {
    const queryKeyword = typeof route.query.q === 'string' ? route.query.q : ''
    const queryDate = typeof route.query.date === 'string' ? route.query.date : ''

    if (queryDate && hasDateFilter.value) {
      monthFilter.value = queryDate.slice(0, 7)
    }

    if (moduleEntry.value?.path !== '/pencarian-dokumen') {
      searchFilter.value = ''
    } else {
      searchFilter.value = queryKeyword
    }

    reportoriumSearch.value = hasReportoriumSearch.value ? queryKeyword : ''

    if (!isOrderModule.value) {
      orderSearchQuery.value = ''
      invoiceStatusFilter.value = 'all'
    }
    masterSearch.value = ''
    masterPage.value = 1
    closeMasterDetailDialog()
    closeUserFormDialog()
    closePasswordDialog()
    closeLayananFormDialog()
    closeDokumenFormDialog()
    reportSettingsError.value = ''
    loadModuleData()
  },
  { immediate: true },
)
</script>

<template>
  <div v-if="moduleEntry" class="space-y-6">
    <div class="space-y-2">
      <p class="display-kicker">{{ moduleEntry.section }}</p>
      <h2 class="text-balance font-display text-4xl text-slate-900 sm:text-5xl">
        {{ moduleEntry.title }}
      </h2>
      <p class="max-w-3xl text-base leading-7 text-slate-600">
        {{ moduleEntry.description }}
      </p>
    </div>

    <SurfaceCard v-if="!isMasterCrudModule" class="p-6 sm:p-7">
      <div class="flex flex-wrap items-end gap-3">
        <label v-if="hasDateFilter" class="flex flex-col gap-2">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Periode</span>
          <input
            v-model="monthFilter"
            type="month"
            class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
          />
        </label>

        <label v-if="hasSearchFilter" class="flex min-w-[220px] flex-1 flex-col gap-2">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Kata Kunci</span>
          <div class="group relative">
            <input
              v-model="searchFilter"
              type="text"
              placeholder="Nomor akta, nama, atau kata kunci lain"
              class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-10 text-sm font-medium text-slate-700 transition focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              @keyup.enter="loadModuleData"
            />
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.473 9.765l3.63 3.63a.75.75 0 1 0 1.06-1.06l-3.63-3.63A5.5 5.5 0 0 0 9 3.5Zm-4 5.5a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
            </svg>
            <button
              v-if="searchFilter"
              type="button"
              class="absolute right-2 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
              @click="searchFilter = ''"
            >
              x
            </button>
          </div>
        </label>

        <label v-if="hasReportoriumSearch" class="flex min-w-[220px] flex-1 flex-col gap-2">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Cari Data</span>
          <div class="group relative">
            <input
              v-model="reportoriumSearch"
              type="text"
              placeholder="Nomor, pengirim, penerima, atau pembuat"
              class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-10 text-sm font-medium text-slate-700 transition focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.473 9.765l3.63 3.63a.75.75 0 1 0 1.06-1.06l-3.63-3.63A5.5 5.5 0 0 0 9 3.5Zm-4 5.5a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
            </svg>
            <button
              v-if="reportoriumSearch"
              type="button"
              class="absolute right-2 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
              @click="reportoriumSearch = ''"
            >
              x
            </button>
          </div>
        </label>

        <label v-if="isOrderModule" class="flex min-w-[260px] flex-1 flex-col gap-2">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pencarian Cepat</span>
          <div class="group relative">
            <input
              v-model="orderSearchQuery"
              type="text"
              placeholder="Cari nama pesanan, no order, petugas, no invoice..."
              class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-10 text-sm font-medium text-slate-700 transition focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 group-focus-within:text-blue-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.473 9.765l3.63 3.63a.75.75 0 1 0 1.06-1.06l-3.63-3.63A5.5 5.5 0 0 0 9 3.5Zm-4 5.5a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd" />
            </svg>
            <button
              v-if="orderSearchQuery"
              type="button"
              class="absolute right-2 top-1/2 inline-flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
              @click="orderSearchQuery = ''"
            >
              x
            </button>
          </div>
        </label>

        <button
          type="button"
          class="h-11 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white transition hover:bg-slate-800"
          :disabled="loading"
          @click="loadModuleData"
        >
          {{ loading ? 'Memuat...' : 'Muat Data' }}
        </button>

        <button
          v-if="isOrderMasukModule"
          type="button"
          class="h-11 rounded-xl border border-blue-200 bg-blue-50 px-5 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100"
          @click="openCreateOrderDialog"
        >
          Buat Pesanan
        </button>

        <button
          v-if="canOpenReport"
          type="button"
          class="h-11 rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
          @click="openReport(reportUrl)"
        >
          {{ reportLabel }}
        </button>

        <button
          v-if="canDownloadExcelTemplate"
          type="button"
          class="h-11 rounded-xl border border-blue-200 bg-blue-50 px-5 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100"
          @click="downloadExcelTemplate"
        >
          Download Template
        </button>

        <button
          v-if="canUploadExcel"
          type="button"
          class="h-11 rounded-xl border border-slate-300 bg-white px-5 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="excelUploadState.submitting"
          @click="triggerExcelUploadSelect"
        >
          {{ excelUploadState.submitting ? 'Mengupload...' : 'Upload Excel' }}
        </button>

        <button
          v-if="canCreateAktaMassal"
          type="button"
          class="h-11 rounded-xl border border-violet-200 bg-violet-50 px-5 text-sm font-semibold text-violet-700 transition hover:border-violet-300 hover:bg-violet-100"
          @click="openAktaMassalDialog"
        >
          Buat Akta Notaris Massal
        </button>
        <input
          v-if="canUploadExcel"
          ref="excelUploadInputRef"
          type="file"
          accept=".xls,.xlsx"
          class="hidden"
          @change="onExcelFileChange"
        />
      </div>
      <p v-if="excelUploadState.error" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ excelUploadState.error }}
      </p>
      <div v-if="isInvoiceModule" class="mt-4 flex flex-wrap items-center gap-2">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Filter Status Invoice</p>
        <button
          v-for="option in invoiceStatusFilterOptions"
          :key="`invoice-filter-${option.value}`"
          type="button"
          :class="invoiceStatusFilterButtonClass(option.value)"
          @click="invoiceStatusFilter = option.value"
        >
          {{ option.label }}
        </button>
      </div>
      <p v-if="isOrderModule" class="mt-3 text-xs text-slate-500">
        Menampilkan {{ orderRows.length }} dari {{ rows.length }} data pesanan.
      </p>
    </SurfaceCard>

    <ReportoriumCreateForm
      v-if="isReportoriumModule"
      ref="reportoriumFormRef"
      :path="moduleEntry.path"
      @saved="onReportoriumSaved"
    />

    <Teleport to="body">
      <div v-if="aktaMassalDialogOpen" class="akta-massal-dialog fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-950/60" @click="closeAktaMassalDialog" />
        <div class="relative z-10 flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl">
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-6 py-5">
            <div>
              <p class="text-xs font-black uppercase tracking-[0.25em] text-violet-500">Super Admin</p>
              <h3 class="mt-1 text-2xl font-bold text-slate-950">Buat Akta Notaris Massal</h3>
              <p class="mt-1 text-sm text-slate-500">Membuat nomor akta berurutan untuk data lampau. Jika ada duplikat, seluruh proses ditolak.</p>
            </div>
            <button
              type="button"
              class="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="closeAktaMassalDialog"
            >
              Tutup
            </button>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto p-6">
            <div class="grid gap-4 lg:grid-cols-[0.9fr_1.1fr]">
              <div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <div class="grid gap-3 sm:grid-cols-2">
                  <label class="block">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Periode</span>
                    <input
                      v-model="aktaMassalForm.period"
                      type="month"
                      class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                    />
                  </label>
                  <label class="block">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Tanggal Akta Notaris</span>
                    <input
                      v-model="aktaMassalForm.tanggal_akta"
                      type="date"
                      class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                    />
                  </label>
                </div>

                <label class="block">
                  <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Judul Akta Notaris</span>
                  <input
                    v-model="aktaMassalForm.judul_pekerjaan"
                    type="text"
                    placeholder="Contoh: Akta Lampau"
                    class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                  />
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                  <label class="block">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Jumlah</span>
                    <input
                      v-model.number="aktaMassalForm.jumlah"
                      type="number"
                      min="1"
                      max="1000"
                      class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                    />
                  </label>
                  <label class="block">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Nomor Mulai Opsional</span>
                    <input
                      v-model="aktaMassalForm.nomor_mulai"
                      type="number"
                      min="1"
                      placeholder="Otomatis"
                      class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100"
                    />
                  </label>
                </div>

                <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-sm font-semibold text-slate-700">
                  <input v-model="aktaMassalForm.gunakan_nomor_di_judul" type="checkbox" class="mt-1 h-4 w-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500" />
                  <span>
                    Tambahkan nomor ke judul
                    <span class="block text-xs font-medium text-slate-500">Contoh: Akta Lampau No 101</span>
                  </span>
                </label>

                <div class="flex flex-wrap gap-2">
                  <button
                    type="button"
                    class="h-11 rounded-xl border border-violet-200 bg-white px-5 text-sm font-bold text-violet-700 transition hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="aktaMassalPreviewing || aktaMassalSubmitting"
                    @click="previewAktaMassal"
                  >
                    {{ aktaMassalPreviewing ? 'Membuat Preview...' : 'Preview' }}
                  </button>
                  <button
                    type="button"
                    class="h-11 rounded-xl bg-violet-600 px-5 text-sm font-bold text-white transition hover:bg-violet-500 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="aktaMassalSubmitting || !aktaMassalPreview?.can_save"
                    @click="saveAktaMassal"
                  >
                    {{ aktaMassalSubmitting ? 'Menyimpan...' : 'Simpan Massal' }}
                  </button>
                </div>
              </div>

              <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
                <div v-if="aktaMassalError" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
                  {{ aktaMassalError }}
                </div>
                <div v-if="aktaMassalMessage" class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                  {{ aktaMassalMessage }}
                </div>

                <div v-if="aktaMassalPreview" class="space-y-4">
                  <div class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Nomor Terakhir</p>
                      <p class="mt-1 text-2xl font-black text-slate-950">{{ aktaMassalPreview.nomor_terakhir }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Mulai</p>
                      <p class="mt-1 text-2xl font-black text-slate-950">{{ aktaMassalPreview.nomor_mulai }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Selesai</p>
                      <p class="mt-1 text-2xl font-black text-slate-950">{{ aktaMassalPreview.nomor_selesai }}</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                      <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Status</p>
                      <p class="mt-1 text-sm font-black" :class="aktaMassalPreview.can_save ? 'text-emerald-600' : 'text-red-600'">
                        {{ aktaMassalPreview.can_save ? 'Aman' : 'Duplikat' }}
                      </p>
                    </div>
                  </div>

                  <div v-if="aktaMassalPreview.duplicate_numbers?.length" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    Nomor duplikat: {{ aktaMassalPreview.duplicate_numbers.join(', ') }}
                  </div>

                  <div class="overflow-hidden rounded-2xl border border-slate-200">
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                      <p class="text-sm font-bold text-slate-800">Preview 25 Data Pertama</p>
                    </div>
                    <div class="max-h-72 overflow-auto">
                      <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="sticky top-0 bg-slate-100">
                          <tr>
                            <th class="px-4 py-2 text-left text-xs font-bold uppercase tracking-wider text-slate-500">No Akta Notaris</th>
                            <th class="px-4 py-2 text-left text-xs font-bold uppercase tracking-wider text-slate-500">Tanggal</th>
                            <th class="px-4 py-2 text-left text-xs font-bold uppercase tracking-wider text-slate-500">Judul</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                          <tr v-for="row in aktaMassalPreview.preview_rows" :key="`akta-massal-${row.no_akta}`">
                            <td class="px-4 py-2 font-bold text-slate-800">{{ row.no_akta }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ row.tgl_akta }}</td>
                            <td class="px-4 py-2 text-slate-700">{{ row.judul_pekerjaan }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <div v-else class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-sm text-slate-500">
                  Isi form lalu klik Preview untuk melihat range nomor yang akan dibuat.
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <SurfaceCard class="overflow-hidden p-0">
      <div class="border-b border-slate-100 bg-slate-50/80 px-6 py-4">
        <p class="text-sm font-semibold text-slate-800">Data Operasional</p>
        <p v-if="responseMessage" class="mt-1 text-xs text-slate-500">{{ responseMessage }}</p>
      </div>

      <div class="p-6">
        <div v-if="isAccountSettingsPage" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3">
          <div>
            <p class="text-sm font-semibold text-slate-800">Keamanan Akun Saya</p>
            <p class="mt-1 text-xs text-slate-500">Semua pengguna, termasuk Asisten, dapat mengganti password akun sendiri.</p>
          </div>
          <button type="button" :class="masterActionButtonClass('password')" @click="openPasswordDialog">
            Ganti Password Saya
          </button>
        </div>

        <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
          Memuat data dari backend...
        </div>

        <div v-else-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-700">
          {{ errorMessage }}
        </div>

        <div v-else-if="!rows.length && !isMasterCrudModule" class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
          Belum ada data untuk filter saat ini.
        </div>

        <div v-else-if="isAccountSettingsPage" class="space-y-4">
          <input
            ref="userPhotoUploadInputRef"
            type="file"
            class="hidden"
            accept=".jpg,.jpeg,.png,.webp"
            @change="onUserPhotoInputChange"
          />

          <div class="flex flex-wrap items-end justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
            <div class="space-y-1">
              <p class="text-sm font-semibold text-slate-800">{{ canManageUserCrud ? 'Daftar User' : 'Profil Saya' }}</p>
              <p class="text-xs text-slate-500">{{ canManageUserCrud ? 'Kelola akun user. Tambah dan hapus khusus Admin & Super Admin.' : 'Anda hanya dapat melihat dan mengubah data akun sendiri.' }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
              <label v-if="canManageUserCrud" class="w-[300px] max-w-full">
                <span class="sr-only">Cari User</span>
                <input
                  v-model="masterSearch"
                  type="text"
                  placeholder="Cari nama, email, role, phone, username..."
                  class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>
              <button type="button" :class="masterActionButtonClass('detail')" :disabled="loading" @click="loadModuleData">
                {{ loading ? 'Memuat...' : 'Refresh' }}
              </button>
              <button v-if="canManageUserCrud" type="button" :class="masterActionButtonClass('add')" @click="openCreateUserDialog">
                Tambah User
              </button>
            </div>
          </div>

          <div v-if="!filteredAccountRows.length" class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
            Tidak ada data user yang cocok.
          </div>

          <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-100/80">
                  <tr>
                    <th class="w-14 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nama Lengkap</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Role</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Phone</th>
                    <th v-if="canManageUserCrud" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">OTP Login</th>
                    <th class="w-[220px] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                  <tr v-for="(row, index) in paginatedAccountRows" :key="rowKey(row, index)" class="hover:bg-slate-50/70">
                    <td class="px-4 py-3 text-sm text-slate-600">{{ masterStartItem + index }}</td>
                    <td class="px-4 py-3 text-sm font-semibold text-slate-800">{{ userDisplayName(row) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">{{ formatCell(row.email) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">{{ formatCell(row.level_user) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">{{ formatCell(row.phone) }}</td>
                    <td v-if="canManageUserCrud" class="px-4 py-3 text-sm">
                      <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="row.login_otp_enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'">
                        {{ row.login_otp_enabled ? 'Aktif' : 'Nonaktif' }}
                      </span>
                    </td>
                    <td class="px-4 py-3 text-sm">
                      <div class="flex flex-wrap gap-2">
                        <button type="button" :class="masterActionButtonClass('detail')" @click="openMasterDetailDialog(row, `Detail User ${userDisplayName(row)}`)">
                          Detail
                        </button>
                        <button v-if="canEditUserRow(row)" type="button" :class="masterActionButtonClass('edit')" @click="openEditUserDialog(row)">
                          Edit
                        </button>
                        <button v-if="isSuperAdmin" type="button" :class="masterActionButtonClass('password')" @click="openUserPasswordDialog(row)">
                          Ganti Password
                        </button>
                        <button
                          v-if="canUploadPhotoRow(row)"
                          type="button"
                          :class="masterActionButtonClass('password')"
                          :disabled="isUploadingPhotoForRow(row)"
                          @click="openUploadUserPhoto(row)"
                        >
                          {{ isUploadingPhotoForRow(row) ? 'Uploading...' : 'Upload Foto' }}
                        </button>
                        <button v-if="canDeleteUserRow(row)" type="button" :class="masterActionButtonClass('delete')" @click="deleteUserRow(row)">
                          Hapus
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
              <p class="text-xs text-slate-600">Menampilkan {{ masterStartItem }} - {{ masterEndItem }} dari {{ filteredAccountRows.length }} data</p>
              <div class="flex items-center gap-2">
                <select v-model.number="masterPerPage" class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                  <option v-for="size in masterPerPageOptions" :key="`account-size-${size}`" :value="size">{{ size }}</option>
                </select>
                <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="masterPage <= 1" @click="masterPage -= 1">
                  Prev
                </button>
                <span class="px-2 text-xs font-semibold text-slate-700">{{ masterPage }} / {{ currentMasterTotalPages }}</span>
                <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="masterPage >= currentMasterTotalPages" @click="masterPage += 1">
                  Next
                </button>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="isReportSettingsPage" class="space-y-4">
          <input
            ref="reportSettingsLogoInputRef"
            type="file"
            class="hidden"
            accept=".jpg,.jpeg,.png,.webp"
            @change="onReportSettingsLogoInputChange"
          />

          <div class="flex flex-wrap items-end justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
            <div class="space-y-1">
              <p class="text-sm font-semibold text-slate-800">Pengaturan Identitas Laporan</p>
              <p class="text-xs text-slate-500">Satu sumber data untuk header laporan, tanda terima, penandatangan, dan rekening invoice.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" :class="masterActionButtonClass('detail')" :disabled="loading || reportSettingsSaving" @click="loadModuleData">
                {{ loading ? 'Memuat...' : 'Refresh' }}
              </button>
              <button
                type="button"
                :class="masterActionButtonClass('add')"
                :disabled="reportSettingsSaving || !canManageReportSettings"
                @click="saveReportSettings"
              >
                {{ reportSettingsSaving ? 'Menyimpan...' : 'Simpan Pengaturan' }}
              </button>
            </div>
          </div>

          <div v-if="reportSettingsError" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {{ reportSettingsError }}
          </div>

          <div v-if="databaseBackupError" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {{ databaseBackupError }}
          </div>

          <div v-if="!canManageReportSettings" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Halaman ini hanya bisa diubah oleh Admin atau Super Admin. Data tetap bisa dilihat untuk referensi.
          </div>

          <div class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
            <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
              <div>
                <p class="text-sm font-semibold text-slate-800">Header & Penandatangan</p>
                <p class="mt-1 text-xs text-slate-500">Dipakai di laporan bulanan, invoice, dan tanda terima.</p>
              </div>

              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                  <div class="space-y-1">
                    <p class="text-sm font-semibold text-slate-800">Logo Laporan</p>
                    <p class="text-xs text-slate-500">Upload logo kantor untuk header laporan. Format: JPG, PNG, atau WEBP.</p>
                  </div>
                  <button
                    type="button"
                    :class="masterActionButtonClass('edit')"
                    :disabled="!canManageReportSettings || reportSettingsLogoUploading"
                    @click="openReportSettingsLogoPicker"
                  >
                    {{ reportSettingsLogoUploading ? 'Uploading...' : reportSettingsLogoUrl ? 'Ganti Logo' : 'Upload Logo' }}
                  </button>
                </div>

                <div class="mt-4 flex min-h-[164px] items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-white p-4">
                  <img
                    v-if="reportSettingsLogoUrl"
                    :src="reportSettingsLogoUrl"
                    alt="Logo laporan"
                    class="max-h-32 w-auto"
                  />
                  <div v-else class="text-center text-sm text-slate-500">
                    Belum ada logo khusus. Sistem masih memakai logo default.
                  </div>
                </div>
              </div>

              <div class="grid gap-4 md:grid-cols-2">
                <label class="space-y-2 md:col-span-2">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Label Header</span>
                  <input
                    v-model="reportSettingsForm.header_label"
                    type="text"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    :disabled="!canManageReportSettings || reportSettingsSaving"
                    placeholder="KANTOR NOTARIS / PPAT"
                  />
                </label>

                <label class="space-y-2 md:col-span-2">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Nama Kantor / Notaris</span>
                  <input
                    v-model="reportSettingsForm.office_name"
                    type="text"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    :disabled="!canManageReportSettings || reportSettingsSaving"
                    placeholder="DEWANTARI HANDAYANI, S.H., M.P.A."
                  />
                </label>

                <label class="space-y-2 md:col-span-2">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Alamat Kantor</span>
                  <textarea
                    v-model="reportSettingsForm.office_address"
                    rows="4"
                    class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    :disabled="!canManageReportSettings || reportSettingsSaving"
                    placeholder="Alamat kantor yang tampil di laporan"
                  />
                </label>

                <label class="space-y-2">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Email Kantor</span>
                  <input
                    v-model="reportSettingsForm.office_email"
                    type="email"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    :disabled="!canManageReportSettings || reportSettingsSaving"
                    placeholder="email@kantor.com"
                  />
                </label>

                <label class="space-y-2">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Telepon Kantor</span>
                  <input
                    v-model="reportSettingsForm.office_phone"
                    type="text"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    :disabled="!canManageReportSettings || reportSettingsSaving"
                    placeholder="(021) 123 4567"
                  />
                </label>

                <label class="space-y-2">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Kota Tanda Tangan</span>
                  <input
                    v-model="reportSettingsForm.office_city"
                    type="text"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    :disabled="!canManageReportSettings || reportSettingsSaving"
                    placeholder="Jakarta"
                  />
                </label>

                <label class="space-y-2">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Jabatan Penandatangan</span>
                  <input
                    v-model="reportSettingsForm.signatory_title"
                    type="text"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    :disabled="!canManageReportSettings || reportSettingsSaving"
                    placeholder="Notaris / PPAT DKI Jakarta"
                  />
                </label>

                <label class="space-y-2 md:col-span-2">
                  <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Nama Penandatangan</span>
                  <input
                    v-model="reportSettingsForm.signatory_name"
                    type="text"
                    class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                    :disabled="!canManageReportSettings || reportSettingsSaving"
                    placeholder="Nama yang tampil di bagian tanda tangan"
                  />
                </label>
              </div>
            </div>

            <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
              <div>
                <p class="text-sm font-semibold text-slate-800">Rekening Invoice</p>
                <p class="mt-1 text-xs text-slate-500">Setiap kolom ditampilkan sebagai satu kotak rekening pada footer invoice.</p>
              </div>

              <label class="space-y-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rekening 1</span>
                <textarea
                  v-model="reportSettingsForm.invoice_bank_account_1"
                  rows="5"
                  class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                  :disabled="!canManageReportSettings || reportSettingsSaving"
                  placeholder="BANK MANDIRI&#10;Acc:...&#10;A/n ..."
                />
              </label>

              <label class="space-y-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rekening 2</span>
                <textarea
                  v-model="reportSettingsForm.invoice_bank_account_2"
                  rows="5"
                  class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                  :disabled="!canManageReportSettings || reportSettingsSaving"
                  placeholder="BANK BCA&#10;Acc:...&#10;A/n ..."
                />
              </label>

              <label class="space-y-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Rekening 3</span>
                <textarea
                  v-model="reportSettingsForm.invoice_bank_account_3"
                  rows="5"
                  class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100"
                  :disabled="!canManageReportSettings || reportSettingsSaving"
                  placeholder="BANK BRI&#10;Acc:...&#10;A/n ..."
                />
              </label>
            </div>
          </div>

          <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="space-y-1">
                <p class="text-sm font-semibold text-slate-800">Backup Database SQL</p>
                <p class="text-xs text-slate-500">Backup dibuat per bulan dalam format `.sql`. Backup yang lebih lama dari satu tahun dapat dibersihkan.</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <button type="button" :class="masterActionButtonClass('detail')" :disabled="databaseBackupLoading || loading" @click="loadDatabaseBackupState">
                  {{ databaseBackupLoading ? 'Memuat...' : 'Refresh Backup' }}
                </button>
                <button
                  type="button"
                  :class="masterActionButtonClass('add')"
                  :disabled="databaseBackupRunning || !canManageReportSettings"
                  @click="runDatabaseBackup"
                >
                  {{ databaseBackupRunning ? 'Memproses...' : 'Buat Backup Bulan Ini' }}
                </button>
                <button
                  type="button"
                  :class="masterActionButtonClass('delete')"
                  :disabled="databaseBackupPruning || !canManageReportSettings"
                  @click="pruneOldDatabaseBackups"
                >
                  {{ databaseBackupPruning ? 'Membersihkan...' : 'Hapus Backup > 1 Tahun' }}
                </button>
              </div>
            </div>

            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Frekuensi</p>
                <p class="mt-2 text-sm font-semibold text-slate-800">Bulanan</p>
                <p class="mt-1 text-xs text-slate-500">{{ databaseBackupSummary.policy.schedule_time || 'Tanggal 1 setiap bulan' }}</p>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Retensi</p>
                <p class="mt-2 text-sm font-semibold text-slate-800">{{ databaseBackupSummary.policy.retention_months }} Bulan</p>
                <p class="mt-1 text-xs text-slate-500">Backup di atas periode ini bisa dibersihkan.</p>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Backup</p>
                <p class="mt-2 text-sm font-semibold text-slate-800">{{ databaseBackupSummary.total_backups }} File</p>
                <p class="mt-1 text-xs text-slate-500">Total ukuran {{ databaseBackupSummary.total_size_human }}</p>
              </div>
              <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Backup Terakhir</p>
                <p class="mt-2 text-sm font-semibold text-slate-800">{{ databaseBackupSummary.last_backup?.created_at_label || 'Belum ada backup' }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ databaseBackupSummary.last_backup?.display_name || databaseBackupSummary.scheduler.next_run_at_label || 'Jadwal belum tersedia' }}</p>
              </div>
            </div>

            <div class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-900">
              <p class="font-semibold">Catatan otomatisasi</p>
              <p class="mt-1">
                Command terjadwal: <span class="font-mono">{{ databaseBackupSummary.scheduler.command }}</span>.
                Pastikan server lokal menjalankan <span class="font-mono">php artisan schedule:run</span> agar backup bulanan otomatis benar-benar berjalan.
              </p>
              <p v-if="databaseBackupSummary.policy.directory" class="mt-1">
                Lokasi file: <span class="font-mono">{{ databaseBackupSummary.policy.directory }}</span>
              </p>
            </div>

            <div v-if="!databaseBackupRows.length" class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">
              Belum ada file backup SQL. Gunakan tombol <strong>Buat Backup Bulan Ini</strong> untuk membuat file pertama.
            </div>

            <div v-else class="overflow-hidden rounded-2xl border border-slate-200">
              <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                  <thead class="bg-slate-100/80">
                    <tr>
                      <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">File</th>
                      <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Periode</th>
                      <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Dibuat</th>
                      <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Ukuran</th>
                      <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Status</th>
                      <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100 bg-white">
                    <tr v-for="backup in databaseBackupRows" :key="backup.file_name" class="align-top">
                      <td class="px-4 py-3 text-sm text-slate-700">
                        <div class="font-medium text-slate-800">{{ backup.display_name }}</div>
                        <div class="mt-1 text-xs text-slate-500">.sql</div>
                      </td>
                      <td class="px-4 py-3 text-sm text-slate-700">{{ backup.month_key || '-' }}</td>
                      <td class="px-4 py-3 text-sm text-slate-700">{{ backup.created_at_label || '-' }}</td>
                      <td class="px-4 py-3 text-sm text-slate-700">{{ backup.size_human || '0 B' }}</td>
                      <td class="px-4 py-3 text-sm text-slate-700">
                        <span
                          class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                          :class="backup.eligible_for_prune ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'"
                        >
                          {{ backup.eligible_for_prune ? 'Bisa dihapus' : 'Aktif disimpan' }}
                        </span>
                      </td>
                      <td class="px-4 py-3 text-sm text-slate-700">
                        <button
                          type="button"
                          :class="masterActionButtonClass('detail')"
                          :disabled="databaseBackupDownloading === backup.file_name"
                          @click="downloadDatabaseBackup(backup.file_name)"
                        >
                          {{ databaseBackupDownloading === backup.file_name ? 'Mengunduh...' : 'Download SQL' }}
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="isMasterLayananModule" class="space-y-4">
          <div class="flex flex-wrap items-end justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
            <div>
              <p class="text-sm font-semibold text-slate-800">Master Layanan</p>
              <p class="text-xs text-slate-500">Kelola layanan dengan dialog create/edit agar tabel tetap ringkas.</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
              <label class="w-[300px] max-w-full">
                <span class="sr-only">Cari Layanan</span>
                <input
                  v-model="masterSearch"
                  type="text"
                  placeholder="Cari id layanan, nama layanan, pekerjaan..."
                  class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>
              <button type="button" :class="masterActionButtonClass('detail')" :disabled="loading" @click="loadModuleData">
                {{ loading ? 'Memuat...' : 'Refresh' }}
              </button>
              <button type="button" :class="masterActionButtonClass('add')" @click="openCreateLayananDialog">
                Tambah Layanan
              </button>
            </div>
          </div>

          <div v-if="!filteredLayananRows.length" class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
            Tidak ada data layanan yang cocok.
          </div>

          <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-100/80">
                  <tr>
                    <th class="w-14 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">ID Layanan</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nama Layanan</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Pekerjaan Milik</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">APHT</th>
                    <th class="w-[240px] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                  <tr v-for="(row, index) in paginatedLayananRows" :key="rowKey(row, index)" class="hover:bg-slate-50/70">
                    <td class="px-4 py-3 text-sm text-slate-600">{{ masterStartItem + index }}</td>
                    <td class="px-4 py-3 text-sm font-semibold text-slate-800">{{ formatCell(row.id_akta) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">{{ formatCell(row.nama_akta) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">{{ formatCell(row.pekerjaan_milik) }}</td>
                    <td class="px-4 py-3 text-sm">
                      <span :class="toMasterBadgeClass(row.apht)">
                        {{ String(row.apht || 'FALSE').toUpperCase() }}
                      </span>
                    </td>
                    <td class="px-4 py-3 text-sm">
                      <div class="flex flex-wrap gap-2">
                        <button type="button" :class="masterActionButtonClass('detail')" @click="openMasterDetailDialog(row, `Detail Layanan ${formatCell(row.nama_akta)}`)">
                          Detail
                        </button>
                        <button type="button" :class="masterActionButtonClass('edit')" @click="openEditLayananDialog(row)">
                          Edit
                        </button>
                        <button type="button" :class="masterActionButtonClass('delete')" @click="deleteLayananRow(row)">
                          Hapus
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
              <p class="text-xs text-slate-600">Menampilkan {{ masterStartItem }} - {{ masterEndItem }} dari {{ filteredLayananRows.length }} data</p>
              <div class="flex items-center gap-2">
                <select v-model.number="masterPerPage" class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                  <option v-for="size in masterPerPageOptions" :key="`layanan-size-${size}`" :value="size">{{ size }}</option>
                </select>
                <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="masterPage <= 1" @click="masterPage -= 1">
                  Prev
                </button>
                <span class="px-2 text-xs font-semibold text-slate-700">{{ masterPage }} / {{ currentMasterTotalPages }}</span>
                <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="masterPage >= currentMasterTotalPages" @click="masterPage += 1">
                  Next
                </button>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="isMasterDokumenModule" class="space-y-4">
          <div class="flex flex-wrap items-end justify-between gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
            <div>
              <p class="text-sm font-semibold text-slate-800">Master Dokumen</p>
              <p class="text-xs text-slate-500">Kelola dokumen standar dari dialog create/edit agar data tetap rapi.</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
              <label class="w-[300px] max-w-full">
                <span class="sr-only">Cari Dokumen</span>
                <input
                  v-model="masterSearch"
                  type="text"
                  placeholder="Cari id dokumen atau nama dokumen..."
                  class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>
              <button type="button" :class="masterActionButtonClass('detail')" :disabled="loading" @click="loadModuleData">
                {{ loading ? 'Memuat...' : 'Refresh' }}
              </button>
              <button type="button" :class="masterActionButtonClass('add')" @click="openCreateDokumenDialog">
                Tambah Dokumen
              </button>
            </div>
          </div>

          <div v-if="!filteredDokumenRows.length" class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
            Tidak ada data dokumen yang cocok.
          </div>

          <div v-else class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-100/80">
                  <tr>
                    <th class="w-14 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">ID Dokumen</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nama Dokumen</th>
                    <th class="w-[220px] px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 bg-white">
                  <tr v-for="(row, index) in paginatedDokumenRows" :key="rowKey(row, index)" class="hover:bg-slate-50/70">
                    <td class="px-4 py-3 text-sm text-slate-600">{{ masterStartItem + index }}</td>
                    <td class="px-4 py-3 text-sm font-semibold text-slate-800">{{ formatCell(row.id_dokumen) }}</td>
                    <td class="px-4 py-3 text-sm text-slate-700">{{ formatCell(row.nama_dokumen) }}</td>
                    <td class="px-4 py-3 text-sm">
                      <div class="flex flex-wrap gap-2">
                        <button type="button" :class="masterActionButtonClass('detail')" @click="openMasterDetailDialog(row, `Detail Dokumen ${formatCell(row.nama_dokumen)}`)">
                          Detail
                        </button>
                        <button type="button" :class="masterActionButtonClass('edit')" @click="openEditDokumenDialog(row)">
                          Edit
                        </button>
                        <button type="button" :class="masterActionButtonClass('delete')" @click="deleteDokumenRow(row)">
                          Hapus
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-4 py-3">
              <p class="text-xs text-slate-600">Menampilkan {{ masterStartItem }} - {{ masterEndItem }} dari {{ filteredDokumenRows.length }} data</p>
              <div class="flex items-center gap-2">
                <select v-model.number="masterPerPage" class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                  <option v-for="size in masterPerPageOptions" :key="`dokumen-size-${size}`" :value="size">{{ size }}</option>
                </select>
                <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="masterPage <= 1" @click="masterPage -= 1">
                  Prev
                </button>
                <span class="px-2 text-xs font-semibold text-slate-700">{{ masterPage }} / {{ currentMasterTotalPages }}</span>
                <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="masterPage >= currentMasterTotalPages" @click="masterPage += 1">
                  Next
                </button>
              </div>
            </div>
          </div>
        </div>

        <div v-else-if="isReportoriumModule && !filteredReportoriumRows.length" class="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
          Tidak ada data yang cocok dengan pencarian.
        </div>

        <div v-else-if="isReportoriumModule" :class="reportoriumWrapperClass">
          <div class="overflow-x-auto">
            <table :class="reportoriumTableClass">
            <thead class="bg-slate-100/80">
              <tr>
                <th class="w-14 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                <th v-if="hasAction('expand')" class="w-16 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Detail</th>
                <th
                  v-for="column in reportoriumColumns"
                  :key="column.key"
                  :class="reportoriumColumnHeaderClass(column.key)"
                >
                  {{ column.label }}
                </th>
                <th :class="reportoriumActionHeaderClass">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
              <template v-for="(row, index) in filteredReportoriumRows" :key="reportoriumRowKey(row, index)">
                <tr
                  :class="[
                    reportoriumRowClass,
                    isHighlightedReportoriumRow(row, index) ? 'ring-2 ring-amber-400 bg-amber-50/80 shadow-inner' : '',
                    isPpatRekananKeluarRow(row) ? 'bg-amber-50/80 hover:bg-amber-100/80' : '',
                  ]"
                >
                  <td class="px-3 py-3 text-sm text-slate-600">{{ index + 1 }}</td>
                  <td v-if="hasAction('expand')" class="px-3 py-3">
                    <button
                      v-if="hasAdditionalDetails(row)"
                      type="button"
                      :class="reportoriumExpandButtonClass"
                      @click="onReportoriumAction('expand', row, index)"
                    >
                      {{ isRowExpanded(row, index) ? 'Tutup' : 'Expand' }}
                    </button>
                    <span v-else class="text-xs text-slate-400">-</span>
                  </td>
                  <td
                    v-for="column in reportoriumColumns"
                    :key="`${reportoriumRowKey(row, index)}-${column.key}`"
                    :class="reportoriumCellClass(column.key)"
                  >
                    <button
                      v-if="column.key === 'no_surat' || column.key === 'nomor_tanda_terima'"
                      type="button"
                      class="inline-flex min-w-[86px] items-center justify-center rounded-lg bg-blue-50 px-2 py-1 text-left text-xs font-semibold text-blue-700 transition hover:bg-blue-100 hover:text-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-200"
                      :title="`Klik untuk copy: ${formatReportoriumCell(row, column.key)}`"
                      @click="copyReportoriumCell(row, column.key)"
                    >
                      {{ formatReportoriumCell(row, column.key) }}
                    </button>
                    <template v-else-if="isBadgeColumn(column.key)">
                      <span
                        class="inline-flex min-w-[86px] items-center justify-center rounded-lg px-2 py-1 text-xs font-semibold"
                        :class="isPpatRekananKeluarRow(row) && column.key === 'no_akta' ? 'bg-amber-100 text-amber-800 ring-1 ring-amber-300' : 'bg-blue-50 text-blue-700'"
                      >
                        {{ formatReportoriumCell(row, column.key) }}
                      </span>
                      <p
                        v-if="isPpatRekananKeluarRow(row) && column.key === 'no_akta'"
                        class="mt-1 text-[11px] font-bold text-amber-700"
                      >
                        Dipakai rekanan: {{ row.nama_ppat_rekanan || '-' }}
                      </p>
                    </template>
                    <span v-else :class="reportoriumValueClass(column.key)" :title="formatReportoriumCell(row, column.key)">
                      {{ formatReportoriumCell(row, column.key) }}
                    </span>
                  </td>
                  <td :class="reportoriumActionCellClass">
                    <div class="flex flex-wrap gap-2">
                      <button
                        v-if="hasAction('edit')"
                        type="button"
                        :class="reportoriumActionButtonClass('edit')"
                        :disabled="isArsipUser"
                        @click="onReportoriumAction('edit', row, index)"
                      >
                        Edit
                      </button>
                      <template v-if="isSuratBookPath(moduleEntry.path)">
                        <button
                          type="button"
                          :class="reportoriumActionButtonClass('upload')"
                          :disabled="isArsipUser"
                          @click="openUploadDialog(row)"
                        >
                          {{ row.file ? 'Upload Ulang Surat' : 'Upload Surat' }}
                        </button>
                        <button
                          v-if="getFilePreviewUrl(row)"
                          type="button"
                          :class="reportoriumActionButtonClass('file')"
                          @click="openReport(getFilePreviewUrl(row), `Preview ${formatCell(row.file)}`)"
                        >
                          Lihat
                        </button>
                        <button
                          v-if="row.file"
                          type="button"
                          :class="reportoriumActionButtonClass('print')"
                          :disabled="downloadRequestLoading"
                          @click="requestReportoriumDownload(row)"
                        >
                          {{ downloadRequestLoading ? 'Memproses...' : 'Download' }}
                        </button>
                        <span
                          v-else
                          class="inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700"
                        >
                          Tolong upload suratnya
                        </span>
                      </template>
                      <button
                        v-if="hasAction('upload')"
                        type="button"
                        :class="reportoriumActionButtonClass('upload')"
                        :disabled="isArsipUser"
                        @click="onReportoriumAction('upload', row, index)"
                      >
                        Upload
                      </button>
                      <button
                        v-if="hasAction('delete') && canDeleteRow(row)"
                        type="button"
                        :class="reportoriumActionButtonClass('delete')"
                        @click="onReportoriumAction('delete', row, index)"
                      >
                        Hapus
                      </button>
                      <button
                        v-if="hasAction('print')"
                        type="button"
                        :class="reportoriumActionButtonClass('print')"
                        @click="onReportoriumAction('print', row, index)"
                      >
                        Cetak
                      </button>
                    </div>
                  </td>
                </tr>

                <tr v-if="isRowExpanded(row, index)" class="bg-slate-50/60">
                  <td colspan="100%" class="px-3 py-4">
                    <div class="space-y-4">
                      <div v-if="getPenghadapRows(row).length" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                        <div class="border-b border-slate-100 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-600">
                          Data Penghadap
                        </div>
                        <div class="overflow-x-auto">
                          <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-100/70">
                              <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">Nama Penghadap</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">No Identitas</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">Jenis Client</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">Status Kedudukan</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-slate-600">Mewakili</th>
                              </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                              <tr v-for="(penghadap, pIndex) in getPenghadapRows(row)" :key="`${reportoriumRowKey(row, index)}-p-${pIndex}`">
                                <td class="px-3 py-2 text-xs text-slate-700">{{ formatCell(penghadap.nama_client) }}</td>
                                <td class="px-3 py-2 text-xs text-slate-700">{{ formatCell(penghadap.no_identitas) }}</td>
                                <td class="px-3 py-2 text-xs text-slate-700">{{ formatCell(penghadap.jenis_client) }}</td>
                                <td class="px-3 py-2 text-xs text-slate-700">{{ formatCell(penghadap.status_kedudukan) }}</td>
                                <td class="px-3 py-2 text-xs text-slate-700">{{ formatCell(penghadap.mewakili) }}</td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                      </div>

                      <div
                        v-if="moduleEntry.path === '/buku_ppat' && barcodeImageUrl(row)"
                        class="rounded-xl border border-slate-200 bg-white p-4"
                      >
                        <img :src="barcodeImageUrl(row)" alt="Barcode Akta" class="mx-auto max-h-36 w-auto" />
                        <div class="mt-3 flex justify-center">
                          <button
                            type="button"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                            @click="downloadBarcode(row)"
                          >
                            Download Barcode Akta
                          </button>
                        </div>
                      </div>

                      <div v-if="moduleEntry.path === '/buku_ppat'" class="grid gap-2 sm:grid-cols-2">
                        <div
                          v-for="item in ppatDetailPairs(row)"
                          :key="`${reportoriumRowKey(row, index)}-${item[0]}`"
                          class="rounded-lg border border-slate-200 bg-white px-3 py-2"
                        >
                          <p class="text-xs font-semibold text-slate-600">{{ item[0] }}</p>
                          <p class="mt-1 text-sm text-slate-700">{{ formatCell(item[1]) }}</p>
                        </div>
                      </div>

                      <div v-if="moduleEntry.path === '/buku_surat_notaris' || moduleEntry.path === '/buku_surat_ppat'" class="rounded-xl border border-slate-200 bg-white p-3">
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Status File Surat</p>
                        <p v-if="row.file" class="mt-1 text-sm text-slate-700">File: {{ formatCell(row.file) }}</p>
                        <p v-else class="mt-1 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-700">
                          Tolong upload suratnya lewat tombol Upload Surat di kolom Aksi.
                        </p>
                        <button
                          v-if="row.file"
                          type="button"
                          :class="reportoriumActionButtonClass('delete')"
                          class="mt-3"
                          @click="deleteReportoriumFile(row)"
                        >
                          Hapus File Surat
                        </button>
                      </div>

                      <div v-if="moduleEntry.path === '/tanda_terima' || moduleEntry.path === '/tanda_terima_masuk'" class="space-y-3">
                        <div class="flex flex-wrap gap-2">
                          <button
                            type="button"
                            :class="reportoriumActionButtonClass('upload')"
                            :disabled="isArsipUser"
                            @click="openUploadDialog(row)"
                          >
                            {{ row.file ? 'Upload Ulang Tanda Terima' : 'Upload Tanda Terima' }}
                          </button>
                          <button
                            v-if="canDeleteTandaTerima(row)"
                            type="button"
                            :class="reportoriumActionButtonClass('delete')"
                            @click="deleteReportoriumFile(row)"
                          >
                            Hapus Tanda Terima
                          </button>
                          <button
                            v-if="getFilePreviewUrl(row)"
                            type="button"
                            :class="reportoriumActionButtonClass('file')"
                            @click="openReport(getFilePreviewUrl(row), `Preview ${formatCell(row.file)}`)"
                          >
                            Lihat File
                          </button>
                          <button
                            v-if="row.file"
                            type="button"
                            :class="reportoriumActionButtonClass('print')"
                            :disabled="downloadRequestLoading"
                            @click="requestReportoriumDownload(row)"
                          >
                            {{ downloadRequestLoading ? 'Memproses...' : 'Download' }}
                          </button>
                          <p class="self-center text-xs text-slate-500">File: {{ formatCell(row.file) }}</p>
                        </div>
                        <div v-if="getFilePreviewUrl(row)" class="overflow-hidden rounded-xl border border-slate-200 bg-white">
                          <iframe
                            :src="getFileIframePreviewUrl(row)"
                            class="h-[520px] w-full"
                            frameborder="0"
                          />
                        </div>
                        <div v-if="Array.isArray(row.isi_diterimas) && row.isi_diterimas.length" class="rounded-xl border border-slate-200 bg-white p-3">
                          <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Isi Diterima</p>
                          <ul class="mt-2 space-y-1">
                            <li
                              v-for="(item, itemIndex) in row.isi_diterimas"
                              :key="`${reportoriumRowKey(row, index)}-isi-${itemIndex}`"
                              class="text-sm text-slate-700"
                            >
                              {{ itemIndex + 1 }}. {{ formatCell((item as Record<string, unknown>).isi_diterima) }}
                            </li>
                          </ul>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
            </table>
          </div>
        </div>

        <div v-else class="overflow-x-auto rounded-2xl border border-slate-200">
          <table class="min-w-full divide-y divide-slate-200">
            <thead class="bg-slate-100/80">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                <th
                  v-for="column in columns"
                  :key="column"
                  class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600"
                >
                  {{ resolveColumnLabel(column) }}
                </th>
                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
              <tr v-for="(row, index) in rows" :key="rowKey(row, index)" class="hover:bg-slate-50/70">
                <td class="px-4 py-3 text-sm text-slate-600">{{ index + 1 }}</td>
                <td
                  v-for="column in columns"
                  :key="`${rowKey(row, index)}-${column}`"
                  class="max-w-[320px] px-4 py-3 text-sm text-slate-700 align-top"
                >
                  <template v-if="isExpandableValue(row[column])">
                    <button
                      type="button"
                      class="rounded-lg border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                      @click="toggleCellExpand(row, index, column)"
                    >
                      {{ isCellExpanded(row, index, column) ? 'Tutup' : 'Expand' }}
                    </button>

                    <div v-if="isCellExpanded(row, index, column)" class="mt-2 space-y-2">
                      <template v-if="toArrayItems(row[column]).length">
                        <details
                          v-for="(item, itemIndex) in toArrayItems(row[column])"
                          :key="`${rowKey(row, index)}-${column}-${itemIndex}`"
                          class="rounded-lg border border-slate-200 bg-slate-50/70"
                        >
                          <summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-slate-700">
                            {{ column }} {{ itemIndex + 1 }}
                          </summary>
                          <div class="space-y-1 border-t border-slate-100 px-3 py-2">
                            <template v-if="toObjectEntries(item).length">
                              <p
                                v-for="entry in toObjectEntries(item)"
                                :key="`${rowKey(row, index)}-${column}-${itemIndex}-${entry[0]}`"
                                class="text-xs text-slate-600"
                              >
                                <span class="font-semibold text-slate-700">{{ entry[0] }}:</span> {{ formatCell(entry[1]) }}
                              </p>
                            </template>
                            <p v-else class="text-xs text-slate-600">{{ formatCell(item) }}</p>
                          </div>
                        </details>
                      </template>

                      <div v-else class="space-y-1 rounded-lg border border-slate-200 bg-slate-50/70 px-3 py-2">
                        <p
                          v-for="entry in toObjectEntries(row[column])"
                          :key="`${rowKey(row, index)}-${column}-${entry[0]}`"
                          class="text-xs text-slate-600"
                        >
                          <span class="font-semibold text-slate-700">{{ entry[0] }}:</span> {{ formatCell(entry[1]) }}
                        </p>
                      </div>
                    </div>
                  </template>
                  <template v-else-if="isOrderModule && column === 'status_invoice'">
                    <span :class="orderBadgeClass(formatOrderCell(row as OrderRow, column))">
                      {{ formatOrderCell(row as OrderRow, column) }}
                    </span>
                  </template>
                  <template v-else-if="isOrderModule && column === 'no_inv'">
                    <span class="inline-flex rounded-lg bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">
                      {{ formatOrderCell(row as OrderRow, column) }}
                    </span>
                  </template>
                  <template v-else-if="isOrderModule">
                    {{ formatOrderCell(row as OrderRow, column) }}
                  </template>
                  <template v-else>
                    {{ formatCell(row[column]) }}
                  </template>
                </td>
                <td class="px-4 py-3 text-sm">
                  <div v-if="isOrderModule" class="flex flex-wrap gap-2">
                    <button
                      v-if="hasOrderAction('edit')"
                      type="button"
                      :class="orderActionButtonClass('edit')"
                      @click="onOrderAction('edit', row as OrderRow)"
                    >
                      Edit
                    </button>
                    <button
                      v-if="hasOrderAction('complete')"
                      type="button"
                      :class="orderActionButtonClass('complete')"
                      @click="onOrderAction('complete', row as OrderRow)"
                    >
                      Selesaikan
                    </button>
                    <button
                      v-if="hasOrderAction('invoice')"
                      type="button"
                      :class="orderActionButtonClass('invoice')"
                      :disabled="!canCreateInvoice(row as OrderRow)"
                      @click="onOrderAction('invoice', row as OrderRow)"
                    >
                      Buat Invoice
                    </button>
                    <button
                      v-if="hasOrderAction('detail')"
                      type="button"
                      :class="orderActionButtonClass('detail')"
                      @click="onOrderAction('detail', row as OrderRow)"
                    >
                      Detail
                    </button>
                    <button
                      v-if="hasOrderAction('print')"
                      type="button"
                      :class="orderActionButtonClass('print')"
                      @click="onOrderAction('print', row as OrderRow)"
                    >
                      Cetak Invoice
                    </button>
                  </div>
                  <button
                    v-else-if="resolveRowPrintUrl(row)"
                    type="button"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
                    @click="openReport(resolveRowPrintUrl(row))"
                  >
                    Cetak
                  </button>
                  <span v-else class="text-xs text-slate-400">-</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </SurfaceCard>

    <Teleport to="body">
      <div v-if="orderEditorOpen" class="fixed inset-0 z-[55] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeOrderEditorDialog" />
        <div class="relative z-10 w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Data Pesanan</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ orderEditorMode === 'create' ? 'Buat Pesanan' : 'Edit Pesanan' }}</h3>
            </div>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeOrderEditorDialog">
              Tutup
            </button>
          </div>

          <div class="mt-4 grid gap-4">
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Nama Pesanan</span>
              <input v-model="orderEditorForm.nama_pesanan" type="text" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Asisten</span>
              <select v-model="orderEditorForm.id_user" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option value="">{{ orderEditorLoading ? 'Memuat asisten...' : 'Pilih Asisten' }}</option>
                <option v-for="option in assistantOptions" :key="String(option.id_user)" :value="option.id_user">
                  {{ String(option.nama_lengkap || '-') }}
                </option>
              </select>
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Keterangan Order</span>
              <textarea v-model="orderEditorForm.keterangan_order" rows="4" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
          </div>

          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeOrderEditorDialog">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="orderEditorSaving" @click="saveOrderEditor">
              {{ orderEditorSaving ? 'Menyimpan...' : 'Simpan Pesanan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="orderWorkflowOpen" class="fixed inset-0 z-[56] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeOrderWorkflow" />
        <div class="relative z-10 flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
          <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <h3 class="text-lg font-semibold text-slate-900">{{ orderWorkflowTitle }}</h3>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeOrderWorkflow">
              Tutup
            </button>
          </div>
          <div class="min-h-0 flex-1 overflow-y-auto p-5">
            <div v-if="orderWorkflowMode !== 'detail'" class="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tambah Detail Pekerjaan</p>
              <div class="mt-3 grid gap-3 md:grid-cols-3">
                <input v-model="orderDetailDraft.jenis_pekerjaan" type="text" placeholder="Jenis Pekerjaan" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" />
                <input v-model="orderDetailDraft.nama_pekerjaan" type="text" placeholder="Nama Pekerjaan" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" />
                <input v-model="orderDetailDraft.no_pekerjaan" type="text" placeholder="Nomor Pekerjaan" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" />
                <input v-model="orderDetailDraft.tanggal_pekerjaan" type="date" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" />
                <input v-model="orderDetailDraft.pembuat" type="text" placeholder="Pembuat" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" />
                <input v-model.number="orderDetailDraft.harga" type="number" min="0" placeholder="Harga" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700" />
              </div>
              <div class="mt-3 flex justify-end">
                <button type="button" :class="orderActionButtonClass('add')" @click="addWorkflowDetail">Tambah Detail</button>
              </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-slate-200">
              <table class="min-w-full divide-y divide-slate-200 bg-white">
                <thead class="bg-slate-100/80">
                  <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Jenis</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nama</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Tanggal</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Pembuat</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Harga</th>
                    <th v-if="orderWorkflowMode !== 'detail'" class="w-[90px] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="(detail, detailIndex) in workflowDetails" :key="`workflow-${detailIndex}`">
                    <td class="px-3 py-2 text-xs text-slate-700">{{ detail.jenis_pekerjaan || '-' }}</td>
                    <td class="px-3 py-2 text-xs text-slate-700">{{ detail.nama_pekerjaan || '-' }}</td>
                    <td class="px-3 py-2 text-xs text-slate-700">{{ detail.no_pekerjaan || '-' }}</td>
                    <td class="px-3 py-2 text-xs text-slate-700">{{ detail.tanggal_pekerjaan || '-' }}</td>
                    <td class="px-3 py-2 text-xs text-slate-700">{{ detail.pembuat || '-' }}</td>
                    <td class="px-3 py-2 text-xs text-slate-700">{{ formatCurrency(detail.harga) }}</td>
                    <td v-if="orderWorkflowMode !== 'detail'" class="px-3 py-2">
                      <button type="button" :class="orderActionButtonClass('remove')" @click="removeWorkflowDetail(detailIndex)">Hapus</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="!workflowDetails.length" class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
              Detail pekerjaan belum tersedia.
            </div>

            <div v-if="orderWorkflowMode === 'invoice'" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pengaturan Invoice</p>
              <div class="mt-3 grid gap-3 md:grid-cols-3">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                  <input v-model="invoiceDraft.status_diskon" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600" />
                  Gunakan Diskon
                </label>
                <input
                  v-model.number="invoiceDraft.nilai_diskon"
                  type="number"
                  min="0"
                  max="100"
                  :disabled="!invoiceDraft.status_diskon"
                  placeholder="Nilai Diskon (%)"
                  class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 disabled:cursor-not-allowed disabled:bg-slate-100"
                />
                <label class="flex items-center gap-2 text-sm text-slate-700">
                  <input v-model="invoiceDraft.status_tax" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600" />
                  Gunakan Tax (25%)
                </label>
              </div>
              <div class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                <p class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">Diskon: {{ formatCurrency(invoiceDraft.diskon) }}</p>
                <p class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">Tax: {{ formatCurrency(invoiceDraft.tax) }}</p>
                <p class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-900">Grand Total: {{ formatCurrency(invoiceDraft.grand_total) }}</p>
              </div>
            </div>
          </div>
          <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-4">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeOrderWorkflow">
              Tutup
            </button>
            <button
              v-if="orderWorkflowMode !== 'detail'"
              type="button"
              class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="orderWorkflowSaving"
              @click="saveOrderWorkflow"
            >
              {{ orderWorkflowSaving ? 'Menyimpan...' : orderWorkflowMode === 'invoice' ? 'Simpan & Buat Invoice' : 'Simpan & Selesaikan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="invoiceStatusOpen" class="fixed inset-0 z-[57] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeInvoiceStatusDialog" />
        <div class="relative z-10 w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <h3 class="text-lg font-semibold text-slate-900">Detail Invoice</h3>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeInvoiceStatusDialog">
              Tutup
            </button>
          </div>
          <div class="mt-4 grid gap-4">
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Keterangan No Invoice</span>
              <input v-model="invoiceStatusForm.ket_noinv" type="text" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Status Invoice</span>
              <select v-model="invoiceStatusForm.status_invoice" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option value="Belum Bayar">Belum Bayar</option>
                <option value="Belum Lunas">Belum Lunas</option>
                <option value="Lunas">Lunas</option>
                <option value="Cancel">Cancel</option>
              </select>
            </label>
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeInvoiceStatusDialog">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="invoiceStatusSaving" @click="saveInvoiceStatus">
              {{ invoiceStatusSaving ? 'Menyimpan...' : 'Update Invoice' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="uploadDialog.open" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/50" @click="closeUploadDialog" />
        <div class="relative z-10 flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
          <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Upload</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ uploadDialog.title }}</h3>
            </div>
            <button
              type="button"
              class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
              @click="closeUploadDialog"
            >
              Tutup
            </button>
          </div>

          <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
            <p v-if="uploadDialog.error" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {{ uploadDialog.error }}
            </p>

            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-3">
              <input
                type="file"
                class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700"
                :multiple="Boolean(uploadDialog.config?.multiple)"
                @change="onUploadFileChange"
              />
              <p class="mt-2 text-xs text-slate-500">
                {{
                  uploadDialog.config?.multiple ? 'Bisa pilih beberapa file sekaligus.' : 'Pilih satu file dokumen.'
                }}
              </p>
            </div>

            <div v-if="uploadDialogHasStandardDocs" class="space-y-3">
              <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Dokumen Yang Sudah Terupload</p>
                <button
                  type="button"
                  class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                  :disabled="uploadDialog.loadingDocuments || uploadDialog.documentActionLoading"
                  @click="loadUploadDocuments"
                >
                  {{ uploadDialog.loadingDocuments ? 'Memuat...' : 'Refresh' }}
                </button>
              </div>

              <div v-if="uploadDialog.loadingDocuments" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Memuat daftar dokumen...
              </div>

              <div v-else-if="!uploadDialog.documents.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                Belum ada dokumen pada data ini.
              </div>

              <div v-else class="overflow-hidden rounded-xl border border-slate-200">
                <div class="max-h-[42vh] overflow-y-auto overflow-x-hidden">
                  <table class="w-full table-fixed divide-y divide-slate-200 bg-white">
                    <thead class="bg-slate-100/80">
                      <tr>
                        <th class="w-[40%] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nama Dokumen</th>
                        <th class="w-[38%] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">File</th>
                        <th class="w-[22%] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                      <tr v-for="(document, docIndex) in uploadDialog.documents" :key="standardDocumentKey(document, docIndex)">
                        <td class="px-3 py-2 text-sm text-slate-700 align-top">
                          <input
                            v-if="uploadDialog.editingDocumentIndex === docIndex"
                            v-model="uploadDialog.editingDocumentName"
                            type="text"
                            class="h-9 w-full rounded-lg border border-slate-200 bg-white px-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                          />
                          <span v-else class="block break-words">{{ formatCell(document.nama_dokumen) }}</span>
                        </td>
                        <td class="px-3 py-2 text-sm text-slate-600 align-top">
                          <span class="block w-full overflow-hidden text-ellipsis whitespace-nowrap" :title="formatCell(document.nama_berkas)">
                            {{ formatCell(document.nama_berkas) }}
                          </span>
                        </td>
                        <td class="px-3 py-2 align-top">
                          <div class="flex flex-wrap gap-1.5">
                            <button
                              type="button"
                              class="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                              :disabled="uploadDialog.documentActionLoading"
                              @click="openUploadDocumentFile(document)"
                            >
                              Lihat
                            </button>
                            <button
                              type="button"
                              class="rounded-lg border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                              :disabled="uploadDialog.documentActionLoading || downloadRequestLoading"
                              @click="requestStandardDocumentDownload(document)"
                            >
                              Download
                            </button>
                            <button
                              v-if="uploadDialog.editingDocumentIndex !== docIndex"
                              type="button"
                              class="rounded-lg border border-blue-200 bg-blue-50 px-2 py-1 text-[11px] font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                              :disabled="uploadDialog.documentActionLoading"
                              @click="beginEditDocument(docIndex)"
                            >
                              Edit
                            </button>
                            <button
                              v-else
                              type="button"
                              class="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50"
                              :disabled="uploadDialog.documentActionLoading"
                              @click="saveDocumentName(docIndex)"
                            >
                              Simpan
                            </button>
                            <button
                              v-if="uploadDialog.editingDocumentIndex === docIndex"
                              type="button"
                              class="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                              :disabled="uploadDialog.documentActionLoading"
                              @click="cancelEditDocument"
                            >
                              Batal
                            </button>
                            <button
                              type="button"
                              class="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-[11px] font-semibold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                              :disabled="uploadDialog.documentActionLoading"
                              @click="deleteUploadDocument(docIndex)"
                            >
                              Hapus
                            </button>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

            </div>
          </div>

          <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
            <button
              type="button"
              class="h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
              :disabled="uploadDialog.submitting || uploadDialog.documentActionLoading"
              @click="closeUploadDialog"
            >
              Batal
            </button>
            <button
              type="button"
              class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="uploadDialog.submitting || uploadDialog.documentActionLoading"
              @click="submitUpload"
            >
              {{ uploadDialog.submitting ? 'Mengupload...' : 'Upload' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="reportPreviewDialog.open" class="fixed inset-0 z-[58] flex items-center justify-center">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeReportPreviewDialog" />
        <div class="relative z-10 flex h-screen w-screen flex-col overflow-hidden bg-white shadow-2xl">
          <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Preview</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ reportPreviewDialog.title }}</h3>
            </div>
            <button
              type="button"
              class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50"
              @click="closeReportPreviewDialog"
            >
              Tutup
            </button>
          </div>

          <div class="min-h-0 flex-1 bg-slate-100/70">
            <iframe
              v-if="reportPreviewDialog.url"
              :src="reportPreviewDialog.url"
              class="h-full w-full border-0 bg-white"
              frameborder="0"
            />
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="masterDetailDialog.open" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeMasterDetailDialog" />
        <div class="relative z-10 w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Detail Data</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ masterDetailDialog.title }}</h3>
            </div>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeMasterDetailDialog">
              Tutup
            </button>
          </div>
          <div class="mt-4 max-h-[60vh] overflow-y-auto rounded-xl border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200">
              <tbody class="divide-y divide-slate-100 bg-white">
                <tr v-for="entry in masterDetailEntries" :key="`detail-${entry[0]}`">
                  <td class="w-[180px] bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-slate-600">{{ entry[0] }}</td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ formatCell(entry[1]) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="userFormDialog.open" class="fixed inset-0 z-[71] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeUserFormDialog" />
        <div class="relative z-10 w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Account Setting</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">{{ userDialogTitle }}</h3>
            </div>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeUserFormDialog">
              Tutup
            </button>
          </div>

          <p v-if="userFormDialog.error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ userFormDialog.error }}</p>

          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Nama Lengkap</span>
              <input v-model="userForm.nama_lengkap" type="text" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
            <label v-if="canManageUserCrud" class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Role</span>
              <select v-model="userForm.level_user" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option v-for="role in roleOptions" :key="`role-${role}`" :value="role">{{ role }}</option>
              </select>
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Email</span>
              <input v-model="userForm.email" type="email" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
            <label class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Phone</span>
              <input v-model="userForm.phone" type="text" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
            <label v-if="userFormDialog.mode === 'create'" class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ userFormDialog.mode === 'create' ? 'Password' : 'Password Baru' }}</span>
              <input v-model="userForm.password" type="password" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
            <label v-if="userFormDialog.mode === 'create'" class="flex flex-col gap-2">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Konfirmasi Password</span>
              <input v-model="userForm.password_confirmation" type="password" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
            <label v-if="canManageUserCrud" class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 sm:col-span-2">
              <input v-model="userForm.login_otp_enabled" type="checkbox" class="h-5 w-5 rounded border-slate-300 text-blue-600 focus:ring-blue-500" />
              <span>
                <span class="block text-sm font-semibold text-slate-800">Aktifkan OTP saat login</span>
                <span class="mt-0.5 block text-xs text-slate-500">Kode verifikasi akan dikirim ke nomor WhatsApp user setelah password benar.</span>
              </span>
            </label>
          </div>

          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeUserFormDialog">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="userFormDialog.saving" @click="saveUserForm">
              {{ userFormDialog.saving ? 'Menyimpan...' : userFormDialog.mode === 'create' ? 'Simpan User' : 'Simpan Perubahan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="passwordDialog.open" class="fixed inset-0 z-[72] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closePasswordDialog" />
        <div class="relative z-10 w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Keamanan</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">Ganti Password Saya</h3>
            </div>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closePasswordDialog">
              Tutup
            </button>
          </div>
          <p v-if="passwordDialog.message" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ passwordDialog.message }}</p>
          <p v-if="passwordDialog.error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ passwordDialog.error }}</p>
          <div class="mt-4 grid gap-4">
            <input v-model="passwordDialogForm.currentPassword" type="password" placeholder="Password saat ini" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            <input v-model="passwordDialogForm.newPassword" type="password" placeholder="Password baru" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            <input v-model="passwordDialogForm.confirmPassword" type="password" placeholder="Konfirmasi password baru" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closePasswordDialog">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="passwordDialog.saving" @click="savePasswordDialog">
              {{ passwordDialog.saving ? 'Menyimpan...' : 'Update Password' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="userPasswordDialog.open" class="fixed inset-0 z-[73] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeUserPasswordDialog" />
        <div class="relative z-10 w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Khusus Super Admin</p>
              <h3 class="mt-1 text-lg font-semibold text-slate-900">Ganti Password {{ userPasswordDialog.targetName }}</h3>
            </div>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeUserPasswordDialog">
              Tutup
            </button>
          </div>
          <p class="mt-3 text-sm text-slate-500">Masukkan password Super Admin sebagai konfirmasi keamanan.</p>
          <p v-if="userPasswordDialog.error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ userPasswordDialog.error }}</p>
          <div class="mt-4 grid gap-4">
            <input v-model="userPasswordDialogForm.currentPassword" type="password" placeholder="Password Super Admin" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            <input v-model="userPasswordDialogForm.newPassword" type="password" placeholder="Password baru, minimal 8 karakter" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            <input v-model="userPasswordDialogForm.confirmPassword" type="password" placeholder="Konfirmasi password baru" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeUserPasswordDialog">Batal</button>
            <button type="button" class="h-10 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="userPasswordDialog.saving" @click="saveUserPasswordDialog">
              {{ userPasswordDialog.saving ? 'Menyimpan...' : 'Ganti Password' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="layananFormDialog.open" class="fixed inset-0 z-[73] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeLayananFormDialog" />
        <div class="relative z-10 w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <h3 class="text-lg font-semibold text-slate-900">{{ layananDialogTitle }}</h3>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeLayananFormDialog">
              Tutup
            </button>
          </div>
          <p v-if="layananFormDialog.error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ layananFormDialog.error }}</p>
          <div class="mt-4 grid gap-4">
            <input v-model="layananForm.nama_akta" type="text" placeholder="Nama layanan" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            <select v-model="layananForm.pekerjaan_milik" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option v-for="option in pekerjaanMilikOptions" :key="`pm-${option}`" :value="option">{{ option }}</option>
            </select>
            <select v-model="layananForm.apht" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option value="FALSE">APHT: FALSE</option>
              <option value="TRUE">APHT: TRUE</option>
            </select>
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeLayananFormDialog">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="layananFormDialog.saving" @click="saveLayananForm">
              {{ layananFormDialog.saving ? 'Menyimpan...' : layananFormDialog.mode === 'create' ? 'Simpan Layanan' : 'Update Layanan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="dokumenFormDialog.open" class="fixed inset-0 z-[74] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeDokumenFormDialog" />
        <div class="relative z-10 w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <h3 class="text-lg font-semibold text-slate-900">{{ dokumenDialogTitle }}</h3>
            <button type="button" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" @click="closeDokumenFormDialog">
              Tutup
            </button>
          </div>
          <p v-if="dokumenFormDialog.error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ dokumenFormDialog.error }}</p>
          <div class="mt-4 grid gap-4">
            <input v-model="dokumenForm.nama_dokumen" type="text" placeholder="Nama dokumen" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
          </div>
          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeDokumenFormDialog">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="dokumenFormDialog.saving" @click="saveDokumenForm">
              {{ dokumenFormDialog.saving ? 'Menyimpan...' : dokumenFormDialog.mode === 'create' ? 'Simpan Dokumen' : 'Update Dokumen' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>
