<script setup lang="ts">
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
  settings?: {
    enabled?: boolean
    driver?: string
    base_url?: string
    api_key?: string
    session?: string
    send_message_endpoint?: string
    check_number_endpoint?: string
    status_endpoint?: string
    timeout_seconds?: number
    webhook_url?: string
    webhook_events?: string[]
    webhook_secret?: string
    webhook_auto_configure?: boolean
  }
  webhook?: {
    status?: string
    message?: string
    desired?: {
      url?: string
      events?: string[]
      has_secret?: boolean
    } | null
    matching?: {
      url?: string
      events?: string[]
      has_secret?: boolean
    } | null
    current?: Array<{
      url?: string
      events?: string[]
      has_secret?: boolean
    }>
  }
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

type ApiEnvelope<T> = {
  status?: boolean
  message?: string
  data?: T
}

definePageMeta({
  middleware: ['auth', 'admin-only'],
})

useHead({
  title: 'WhatsApp Gateway',
})

const business = useLegacyBusiness()
const loading = ref(false)
const action = ref<'start' | 'stop' | ''>('')
const saving = ref(false)
const syncingWebhook = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const whatsapp = ref<WhatsappStatus | null>(null)
const autoRefresh = ref(true)
let refreshTimer: ReturnType<typeof setInterval> | undefined
const settingsForm = reactive({
  enabled: true,
  driver: 'official',
  base_url: 'http://127.0.0.1:8010',
  api_key: 'admin',
  session: 'default',
  send_message_endpoint: '/api/sendText',
  check_number_endpoint: '/api/contacts/check-exists',
  status_endpoint: '/api/sessions',
  timeout_seconds: 30,
  webhook_url: 'http://bothwa:8020/webhook/waha',
  webhook_events: 'message',
  webhook_secret: '',
  webhook_auto_configure: true,
})

const containers = computed(() => whatsapp.value?.containers || {})
const ready = computed(() => {
  const statusText = String(whatsapp.value?.waha?.status || '').toLowerCase()
  const sessionStatus = String(whatsapp.value?.waha?.session_status || '').toUpperCase()
  return statusText === 'ready' || ['WORKING', 'CONNECTED', 'AUTHENTICATED'].includes(sessionStatus)
})
const running = computed(() => Boolean(
  containers.value.bothwa?.running && containers.value['simanis-waha']?.running,
))
const serviceHealthy = computed(() => running.value || ready.value)
const webhookHealthy = computed(() => String(whatsapp.value?.webhook?.status || '') === 'synced')
const webhookStatusLabel = computed(() => {
  const status = String(whatsapp.value?.webhook?.status || '').toLowerCase()
  if (status === 'synced') return 'Sinkron'
  if (status === 'not_synced') return 'Belum sinkron'
  if (status === 'disabled') return 'Nonaktif'
  if (status === 'unsupported') return 'Tidak didukung'
  if (status === 'error') return 'Error'
  return '-'
})

const syncFormFromStatus = () => {
  const settings = whatsapp.value?.settings
  if (!settings) return

  settingsForm.enabled = settings.enabled !== false
  settingsForm.driver = settings.driver || 'official'
  settingsForm.base_url = settings.base_url || 'http://127.0.0.1:8010'
  settingsForm.api_key = settings.api_key || ''
  settingsForm.session = settings.session || 'default'
  settingsForm.send_message_endpoint = settings.send_message_endpoint || '/api/sendText'
  settingsForm.check_number_endpoint = settings.check_number_endpoint || '/api/contacts/check-exists'
  settingsForm.status_endpoint = settings.status_endpoint || '/api/sessions'
  settingsForm.timeout_seconds = Number(settings.timeout_seconds || 30)
  settingsForm.webhook_url = settings.webhook_url || 'http://bothwa:8020/webhook/waha'
  settingsForm.webhook_events = (settings.webhook_events || ['message']).join(', ')
  settingsForm.webhook_secret = settings.webhook_secret || ''
  settingsForm.webhook_auto_configure = settings.webhook_auto_configure !== false
}

const formatDate = (value: unknown) => {
  const raw = String(value || '').trim()
  if (!raw) return '-'
  const parsed = new Date(raw)
  if (Number.isNaN(parsed.getTime())) return raw
  return parsed.toLocaleString('id-ID')
}

const loadWhatsapp = async () => {
  if (loading.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await business.settings.getHaWhatsapp() as ApiEnvelope<WhatsappStatus>
    whatsapp.value = response.data || null
    syncFormFromStatus()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal memuat status WhatsApp Gateway.'
  } finally {
    loading.value = false
  }
}

const buildSettingsPayload = (syncWebhook = false) => ({
  enabled: settingsForm.enabled,
  driver: settingsForm.driver,
  base_url: settingsForm.base_url,
  api_key: settingsForm.api_key,
  session: settingsForm.session,
  send_message_endpoint: settingsForm.send_message_endpoint,
  check_number_endpoint: settingsForm.check_number_endpoint,
  status_endpoint: settingsForm.status_endpoint,
  timeout_seconds: Number(settingsForm.timeout_seconds || 30),
  webhook_url: settingsForm.webhook_url,
  webhook_events: settingsForm.webhook_events,
  webhook_secret: settingsForm.webhook_secret,
  webhook_auto_configure: settingsForm.webhook_auto_configure,
  sync_webhook: syncWebhook,
})

const saveSettings = async (syncWebhook = false) => {
  if (saving.value) return
  saving.value = true
  successMessage.value = ''
  errorMessage.value = ''
  try {
    const response = await business.settings.saveHaWhatsappSettings(buildSettingsPayload(syncWebhook)) as ApiEnvelope<WhatsappStatus>
    whatsapp.value = response.data || null
    syncFormFromStatus()
    successMessage.value = response.message || 'Pengaturan WhatsApp berhasil disimpan.'
  } catch (error) {
    const response = (error as { data?: ApiEnvelope<WhatsappStatus> })?.data
    if (response?.data) {
      whatsapp.value = response.data
      syncFormFromStatus()
    }
    errorMessage.value = response?.message || 'Gagal menyimpan pengaturan WhatsApp.'
  } finally {
    saving.value = false
  }
}

const repairWebhook = async () => {
  if (syncingWebhook.value) return
  syncingWebhook.value = true
  successMessage.value = ''
  errorMessage.value = ''
  try {
    const response = await business.settings.syncHaWhatsappWebhook() as ApiEnvelope<WhatsappStatus>
    whatsapp.value = response.data || null
    syncFormFromStatus()
    successMessage.value = response.message || 'Webhook WhatsApp berhasil disinkronkan.'
  } catch (error) {
    const response = (error as { data?: ApiEnvelope<WhatsappStatus> })?.data
    if (response?.data) {
      whatsapp.value = response.data
      syncFormFromStatus()
    }
    errorMessage.value = response?.message || 'Gagal memperbaiki webhook WhatsApp.'
  } finally {
    syncingWebhook.value = false
  }
}

const runAction = async (selectedAction: 'start' | 'stop') => {
  if (action.value) return
  action.value = selectedAction
  successMessage.value = ''
  errorMessage.value = ''
  try {
    const response = selectedAction === 'start'
      ? await business.settings.startHaWhatsapp() as ApiEnvelope<WhatsappStatus>
      : await business.settings.stopHaWhatsapp() as ApiEnvelope<WhatsappStatus>
    whatsapp.value = response.data || null
    successMessage.value = response.message || (selectedAction === 'start'
      ? 'WhatsApp Gateway sedang dijalankan.'
      : 'WhatsApp Gateway dihentikan.')
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message
      || (selectedAction === 'start'
        ? 'Gagal menjalankan WhatsApp Gateway.'
        : 'Gagal menghentikan WhatsApp Gateway.')
  } finally {
    action.value = ''
  }
}

watch(autoRefresh, (enabled) => {
  if (refreshTimer) clearInterval(refreshTimer)
  refreshTimer = enabled ? setInterval(() => void loadWhatsapp(), 15000) : undefined
})

onMounted(() => {
  void loadWhatsapp()
  refreshTimer = setInterval(() => void loadWhatsapp(), 15000)
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
          <p class="display-kicker">WhatsApp Gateway</p>
          <h2 class="mt-2 text-2xl font-semibold text-slate-900">Kontrol Login WA</h2>
          <p class="mt-2 text-sm text-slate-500">
            Jalankan bothWA/WAHA, pantau session, dan scan QR login dari dashboard.
          </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <label class="inline-flex items-center gap-2 text-sm text-slate-600">
            <input v-model="autoRefresh" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-blue-600">
            Refresh otomatis
          </label>
          <button
            type="button"
            class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 disabled:opacity-50"
            :disabled="loading || Boolean(action)"
            @click="loadWhatsapp"
          >
            {{ loading ? 'Memuat...' : 'Refresh WA' }}
          </button>
        </div>
      </div>

      <p v-if="successMessage" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ successMessage }}
      </p>
      <p v-if="errorMessage" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errorMessage }}
      </p>
    </SurfaceCard>

    <div class="grid gap-4 md:grid-cols-4">
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Status Service</p>
        <p class="mt-3 text-2xl font-semibold" :class="serviceHealthy ? 'text-emerald-700' : 'text-amber-700'">
          {{ serviceHealthy ? 'Running' : 'Stopped' }}
        </p>
        <p class="mt-2 text-xs text-slate-500">
          {{ running ? 'Docker container aktif.' : ready ? 'WAHA aktif, status Docker belum terbaca.' : 'Terakhir diperiksa ' + formatDate(whatsapp?.checked_at) }}
        </p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Session WAHA</p>
        <p class="mt-3 text-2xl font-semibold" :class="ready ? 'text-emerald-700' : 'text-amber-700'">
          {{ whatsapp?.waha?.session_status || whatsapp?.waha?.status || '-' }}
        </p>
        <p class="mt-2 text-xs text-slate-500">Session: {{ whatsapp?.waha?.session || 'default' }}</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Webhook WAHA</p>
        <p class="mt-3 text-2xl font-semibold" :class="webhookHealthy ? 'text-emerald-700' : 'text-amber-700'">
          {{ webhookStatusLabel }}
        </p>
        <p class="mt-2 truncate text-xs text-slate-500" :title="whatsapp?.webhook?.desired?.url || ''">
          {{ whatsapp?.webhook?.desired?.url || whatsapp?.webhook?.message || '-' }}
        </p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Aksi</p>
        <div class="mt-4 flex flex-wrap gap-3">
          <button
            type="button"
            class="h-10 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="Boolean(action) || serviceHealthy"
            @click="runAction('start')"
          >
            {{ action === 'start' ? 'Menjalankan...' : 'Jalankan WA' }}
          </button>
          <button
            type="button"
            class="h-10 rounded-xl bg-red-600 px-4 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="Boolean(action) || !running"
            @click="runAction('stop')"
          >
            {{ action === 'stop' ? 'Menghentikan...' : 'Stop WA' }}
          </button>
          <button
            type="button"
            class="h-10 rounded-xl border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 disabled:opacity-50"
            :disabled="syncingWebhook || Boolean(action)"
            @click="repairWebhook"
          >
            {{ syncingWebhook ? 'Sinkron...' : 'Perbaiki Webhook' }}
          </button>
        </div>
      </SurfaceCard>
    </div>

    <SurfaceCard class="p-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h3 class="font-semibold text-slate-900">Pengaturan WhatsApp Gateway</h3>
          <p class="mt-1 text-sm text-slate-500">
            Simpan konfigurasi WAHA dan webhook. Untuk setup Docker saat ini, webhook internal yang sehat adalah
            <span class="font-mono">http://bothwa:8020/webhook/waha</span>.
          </p>
        </div>
        <div class="flex flex-wrap gap-3">
          <button
            type="button"
            class="h-10 rounded-xl border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 disabled:opacity-50"
            :disabled="saving || syncingWebhook"
            @click="saveSettings(true)"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan + Sinkron Webhook' }}
          </button>
          <button
            type="button"
            class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="saving || syncingWebhook"
            @click="saveSettings(false)"
          >
            {{ saving ? 'Menyimpan...' : 'Simpan Pengaturan' }}
          </button>
        </div>
      </div>

      <div class="mt-5 grid gap-4 lg:grid-cols-3">
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Base URL WAHA</span>
          <input
            v-model.trim="settingsForm.base_url"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="http://127.0.0.1:8010"
          >
        </label>
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">API Key WAHA</span>
          <input
            v-model.trim="settingsForm.api_key"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="admin"
          >
        </label>
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Session</span>
          <input
            v-model.trim="settingsForm.session"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="default"
          >
        </label>
      </div>

      <div class="mt-4 grid gap-4 lg:grid-cols-[1.2fr_0.8fr_0.8fr]">
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Webhook URL</span>
          <input
            v-model.trim="settingsForm.webhook_url"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="http://bothwa:8020/webhook/waha"
          >
        </label>
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Event Webhook</span>
          <input
            v-model.trim="settingsForm.webhook_events"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="message"
          >
        </label>
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Webhook Secret</span>
          <input
            v-model.trim="settingsForm.webhook_secret"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="Secret header X-Webhook-Secret"
          >
        </label>
      </div>

      <div class="mt-4 grid gap-4 lg:grid-cols-4">
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Endpoint Kirim Pesan</span>
          <input
            v-model.trim="settingsForm.send_message_endpoint"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="/api/sendText"
          >
        </label>
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Endpoint Cek Nomor</span>
          <input
            v-model.trim="settingsForm.check_number_endpoint"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="/api/contacts/check-exists"
          >
        </label>
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Endpoint Status</span>
          <input
            v-model.trim="settingsForm.status_endpoint"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="/api/sessions"
          >
        </label>
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Timeout</span>
          <input
            v-model.number="settingsForm.timeout_seconds"
            type="number"
            min="5"
            max="120"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
          >
        </label>
      </div>

      <div class="mt-4 flex flex-wrap gap-4 text-sm text-slate-700">
        <label class="inline-flex items-center gap-2">
          <input v-model="settingsForm.enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300">
          Aktifkan WAHA
        </label>
        <label class="inline-flex items-center gap-2">
          <input v-model="settingsForm.webhook_auto_configure" type="checkbox" class="h-4 w-4 rounded border-slate-300">
          Auto-config webhook
        </label>
      </div>

      <div v-if="whatsapp?.webhook?.current?.length" class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Webhook terpasang di WAHA</p>
        <div class="mt-3 space-y-2 text-sm text-slate-700">
          <div v-for="(webhook, index) in whatsapp.webhook.current" :key="index" class="rounded-lg bg-white p-3">
            <p class="break-all font-mono text-xs">{{ webhook.url || '-' }}</p>
            <p class="mt-1 text-xs text-slate-500">
              Events: {{ webhook.events?.join(', ') || '-' }} |
              Secret: {{ webhook.has_secret ? 'ada' : 'tidak ada' }}
            </p>
          </div>
        </div>
      </div>
    </SurfaceCard>

    <div class="grid gap-6 xl:grid-cols-[1fr_0.9fr]">
      <SurfaceCard class="p-6">
        <h3 class="font-semibold text-slate-900">Container</h3>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <div
            v-for="(container, name) in containers"
            :key="name"
            class="rounded-xl border border-slate-200 bg-slate-50 p-4"
          >
            <div class="flex items-center justify-between gap-3">
              <p class="text-sm font-semibold text-slate-800">{{ name }}</p>
              <span
                class="rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="container.running ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
              >
                {{ container.status || 'missing' }}
              </span>
            </div>
            <p class="mt-2 truncate text-xs text-slate-500" :title="container.image || ''">
              {{ container.image || 'image belum tersedia' }}
            </p>
          </div>
        </div>
      </SurfaceCard>

      <SurfaceCard class="p-6 text-center">
        <h3 class="font-semibold text-slate-900">Scan Login WhatsApp</h3>
        <div class="mt-4 flex min-h-[300px] items-center justify-center rounded-xl bg-slate-50 p-4">
          <img
            v-if="whatsapp?.qr_image"
            :src="whatsapp.qr_image"
            alt="QR login WhatsApp"
            class="h-64 w-64 rounded-lg bg-white p-2 shadow-sm"
          >
          <div v-else class="text-sm text-slate-500">
            <p v-if="ready" class="font-semibold text-emerald-700">WhatsApp sudah login.</p>
            <p v-else-if="whatsapp?.waha?.error" class="text-red-600">{{ whatsapp.waha.error }}</p>
            <p v-else>QR belum tersedia. Jalankan WA lalu tekan Refresh WA.</p>
          </div>
        </div>
        <p v-if="whatsapp?.waha?.qr_error" class="mt-3 text-xs text-red-600">{{ whatsapp.waha.qr_error }}</p>
      </SurfaceCard>
    </div>
  </div>
</template>
