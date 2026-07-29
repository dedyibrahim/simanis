<script setup lang="ts">
type DatabaseStatus = {
  client_count?: number
  latest_client_update?: string | null
  slave_configured?: boolean
  slave_io_running?: string | null
  slave_sql_running?: string | null
  seconds_behind_master?: number | null
  master_host?: string | null
  last_io_error?: string
  last_sql_error?: string
}

type FileStatus = {
  status?: string
  last_started_at?: string
  last_success_at?: string
  last_error?: string
  source_bytes?: number
  destination_bytes?: number
  progress_percent?: number
  current_directory?: string
  activity_bytes?: number
  updated_at?: string
  process_running?: boolean
}

type ResourceStatus = {
  cpu_usage_percent?: number
  cpu_cores?: number
  cpu_model?: string
  load_1?: number
  load_5?: number
  load_15?: number
  memory_total_bytes?: number
  memory_used_bytes?: number
  memory_usage_percent?: number
  disk_total_bytes?: number
  disk_used_bytes?: number
  disk_usage_percent?: number
  uptime_seconds?: number
}

type ServerStatus = {
  hostname?: string
  server_ip?: string
  role?: string
  vip_active?: boolean
  application_release?: string
  resources?: ResourceStatus
  database?: DatabaseStatus
  files?: FileStatus
}

type HaStatus = {
  checked_at?: string
  virtual_ip?: string
  local?: ServerStatus
  peer?: {
    reachable?: boolean
    error?: string
    data?: ServerStatus
  }
  summary?: Record<string, boolean>
}

type WhatsappContainer = {
  exists?: boolean
  running?: boolean
  status?: string
  image?: string | null
  restart_count?: number | null
}

type WhatsappStatus = {
  checked_at?: string
  containers?: Record<string, WhatsappContainer>
  waha?: {
    status?: string
    session_status?: string
    session?: string
    http_status?: number
    error?: string
    qr_error?: string
  }
  qr_image?: string | null
  qr_available?: boolean
  command?: {
    exit_code?: number
    output?: string[]
  }
}

type KtpOcrStatus = {
  checked_at?: string
  containers?: Record<string, WhatsappContainer>
  settings?: {
    base_url?: string
    config?: string
    engine?: string
    timeout_ms?: number
    env_path?: string
  }
  health?: {
    ok?: boolean
    http_status?: number | null
    error?: string | null
    body?: Record<string, unknown>
  }
  command?: {
    exit_code?: number
    output?: string[]
  }
}

type ApiEnvelope<T> = {
  status?: boolean
  message?: string
  data?: T
}

definePageMeta({
  middleware: ['auth', 'admin-only'],
})

useHead({
  title: 'Status Sinkronisasi',
})

const business = useLegacyBusiness()
const loading = ref(false)
const whatsappLoading = ref(false)
const ktpOcrLoading = ref(false)
const whatsappAction = ref<'start' | 'stop' | ''>('')
const ktpOcrAction = ref<'start' | 'stop' | 'restart' | 'save' | ''>('')
const errorMessage = ref('')
const whatsappMessage = ref('')
const whatsappError = ref('')
const ktpOcrMessage = ref('')
const ktpOcrError = ref('')
const status = ref<HaStatus | null>(null)
const whatsapp = ref<WhatsappStatus | null>(null)
const ktpOcr = ref<KtpOcrStatus | null>(null)
const ktpOcrForm = reactive({
  base_url: 'http://ktp-ocr-lab:8765',
  config: 'paddleocr-fast.json',
  engine: 'paddleocr',
  timeout_ms: 90000,
})
const autoRefresh = ref(true)
let refreshTimer: ReturnType<typeof setInterval> | undefined

const summaryItems = computed(() => {
  const summary = status.value?.summary || {}
  return [
    { key: 'peer_reachable', label: 'Server Standby', detail: 'Server pasangan dapat dihubungi' },
    { key: 'database_replication_healthy', label: 'Replikasi Database', detail: 'Thread replikasi aktif dan lag rendah' },
    { key: 'database_data_matches', label: 'Kesamaan Database', detail: 'Jumlah dan data client terbaru sama' },
    { key: 'files_synchronized', label: 'Folder Public', detail: 'Arsip dokumen selesai disinkronkan' },
    { key: 'application_matches', label: 'Versi Aplikasi', detail: 'Release aplikasi pada kedua server sama' },
  ].map(item => ({ ...item, healthy: Boolean(summary[item.key]) }))
})

const overallHealthy = computed(() => Boolean(status.value?.summary?.overall_healthy))
const peerStatus = computed(() => status.value?.peer?.data)
const whatsappContainers = computed(() => whatsapp.value?.containers || {})
const whatsappReady = computed(() => {
  const statusText = String(whatsapp.value?.waha?.status || '').toLowerCase()
  const sessionStatus = String(whatsapp.value?.waha?.session_status || '').toUpperCase()
  return statusText === 'ready' || ['WORKING', 'CONNECTED', 'AUTHENTICATED'].includes(sessionStatus)
})
const whatsappRunning = computed(() => Boolean(
  whatsappContainers.value.bothwa?.running && whatsappContainers.value['simanis-waha']?.running,
))
const whatsappServiceHealthy = computed(() => whatsappRunning.value || whatsappReady.value)
const ktpOcrContainer = computed(() => ktpOcr.value?.containers?.['ktp-ocr-lab'])
const ktpOcrRunning = computed(() => Boolean(ktpOcrContainer.value?.running))
const ktpOcrHealthy = computed(() => Boolean(ktpOcr.value?.health?.ok))

const formatDate = (value: unknown) => {
  const raw = String(value || '').trim()
  if (!raw) return '-'
  const parsed = new Date(raw)
  if (Number.isNaN(parsed.getTime())) return raw
  return parsed.toLocaleString('id-ID')
}

const formatBytes = (value: unknown) => {
  const bytes = Number(value || 0)
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / 1024 ** index).toFixed(index > 2 ? 2 : 1)} ${units[index]}`
}

const formatUptime = (value: unknown) => {
  const seconds = Math.max(0, Number(value || 0))
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  return `${days} hari ${hours} jam ${minutes} menit`
}

const loadStatus = async () => {
  if (loading.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await business.settings.getHaStatus() as ApiEnvelope<HaStatus>
    status.value = response.data || null
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal memuat status sinkronisasi.'
  } finally {
    loading.value = false
  }
}

const loadWhatsapp = async () => {
  if (whatsappLoading.value) return
  whatsappLoading.value = true
  whatsappError.value = ''
  try {
    const response = await business.settings.getHaWhatsapp() as ApiEnvelope<WhatsappStatus>
    whatsapp.value = response.data || null
  } catch (error) {
    whatsappError.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal memuat status WhatsApp Gateway.'
  } finally {
    whatsappLoading.value = false
  }
}

const syncKtpOcrForm = () => {
  const settings = ktpOcr.value?.settings
  if (!settings) return
  ktpOcrForm.base_url = settings.base_url || 'http://ktp-ocr-lab:8765'
  ktpOcrForm.config = settings.config || 'paddleocr-fast.json'
  ktpOcrForm.engine = settings.engine || 'paddleocr'
  ktpOcrForm.timeout_ms = Number(settings.timeout_ms || 90000)
}

const loadKtpOcr = async () => {
  if (ktpOcrLoading.value) return
  ktpOcrLoading.value = true
  ktpOcrError.value = ''
  try {
    const response = await business.settings.getHaKtpOcr() as ApiEnvelope<KtpOcrStatus>
    ktpOcr.value = response.data || null
    syncKtpOcrForm()
  } catch (error) {
    ktpOcrError.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal memuat status OCR KTP.'
  } finally {
    ktpOcrLoading.value = false
  }
}

const runWhatsappAction = async (action: 'start' | 'stop') => {
  if (whatsappAction.value) return
  whatsappAction.value = action
  whatsappMessage.value = ''
  whatsappError.value = ''
  try {
    const response = action === 'start'
      ? await business.settings.startHaWhatsapp() as ApiEnvelope<WhatsappStatus>
      : await business.settings.stopHaWhatsapp() as ApiEnvelope<WhatsappStatus>
    whatsapp.value = response.data || null
    whatsappMessage.value = response.message || (action === 'start'
      ? 'WhatsApp Gateway sedang dijalankan.'
      : 'WhatsApp Gateway dihentikan.')
    await loadStatus()
  } catch (error) {
    whatsappError.value = (error as { data?: { message?: string } })?.data?.message
      || (action === 'start' ? 'Gagal menjalankan WhatsApp Gateway.' : 'Gagal menghentikan WhatsApp Gateway.')
  } finally {
    whatsappAction.value = ''
  }
}

const saveKtpOcrSettings = async () => {
  if (ktpOcrAction.value) return
  ktpOcrAction.value = 'save'
  ktpOcrMessage.value = ''
  ktpOcrError.value = ''
  try {
    const response = await business.settings.saveHaKtpOcrSettings({ ...ktpOcrForm }) as ApiEnvelope<KtpOcrStatus>
    ktpOcr.value = response.data || null
    ktpOcrMessage.value = response.message || 'Pengaturan OCR KTP disimpan.'
  } catch (error) {
    ktpOcrError.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal menyimpan pengaturan OCR KTP.'
  } finally {
    ktpOcrAction.value = ''
  }
}

const runKtpOcrAction = async (action: 'start' | 'stop' | 'restart') => {
  if (ktpOcrAction.value) return
  ktpOcrAction.value = action
  ktpOcrMessage.value = ''
  ktpOcrError.value = ''
  try {
    const request = action === 'start'
      ? business.settings.startHaKtpOcr()
      : action === 'stop'
        ? business.settings.stopHaKtpOcr()
        : business.settings.restartHaKtpOcr()
    const response = await request as ApiEnvelope<KtpOcrStatus>
    ktpOcr.value = response.data || null
    ktpOcrMessage.value = response.message || 'Aksi OCR KTP berhasil dijalankan.'
    syncKtpOcrForm()
  } catch (error) {
    ktpOcrError.value = (error as { data?: { message?: string } })?.data?.message
      || 'Aksi OCR KTP gagal dijalankan.'
  } finally {
    ktpOcrAction.value = ''
  }
}

watch(autoRefresh, (enabled) => {
  if (refreshTimer) clearInterval(refreshTimer)
  refreshTimer = enabled ? setInterval(() => {
    void loadStatus()
    void loadKtpOcr()
  }, 15000) : undefined
})

onMounted(() => {
  void loadStatus()
  void loadKtpOcr()
  refreshTimer = setInterval(() => {
    void loadStatus()
    void loadKtpOcr()
  }, 15000)
})

onBeforeUnmount(() => {
  if (refreshTimer) clearInterval(refreshTimer)
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="display-kicker">High Availability</p>
          <h2 class="mt-2 text-2xl font-semibold text-slate-900">Status Sinkronisasi Server</h2>
          <p class="mt-2 text-sm text-slate-500">Pantau server, database, folder public, dan Virtual IP SIMANIS.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <label class="inline-flex items-center gap-2 text-sm text-slate-600">
            <input v-model="autoRefresh" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600">
            Refresh otomatis
          </label>
          <button
            type="button"
            class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="loading"
            @click="loadStatus"
          >
            {{ loading ? 'Memeriksa...' : 'Periksa Sekarang' }}
          </button>
        </div>
      </div>

      <p v-if="errorMessage" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errorMessage }}
      </p>
    </SurfaceCard>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Status Keseluruhan</p>
        <p class="mt-3 text-2xl font-semibold" :class="overallHealthy ? 'text-emerald-700' : 'text-amber-700'">
          {{ overallHealthy ? 'Tersinkron' : 'Perlu Perhatian' }}
        </p>
        <p class="mt-2 text-xs text-slate-500">Terakhir diperiksa {{ formatDate(status?.checked_at) }}</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Virtual IP</p>
        <p class="mt-3 text-2xl font-semibold text-slate-900">{{ status?.virtual_ip || '-' }}</p>
        <p class="mt-2 text-xs text-slate-500">Alamat yang digunakan pengguna dan frontend.</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Lag Database Standby</p>
        <p class="mt-3 text-2xl font-semibold text-slate-900">
          {{ peerStatus?.database?.seconds_behind_master ?? '-' }} detik
        </p>
        <p class="mt-2 text-xs text-slate-500">Target sehat maksimal 10 detik.</p>
      </SurfaceCard>
    </div>

    <SurfaceCard class="overflow-hidden p-0">
      <div class="border-b border-slate-200 px-6 py-4">
        <h3 class="font-semibold text-slate-900">Pemeriksaan Komponen</h3>
      </div>
      <div class="divide-y divide-slate-100">
        <div v-for="item in summaryItems" :key="item.key" class="flex items-center justify-between gap-4 px-6 py-4">
          <div>
            <p class="text-sm font-semibold text-slate-800">{{ item.label }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ item.detail }}</p>
          </div>
          <span
            class="rounded-full px-3 py-1 text-xs font-semibold"
            :class="item.healthy ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
          >
            {{ item.healthy ? 'Sehat' : 'Belum Sehat' }}
          </span>
        </div>
      </div>
    </SurfaceCard>

    <SurfaceCard class="p-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">OCR KTP</p>
          <h3 class="mt-2 text-lg font-semibold text-slate-900">Service Pembaca KTP WhatsApp</h3>
          <p class="mt-1 text-sm text-slate-500">Pantau health OCR dan jalankan recovery tanpa masuk server.</p>
        </div>
        <span
          class="rounded-full px-3 py-1 text-xs font-semibold"
          :class="ktpOcrHealthy ? 'bg-emerald-100 text-emerald-700' : ktpOcrRunning ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700'"
        >
          {{ ktpOcrHealthy ? 'Sehat' : ktpOcrRunning ? 'Service hidup, health gagal' : 'Mati' }}
        </span>
      </div>

      <div class="mt-5 grid gap-3 md:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
          <p class="text-xs text-slate-500">Container</p>
          <p class="mt-1 font-semibold text-slate-800">{{ ktpOcrContainer?.status || 'missing' }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
          <p class="text-xs text-slate-500">Health HTTP</p>
          <p class="mt-1 font-semibold text-slate-800">{{ ktpOcr?.health?.http_status || '-' }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
          <p class="text-xs text-slate-500">Config Aktif</p>
          <p class="mt-1 truncate font-semibold text-slate-800" :title="ktpOcr?.settings?.config">{{ ktpOcr?.settings?.config || '-' }}</p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
          <p class="text-xs text-slate-500">Dicek</p>
          <p class="mt-1 font-semibold text-slate-800">{{ formatDate(ktpOcr?.checked_at) }}</p>
        </div>
      </div>

      <div class="mt-5 grid gap-4 lg:grid-cols-4">
        <label class="lg:col-span-2">
          <span class="text-xs font-semibold text-slate-600">Base URL OCR</span>
          <input
            v-model="ktpOcrForm.base_url"
            class="mt-1 h-10 w-full rounded-lg border border-slate-300 px-3 text-sm focus:border-blue-500 focus:outline-none"
            placeholder="http://ktp-ocr-lab:8765"
          >
        </label>
        <label>
          <span class="text-xs font-semibold text-slate-600">Config</span>
          <select
            v-model="ktpOcrForm.config"
            class="mt-1 h-10 w-full rounded-lg border border-slate-300 px-3 text-sm focus:border-blue-500 focus:outline-none"
          >
            <option value="paddleocr-fast.json">paddleocr-fast.json</option>
            <option value="paddleocr-photo.json">paddleocr-photo.json</option>
            <option value="tesseract-fast.json">tesseract-fast.json</option>
          </select>
        </label>
        <label>
          <span class="text-xs font-semibold text-slate-600">Engine</span>
          <select
            v-model="ktpOcrForm.engine"
            class="mt-1 h-10 w-full rounded-lg border border-slate-300 px-3 text-sm focus:border-blue-500 focus:outline-none"
          >
            <option value="paddleocr">PaddleOCR</option>
            <option value="tesseract">Tesseract</option>
          </select>
        </label>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-sm text-slate-600">
          Timeout
          <input
            v-model.number="ktpOcrForm.timeout_ms"
            type="number"
            min="5000"
            max="300000"
            step="5000"
            class="h-10 w-32 rounded-lg border border-slate-300 px-3 text-sm focus:border-blue-500 focus:outline-none"
          >
          ms
        </label>
        <button
          type="button"
          class="h-10 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="Boolean(ktpOcrAction)"
          @click="saveKtpOcrSettings"
        >
          {{ ktpOcrAction === 'save' ? 'Menyimpan...' : 'Simpan Setting' }}
        </button>
        <button
          type="button"
          class="h-10 rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-700 disabled:opacity-50"
          :disabled="ktpOcrLoading"
          @click="loadKtpOcr"
        >
          {{ ktpOcrLoading ? 'Mengecek...' : 'Cek Health' }}
        </button>
        <button
          type="button"
          class="h-10 rounded-lg bg-emerald-600 px-4 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="Boolean(ktpOcrAction)"
          @click="runKtpOcrAction('start')"
        >
          {{ ktpOcrAction === 'start' ? 'Starting...' : 'Start' }}
        </button>
        <button
          type="button"
          class="h-10 rounded-lg bg-amber-600 px-4 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="Boolean(ktpOcrAction)"
          @click="runKtpOcrAction('restart')"
        >
          {{ ktpOcrAction === 'restart' ? 'Restarting...' : 'Restart' }}
        </button>
        <button
          type="button"
          class="h-10 rounded-lg bg-red-600 px-4 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="Boolean(ktpOcrAction)"
          @click="runKtpOcrAction('stop')"
        >
          {{ ktpOcrAction === 'stop' ? 'Stopping...' : 'Stop' }}
        </button>
      </div>

      <p v-if="ktpOcrMessage" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ ktpOcrMessage }}
      </p>
      <p v-if="ktpOcrError || ktpOcr?.health?.error" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ ktpOcrError || ktpOcr?.health?.error }}
      </p>
      <pre v-if="ktpOcr?.command?.output?.length" class="mt-4 max-h-44 overflow-auto rounded-xl bg-slate-950 p-4 text-xs text-slate-100">{{ ktpOcr.command.output.join('\n') }}</pre>
    </SurfaceCard>

    <div class="grid gap-6 xl:grid-cols-2">
      <SurfaceCard v-for="server in [status?.local, peerStatus]" :key="server?.server_ip || server?.hostname" class="p-6">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ server?.role || 'Server' }}</p>
            <h3 class="mt-2 text-lg font-semibold text-slate-900">{{ server?.hostname || 'Tidak terhubung' }}</h3>
            <p class="mt-1 text-sm text-slate-500">{{ server?.server_ip || status?.peer?.error || '-' }}</p>
          </div>
          <div class="flex flex-col items-end gap-2">
            <span
              v-if="server?.vip_active"
              class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700"
            >
              Aktif menerima trafik
            </span>
            <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
              {{ server?.application_release || 'release belum tercatat' }}
            </span>
          </div>
        </div>

        <dl class="mt-5 grid gap-3 sm:grid-cols-2">
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs text-slate-500">Jumlah Client</dt>
            <dd class="mt-1 font-semibold text-slate-800">{{ server?.database?.client_count ?? '-' }}</dd>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs text-slate-500">Data Client Terakhir</dt>
            <dd class="mt-1 text-sm font-semibold text-slate-800">{{ formatDate(server?.database?.latest_client_update) }}</dd>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs text-slate-500">Slave IO / SQL</dt>
            <dd class="mt-1 font-semibold text-slate-800">
              {{ server?.database?.slave_io_running || '-' }} / {{ server?.database?.slave_sql_running || '-' }}
            </dd>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs text-slate-500">Status Folder</dt>
            <dd class="mt-1 font-semibold text-slate-800">{{ server?.files?.status || 'belum tersedia' }}</dd>
          </div>
        </dl>

        <div v-if="server?.resources" class="mt-4">
          <h4 class="text-sm font-semibold text-slate-800">Resource Server</h4>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div
              v-for="resource in [
                { label: 'CPU', value: `${server.resources.cpu_usage_percent ?? 0}%`, detail: `${server.resources.cpu_cores ?? '-'} core | load ${server.resources.load_1 ?? 0}` },
                { label: 'RAM', value: `${server.resources.memory_usage_percent ?? 0}%`, detail: `${formatBytes(server.resources.memory_used_bytes)} / ${formatBytes(server.resources.memory_total_bytes)}` },
                { label: 'Disk', value: `${server.resources.disk_usage_percent ?? 0}%`, detail: `${formatBytes(server.resources.disk_used_bytes)} / ${formatBytes(server.resources.disk_total_bytes)}` },
                { label: 'Uptime', value: formatUptime(server.resources.uptime_seconds), detail: server.resources.cpu_model || '-' },
              ]"
              :key="resource.label"
              class="rounded-xl border border-slate-200 bg-slate-50 p-3"
            >
              <div class="flex items-center justify-between gap-3">
                <span class="text-xs text-slate-500">{{ resource.label }}</span>
                <strong class="text-sm text-slate-800">{{ resource.value }}</strong>
              </div>
              <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200" v-if="resource.label !== 'Uptime'">
                <div
                  class="h-full rounded-full"
                  :class="Number.parseFloat(resource.value) >= 85 ? 'bg-red-500' : Number.parseFloat(resource.value) >= 70 ? 'bg-amber-500' : 'bg-emerald-500'"
                  :style="{ width: `${Math.min(100, Number.parseFloat(resource.value) || 0)}%` }"
                />
              </div>
              <p class="mt-2 truncate text-[11px] text-slate-500" :title="resource.detail">{{ resource.detail }}</p>
            </div>
          </div>
        </div>

        <div v-if="server?.files" class="mt-4 rounded-xl border border-slate-200 p-4">
          <div class="flex items-center justify-between text-xs text-slate-500">
            <span>Progress folder public</span>
            <span>{{ Number(server.files.progress_percent || 0).toFixed(1) }}%</span>
          </div>
          <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full bg-blue-600" :style="{ width: `${Math.min(100, Number(server.files.progress_percent || 0))}%` }" />
          </div>
          <div class="mt-3 flex flex-wrap justify-between gap-2 text-xs text-slate-500">
            <span>{{ formatBytes(server.files.destination_bytes) }} / {{ formatBytes(server.files.source_bytes) }}</span>
            <span>Sukses terakhir: {{ formatDate(server.files.last_success_at) }}</span>
          </div>
          <p v-if="server.files.process_running" class="mt-2 text-xs font-medium text-blue-700">
            Aktif memproses {{ server.files.current_directory || 'folder public' }}
            <span v-if="server.files.activity_bytes"> | aktivitas {{ formatBytes(server.files.activity_bytes) }}</span>
          </p>
          <p v-if="server.files.updated_at" class="mt-1 text-[11px] text-slate-400">
            Aktivitas diperbarui {{ formatDate(server.files.updated_at) }}
          </p>
          <p v-if="server.files.last_error" class="mt-2 text-xs text-red-600">{{ server.files.last_error }}</p>
        </div>
      </SurfaceCard>
    </div>
  </div>
</template>
