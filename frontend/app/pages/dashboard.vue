<script setup lang="ts">
import {
  BuildingLibraryIcon,
  CheckBadgeIcon,
  ClipboardDocumentListIcon,
  DocumentTextIcon,
  MagnifyingGlassIcon,
  TrophyIcon,
} from '@heroicons/vue/24/outline'

definePageMeta({
  middleware: 'auth',
})

useHead({
  title: 'Dashboard',
})

type ApiEnvelope<T> = {
  status?: boolean
  message?: string
  data?: T
}

type Winner = {
  judul: string
  jumlah: number | string
  nama_lengkap: string
}

type Workload = {
  title: string
  total: number | string
}

type ChartResponse = {
  categories: string[]
  notaris: Array<number | string>
  legalisasi: Array<number | string>
  waarmerking?: Array<number | string>
  warmerking?: Array<number | string>
  ppat: Array<number | string>
}

type FilterState = {
  start_date: string
  end_date: string
}

const business = useLegacyBusiness()
const { isDark } = useThemeMode()

const defaultWinners: Winner[] = [
  { judul: 'Pembuat Akta Notaris', jumlah: 0, nama_lengkap: '-' },
  { judul: 'Pembuat Akta PPAT', jumlah: 0, nama_lengkap: '-' },
  { judul: 'Pembuat Legalisasi', jumlah: 0, nama_lengkap: '-' },
  { judul: 'Pembuat Waarmerking', jumlah: 0, nama_lengkap: '-' },
]

const fallbackWinner: Winner = { judul: '-', jumlah: 0, nama_lengkap: '-' }

const defaultTotals: Workload[] = [
  { title: 'Akta Notaris', total: 0 },
  { title: 'Legalisasi', total: 0 },
  { title: 'Waarmerking', total: 0 },
  { title: 'Akta PPAT', total: 0 },
]

const winners = ref<Winner[]>([...defaultWinners])
const totals = ref<Workload[]>([...defaultTotals])
const chartData = ref<ChartResponse>({
  categories: [],
  notaris: [],
  legalisasi: [],
  waarmerking: [],
  warmerking: [],
  ppat: [],
})

const loadingWinners = ref(false)
const loadingTotals = ref(false)
const loadingChart = ref(false)
const winnerError = ref('')
const totalsError = ref('')
const chartError = ref('')
const quickSearchQuery = ref('')

const totalFilter = reactive<FilterState>({
  start_date: '',
  end_date: '',
})

const chartFilter = reactive<FilterState>({
  start_date: '',
  end_date: '',
})

const toArray = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && Array.isArray((payload as ApiEnvelope<T[]>).data)) {
    return (payload as ApiEnvelope<T[]>).data || []
  }
  return []
}

const toData = <T>(payload: unknown, fallback: T): T => {
  if (payload && typeof payload === 'object' && 'data' in payload) {
    return ((payload as ApiEnvelope<T>).data || fallback) as T
  }
  return fallback
}

const toNumber = (value: unknown) => {
  const numeric = Number(value)
  return Number.isFinite(numeric) ? numeric : 0
}

const toQuery = (filter: FilterState) => {
  const query: Record<string, string> = {}
  if (filter.start_date) query.start_date = filter.start_date
  if (filter.end_date) query.end_date = filter.end_date
  return Object.keys(query).length ? query : undefined
}

const loadWinners = async () => {
  loadingWinners.value = true
  winnerError.value = ''
  try {
    const response = await business.dashboard.getPemenang() as ApiEnvelope<Winner[]>
    const list = toArray<Winner>(response)
    winners.value = list.length ? list : [...defaultWinners]
  } catch (error) {
    winners.value = [...defaultWinners]
    winnerError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat data pemenang.'
  } finally {
    loadingWinners.value = false
  }
}

const loadTotals = async () => {
  loadingTotals.value = true
  totalsError.value = ''
  try {
    const response = await business.dashboard.getTotalPekerjaan(toQuery(totalFilter)) as ApiEnvelope<Workload[]>
    const list = toArray<Workload>(response)
    totals.value = list.length ? list : [...defaultTotals]
  } catch (error) {
    totals.value = [...defaultTotals]
    totalsError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat jumlah pekerjaan.'
  } finally {
    loadingTotals.value = false
  }
}

const loadChart = async () => {
  loadingChart.value = true
  chartError.value = ''
  try {
    const response = await business.dashboard.getGrafik(toQuery(chartFilter)) as ApiEnvelope<ChartResponse>
    chartData.value = toData(response, {
      categories: [],
      notaris: [],
      legalisasi: [],
      waarmerking: [],
      warmerking: [],
      ppat: [],
    })
  } catch (error) {
    chartData.value = {
      categories: [],
      notaris: [],
      legalisasi: [],
      waarmerking: [],
      warmerking: [],
      ppat: [],
    }
    chartError.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat grafik pekerjaan.'
  } finally {
    loadingChart.value = false
  }
}

const refreshDashboard = async () => {
  await Promise.all([loadWinners(), loadTotals(), loadChart()])
}

const openDocumentSearch = async () => {
  const query = quickSearchQuery.value.trim()
  await navigateTo({
    path: '/pencarian-dokumen',
    query: query ? { q: query } : {},
  })
}

const resolveWorkloadStyle = (title: string) => {
  if (isDark.value) {
    if (title === 'Akta Notaris') return { icon: DocumentTextIcon, className: 'bg-blue-500/15 text-blue-200 ring-1 ring-blue-400/35' }
    if (title === 'Legalisasi') return { icon: CheckBadgeIcon, className: 'bg-emerald-500/15 text-emerald-200 ring-1 ring-emerald-400/35' }
    if (title === 'Waarmerking') return { icon: ClipboardDocumentListIcon, className: 'bg-amber-500/15 text-amber-200 ring-1 ring-amber-400/35' }
    if (title === 'Akta PPAT') return { icon: BuildingLibraryIcon, className: 'bg-rose-500/15 text-rose-200 ring-1 ring-rose-400/35' }
    return { icon: ClipboardDocumentListIcon, className: 'bg-slate-500/20 text-slate-200 ring-1 ring-slate-400/35' }
  }

  if (title === 'Akta Notaris') {
    return { icon: DocumentTextIcon, className: 'bg-blue-100 text-blue-700' }
  }
  if (title === 'Legalisasi') {
    return { icon: CheckBadgeIcon, className: 'bg-emerald-100 text-emerald-700' }
  }
  if (title === 'Waarmerking') {
    return { icon: ClipboardDocumentListIcon, className: 'bg-amber-100 text-amber-700' }
  }
  if (title === 'Akta PPAT') {
    return { icon: BuildingLibraryIcon, className: 'bg-rose-100 text-rose-700' }
  }
  return { icon: ClipboardDocumentListIcon, className: 'bg-slate-100 text-slate-700' }
}

const winnerCardClass = (index: number) => {
  if (isDark.value) {
    const tones = [
      'from-amber-500/8 to-slate-900 border-amber-400/35',
      'from-blue-500/10 to-slate-900 border-blue-400/35',
      'from-emerald-500/10 to-slate-900 border-emerald-400/35',
      'from-violet-500/10 to-slate-900 border-violet-400/35',
    ]
    return tones[index % tones.length]
  }

  const tones = [
    'from-amber-50 to-orange-50 border-amber-200/70',
    'from-blue-50 to-sky-50 border-blue-200/70',
    'from-emerald-50 to-teal-50 border-emerald-200/70',
    'from-violet-50 to-fuchsia-50 border-violet-200/70',
  ]
  return tones[index % tones.length]
}

const winnerIconClass = (index: number) => {
  if (isDark.value) {
    const tones = [
      'from-amber-500/30 to-orange-500/20 text-amber-100',
      'from-blue-500/30 to-sky-500/20 text-blue-100',
      'from-emerald-500/30 to-teal-500/20 text-emerald-100',
      'from-violet-500/30 to-fuchsia-500/20 text-violet-100',
    ]
    return tones[index % tones.length]
  }

  const tones = [
    'from-amber-200 to-orange-200 text-amber-800',
    'from-blue-200 to-sky-200 text-blue-800',
    'from-emerald-200 to-teal-200 text-emerald-800',
    'from-violet-200 to-fuchsia-200 text-violet-800',
  ]
  return tones[index % tones.length]
}

const workloadTileClass = (title: string) => {
  if (isDark.value) {
    if (title === 'Akta Notaris') return 'border-blue-500/35 bg-gradient-to-br from-blue-500/8 to-slate-900'
    if (title === 'Legalisasi') return 'border-emerald-500/35 bg-gradient-to-br from-emerald-500/8 to-slate-900'
    if (title === 'Waarmerking') return 'border-amber-500/35 bg-gradient-to-br from-amber-500/8 to-slate-900'
    if (title === 'Akta PPAT') return 'border-rose-500/35 bg-gradient-to-br from-rose-500/8 to-slate-900'
    return 'border-slate-700 bg-slate-900'
  }

  if (title === 'Akta Notaris') return 'border-blue-200/70 bg-gradient-to-br from-blue-50/70 to-white'
  if (title === 'Legalisasi') return 'border-emerald-200/70 bg-gradient-to-br from-emerald-50/70 to-white'
  if (title === 'Waarmerking') return 'border-amber-200/70 bg-gradient-to-br from-amber-50/70 to-white'
  if (title === 'Akta PPAT') return 'border-rose-200/70 bg-gradient-to-br from-rose-50/70 to-white'
  return 'border-slate-200/80 bg-white'
}

const chartRows = computed(() => {
  const data = chartData.value
  const categories = Array.isArray(data.categories) ? data.categories : []
  const rows = categories.map((name, index) => {
    const notaris = toNumber(data.notaris[index])
    const legalisasi = toNumber(data.legalisasi[index])
    const waarmerking = toNumber((data.waarmerking || data.warmerking || [])[index])
    const ppat = toNumber(data.ppat[index])
    const total = notaris + legalisasi + waarmerking + ppat
    return {
      name,
      total,
      points: [
        { label: 'Notaris', value: notaris, color: 'bg-blue-500' },
        { label: 'Legalisasi', value: legalisasi, color: 'bg-emerald-500' },
        { label: 'Waarmerking', value: waarmerking, color: 'bg-amber-500' },
        { label: 'PPAT', value: ppat, color: 'bg-rose-500' },
      ],
    }
  })

  const sortedRows = [...rows].sort((a, b) =>
    b.total - a.total || String(a.name).localeCompare(String(b.name), 'id-ID'),
  )

  const maxTotal = Math.max(...sortedRows.map(row => row.total), 1)

  return sortedRows.map(row => ({
    ...row,
    width: `${Math.max((row.total / maxTotal) * 100, 4)}%`,
  }))
})

const totalCompleted = computed(() =>
  totals.value.reduce((sum, item) => sum + toNumber(item.total), 0),
)

const topWinner = computed(() => winners.value.at(0) || defaultWinners[0])
const topWinnerSafe = computed(() => topWinner.value || defaultWinners[0] || fallbackWinner)

const formatCompactNumber = (value: unknown) =>
  new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(toNumber(value))

onMounted(() => {
  void refreshDashboard()
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard
      class="relative overflow-hidden p-0"
      :class="isDark ? 'border-slate-700/80 shadow-xl shadow-slate-950/60' : 'border-slate-200/80 shadow-xl shadow-sky-200/50'"
    >
      <div
        class="pointer-events-none absolute inset-0 bg-gradient-to-br"
        :class="isDark ? 'from-slate-900 via-slate-900/95 to-slate-800/90' : 'from-cyan-100/80 via-white to-indigo-100/80'"
      />
      <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full blur-3xl" :class="isDark ? 'bg-cyan-500/20' : 'bg-cyan-300/40'" />
      <div class="pointer-events-none absolute -bottom-20 -left-12 h-56 w-56 rounded-full blur-3xl" :class="isDark ? 'bg-indigo-500/20' : 'bg-indigo-300/35'" />

      <div class="relative grid gap-6 p-6 sm:p-8 lg:grid-cols-[1.4fr_1fr] lg:items-start">
        <div>
          <p class="display-kicker">Dashboard</p>
          <h2 class="mt-2 font-display text-4xl text-slate-900 sm:text-5xl">Ringkasan Operasional</h2>
          <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600">
            Pantau kontributor, volume pekerjaan, dan distribusi asisten dalam satu tampilan kerja.
          </p>
          <div class="mt-5 flex flex-wrap gap-2">
            <span class="rounded-full border px-3 py-1 text-xs font-semibold" :class="isDark ? 'border-slate-700 bg-slate-900/85 text-slate-200' : 'border-white/80 bg-white/80 text-slate-700'">
              Total pekerjaan {{ formatCompactNumber(totalCompleted) }}
            </span>
            <span class="rounded-full border px-3 py-1 text-xs font-semibold" :class="isDark ? 'border-slate-700 bg-slate-900/85 text-slate-200' : 'border-white/80 bg-white/80 text-slate-700'">
              Top performer {{ topWinnerSafe.nama_lengkap || '-' }}
            </span>
          </div>
        </div>

        <div class="rounded-2xl border p-4 shadow-sm backdrop-blur-sm" :class="isDark ? 'border-slate-700 bg-slate-900/80' : 'border-white/80 bg-white/70'">
          <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi cepat</p>
          <p class="mt-1 text-sm text-slate-600">Cari data langsung dari dashboard dan buka halaman pencarian dokumen.</p>
          <form class="mt-4 space-y-3" @submit.prevent="openDocumentSearch">
            <div class="relative">
              <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                v-model="quickSearchQuery"
                type="search"
                placeholder="Cari nama client, identitas, atau kata kunci"
                class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-10 pr-3 text-sm text-slate-700 shadow-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </div>
            <button
              type="submit"
              class="h-11 w-full rounded-xl bg-gradient-to-r from-blue-600 via-cyan-600 to-indigo-600 px-5 text-sm font-semibold text-white transition hover:from-blue-700 hover:via-cyan-700 hover:to-indigo-700"
            >
              Cari Dokumen
            </button>
          </form>
        </div>
      </div>
    </SurfaceCard>

    <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
      <SurfaceCard
        class="border p-6 shadow-sm sm:p-7"
        :class="isDark ? 'border-slate-700/80 bg-gradient-to-b from-slate-900 to-slate-900/70' : 'border-slate-200/80 bg-gradient-to-b from-white to-slate-50/60'"
      >
        <div class="mb-4 flex items-center justify-between">
          <div>
            <p class="display-kicker">Pemenang</p>
            <h3 class="mt-2 text-2xl font-semibold text-slate-900">Kontributor Terbanyak</h3>
          </div>
        </div>

        <p v-if="winnerError" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {{ winnerError }}
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
          <div
            v-for="(winner, winnerIndex) in winners"
            :key="winner.judul"
            class="rounded-2xl border bg-gradient-to-br p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
            :class="winnerCardClass(winnerIndex)"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-sm font-semibold text-slate-900">{{ winner.judul }} terbanyak</p>
                <p class="mt-1 text-sm text-slate-500">{{ winner.nama_lengkap || '-' }}</p>
              </div>
              <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br" :class="winnerIconClass(winnerIndex)">
                <TrophyIcon class="h-5 w-5" />
              </div>
            </div>
            <p class="mt-3 text-3xl font-semibold text-slate-900">{{ formatCompactNumber(winner.jumlah) }}</p>
          </div>
        </div>
      </SurfaceCard>

      <SurfaceCard
        class="border p-6 shadow-sm sm:p-7"
        :class="isDark ? 'border-slate-700/80 bg-gradient-to-b from-slate-900 to-slate-900/70' : 'border-slate-200/80 bg-gradient-to-b from-white to-slate-50/60'"
      >
        <p class="display-kicker">Jumlah Pekerjaan</p>
        <h3 class="mt-2 text-2xl font-semibold text-slate-900">Total Pekerjaan yang Sudah Diselesaikan</h3>

        <div class="mt-5 grid gap-3 sm:grid-cols-2">
          <label class="flex flex-col gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Mulai</span>
            <input v-model="totalFilter.start_date" type="date" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="flex flex-col gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Akhir</span>
            <input v-model="totalFilter.end_date" type="date" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
          </label>
        </div>

        <button
          type="button"
          class="mt-4 h-11 w-full rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="loadingTotals"
          @click="loadTotals"
        >
          {{ loadingTotals ? 'Memuat...' : 'Filter' }}
        </button>

        <p v-if="totalsError" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          {{ totalsError }}
        </p>

        <div class="mt-5 grid gap-3 sm:grid-cols-2">
          <div
            v-for="item in totals"
            :key="item.title"
            class="flex items-center gap-3 rounded-xl border p-3 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
            :class="workloadTileClass(item.title)"
          >
            <div class="flex h-10 w-10 items-center justify-center rounded-lg" :class="resolveWorkloadStyle(item.title).className">
              <component :is="resolveWorkloadStyle(item.title).icon" class="h-5 w-5" />
            </div>
            <div>
              <p class="text-xs text-slate-500">{{ item.title }}</p>
              <p class="text-xl font-semibold text-slate-900">{{ formatCompactNumber(item.total) }}</p>
            </div>
          </div>
        </div>
      </SurfaceCard>
    </div>

    <SurfaceCard
      class="border p-6 shadow-sm sm:p-7"
      :class="isDark ? 'border-slate-700/80 bg-gradient-to-b from-slate-900 to-slate-900/70' : 'border-slate-200/80 bg-gradient-to-b from-white via-slate-50/40 to-sky-50/40'"
    >
      <p class="display-kicker">Grafik Pekerjaan Asisten</p>
      <h3 class="mt-2 text-2xl font-semibold text-slate-900">Distribusi pekerjaan per asisten</h3>

      <div class="mt-5 grid gap-3 md:grid-cols-[1fr_1fr_auto]">
        <label class="flex flex-col gap-2">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Mulai</span>
          <input v-model="chartFilter.start_date" type="date" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
        </label>
        <label class="flex flex-col gap-2">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Akhir</span>
          <input v-model="chartFilter.end_date" type="date" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
        </label>
        <button
          type="button"
          class="h-11 self-end rounded-xl bg-gradient-to-r from-blue-600 to-violet-600 px-6 text-sm font-semibold text-white transition hover:from-blue-700 hover:to-violet-700 disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="loadingChart"
          @click="loadChart"
        >
          {{ loadingChart ? 'Memuat...' : 'Filter' }}
        </button>
      </div>

      <p v-if="chartError" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ chartError }}
      </p>

      <div v-if="!chartRows.length" class="mt-6 rounded-xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500" :class="isDark ? 'border-slate-700 bg-slate-900 text-slate-300' : ''">
        Belum ada data grafik pada rentang tanggal ini.
      </div>

      <div v-else class="mt-6 space-y-4">
        <div
          v-for="row in chartRows"
          :key="row.name"
          class="rounded-2xl border p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
          :class="isDark ? 'border-slate-700/80 bg-gradient-to-br from-slate-900 to-slate-900/70' : 'border-slate-200/90 bg-gradient-to-br from-white to-slate-50'"
        >
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ row.name }}</p>
              <p class="text-xs text-slate-500">Total pekerjaan {{ row.total }}</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs text-slate-600">
              <span
                v-for="point in row.points"
                :key="`${row.name}-${point.label}`"
                class="inline-flex items-center gap-1 rounded-full px-2.5 py-1"
                :class="isDark ? 'bg-slate-800 text-slate-300' : 'bg-white'"
              >
                <span class="h-2 w-2 rounded-full" :class="point.color"></span>
                {{ point.label }} {{ point.value }}
              </span>
            </div>
          </div>
          <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full bg-gradient-to-r from-blue-500 via-emerald-500 to-rose-500" :style="{ width: row.width }" />
          </div>
        </div>
      </div>

      <p class="mt-4 text-xs text-slate-500">
        Total pekerjaan yang dikerjakan gabungan PPAT dan Notaris.
      </p>
    </SurfaceCard>
  </div>
</template>
