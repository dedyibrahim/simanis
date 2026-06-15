<script setup lang="ts">
type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type JobRow = {
  module_path?: string
  record_id?: string | number
  nomor?: string | number
  judul?: string
  assignee_id?: string
  assignee_name?: string
  tanggal?: string
  created_at?: string
}

type AssistantRow = {
  id_user?: string
  nama_lengkap?: string
  level_user?: string
}

definePageMeta({
  middleware: ['auth', 'admin-only'],
})

useHead({
  title: 'Kontrol Pekerjaan Admin',
})

const business = useLegacyBusiness()

const monthFilter = ref('')
const moduleFilter = ref('all')
const assigneeFilter = ref('all')
const search = ref('')
const jobs = ref<JobRow[]>([])
const loadingJobs = ref(false)
const jobsError = ref('')
const jobsMessage = ref('')
const jobsPage = ref(1)
const jobsPerPage = ref(20)
const jobsPerPageOptions = [20, 50, 100]

const assistants = ref<AssistantRow[]>([])
const loadingAssistants = ref(false)

const actionLoadingKey = ref('')
const bulkActionLoading = ref(false)
const selectedJobKeys = ref<string[]>([])
const reassignDialog = reactive({
  open: false,
  saving: false,
  targetIdUser: '',
  rows: [] as JobRow[],
  error: '',
})

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

const moduleLabelMap = Object.fromEntries(moduleOptions.map(item => [item.value, item.label]))

const jobsTotalRows = computed(() => jobs.value.length)
const jobsTotalPages = computed(() => Math.max(1, Math.ceil(jobsTotalRows.value / jobsPerPage.value)))
const paginatedJobs = computed(() => {
  const start = (jobsPage.value - 1) * jobsPerPage.value
  return jobs.value.slice(start, start + jobsPerPage.value)
})
const jobsStart = computed(() => {
  if (!jobsTotalRows.value) return 0
  return (jobsPage.value - 1) * jobsPerPage.value + 1
})
const jobsEnd = computed(() => Math.min(jobsStart.value + jobsPerPage.value - 1, jobsTotalRows.value))

const toList = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && 'data' in payload) {
    const data = (payload as ApiEnvelope<unknown>).data
    if (Array.isArray(data)) return data
    if (typeof data === 'string') {
      const text = data.trim()
      if ((text.startsWith('[') && text.endsWith(']')) || (text.startsWith('{') && text.endsWith('}'))) {
        try {
          const parsed = JSON.parse(text)
          return Array.isArray(parsed) ? parsed as T[] : []
        } catch {
          return []
        }
      }
    }
  }
  return []
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

const rowActionKey = (row: JobRow) => `${String(row.module_path || '')}:${String(row.record_id || '')}`
const selectedJobs = computed(() => {
  const selected = new Set(selectedJobKeys.value)
  return jobs.value.filter(row => selected.has(rowActionKey(row)))
})

const isAllJobsSelected = computed(() =>
  jobs.value.length > 0 && selectedJobKeys.value.length === jobs.value.length,
)

const isRowSelected = (row: JobRow) => selectedJobKeys.value.includes(rowActionKey(row))

const onToggleSelectAll = (event: Event) => {
  const checked = Boolean((event.target as HTMLInputElement)?.checked)
  selectedJobKeys.value = checked ? jobs.value.map(row => rowActionKey(row)) : []
}

const onToggleSelectRow = (row: JobRow, event: Event) => {
  const key = rowActionKey(row)
  const checked = Boolean((event.target as HTMLInputElement)?.checked)

  if (checked) {
    if (!selectedJobKeys.value.includes(key)) {
      selectedJobKeys.value = [...selectedJobKeys.value, key]
    }
    return
  }

  selectedJobKeys.value = selectedJobKeys.value.filter(item => item !== key)
}

const clearSelectedJobs = () => {
  selectedJobKeys.value = []
}

const isRowActionLoading = (row: JobRow) =>
  bulkActionLoading.value || actionLoadingKey.value === rowActionKey(row)

const loadAssistants = async () => {
  if (loadingAssistants.value || assistants.value.length) return
  loadingAssistants.value = true
  try {
    const response = await business.adminWork.getAsisten() as ApiEnvelope<AssistantRow[]>
    assistants.value = toList<AssistantRow>(response)
  } catch {
    assistants.value = []
  } finally {
    loadingAssistants.value = false
  }
}

const loadJobs = async () => {
  loadingJobs.value = true
  jobsError.value = ''
  jobsMessage.value = ''
  try {
    const query: Record<string, string> = {}
    if (monthFilter.value) query.date = monthFilter.value
    if (moduleFilter.value !== 'all') query.module_path = moduleFilter.value
    if (assigneeFilter.value !== 'all') query.assignee_id = assigneeFilter.value
    if (search.value.trim()) query.search = search.value.trim()
    const response = await business.adminWork.getReportoriumJobs(query) as ApiEnvelope<JobRow[]>
    jobs.value = toList<JobRow>(response)
    const validKeys = new Set(jobs.value.map(row => rowActionKey(row)))
    selectedJobKeys.value = selectedJobKeys.value.filter(key => validKeys.has(key))
    jobsPage.value = 1
    jobsMessage.value = response.message || ''
  } catch (error) {
    jobs.value = []
    selectedJobKeys.value = []
    jobsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat data pekerjaan reportorium.'
  } finally {
    loadingJobs.value = false
  }
}

const openReassignDialog = async (row: JobRow) => {
  await loadAssistants()
  reassignDialog.open = true
  reassignDialog.error = ''
  reassignDialog.rows = [row]
  reassignDialog.targetIdUser = ''
}

const openBulkReassignDialog = async () => {
  if (!selectedJobs.value.length) {
    jobsError.value = 'Pilih minimal satu pekerjaan terlebih dahulu.'
    return
  }

  await loadAssistants()
  reassignDialog.open = true
  reassignDialog.error = ''
  reassignDialog.rows = [...selectedJobs.value]
  reassignDialog.targetIdUser = ''
}

const resetReassignDialogState = () => {
  reassignDialog.open = false
  reassignDialog.error = ''
  reassignDialog.rows = []
  reassignDialog.targetIdUser = ''
}

const closeReassignDialog = () => {
  if (reassignDialog.saving) return
  resetReassignDialogState()
}

const executeReassign = async (
  rowsToProcess: JobRow[],
  options: { targetIdUser?: string; takeOver?: boolean },
) => {
  let success = 0
  let failed = 0
  let errorMessage = ''

  for (const row of rowsToProcess) {
    const modulePath = String(row.module_path || '').trim()
    const recordId = String(row.record_id || '').trim()
    if (!modulePath || !recordId) {
      failed += 1
      if (!errorMessage) errorMessage = 'Ada data pekerjaan tidak valid.'
      continue
    }

    try {
      await business.adminWork.reassign({
        module_path: modulePath,
        record_id: recordId,
        ...(options.takeOver ? { take_over: true } : { target_id_user: String(options.targetIdUser || '') }),
      })
      success += 1
    } catch (error) {
      failed += 1
      if (!errorMessage) {
        errorMessage = (error as { data?: { message?: string } })?.data?.message || 'Sebagian data gagal diproses.'
      }
    }
  }

  return { success, failed, errorMessage }
}

const saveReassign = async () => {
  if (reassignDialog.saving || !reassignDialog.rows.length) return
  if (!reassignDialog.targetIdUser) {
    reassignDialog.error = 'Pilih asisten tujuan terlebih dahulu.'
    return
  }

  reassignDialog.saving = true
  reassignDialog.error = ''
  jobsError.value = ''
  try {
    const total = reassignDialog.rows.length
    const result = await executeReassign(reassignDialog.rows, {
      targetIdUser: reassignDialog.targetIdUser,
    })

    if (result.failed > 0) {
      jobsError.value = result.errorMessage || `${result.failed} dari ${total} data gagal dialihkan.`
    } else {
      jobsError.value = ''
    }

    jobsMessage.value = result.success > 0
      ? `Berhasil mengalihkan ${result.success} dari ${total} pekerjaan.`
      : 'Tidak ada data yang berhasil dialihkan.'
    resetReassignDialogState()
    clearSelectedJobs()
    await loadJobs()
  } catch (error) {
    reassignDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengalihkan pekerjaan.'
  } finally {
    reassignDialog.saving = false
  }
}

const takeOver = async (row: JobRow) => {
  if (!import.meta.client) return
  if (!window.confirm('Ambil alih pekerjaan ini sekarang?')) return
  actionLoadingKey.value = rowActionKey(row)
  jobsError.value = ''
  try {
    const result = await executeReassign([row], { takeOver: true })
    if (result.failed > 0) {
      jobsError.value = result.errorMessage || 'Gagal mengambil alih pekerjaan.'
    } else {
      jobsError.value = ''
    }
    jobsMessage.value = result.success > 0
      ? 'Berhasil mengambil alih pekerjaan.'
      : 'Tidak ada data yang berhasil diproses.'
    clearSelectedJobs()
    await loadJobs()
  } catch (error) {
    jobsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengambil alih pekerjaan.'
  } finally {
    actionLoadingKey.value = ''
  }
}

const takeOverSelected = async () => {
  if (!import.meta.client) return
  if (!selectedJobs.value.length) {
    jobsError.value = 'Pilih minimal satu pekerjaan terlebih dahulu.'
    return
  }
  if (!window.confirm(`Ambil alih ${selectedJobs.value.length} pekerjaan terpilih sekarang?`)) return

  bulkActionLoading.value = true
  jobsError.value = ''
  try {
    const total = selectedJobs.value.length
    const result = await executeReassign(selectedJobs.value, { takeOver: true })
    if (result.failed > 0) {
      jobsError.value = result.errorMessage || `${result.failed} dari ${total} data gagal diambil alih.`
    }
    jobsMessage.value = result.success > 0
      ? `Berhasil mengambil alih ${result.success} dari ${total} pekerjaan.`
      : 'Tidak ada data yang berhasil diproses.'
    clearSelectedJobs()
    await loadJobs()
  } catch (error) {
    jobsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengambil alih pekerjaan massal.'
  } finally {
    bulkActionLoading.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadAssistants(), loadJobs()])
})

watch(jobsPerPage, () => {
  jobsPage.value = 1
})

watch(jobsTotalPages, () => {
  if (jobsPage.value > jobsTotalPages.value) jobsPage.value = jobsTotalPages.value
})

</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <p class="display-kicker">Kontrol Admin</p>
      <h2 class="mt-2 text-3xl font-semibold text-slate-900">Kontrol Pekerjaan</h2>
      <p class="mt-2 max-w-3xl text-sm text-slate-600">
        Panel ini khusus Admin/Super Admin untuk mengalihkan pekerjaan reportorium.
      </p>
    </SurfaceCard>

    <SurfaceCard class="overflow-hidden p-0">
      <div class="border-b border-slate-100 bg-slate-50 px-6 py-4">
        <p class="text-sm font-semibold text-slate-800">Kontrol Pekerjaan Reportorium</p>
      </div>
      <div class="space-y-4 p-6">
        <div class="flex flex-wrap items-end gap-2">
          <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Periode (Opsional)</span>
            <input v-model="monthFilter" type="month" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
          </label>
          <button
            type="button"
            class="h-10 rounded-xl border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
            @click="monthFilter = ''"
          >
            Semua Waktu
          </button>
          <label class="flex min-w-[220px] flex-1 flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Modul</span>
            <select v-model="moduleFilter" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option v-for="option in moduleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
          <label class="flex min-w-[220px] flex-1 flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Asisten</span>
            <select v-model="assigneeFilter" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option value="all">Semua Asisten</option>
              <option v-for="assistant in assistants" :key="String(assistant.id_user)" :value="String(assistant.id_user || '')">
                {{ assistant.nama_lengkap || assistant.id_user }} ({{ assistant.level_user || 'User' }})
              </option>
            </select>
          </label>
          <label class="flex min-w-[260px] flex-[1.4] flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pencarian</span>
            <input
              v-model="search"
              type="text"
              placeholder="Cari no, judul, asisten, atau id record..."
              class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              @keyup.enter="loadJobs"
            />
          </label>
          <button type="button" class="h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" :disabled="loadingJobs" @click="loadJobs">
            {{ loadingJobs ? 'Memuat...' : 'Refresh' }}
          </button>
        </div>

        <p v-if="jobsMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ jobsMessage }}</p>
        <p v-if="jobsError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ jobsError }}</p>

        <div v-if="jobs.length" class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
          <span class="text-xs font-semibold text-slate-600">
            Terpilih {{ selectedJobs.length }} pekerjaan
          </span>
          <button
            type="button"
            class="inline-flex h-8 items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="!selectedJobs.length || bulkActionLoading"
            @click="openBulkReassignDialog"
          >
            Alihkan Massal
          </button>
          <button
            type="button"
            class="inline-flex h-8 items-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="!selectedJobs.length || bulkActionLoading"
            @click="takeOverSelected"
          >
            {{ bulkActionLoading ? 'Memproses...' : 'Take Over Massal' }}
          </button>
          <button
            type="button"
            class="inline-flex h-8 items-center rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="!selectedJobs.length || bulkActionLoading"
            @click="clearSelectedJobs"
          >
            Reset Pilihan
          </button>
        </div>

        <div v-if="loadingJobs" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Memuat pekerjaan reportorium...
        </div>
        <div v-else-if="!jobs.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Data pekerjaan tidak ditemukan.
        </div>
        <div v-else class="overflow-hidden rounded-xl border border-slate-200">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 bg-white">
              <thead class="bg-slate-100/80">
                <tr>
                  <th class="w-12 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                      :checked="isAllJobsSelected"
                      @change="onToggleSelectAll"
                    />
                  </th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Modul</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Record</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nomor</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Judul</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Asisten Saat Ini</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Tanggal</th>
                  <th class="w-[220px] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="row in paginatedJobs" :key="`${row.module_path}-${row.record_id}`">
                  <td class="px-3 py-2 align-top">
                    <input
                      type="checkbox"
                      class="mt-1 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                      :checked="isRowSelected(row)"
                      @change="onToggleSelectRow(row, $event)"
                    />
                  </td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ moduleLabelMap[String(row.module_path || '')] || row.module_path || '-' }}</td>
                  <td class="px-3 py-2 text-sm font-semibold text-slate-800">{{ row.record_id || '-' }}</td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ row.nomor || '-' }}</td>
                  <td class="max-w-[280px] px-3 py-2 text-sm text-slate-700">
                    <span class="block truncate" :title="String(row.judul || '-')">{{ row.judul || '-' }}</span>
                  </td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ row.assignee_name || '-' }}</td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ formatDateTime(row.tanggal || row.created_at) }}</td>
                  <td class="px-3 py-2">
                    <div class="flex flex-wrap gap-2">
                      <button
                        type="button"
                        class="inline-flex h-8 items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="isRowActionLoading(row)"
                        @click="openReassignDialog(row)"
                      >
                        Alihkan
                      </button>
                      <button
                        type="button"
                        class="inline-flex h-8 items-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="isRowActionLoading(row)"
                        @click="takeOver(row)"
                      >
                        {{ isRowActionLoading(row) ? 'Memproses...' : 'Take Over' }}
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-3 py-2">
            <p class="text-xs text-slate-600">
              Menampilkan {{ jobsStart }} - {{ jobsEnd }} dari {{ jobsTotalRows }} data.
            </p>
            <div class="flex items-center gap-2">
              <select v-model.number="jobsPerPage" class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option v-for="size in jobsPerPageOptions" :key="`jobs-size-${size}`" :value="size">{{ size }}</option>
              </select>
              <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="jobsPage <= 1" @click="jobsPage -= 1">
                Prev
              </button>
              <span class="px-2 text-xs font-semibold text-slate-700">{{ jobsPage }} / {{ jobsTotalPages }}</span>
              <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="jobsPage >= jobsTotalPages" @click="jobsPage += 1">
                Next
              </button>
            </div>
          </div>
        </div>
      </div>
    </SurfaceCard>

    <Teleport to="body">
      <div v-if="reassignDialog.open" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeReassignDialog" />
        <div class="relative z-10 w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <h3 class="text-lg font-semibold text-slate-900">
            {{ reassignDialog.rows.length > 1 ? 'Alihkan Pekerjaan Massal' : 'Alihkan Pekerjaan' }}
          </h3>
          <p v-if="reassignDialog.rows.length === 1" class="mt-1 text-sm text-slate-600">
            {{ moduleLabelMap[String(reassignDialog.rows[0]?.module_path || '')] || reassignDialog.rows[0]?.module_path || '-' }} -
            ID {{ reassignDialog.rows[0]?.record_id || '-' }}
          </p>
          <p v-else class="mt-1 text-sm text-slate-600">
            {{ reassignDialog.rows.length }} pekerjaan dipilih untuk dialihkan ke asisten tujuan.
          </p>
          <p class="mt-1 text-xs text-slate-500">
            {{ reassignDialog.rows.length === 1
              ? `Asisten saat ini: ${reassignDialog.rows[0]?.assignee_name || '-'}`
              : 'Semua data terpilih akan diproses sekaligus.' }}
          </p>

          <label class="mt-4 flex flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Asisten Tujuan</span>
            <select v-model="reassignDialog.targetIdUser" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option value="">{{ loadingAssistants ? 'Memuat asisten...' : 'Pilih Asisten' }}</option>
              <option v-for="assistant in assistants" :key="String(assistant.id_user)" :value="assistant.id_user">
                {{ assistant.nama_lengkap || assistant.id_user }} ({{ assistant.level_user || 'User' }})
              </option>
            </select>
          </label>

          <p v-if="reassignDialog.error" class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ reassignDialog.error }}
          </p>

          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="closeReassignDialog">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60" :disabled="reassignDialog.saving" @click="saveReassign">
              {{ reassignDialog.saving ? 'Menyimpan...' : `Simpan Pengalihan (${reassignDialog.rows.length})` }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
