<script setup lang="ts">
type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type AnomalyRow = {
  module_path?: string
  module_label?: string
  record_id?: string | number
  nomor?: string | number
  judul?: string
  assignee_id?: string
  assignee_name?: string
  tanggal?: string
  created_at?: string
}

type AnomalyGroup = {
  type?: string
  severity?: string
  module_path?: string
  module_label?: string
  month?: string
  nomor?: string | number
  count?: number
  first_date?: string
  last_date?: string
  rows?: AnomalyRow[]
}

type ModuleSummary = {
  module_path?: string
  module_label?: string
  total_rows?: number
  duplicate_groups?: number
  duplicate_rows?: number
}

type AnomalyPayload = {
  month?: string
  summary?: {
    total_rows?: number
    duplicate_groups?: number
    duplicate_rows?: number
  }
  modules?: ModuleSummary[]
  groups?: AnomalyGroup[]
}

type EditRecordPayload = {
  module_path?: string
  module_label?: string
  record_id?: string | number
  table?: string
  id_field?: string
  columns?: string[]
  editable_columns?: string[]
  record?: Record<string, unknown>
}

definePageMeta({
  middleware: ['auth', 'admin-only'],
})

useHead({
  title: 'Kontrol Anomali',
})

const business = useLegacyBusiness()

const moduleOptions = [
  { value: 'all', label: 'Semua Modul Reportorium' },
  { value: '/buku_akta', label: 'Buku Akta' },
  { value: '/buku_legalisasi', label: 'Buku Legalisasi' },
  { value: '/buku_waarmerking', label: 'Buku Waarmerking' },
  { value: '/buku_ppat', label: 'Buku PPAT' },
  { value: '/buku_surat_notaris', label: 'Surat Notaris' },
  { value: '/buku_surat_ppat', label: 'Surat PPAT' },
  { value: '/tanda_terima', label: 'Tanda Terima Keluar' },
  { value: '/tanda_terima_masuk', label: 'Tanda Terima Masuk' },
]

const monthFilter = ref('')
const moduleFilter = ref('all')
const search = ref('')
const loading = ref(false)
const deletingKey = ref('')
const editDialog = reactive({
  open: false,
  loading: false,
  saving: false,
  modulePath: '',
  recordId: '',
  moduleLabel: '',
  table: '',
  idField: '',
  columns: [] as string[],
  editableColumns: [] as string[],
  form: {} as Record<string, string>,
  error: '',
})
const errorMessage = ref('')
const successMessage = ref('')
const payload = ref<AnomalyPayload>({
  summary: {},
  modules: [],
  groups: [],
})
const openedGroups = ref<string[]>([])

const groups = computed(() => payload.value.groups || [])
const modules = computed(() => payload.value.modules || [])
const summary = computed(() => payload.value.summary || {})

const totalRows = computed(() => Number(summary.value.total_rows || 0))
const duplicateGroups = computed(() => Number(summary.value.duplicate_groups || 0))
const duplicateRows = computed(() => Number(summary.value.duplicate_rows || 0))
const healthyRows = computed(() => Math.max(0, totalRows.value - duplicateRows.value))

const groupKey = (group: AnomalyGroup) =>
  `${String(group.module_path || '')}:${String(group.nomor || '')}`

const rowKey = (row: AnomalyRow) =>
  `${String(row.module_path || '')}:${String(row.record_id || '')}`

const monthForAnomalyRow = (row: AnomalyRow) => {
  const raw = String(row.tanggal || row.created_at || '').trim()
  if (!raw) return String(payload.value.month || monthFilter.value || '').slice(0, 7)
  const parsed = new Date(raw)
  if (Number.isNaN(parsed.getTime())) return raw.slice(0, 7)
  return parsed.toISOString().slice(0, 7)
}

const openAnomalyRow = async (row: AnomalyRow) => {
  const modulePath = String(row.module_path || '').trim()
  if (!modulePath) return

  const recordId = String(row.record_id || '').trim()
  const keyword = recordId || String(row.nomor || row.judul || '').trim()
  const query: Record<string, string> = {}
  const month = monthForAnomalyRow(row)

  if (month) query.date = month
  if (keyword) query.q = keyword
  if (recordId) query.record_id = recordId

  await navigateTo({ path: modulePath, query })
}

const isGroupOpen = (group: AnomalyGroup) => openedGroups.value.includes(groupKey(group))

const toggleGroup = (group: AnomalyGroup) => {
  const key = groupKey(group)
  openedGroups.value = openedGroups.value.includes(key)
    ? openedGroups.value.filter(item => item !== key)
    : [...openedGroups.value, key]
}

const formatDateTime = (value: unknown) => {
  const raw = String(value || '').trim()
  if (!raw) return '-'
  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) return raw
  return date.toLocaleString('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const fieldLabel = (field: string) =>
  field
    .replace(/_/g, ' ')
    .replace(/\b\w/g, char => char.toUpperCase())

const normalizeFieldValue = (value: unknown) => {
  if (value === null || value === undefined) return ''
  return String(value)
}

const inputTypeForField = (field: string, value: string) => {
  const lower = field.toLowerCase()
  if (lower.includes('email')) return 'email'
  if (lower.includes('tanggal') || lower.startsWith('tgl_')) return 'date'
  if ((lower === 'created_at' || lower === 'updated_at' || lower.includes('datetime')) && (value.includes(' ') || value.includes('T'))) {
    return 'datetime-local'
  }
  if (/^(no_|nomor|jumlah|total|nilai|harga|biaya)/.test(lower) && /^-?\d+(\.\d+)?$/.test(value)) return 'number'
  return 'text'
}

const valueForInput = (field: string, value: string) => {
  const type = inputTypeForField(field, value)
  if (type === 'datetime-local' && value.includes(' ')) {
    return value.replace(' ', 'T').slice(0, 16)
  }
  if (type === 'date' && value.length >= 10) {
    return value.slice(0, 10)
  }
  return value
}

const valueForSave = (field: string, value: string) => {
  const lower = field.toLowerCase()
  if ((lower === 'created_at' || lower === 'updated_at' || lower.includes('datetime')) && value.includes('T')) {
    return `${value.replace('T', ' ')}:00`
  }
  return value
}

const closeEditDialog = () => {
  if (editDialog.saving) return
  editDialog.open = false
  editDialog.loading = false
  editDialog.saving = false
  editDialog.modulePath = ''
  editDialog.recordId = ''
  editDialog.moduleLabel = ''
  editDialog.table = ''
  editDialog.idField = ''
  editDialog.columns = []
  editDialog.editableColumns = []
  editDialog.form = {}
  editDialog.error = ''
}

const openEditDialog = async (row: AnomalyRow) => {
  const modulePath = String(row.module_path || '').trim()
  const recordId = String(row.record_id || '').trim()
  if (!modulePath || !recordId || editDialog.loading) return

  editDialog.open = true
  editDialog.loading = true
  editDialog.saving = false
  editDialog.modulePath = modulePath
  editDialog.recordId = recordId
  editDialog.moduleLabel = String(row.module_label || modulePath)
  editDialog.error = ''
  editDialog.columns = []
  editDialog.editableColumns = []
  editDialog.form = {}

  try {
    const response = await business.adminWork.getNumberAnomalyRecord({
      module_path: modulePath,
      record_id: recordId,
    }) as ApiEnvelope<EditRecordPayload>
    const data = response.data || {}
    editDialog.moduleLabel = String(data.module_label || row.module_label || modulePath)
    editDialog.table = String(data.table || '')
    editDialog.idField = String(data.id_field || '')
    editDialog.columns = data.columns || []
    editDialog.editableColumns = data.editable_columns || []
    editDialog.form = Object.fromEntries(
      editDialog.columns.map((column) => {
        const rawValue = normalizeFieldValue(data.record?.[column])
        return [column, valueForInput(column, rawValue)]
      }),
    )
  } catch (error) {
    editDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat detail data.'
  } finally {
    editDialog.loading = false
  }
}

const saveEditDialog = async () => {
  if (editDialog.saving || !editDialog.modulePath || !editDialog.recordId) return

  editDialog.saving = true
  editDialog.error = ''
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const fields = Object.fromEntries(
      editDialog.editableColumns.map(column => [column, valueForSave(column, editDialog.form[column] ?? '')]),
    )
    const response = await business.adminWork.updateNumberAnomalyRecord({
      module_path: editDialog.modulePath,
      record_id: editDialog.recordId,
      fields,
    }) as ApiEnvelope<EditRecordPayload>
    successMessage.value = response.message || 'Data anomali berhasil diperbarui.'
    closeEditDialog()
    await loadAnomalies()
  } catch (error) {
    editDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan perubahan.'
  } finally {
    editDialog.saving = false
  }
}

const resetFilters = () => {
  monthFilter.value = ''
  moduleFilter.value = 'all'
  search.value = ''
  loadAnomalies()
}

const deleteAnomalyRow = async (row: AnomalyRow) => {
  const modulePath = String(row.module_path || '').trim()
  const recordId = String(row.record_id || '').trim()
  if (!modulePath || !recordId || deletingKey.value) return

  const confirmed = window.confirm(`Hapus data anomali ${recordId} dari ${row.module_label || modulePath}? Aksi ini permanen.`)
  if (!confirmed) return

  deletingKey.value = rowKey(row)
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const response = await business.adminWork.deleteNumberAnomaly({
      module_path: modulePath,
      record_id: recordId,
    }) as ApiEnvelope
    successMessage.value = response.message || 'Data anomali berhasil dihapus.'
    await loadAnomalies()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus data anomali.'
  } finally {
    deletingKey.value = ''
  }
}

const loadAnomalies = async () => {
  loading.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const query: Record<string, string> = {}
    if (monthFilter.value) query.date = monthFilter.value
    if (moduleFilter.value !== 'all') query.module_path = moduleFilter.value
    if (search.value.trim()) query.search = search.value.trim()

    const response = await business.adminWork.getNumberAnomalies(query) as ApiEnvelope<AnomalyPayload>
    payload.value = response.data || { summary: {}, modules: [], groups: [] }
    successMessage.value = response.message || ''
    openedGroups.value = groups.value.slice(0, 3).map(group => groupKey(group))
  } catch (error) {
    payload.value = { summary: {}, modules: [], groups: [] }
    openedGroups.value = []
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat data anomali.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadAnomalies()
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <p class="display-kicker">Kontrol Admin</p>
      <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="text-3xl font-semibold text-slate-900">Kontrol Anomali</h2>
          <p class="mt-2 max-w-3xl text-sm text-slate-600">
            Scan dan trace nomor reportorium yang terpakai lebih dari sekali dalam bulan yang sama.
          </p>
        </div>
        <button
          type="button"
          class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="loading"
          @click="loadAnomalies"
        >
          {{ loading ? 'Scanning...' : 'Scan Ulang' }}
        </button>
      </div>
    </SurfaceCard>

    <div class="grid gap-4 md:grid-cols-4">
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total Record</p>
        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ totalRows }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ payload.month || monthFilter ? `Periode ${payload.month || monthFilter}` : 'Semua periode' }}</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Kelompok Anomali</p>
        <p class="mt-2 text-2xl font-semibold" :class="duplicateGroups ? 'text-red-600' : 'text-emerald-600'">{{ duplicateGroups }}</p>
        <p class="mt-1 text-xs text-slate-500">Nomor ganda ditemukan</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Record Terdampak</p>
        <p class="mt-2 text-2xl font-semibold" :class="duplicateRows ? 'text-amber-600' : 'text-emerald-600'">{{ duplicateRows }}</p>
        <p class="mt-1 text-xs text-slate-500">Baris yang perlu dicek</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Record Aman</p>
        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ healthyRows }}</p>
        <p class="mt-1 text-xs text-slate-500">Tidak masuk nomor ganda</p>
      </SurfaceCard>
    </div>

    <SurfaceCard class="overflow-hidden p-0">
      <div class="border-b border-slate-100 bg-slate-50 px-6 py-4">
        <p class="text-sm font-semibold text-slate-800">Filter Scan Anomali</p>
      </div>
      <div class="space-y-4 p-6">
        <div class="flex flex-wrap items-end gap-2">
          <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Periode</span>
            <input
              v-model="monthFilter"
              type="month"
              class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </label>
          <label class="flex min-w-[220px] flex-1 flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Modul</span>
            <select v-model="moduleFilter" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option v-for="option in moduleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
          <label class="flex min-w-[260px] flex-[1.4] flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pencarian</span>
            <input
              v-model="search"
              type="text"
              placeholder="Cari nomor, record, judul, atau asisten..."
              class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              @keyup.enter="loadAnomalies"
            />
          </label>
          <button type="button" class="h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" :disabled="loading" @click="loadAnomalies">
            {{ loading ? 'Memuat...' : 'Terapkan' }}
          </button>
          <button type="button" class="h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" :disabled="loading" @click="resetFilters">
            Reset
          </button>
        </div>

        <p v-if="successMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ successMessage }}</p>
        <p v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

        <div v-if="modules.length" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <div v-for="item in modules" :key="String(item.module_path)" class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-slate-900">{{ item.module_label || item.module_path }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ item.total_rows || 0 }} record discan</p>
              </div>
              <span
                class="rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="Number(item.duplicate_groups || 0) ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700'"
              >
                {{ item.duplicate_groups || 0 }} anomali
              </span>
            </div>
            <p class="mt-3 text-xs text-slate-500">
              {{ item.duplicate_rows || 0 }} record terdampak.
            </p>
          </div>
        </div>
      </div>
    </SurfaceCard>

    <SurfaceCard class="overflow-hidden p-0">
      <div class="border-b border-slate-100 bg-slate-50 px-6 py-4">
        <p class="text-sm font-semibold text-slate-800">Trace Nomor Ganda</p>
      </div>

      <div class="space-y-3 p-6">
        <div v-if="loading" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Sedang men-scan data anomali...
        </div>
        <div v-else-if="!groups.length" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
          Tidak ada nomor ganda pada filter ini.
        </div>

        <template v-else>
          <div v-for="group in groups" :key="groupKey(group)" class="overflow-hidden rounded-2xl border border-red-100 bg-white">
            <button
              type="button"
              class="flex w-full flex-wrap items-center justify-between gap-3 bg-red-50 px-4 py-3 text-left transition hover:bg-red-100/70"
              @click="toggleGroup(group)"
            >
              <div>
                <p class="text-sm font-semibold text-red-800">
                {{ group.module_label || group.module_path }} - Nomor {{ group.nomor || '-' }}
              </p>
              <p class="mt-1 text-xs text-red-700">
                {{ group.count || 0 }} record memakai nomor yang sama pada {{ group.month || 'periode tanpa tanggal' }}.
                Rentang: {{ formatDateTime(group.first_date) }} - {{ formatDateTime(group.last_date) }}
              </p>
              </div>
              <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-red-700">
                {{ isGroupOpen(group) ? 'Tutup Detail' : 'Lihat Detail' }}
              </span>
            </button>

            <div v-if="isGroupOpen(group)" class="overflow-x-auto">
              <table class="min-w-full divide-y divide-slate-200 bg-white">
                <thead class="bg-slate-100/80">
                  <tr>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Record</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nomor</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Judul / Client</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Asisten</th>
                    <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Tanggal</th>
                    <th class="w-[260px] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="row in group.rows || []" :key="`${row.module_path}-${row.record_id}`">
                    <td class="px-3 py-2 text-sm font-semibold text-slate-800">{{ row.record_id || '-' }}</td>
                    <td class="px-3 py-2 text-sm text-slate-700">{{ row.nomor || '-' }}</td>
                    <td class="max-w-[360px] px-3 py-2 text-sm text-slate-700">
                      <span class="block truncate" :title="String(row.judul || '-')">{{ row.judul || '-' }}</span>
                    </td>
                    <td class="px-3 py-2 text-sm text-slate-700">{{ row.assignee_name || row.assignee_id || '-' }}</td>
                    <td class="px-3 py-2 text-sm text-slate-700">{{ formatDateTime(row.tanggal || row.created_at) }}</td>
                    <td class="px-3 py-2">
                      <button
                        type="button"
                        class="mr-2 inline-flex h-8 items-center rounded-lg border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="editDialog.loading || editDialog.saving || Boolean(deletingKey)"
                        @click="openAnomalyRow(row)"
                      >
                        Buka
                      </button>
                      <button
                        type="button"
                        class="mr-2 inline-flex h-8 items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="editDialog.loading || editDialog.saving || Boolean(deletingKey)"
                        @click="openEditDialog(row)"
                      >
                        Edit
                      </button>
                      <button
                        type="button"
                        class="inline-flex h-8 items-center rounded-lg border border-red-200 bg-red-50 px-3 text-xs font-semibold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="Boolean(deletingKey)"
                        @click="deleteAnomalyRow(row)"
                      >
                        {{ deletingKey === rowKey(row) ? 'Menghapus...' : 'Hapus' }}
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>
      </div>
    </SurfaceCard>

    <Teleport to="body">
      <div v-if="editDialog.open" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeEditDialog" />
        <div class="relative z-10 flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
          <div class="border-b border-slate-200 px-5 py-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Edit Data Anomali</p>
            <h3 class="mt-1 text-lg font-semibold text-slate-900">
              {{ editDialog.moduleLabel }} - {{ editDialog.recordId }}
            </h3>
            <p class="mt-1 text-xs text-slate-500">
              Tabel: {{ editDialog.table || '-' }}. Field ID utama tidak bisa diubah.
            </p>
          </div>

          <div class="flex-1 overflow-y-auto p-5">
            <div v-if="editDialog.loading" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
              Memuat seluruh field data...
            </div>

            <p v-if="editDialog.error" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
              {{ editDialog.error }}
            </p>

            <div v-if="!editDialog.loading && editDialog.columns.length" class="grid gap-4 md:grid-cols-2">
              <label v-for="column in editDialog.columns" :key="column" class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                  {{ fieldLabel(column) }}
                  <span v-if="column === editDialog.idField" class="ml-1 text-red-500">(ID terkunci)</span>
                </span>
                <textarea
                  v-if="String(editDialog.form[column] || '').length > 120 || column.toLowerCase().includes('keterangan') || column.toLowerCase().includes('judul')"
                  v-model="editDialog.form[column]"
                  rows="3"
                  class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100 disabled:text-slate-500"
                  :disabled="column === editDialog.idField || editDialog.saving"
                />
                <input
                  v-else
                  v-model="editDialog.form[column]"
                  :type="inputTypeForField(column, String(editDialog.form[column] || ''))"
                  class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-slate-100 disabled:text-slate-500"
                  :disabled="column === editDialog.idField || editDialog.saving"
                />
              </label>
            </div>
          </div>

          <div class="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
            <button type="button" class="h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-100" :disabled="editDialog.saving" @click="closeEditDialog">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60" :disabled="editDialog.loading || editDialog.saving || Boolean(editDialog.error)" @click="saveEditDialog">
              {{ editDialog.saving ? 'Menyimpan...' : 'Simpan Perubahan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
