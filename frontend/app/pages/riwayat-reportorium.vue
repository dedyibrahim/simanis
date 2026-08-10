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
  middleware: 'auth',
})

useHead({
  title: 'Riwayat Reportorium',
})

const { user } = useSession()
const business = useLegacyBusiness()

const isAdminRole = computed(() => {
  const role = String(user.value?.level_user || '').trim().toLowerCase()
  return role === 'admin' || role === 'super admin' || role === 'superadmin'
})

const moduleOptions = [
  { value: 'all', label: 'Semua Modul Reportorium' },
  { value: '/buku_akta', label: 'Buku Akta Notaris' },
  { value: '/buku_legalisasi', label: 'Buku Legalisasi' },
  { value: '/buku_waarmerking', label: 'Buku Waarmerking' },
  { value: '/buku_ppat', label: 'Buku PPAT' },
  { value: '/buku_surat_notaris', label: 'Surat Notaris' },
  { value: '/buku_surat_ppat', label: 'Surat PPAT' },
  { value: '/tanda_terima', label: 'Tanda Terima Keluar' },
  { value: '/tanda_terima_masuk', label: 'Tanda Terima Masuk' },
]

const moduleLabelMap = Object.fromEntries(moduleOptions.map(item => [item.value, item.label]))

const moduleFilter = ref('all')
const assigneeFilter = ref<'all' | 'me' | string>('me')
const search = ref('')
const loading = ref(false)
const errorMessage = ref('')
const responseMessage = ref('')
const rows = ref<JobRow[]>([])

const assistants = ref<AssistantRow[]>([])
const loadingAssistants = ref(false)

const page = ref(1)
const perPage = ref(20)

const totalRows = computed(() => rows.value.length)
const totalPages = computed(() => Math.max(1, Math.ceil(totalRows.value / perPage.value)))

const paginatedRows = computed(() => {
  const start = (page.value - 1) * perPage.value
  return rows.value.slice(start, start + perPage.value)
})

const rowStart = computed(() => {
  if (!totalRows.value) return 0
  return (page.value - 1) * perPage.value + 1
})

const rowEnd = computed(() => Math.min(page.value * perPage.value, totalRows.value))

const toList = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && 'data' in payload) {
    const data = (payload as ApiEnvelope<unknown>).data
    if (Array.isArray(data)) return data as T[]
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
  const parsed = new Date(raw)
  if (Number.isNaN(parsed.getTime())) return raw
  return parsed.toLocaleString('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const loadAssistants = async () => {
  if (!isAdminRole.value || loadingAssistants.value || assistants.value.length) return
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

const normalizeAssigneeFilter = () => {
  if (!isAdminRole.value) return ''

  if (assigneeFilter.value === 'me') {
    return String(user.value?.id_user || '')
  }

  if (assigneeFilter.value === 'all') {
    return 'all'
  }

  return String(assigneeFilter.value || '').trim()
}

const loadRows = async () => {
  loading.value = true
  errorMessage.value = ''
  responseMessage.value = ''
  try {
    const query: Record<string, string> = {}
    if (moduleFilter.value !== 'all') query.module_path = moduleFilter.value
    if (search.value.trim()) query.search = search.value.trim()

    const selectedAssignee = normalizeAssigneeFilter()
    if (selectedAssignee) {
      query.assignee_id = selectedAssignee
    }

    const response = await business.adminWork.getReportoriumJobs(query) as ApiEnvelope<JobRow[]>
    rows.value = toList<JobRow>(response)
    responseMessage.value = response.message || ''
    page.value = 1
  } catch (error) {
    rows.value = []
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat riwayat reportorium.'
  } finally {
    loading.value = false
  }
}

const prevPage = () => {
  if (page.value > 1) page.value -= 1
}

const nextPage = () => {
  if (page.value < totalPages.value) page.value += 1
}

watch(perPage, () => {
  page.value = 1
})

watch(totalPages, () => {
  if (page.value > totalPages.value) page.value = totalPages.value
})

onMounted(async () => {
  await loadAssistants()
  await loadRows()
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <p class="display-kicker">Riwayat Reportorium</p>
      <h2 class="mt-2 text-3xl font-semibold text-slate-900">Daftar Pekerjaan Yang Pernah Dikerjakan</h2>
      <p class="mt-2 max-w-3xl text-sm text-slate-600">
        Halaman ini menampilkan riwayat pekerjaan reportorium tanpa wajib filter bulan.
      </p>
    </SurfaceCard>

    <SurfaceCard class="overflow-hidden p-0">
      <div class="border-b border-slate-100 bg-slate-50 px-6 py-4">
        <p class="text-sm font-semibold text-slate-800">Filter Riwayat</p>
      </div>
      <div class="space-y-4 p-6">
        <div class="flex flex-wrap items-end gap-2">
          <label class="flex min-w-[220px] flex-1 flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Modul</span>
            <select v-model="moduleFilter" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option v-for="option in moduleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>

          <label v-if="isAdminRole" class="flex min-w-[220px] flex-1 flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Asisten</span>
            <select v-model="assigneeFilter" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option value="me">Saya</option>
              <option value="all">Semua Asisten</option>
              <option v-for="assistant in assistants" :key="String(assistant.id_user)" :value="String(assistant.id_user || '')">
                {{ assistant.nama_lengkap || assistant.id_user }} ({{ assistant.level_user || 'User' }})
              </option>
            </select>
          </label>

          <label class="flex min-w-[260px] flex-[1.2] flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pencarian</span>
            <input
              v-model="search"
              type="text"
              placeholder="Cari no, judul, modul, atau nama asisten..."
              class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              @keyup.enter="loadRows"
            />
          </label>

          <button type="button" class="h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" :disabled="loading" @click="loadRows">
            {{ loading ? 'Memuat...' : 'Refresh' }}
          </button>
        </div>

        <p v-if="responseMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ responseMessage }}</p>
        <p v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

        <div v-if="loading" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Memuat riwayat reportorium...
        </div>
        <div v-else-if="!rows.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Belum ada data riwayat untuk filter ini.
        </div>
        <div v-else class="overflow-hidden rounded-xl border border-slate-200">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 bg-white">
              <thead class="bg-slate-100/80">
                <tr>
                  <th class="w-16 px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Modul</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Record</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nomor</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Judul</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Asisten</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Tanggal</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="(row, index) in paginatedRows" :key="`${row.module_path}-${row.record_id}-${index}`">
                  <td class="px-3 py-2 text-sm text-slate-600">{{ (page - 1) * perPage + index + 1 }}</td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ moduleLabelMap[String(row.module_path || '')] || row.module_path || '-' }}</td>
                  <td class="px-3 py-2 text-sm font-semibold text-slate-800">{{ row.record_id || '-' }}</td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ row.nomor || '-' }}</td>
                  <td class="max-w-[300px] px-3 py-2 text-sm text-slate-700">
                    <span class="block truncate" :title="String(row.judul || '-')">{{ row.judul || '-' }}</span>
                  </td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ row.assignee_name || '-' }}</td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ formatDateTime(row.tanggal || row.created_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="rows.length" class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
          <p class="text-xs text-slate-600">
            Menampilkan {{ rowStart }} - {{ rowEnd }} dari {{ totalRows }} data.
          </p>
          <div class="flex items-center gap-2">
            <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
              <span>Per halaman</span>
              <select v-model.number="perPage" class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option :value="10">10</option>
                <option :value="20">20</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
              </select>
            </label>
            <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60" :disabled="page <= 1" @click="prevPage">
              Sebelumnya
            </button>
            <span class="text-xs font-semibold text-slate-600">{{ page }} / {{ totalPages }}</span>
            <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-60" :disabled="page >= totalPages" @click="nextPage">
              Berikutnya
            </button>
          </div>
        </div>
      </div>
    </SurfaceCard>
  </div>
</template>
