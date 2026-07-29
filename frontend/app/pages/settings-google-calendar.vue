<script setup lang="ts">
type CalendarStatus = {
  checked_at?: string
  enabled?: boolean
  configured?: boolean
  schema_ready?: boolean
  only_on_vip?: boolean
  vip_active?: boolean
  calendar_id?: string
  timezone?: string
  calendar_embed_url?: string | null
  credential_exists?: boolean
  service_account_email?: string | null
  event_counts?: {
    total?: number
    synced?: number
    pending?: number
    failed?: number
  }
  last_synced_at?: string | null
  recent_errors?: Array<{
    id?: number
    title?: string
    error?: string
    updated_at?: string
  }>
  command?: {
    exit_code?: number
    output?: string[]
  }
  dry_run?: boolean
  targets?: Array<{
    id?: number
    title?: string
    start_datetime?: string
    end_datetime?: string
    google_event_id?: string | null
  }>
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
  title: 'Google Calendar',
})

const business = useLegacyBusiness()
const loading = ref(false)
const syncing = ref(false)
const dryRunning = ref(false)
const saving = ref(false)
const clearing = ref(false)
const message = ref('')
const errorMessage = ref('')
const calendar = ref<CalendarStatus | null>(null)
const settingsForm = reactive({
  calendar_id: '',
  enabled: true,
  only_on_vip: true,
  timezone: 'Asia/Jakarta',
})

const syncFormFromStatus = () => {
  settingsForm.calendar_id = calendar.value?.calendar_id || ''
  settingsForm.enabled = Boolean(calendar.value?.enabled)
  settingsForm.only_on_vip = calendar.value?.only_on_vip !== false
  settingsForm.timezone = calendar.value?.timezone || 'Asia/Jakarta'
}

const formatDate = (value: unknown) => {
  const raw = String(value || '').trim()
  if (!raw) return '-'
  const parsed = new Date(raw)
  if (Number.isNaN(parsed.getTime())) return raw
  return parsed.toLocaleString('id-ID')
}

const statusLabel = computed(() => {
  if (!calendar.value?.configured) return 'Belum lengkap'
  if (!calendar.value?.enabled) return 'Nonaktif'
  if (calendar.value?.only_on_vip && !calendar.value?.vip_active) return 'Standby'
  return 'Aktif'
})

const statusClass = computed(() => {
  if (!calendar.value?.configured) return 'text-red-700'
  if (!calendar.value?.enabled) return 'text-amber-700'
  if (calendar.value?.only_on_vip && !calendar.value?.vip_active) return 'text-blue-700'
  return 'text-emerald-700'
})

const loadStatus = async () => {
  if (loading.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await business.settings.getGoogleCalendarStatus() as ApiEnvelope<CalendarStatus>
    calendar.value = response.data || null
    syncFormFromStatus()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal memuat status Google Calendar.'
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  if (saving.value) return
  saving.value = true
  message.value = ''
  errorMessage.value = ''
  try {
    const response = await business.settings.saveGoogleCalendarSettings({
      calendar_id: settingsForm.calendar_id,
      enabled: settingsForm.enabled,
      only_on_vip: settingsForm.only_on_vip,
      timezone: settingsForm.timezone,
    }) as ApiEnvelope<CalendarStatus>
    calendar.value = response.data || null
    syncFormFromStatus()
    message.value = response.message || 'Pengaturan Google Calendar berhasil disimpan.'
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal menyimpan pengaturan Google Calendar.'
  } finally {
    saving.value = false
  }
}

const clearErrors = async () => {
  if (clearing.value) return
  clearing.value = true
  message.value = ''
  errorMessage.value = ''
  try {
    const response = await business.settings.clearGoogleCalendarErrors() as ApiEnvelope<CalendarStatus>
    calendar.value = response.data || null
    syncFormFromStatus()
    message.value = response.message || 'Error lama berhasil dibersihkan.'
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal membersihkan error Google Calendar.'
  } finally {
    clearing.value = false
  }
}

const runDryRun = async () => {
  if (dryRunning.value) return
  dryRunning.value = true
  message.value = ''
  errorMessage.value = ''
  try {
    const response = await business.settings.syncGoogleCalendar({
      dry_run: true,
      from: '-2 years',
      to: '+2 years',
    }) as ApiEnvelope<CalendarStatus>
    calendar.value = response.data || null
    message.value = response.message || 'Dry-run selesai.'
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal menjalankan dry-run Google Calendar.'
  } finally {
    dryRunning.value = false
  }
}

const runSync = async () => {
  if (syncing.value) return
  syncing.value = true
  message.value = ''
  errorMessage.value = ''
  try {
    const response = await business.settings.syncGoogleCalendar({
      from: '-2 years',
      to: '+2 years',
    }) as ApiEnvelope<CalendarStatus>
    calendar.value = response.data || null
    message.value = response.message || 'Sinkron Google Calendar selesai.'
  } catch (error) {
    const response = (error as { data?: ApiEnvelope<CalendarStatus> })?.data
    if (response?.data) {
      calendar.value = response.data
    }
    errorMessage.value = response?.message || 'Gagal menjalankan sinkron Google Calendar.'
  } finally {
    syncing.value = false
  }
}

onMounted(() => {
  void loadStatus()
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p class="display-kicker">Google Calendar</p>
          <h2 class="mt-2 text-2xl font-semibold text-slate-900">Sinkron Jadwal SIMANIS</h2>
          <p class="mt-2 text-sm text-slate-500">
            Pantau koneksi service account, jumlah event tersinkron, dan jalankan sinkron manual dari dashboard.
          </p>
        </div>
        <div class="flex flex-wrap gap-3">
          <button
            type="button"
            class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 disabled:opacity-50"
            :disabled="loading || syncing || dryRunning"
            @click="loadStatus"
          >
            {{ loading ? 'Memuat...' : 'Refresh' }}
          </button>
          <button
            type="button"
            class="h-10 rounded-xl border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 disabled:opacity-50"
            :disabled="loading || syncing || dryRunning"
            @click="runDryRun"
          >
            {{ dryRunning ? 'Mengecek...' : 'Dry-run' }}
          </button>
          <button
            type="button"
            class="h-10 rounded-xl bg-emerald-600 px-4 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="loading || syncing || dryRunning || !calendar?.configured || !calendar?.schema_ready"
            @click="runSync"
          >
            {{ syncing ? 'Sinkron...' : 'Sinkron Sekarang' }}
          </button>
        </div>
      </div>

      <p v-if="message" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ message }}
      </p>
      <p v-if="errorMessage" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errorMessage }}
      </p>
      <p
        v-if="calendar && calendar.schema_ready === false"
        class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700"
      >
        Schema Google Calendar belum tersedia di database server ini. Kalau server ini masih standby/read-only,
        sinkron tetap dijalankan dari server VIP yang aktif.
      </p>
    </SurfaceCard>

    <SurfaceCard class="p-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h3 class="font-semibold text-slate-900">Pengaturan Calendar</h3>
          <p class="mt-1 text-sm text-slate-500">
            Ubah Calendar ID dan mode sinkron langsung dari dashboard. Credential JSON tetap disimpan aman di server.
          </p>
        </div>
        <button
          type="button"
          class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="saving || loading"
          @click="saveSettings"
        >
          {{ saving ? 'Menyimpan...' : 'Simpan Pengaturan' }}
        </button>
      </div>

      <div class="mt-5 grid gap-4 lg:grid-cols-[1.4fr_0.6fr]">
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Calendar ID</span>
          <input
            v-model.trim="settingsForm.calendar_id"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="contoh: xxx@group.calendar.google.com"
          >
        </label>
        <label class="block">
          <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Timezone</span>
          <input
            v-model.trim="settingsForm.timezone"
            type="text"
            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-blue-400"
            placeholder="Asia/Jakarta"
          >
        </label>
      </div>

      <div class="mt-4 flex flex-wrap gap-4 text-sm text-slate-700">
        <label class="inline-flex items-center gap-2">
          <input v-model="settingsForm.enabled" type="checkbox" class="h-4 w-4 rounded border-slate-300">
          Aktifkan sinkron Google Calendar
        </label>
        <label class="inline-flex items-center gap-2">
          <input v-model="settingsForm.only_on_vip" type="checkbox" class="h-4 w-4 rounded border-slate-300">
          Auto-sync hanya di server yang memegang VIP
        </label>
      </div>
    </SurfaceCard>

    <div class="grid gap-4 md:grid-cols-4">
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Status</p>
        <p class="mt-3 text-2xl font-semibold" :class="statusClass">{{ statusLabel }}</p>
        <p class="mt-2 text-xs text-slate-500">Terakhir dicek {{ formatDate(calendar?.checked_at) }}</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Schema DB</p>
        <p class="mt-3 text-2xl font-semibold" :class="calendar?.schema_ready === false ? 'text-amber-700' : 'text-emerald-700'">
          {{ calendar?.schema_ready === false ? 'Belum siap' : 'Siap' }}
        </p>
        <p class="mt-2 text-xs text-slate-500">Kolom Google Calendar di tabel event</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Event Tersinkron</p>
        <p class="mt-3 text-2xl font-semibold text-slate-900">{{ calendar?.event_counts?.synced ?? 0 }}</p>
        <p class="mt-2 text-xs text-slate-500">Total {{ calendar?.event_counts?.total ?? 0 }} event</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Pending / Error</p>
        <p class="mt-3 text-2xl font-semibold text-slate-900">
          {{ calendar?.event_counts?.pending ?? 0 }} / {{ calendar?.event_counts?.failed ?? 0 }}
        </p>
        <p class="mt-2 text-xs text-slate-500">Update terakhir {{ formatDate(calendar?.last_synced_at) }}</p>
      </SurfaceCard>
      <SurfaceCard class="p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Mode HA</p>
        <p class="mt-3 text-2xl font-semibold" :class="calendar?.vip_active ? 'text-emerald-700' : 'text-blue-700'">
          {{ calendar?.vip_active ? 'VIP Aktif' : 'Standby' }}
        </p>
        <p class="mt-2 text-xs text-slate-500">
          {{ calendar?.only_on_vip ? 'Auto-sync hanya di server VIP.' : 'Auto-sync semua server.' }}
        </p>
      </SurfaceCard>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_0.9fr]">
      <SurfaceCard class="p-6">
        <h3 class="font-semibold text-slate-900">Konfigurasi</h3>
        <dl class="mt-4 space-y-3 text-sm">
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Calendar ID</dt>
            <dd class="mt-1 break-all text-slate-800">{{ calendar?.calendar_id || '-' }}</dd>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Service Account</dt>
            <dd class="mt-1 break-all text-slate-800">{{ calendar?.service_account_email || '-' }}</dd>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">Credential JSON</dt>
            <dd class="mt-1 text-slate-800">{{ calendar?.credential_exists ? 'Tersedia di server' : 'Belum tersedia' }}</dd>
          </div>
        </dl>

        <a
          v-if="calendar?.calendar_embed_url"
          :href="calendar.calendar_embed_url"
          target="_blank"
          rel="noopener noreferrer"
          class="mt-4 inline-flex h-10 items-center rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white"
        >
          Buka Google Calendar
        </a>

        <p class="mt-3 text-xs text-slate-500">
          Kalau mengganti service account JSON, file-nya tetap perlu ditaruh di server:
          <span class="font-mono">storage/app/google-calendar/service-account-credentials.json</span>
          lalu permission dibuat milik <span class="font-mono">www-data</span>.
        </p>
      </SurfaceCard>

      <SurfaceCard class="p-6">
        <h3 class="font-semibold text-slate-900">Output Sinkron</h3>
        <div class="mt-4 max-h-80 overflow-auto rounded-xl bg-slate-950 p-4 text-xs text-slate-100">
          <template v-if="calendar?.command?.output?.length">
            <p v-for="(line, index) in calendar.command.output" :key="index" class="whitespace-pre-wrap font-mono">
              {{ line }}
            </p>
          </template>
          <p v-else class="text-slate-400">Belum ada output sinkron manual.</p>
        </div>
      </SurfaceCard>
    </div>

    <SurfaceCard v-if="calendar?.targets?.length" class="overflow-hidden p-0">
      <div class="border-b border-slate-200 px-6 py-4">
        <h3 class="font-semibold text-slate-900">Target Dry-run</h3>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-6 py-3">ID</th>
              <th class="px-6 py-3">Judul</th>
              <th class="px-6 py-3">Mulai</th>
              <th class="px-6 py-3">Google Event</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="target in calendar.targets" :key="target.id">
              <td class="px-6 py-3">{{ target.id }}</td>
              <td class="px-6 py-3">{{ target.title }}</td>
              <td class="px-6 py-3">{{ formatDate(target.start_datetime) }}</td>
              <td class="px-6 py-3">{{ target.google_event_id || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </SurfaceCard>

    <SurfaceCard v-if="calendar?.recent_errors?.length" class="p-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="font-semibold text-slate-900">Error Terakhir</h3>
        <button
          type="button"
          class="h-9 rounded-xl border border-red-200 bg-red-50 px-4 text-xs font-semibold text-red-700 disabled:opacity-50"
          :disabled="clearing"
          @click="clearErrors"
        >
          {{ clearing ? 'Membersihkan...' : 'Bersihkan Error Lama' }}
        </button>
      </div>
      <div class="mt-4 space-y-3">
        <div
          v-for="row in calendar.recent_errors"
          :key="row.id"
          class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"
        >
          <p class="font-semibold">#{{ row.id }} - {{ row.title }}</p>
          <p class="mt-1 whitespace-pre-wrap text-xs">{{ row.error }}</p>
        </div>
      </div>
    </SurfaceCard>
  </div>
</template>
