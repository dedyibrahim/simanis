<script setup lang="ts">
type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type DownloadRequestRow = {
  id?: number
  batch_id?: string
  batch_label?: string
  module_path?: string
  row_id?: string
  file_name?: string
  display_name?: string
  file_category?: string
  requested_by_id_user?: string
  requested_by_name?: string
  status?: 'pending' | 'approved' | 'rejected' | string
  approved_by_name?: string
  approved_at?: string
  created_at?: string
  note?: string
}

definePageMeta({
  middleware: ['auth', 'admin-only'],
})

useHead({
  title: 'Persetujuan Dokumen',
})

const business = useLegacyBusiness()

const requestStatusFilter = ref<'pending' | 'approved' | 'rejected' | 'all'>('pending')
const requestSearch = ref('')
const requestRows = ref<DownloadRequestRow[]>([])
const loadingRequests = ref(false)
const requestsError = ref('')
const requestMessage = ref('')
const decidingId = ref<number | null>(null)
const bulkDeciding = ref(false)
const selectedRequestIds = ref<number[]>([])
const requestPage = ref(1)
const requestPerPage = ref(20)
const requestPerPageOptions = [20, 50, 100]

const moduleOptions = [
  { value: '/buku_akta', label: 'Buku Akta Notaris' },
  { value: '/buku_legalisasi', label: 'Buku Legalisasi' },
  { value: '/buku_waarmerking', label: 'Buku Waarmerking' },
  { value: '/buku_ppat', label: 'Buku PPAT' },
  { value: '/buku_surat_notaris', label: 'Surat Notaris' },
  { value: '/buku_surat_ppat', label: 'Surat PPAT' },
  { value: '/tanda_terima', label: 'Tanda Terima Keluar' },
  { value: '/tanda_terima_masuk', label: 'Tanda Terima Masuk' },
  { value: '/pencarian-dokumen', label: 'Pencarian Dokumen' },
]

const moduleLabelMap = Object.fromEntries(moduleOptions.map(item => [item.value, item.label]))

const requestTotalRows = computed(() => requestRows.value.length)
const requestTotalPages = computed(() => Math.max(1, Math.ceil(requestTotalRows.value / requestPerPage.value)))
const paginatedRequestRows = computed(() => {
  const start = (requestPage.value - 1) * requestPerPage.value
  return requestRows.value.slice(start, start + requestPerPage.value)
})
const requestStart = computed(() => {
  if (!requestTotalRows.value) return 0
  return (requestPage.value - 1) * requestPerPage.value + 1
})
const requestEnd = computed(() => Math.min(requestStart.value + requestPerPage.value - 1, requestTotalRows.value))
const pendingVisibleRows = computed(() =>
  paginatedRequestRows.value.filter(row => row.id && String(row.status || '').toLowerCase() === 'pending'),
)
const selectedCount = computed(() => selectedRequestIds.value.length)
const allVisiblePendingSelected = computed(() =>
  pendingVisibleRows.value.length > 0
  && pendingVisibleRows.value.every(row => selectedRequestIds.value.includes(Number(row.id))),
)

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

const statusBadgeClass = (status: unknown) => {
  const value = String(status || '').toLowerCase()
  if (value === 'approved') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  if (value === 'rejected') return 'border-red-200 bg-red-50 text-red-700'
  return 'border-amber-200 bg-amber-50 text-amber-700'
}

const loadRequests = async () => {
  loadingRequests.value = true
  requestsError.value = ''
  requestMessage.value = ''
  try {
    const query: Record<string, string> = {
      status: requestStatusFilter.value,
      limit: '300',
    }
    if (requestSearch.value.trim()) query.search = requestSearch.value.trim()
    const response = await business.documentAccess.listRequests(query) as ApiEnvelope<DownloadRequestRow[]>
    requestRows.value = toList<DownloadRequestRow>(response)
    selectedRequestIds.value = []
    requestPage.value = 1
    requestMessage.value = response.message || ''
  } catch (error) {
    requestRows.value = []
    requestsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat data persetujuan download.'
  } finally {
    loadingRequests.value = false
  }
}

const toggleRequestSelection = (row: DownloadRequestRow) => {
  if (!row.id || String(row.status || '').toLowerCase() !== 'pending') return
  const id = Number(row.id)
  selectedRequestIds.value = selectedRequestIds.value.includes(id)
    ? selectedRequestIds.value.filter(item => item !== id)
    : [...selectedRequestIds.value, id]
}

const toggleAllVisiblePending = () => {
  const ids = pendingVisibleRows.value.map(row => Number(row.id)).filter(Number.isFinite)
  if (!ids.length) return
  if (allVisiblePendingSelected.value) {
    selectedRequestIds.value = selectedRequestIds.value.filter(id => !ids.includes(id))
    return
  }
  selectedRequestIds.value = Array.from(new Set([...selectedRequestIds.value, ...ids]))
}

const decideRequest = async (row: DownloadRequestRow, decision: 'approved' | 'rejected') => {
  if (!row.id || decidingId.value) return
  if (!import.meta.client) return

  const actionText = decision === 'approved' ? 'setujui' : 'tolak'
  if (!window.confirm(`Yakin ingin ${actionText} permintaan download ini?`)) return

  decidingId.value = row.id
  requestsError.value = ''
  try {
    const response = await business.documentAccess.decide(row.id, { decision }) as ApiEnvelope
    requestMessage.value = response.message || 'Status request berhasil diperbarui.'
    await loadRequests()
  } catch (error) {
    requestsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperbarui status request.'
  } finally {
    decidingId.value = null
  }
}

const decideSelectedRequests = async (decision: 'approved' | 'rejected') => {
  if (!selectedRequestIds.value.length || bulkDeciding.value) return
  if (!import.meta.client) return

  const actionText = decision === 'approved' ? 'setujui' : 'tolak'
  if (!window.confirm(`Yakin ingin ${actionText} ${selectedRequestIds.value.length} permintaan download terpilih?`)) return

  bulkDeciding.value = true
  requestsError.value = ''
  try {
    const response = await business.documentAccess.bulkDecide({
      ids: selectedRequestIds.value,
      decision,
    }) as ApiEnvelope
    requestMessage.value = response.message || 'Status request terpilih berhasil diperbarui.'
    selectedRequestIds.value = []
    await loadRequests()
  } catch (error) {
    requestsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperbarui status request terpilih.'
  } finally {
    bulkDeciding.value = false
  }
}

onMounted(async () => {
  await loadRequests()
})

watch(requestPerPage, () => {
  requestPage.value = 1
  selectedRequestIds.value = []
})

watch(requestTotalPages, () => {
  if (requestPage.value > requestTotalPages.value) requestPage.value = requestTotalPages.value
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <p class="display-kicker">Kontrol Admin</p>
      <h2 class="mt-2 text-3xl font-semibold text-slate-900">Persetujuan Dokumen</h2>
      <p class="mt-2 max-w-3xl text-sm text-slate-600">
        Panel ini khusus Admin/Super Admin untuk mengatur izin download dokumen.
      </p>
    </SurfaceCard>

    <SurfaceCard class="overflow-hidden p-0">
      <div class="border-b border-slate-100 bg-slate-50 px-6 py-4">
        <p class="text-sm font-semibold text-slate-800">Persetujuan Download Dokumen</p>
      </div>
      <div class="space-y-4 p-6">
        <div class="flex flex-wrap items-end gap-2">
          <label class="flex flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Status</span>
            <select v-model="requestStatusFilter" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="all">Semua</option>
            </select>
          </label>
          <label class="flex min-w-[280px] flex-1 flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pencarian</span>
            <input
              v-model="requestSearch"
              type="text"
              placeholder="Cari nama requester, file, atau row id..."
              class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              @keyup.enter="loadRequests"
            />
          </label>
          <button type="button" class="h-10 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" :disabled="loadingRequests" @click="loadRequests">
            {{ loadingRequests ? 'Memuat...' : 'Refresh' }}
          </button>
        </div>

        <p v-if="requestMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{{ requestMessage }}</p>
        <p v-if="requestsError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ requestsError }}</p>

        <div
          v-if="selectedCount"
          class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3"
        >
          <p class="text-sm font-semibold text-blue-800">{{ selectedCount }} request dipilih.</p>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="inline-flex h-9 items-center rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="bulkDeciding"
              @click="decideSelectedRequests('approved')"
            >
              Setujui Terpilih
            </button>
            <button
              type="button"
              class="inline-flex h-9 items-center rounded-lg bg-red-600 px-3 text-xs font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="bulkDeciding"
              @click="decideSelectedRequests('rejected')"
            >
              Tolak Terpilih
            </button>
            <button
              type="button"
              class="inline-flex h-9 items-center rounded-lg border border-blue-300 bg-white px-3 text-xs font-semibold text-blue-700 transition hover:bg-blue-50"
              :disabled="bulkDeciding"
              @click="selectedRequestIds = []"
            >
              Batal Pilih
            </button>
          </div>
        </div>

        <div v-if="loadingRequests" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Memuat data persetujuan download...
        </div>
        <div v-else-if="!requestRows.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Belum ada request download untuk filter ini.
        </div>
        <div v-else class="overflow-hidden rounded-xl border border-slate-200">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 bg-white">
              <thead class="bg-slate-100/80">
                <tr>
                  <th class="w-10 px-3 py-2 text-left">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                      :checked="allVisiblePendingSelected"
                      :disabled="!pendingVisibleRows.length"
                      @change="toggleAllVisiblePending"
                    />
                  </th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Requester</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Modul</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Row ID</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">File</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Status</th>
                  <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Waktu Request</th>
                  <th class="w-[210px] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="row in paginatedRequestRows" :key="String(row.id)">
                  <td class="px-3 py-2">
                    <input
                      type="checkbox"
                      class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 disabled:opacity-40"
                      :checked="Boolean(row.id && selectedRequestIds.includes(Number(row.id)))"
                      :disabled="String(row.status || '').toLowerCase() !== 'pending'"
                      @change="toggleRequestSelection(row)"
                    />
                  </td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ row.requested_by_name || '-' }}</td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ moduleLabelMap[String(row.module_path || '')] || row.module_path || '-' }}</td>
                  <td class="px-3 py-2 text-sm font-semibold text-slate-800">{{ row.row_id || '-' }}</td>
                  <td class="max-w-[280px] px-3 py-2 text-sm text-slate-700">
                    <span class="block truncate font-semibold text-slate-800" :title="String(row.display_name || row.file_name || '-')">
                      {{ row.display_name || row.file_name || '-' }}
                    </span>
                    <span v-if="row.display_name && row.file_name" class="mt-1 block truncate text-[11px] text-slate-400" :title="String(row.file_name)">
                      Internal: {{ row.file_name }}
                    </span>
                    <span v-if="row.batch_id" class="mt-1 block truncate text-[11px] font-semibold text-blue-600" :title="row.batch_id">
                      Batch: {{ row.batch_label || row.batch_id }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-sm">
                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass(row.status)">
                      {{ String(row.status || '-').toUpperCase() }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-sm text-slate-700">{{ formatDateTime(row.created_at) }}</td>
                  <td class="px-3 py-2">
                    <div class="flex flex-wrap gap-2">
                      <button
                        v-if="String(row.status || '').toLowerCase() === 'pending'"
                        type="button"
                        class="inline-flex h-8 items-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="decidingId === row.id"
                        @click="decideRequest(row, 'approved')"
                      >
                        Setujui
                      </button>
                      <button
                        v-if="String(row.status || '').toLowerCase() === 'pending'"
                        type="button"
                        class="inline-flex h-8 items-center rounded-lg border border-red-200 bg-red-50 px-3 text-xs font-semibold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="decidingId === row.id"
                        @click="decideRequest(row, 'rejected')"
                      >
                        Tolak
                      </button>
                      <span v-if="String(row.status || '').toLowerCase() !== 'pending'" class="text-xs text-slate-500">
                        {{ row.approved_by_name ? `Diproses: ${row.approved_by_name}` : 'Selesai diproses' }}
                      </span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 bg-slate-50 px-3 py-2">
            <p class="text-xs text-slate-600">
              Menampilkan {{ requestStart }} - {{ requestEnd }} dari {{ requestTotalRows }} data.
            </p>
            <div class="flex items-center gap-2">
              <select v-model.number="requestPerPage" class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option v-for="size in requestPerPageOptions" :key="`requests-size-${size}`" :value="size">{{ size }}</option>
              </select>
              <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="requestPage <= 1" @click="requestPage -= 1">
                Prev
              </button>
              <span class="px-2 text-xs font-semibold text-slate-700">{{ requestPage }} / {{ requestTotalPages }}</span>
              <button type="button" class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50" :disabled="requestPage >= requestTotalPages" @click="requestPage += 1">
                Next
              </button>
            </div>
          </div>
        </div>
      </div>
    </SurfaceCard>
  </div>
</template>
