<script setup lang="ts">
type ReconcileRow = {
  type: string
  module: string
  period: string
  nomor: string
  production_record_ids: string
  production_raw_numbers: string
  mature_raw_numbers: string
  mature_files: string
}

type SummaryRow = {
  type: string
  module: string
  count: string | number
}

type ReviewStatus = 'perlu_cek' | 'salah_tanggal' | 'salah_nomor' | 'duplikat_valid' | 'sudah_diperbaiki' | 'abaikan'

definePageMeta({
  middleware: ['auth', 'admin-only'],
})

useHead({
  title: 'Rekonsiliasi Nomor',
})

const rows = ref<ReconcileRow[]>([])
const summaryRows = ref<SummaryRow[]>([])
const loading = ref(true)
const errorMessage = ref('')
const typeFilter = ref('all')
const moduleFilter = ref('all')
const periodFilter = ref('')
const search = ref('')
const statusFilter = ref('all')
const page = ref(1)
const perPage = ref(50)
const reviewMap = ref<Record<string, ReviewStatus>>({})

const statusOptions: Array<{ value: ReviewStatus, label: string }> = [
  { value: 'perlu_cek', label: 'Perlu cek' },
  { value: 'salah_tanggal', label: 'Salah tanggal' },
  { value: 'salah_nomor', label: 'Salah nomor' },
  { value: 'duplikat_valid', label: 'Duplikat valid' },
  { value: 'sudah_diperbaiki', label: 'Sudah diperbaiki' },
  { value: 'abaikan', label: 'Abaikan' },
]

const typeLabels: Record<string, string> = {
  ADA_DI_PRODUCTION_TIDAK_ADA_DI_DATA_MATANG: 'Ada di production, tidak ada di data matang',
  ADA_DI_DATA_MATANG_TIDAK_ADA_DI_PRODUCTION: 'Ada di data matang, tidak ada di production',
  DUPLIKAT_DI_PRODUCTION: 'Duplikat di production',
  DUPLIKAT_DI_DATA_MATANG: 'Duplikat di data matang',
}

const modulePaths: Record<string, string> = {
  Notaris: '/buku_akta',
  Legalisasi: '/buku_legalisasi',
  Warmerking: '/buku_waarmerking',
  PPAT: '/buku_ppat',
}

const storageKey = 'simanis:rekonsiliasi-nomor:2016-2021:review-status'

const rowKey = (row: ReconcileRow) =>
  `${row.type}|${row.module}|${row.period}|${row.nomor}|${row.production_record_ids}|${row.mature_files}`

const getStatus = (row: ReconcileRow): ReviewStatus =>
  reviewMap.value[rowKey(row)] || 'perlu_cek'

const setStatus = (row: ReconcileRow, status: ReviewStatus) => {
  reviewMap.value = {
    ...reviewMap.value,
    [rowKey(row)]: status,
  }
  if (import.meta.client) {
    localStorage.setItem(storageKey, JSON.stringify(reviewMap.value))
  }
}

const typeOptions = computed(() => [
  { value: 'all', label: 'Semua tipe' },
  ...Array.from(new Set(rows.value.map(row => row.type))).map(type => ({
    value: type,
    label: typeLabels[type] || type,
  })),
])

const moduleOptions = computed(() => [
  { value: 'all', label: 'Semua modul' },
  ...Array.from(new Set(rows.value.map(row => row.module))).sort().map(module => ({
    value: module,
    label: module,
  })),
])

const filteredRows = computed(() => {
  const keyword = search.value.trim().toLowerCase()
  return rows.value.filter((row) => {
    if (typeFilter.value !== 'all' && row.type !== typeFilter.value) return false
    if (moduleFilter.value !== 'all' && row.module !== moduleFilter.value) return false
    if (periodFilter.value && row.period !== periodFilter.value) return false
    if (statusFilter.value !== 'all' && getStatus(row) !== statusFilter.value) return false
    if (!keyword) return true
    return [
      row.type,
      row.module,
      row.period,
      row.nomor,
      row.production_record_ids,
      row.production_raw_numbers,
      row.mature_raw_numbers,
      row.mature_files,
    ].some(value => String(value || '').toLowerCase().includes(keyword))
  })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredRows.value.length / perPage.value)))
const paginatedRows = computed(() => {
  const currentPage = Math.min(page.value, totalPages.value)
  const start = (currentPage - 1) * perPage.value
  return filteredRows.value.slice(start, start + perPage.value)
})

const cards = computed(() => {
  const grouped = new Map<string, number>()
  for (const row of rows.value) {
    const key = typeLabels[row.type] || row.type
    grouped.set(key, (grouped.get(key) || 0) + 1)
  }
  return Array.from(grouped.entries()).map(([label, count]) => ({ label, count }))
})

const statusCounts = computed(() => {
  const counts: Record<string, number> = {}
  for (const row of rows.value) {
    const status = getStatus(row)
    counts[status] = (counts[status] || 0) + 1
  }
  return counts
})

const formatType = (type: string) => typeLabels[type] || type
const statusLabel = (status: string) => statusOptions.find(item => item.value === status)?.label || status

const openProductionRecord = (row: ReconcileRow) => {
  const path = modulePaths[row.module]
  if (!path) return
  const query = row.production_record_ids || row.production_raw_numbers || row.nomor
  navigateTo({ path, query: { q: query } })
}

const exportFilteredCsv = () => {
  if (!import.meta.client) return
  const headers = ['type', 'module', 'period', 'nomor', 'status', 'production_record_ids', 'production_raw_numbers', 'mature_raw_numbers', 'mature_files']
  const escape = (value: unknown) => `"${String(value ?? '').replace(/"/g, '""')}"`
  const lines = [
    headers.join(','),
    ...filteredRows.value.map(row => [
      formatType(row.type),
      row.module,
      row.period,
      row.nomor,
      statusLabel(getStatus(row)),
      row.production_record_ids,
      row.production_raw_numbers,
      row.mature_raw_numbers,
      row.mature_files,
    ].map(escape).join(',')),
  ]
  const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'rekonsiliasi-nomor-filtered.csv'
  link.click()
  URL.revokeObjectURL(url)
}

watch([typeFilter, moduleFilter, periodFilter, search, statusFilter, perPage], () => {
  page.value = 1
})

onMounted(async () => {
  try {
    if (import.meta.client) {
      const stored = localStorage.getItem(storageKey)
      if (stored) reviewMap.value = JSON.parse(stored)
    }
    const [detail, summary] = await Promise.all([
      $fetch<ReconcileRow[]>('/reports/number-anomalies-2016-2021-detail.json'),
      $fetch<SummaryRow[]>('/reports/number-anomalies-2016-2021-summary.json'),
    ])
    rows.value = detail || []
    summaryRows.value = summary || []
  } catch (error) {
    errorMessage.value = (error as { message?: string })?.message || 'Gagal memuat data rekonsiliasi nomor.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <section class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Rekonsiliasi Nomor</p>
          <h1 class="mt-2 text-2xl font-semibold text-slate-900">Data Matang vs Production 2016-2021</h1>
          <p class="mt-2 max-w-3xl text-sm text-slate-500">
            Review selisih nomor per modul, bulan, dan nomor. Halaman ini hanya membaca snapshot CSV/JSON, tidak mengubah database production.
          </p>
        </div>
        <button
          type="button"
          class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          @click="exportFilteredCsv"
        >
          Export Filter
        </button>
      </div>

      <p v-if="errorMessage" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errorMessage }}
      </p>
    </section>

    <section class="grid gap-4 md:grid-cols-4">
      <div
        v-for="card in cards"
        :key="card.label"
        class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ card.label }}</p>
        <p class="mt-3 text-3xl font-semibold text-slate-900">{{ card.count }}</p>
      </div>
    </section>

    <section class="grid gap-4 md:grid-cols-6">
      <div
        v-for="option in statusOptions"
        :key="option.value"
        class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
      >
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ option.label }}</p>
        <p class="mt-2 text-2xl font-semibold text-blue-700">{{ statusCounts[option.value] || 0 }}</p>
      </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="grid gap-3 lg:grid-cols-6">
        <select v-model="typeFilter" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
          <option v-for="option in typeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <select v-model="moduleFilter" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
          <option v-for="option in moduleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <input v-model.trim="periodFilter" type="month" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
        <select v-model="statusFilter" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
          <option value="all">Semua status</option>
          <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <input v-model.trim="search" type="search" class="lg:col-span-2 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" placeholder="Cari nomor, record id, file..." />
      </div>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
        <p class="text-sm font-semibold text-slate-700">
          {{ filteredRows.length }} anomali ditampilkan dari {{ rows.length }} total
        </p>
        <div class="flex items-center gap-2">
          <select v-model.number="perPage" class="h-9 rounded-lg border border-slate-300 bg-white px-2 text-xs font-medium text-slate-700">
            <option :value="25">25</option>
            <option :value="50">50</option>
            <option :value="100">100</option>
          </select>
          <button type="button" class="h-9 rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-700 disabled:opacity-50" :disabled="page <= 1" @click="page -= 1">
            Prev
          </button>
          <span class="px-2 text-xs font-semibold text-slate-700">{{ page }} / {{ totalPages }}</span>
          <button type="button" class="h-9 rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-700 disabled:opacity-50" :disabled="page >= totalPages" @click="page += 1">
            Next
          </button>
        </div>
      </div>

      <div v-if="loading" class="p-6 text-sm text-slate-500">Memuat data rekonsiliasi...</div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-[1320px] divide-y divide-slate-200">
          <thead class="bg-slate-100/80">
            <tr>
              <th class="w-[240px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Tipe</th>
              <th class="w-[120px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Modul</th>
              <th class="w-[100px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Periode</th>
              <th class="w-[100px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nomor</th>
              <th class="w-[190px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Record Production</th>
              <th class="w-[190px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nomor Production</th>
              <th class="w-[170px] px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Nomor Matang</th>
              <th class="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">File Matang</th>
              <th class="sticky right-0 z-10 w-[260px] bg-slate-100/95 px-3 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-600 shadow-[-10px_0_18px_-18px_rgba(15,23,42,0.7)]">Review</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 bg-white">
            <tr v-for="row in paginatedRows" :key="rowKey(row)" class="hover:bg-sky-50/40">
              <td class="px-3 py-3 text-sm font-semibold text-slate-800">{{ formatType(row.type) }}</td>
              <td class="px-3 py-3 text-sm text-slate-700">{{ row.module }}</td>
              <td class="px-3 py-3 text-sm text-slate-700">{{ row.period }}</td>
              <td class="px-3 py-3 text-sm">
                <span class="inline-flex min-w-[56px] justify-center rounded-lg bg-blue-50 px-2 py-1 text-xs font-semibold text-blue-700">{{ row.nomor }}</span>
              </td>
              <td class="px-3 py-3 text-sm text-slate-700">{{ row.production_record_ids || '-' }}</td>
              <td class="px-3 py-3 text-sm text-slate-700">{{ row.production_raw_numbers || '-' }}</td>
              <td class="px-3 py-3 text-sm text-slate-700">{{ row.mature_raw_numbers || '-' }}</td>
              <td class="max-w-[360px] px-3 py-3 text-xs text-slate-500">
                <span class="block truncate" :title="row.mature_files">{{ row.mature_files || '-' }}</span>
              </td>
              <td class="sticky right-0 z-10 bg-white px-3 py-3 text-sm shadow-[-10px_0_18px_-18px_rgba(15,23,42,0.7)]">
                <div class="flex flex-wrap items-center gap-2">
                  <select
                    :value="getStatus(row)"
                    class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs font-semibold text-slate-700"
                    @change="setStatus(row, ($event.target as HTMLSelectElement).value as ReviewStatus)"
                  >
                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                  </select>
                  <button
                    v-if="row.production_record_ids"
                    type="button"
                    class="h-8 rounded-lg border border-blue-200 bg-blue-50 px-3 text-xs font-semibold text-blue-700 transition hover:bg-blue-100"
                    @click="openProductionRecord(row)"
                  >
                    Buka
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
