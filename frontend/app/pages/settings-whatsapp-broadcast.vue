<script setup lang="ts">
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue'
import { ArrowPathIcon, PaperAirplaneIcon, StopIcon } from '@heroicons/vue/24/outline'

definePageMeta({ middleware: ['auth', 'super-admin-only'] })
useHead({ title: 'Blast WA' })

type Recipient = { id: number; name: string; phone: string; level?: string; available: boolean }
type Delivery = { id?: number; recipient_name: string; phone: string; message: string; status: string; error?: string; created_at?: string }
const { request } = useApi()
const people = ref<Recipient[]>([])
const selected = ref<number[]>([])
const search = ref('')
const message = ref('')
const error = ref('')
const loading = ref(false)
const sending = ref(false)
const stopping = ref(false)
const confirming = ref(false)
const results = ref<Delivery[]>([])
const history = ref<Delivery[]>([])
const total = ref(0)
const filtered = computed(() => people.value.filter(a => `${a.name} ${a.level || ''} ${a.phone}`.toLowerCase().includes(search.value.toLowerCase())))
const recipients = computed(() => {
  const phones = new Set<string>()
  return people.value.filter(a => {
    if (!selected.value.includes(a.id) || !a.available || phones.has(a.phone)) return false
    phones.add(a.phone)
    return true
  })
})
const allVisibleSelected = computed(() => filtered.value.some(a => a.available)
  && filtered.value.filter(a => a.available).every(a => selected.value.includes(a.id)))
const statusLabel = (status: string) => ({ sent: 'Diterima gateway', failed: 'Belum terkonfirmasi', skipped: 'Tidak dikirim', processing: 'Sedang diproses', unknown: 'Perlu diperiksa' }[status] || status)
const errorText = (e: any) => e?.data?.message || 'Tidak dapat menghubungi server.'

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [recipientResponse, logs] = await Promise.all([
      request<{ data: Recipient[] }>('auth/whatsapp-broadcast/assistants'),
      request<{ data: Delivery[] }>('auth/whatsapp-broadcast/history'),
    ])
    people.value = recipientResponse.data
    history.value = logs.data
    selected.value = selected.value.filter(id => recipientResponse.data.some(a => a.id === id && a.available))
  } catch (e) { error.value = errorText(e) }
  finally { loading.value = false }
}

function toggleVisible() {
  const ids = filtered.value.filter(a => a.available).map(a => a.id)
  selected.value = allVisibleSelected.value ? selected.value.filter(id => !ids.includes(id)) : [...new Set([...selected.value, ...ids])]
}

function batchId() {
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6]! & 15) | 64
  bytes[8] = (bytes[8]! & 63) | 128
  const hex = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

async function send() {
  if (sending.value || !recipients.value.length || !message.value.trim()) return
  const targets = [...recipients.value]
  const text = message.value.trim()
  const batch = batchId()
  confirming.value = false
  sending.value = true
  stopping.value = false
  results.value = []
  error.value = ''
  total.value = targets.length
  try {
    for (const target of targets) {
      if (stopping.value) break
      try {
        const response = await request<{ data: Delivery }>('auth/whatsapp-broadcast/send', {
          method: 'POST', body: { batch_id: batch, recipient_id: target.id, message: text },
        })
        results.value.push(response.data)
        if (response.data.status !== 'sent') {
          error.value = 'Pengiriman dihentikan karena gateway belum mengonfirmasi pesan terakhir.'
          break
        }
      } catch (e) {
        results.value.push({ recipient_name: target.name, phone: target.phone, message: text, status: 'unknown', error: errorText(e) })
        error.value = 'Pengiriman dihentikan. Periksa riwayat dan WhatsApp sebelum mengirim ulang.'
        break
      }
      if (results.value.length < targets.length) await new Promise(resolve => setTimeout(resolve, 1000))
    }
    const logs = await request<{ data: Delivery[] }>('auth/whatsapp-broadcast/history')
    history.value = logs.data
  } catch (e) { error.value = errorText(e) }
  finally { sending.value = false }
}

function beforeUnload(event: BeforeUnloadEvent) {
  if (sending.value) { event.preventDefault(); event.returnValue = '' }
}
onBeforeRouteLeave(() => !sending.value)
onMounted(() => { load(); window.addEventListener('beforeunload', beforeUnload) })
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload))
</script>

<template>
  <div class="blast space-y-6">
    <header class="flex items-center justify-between gap-3">
      <h1 class="text-xl font-semibold">Blast WA</h1>
      <button class="control" :disabled="loading || sending" @click="load"><ArrowPathIcon class="size-4" /> Refresh</button>
    </header>
    <p v-if="error" role="alert" class="notice">{{ error }}</p>
    <div class="workspace">
      <section class="min-w-0 space-y-3">
        <div class="flex items-center justify-between gap-2"><h2 class="font-semibold">Penerima</h2><span class="text-sm">{{ recipients.length }} nomor dipilih</span></div>
        <input v-model="search" aria-label="Cari penerima" placeholder="Cari nama, level, atau nomor WhatsApp" class="field" :disabled="sending">
        <label class="flex items-center gap-2 py-2 text-sm"><input type="checkbox" :checked="allVisibleSelected" :disabled="sending || loading" @change="toggleVisible"> Pilih semua hasil</label>
        <p v-if="loading" role="status">Memuat penerima...</p>
        <p v-else-if="!filtered.length" class="py-6 text-sm">Tidak ada penerima ditemukan.</p>
        <div class="recipient-list">
          <label v-for="person in filtered" :key="person.id" class="recipient" :class="{ unavailable: !person.available }">
            <input v-model="selected" type="checkbox" :value="person.id" :disabled="sending || !person.available">
            <span class="min-w-0"><span class="block break-words font-medium">{{ person.name }}</span><span class="block text-sm opacity-75">{{ person.level || 'User' }} · {{ person.available ? person.phone : 'Nomor WA belum valid' }}</span></span>
          </label>
        </div>
      </section>
      <section class="min-w-0 space-y-3">
        <label for="broadcast-message" class="block font-semibold">Pesan pengumuman</label>
        <textarea id="broadcast-message" v-model="message" :disabled="sending" maxlength="4000" rows="12" class="field resize-y" placeholder="Tulis pesan..." />
        <p class="text-right text-xs opacity-75">{{ message.length }} / 4000</p>
        <div class="flex flex-wrap items-center gap-3">
          <button class="control primary" :disabled="sending || loading || !recipients.length || !message.trim()" @click="confirming = true"><PaperAirplaneIcon class="size-4" /> Tinjau &amp; Kirim</button>
          <button v-if="sending" class="control" :disabled="stopping" @click="stopping = true"><StopIcon class="size-4" /> {{ stopping ? 'Menghentikan...' : 'Hentikan' }}</button>
        </div>
        <div v-if="total" class="space-y-2" aria-live="polite">
          <p class="text-sm">{{ sending ? 'Mengirim' : 'Selesai diproses' }}: {{ results.length }} / {{ total }} penerima</p>
          <progress class="w-full" :value="results.length" :max="total" />
          <ul class="text-sm"><li v-for="(result, index) in results" :key="index" class="py-1">{{ result.recipient_name }}: {{ statusLabel(result.status) }}</li></ul>
        </div>
      </section>
    </div>
    <section class="border-t pt-5" style="border-color: var(--simanis-border)">
      <h2 class="mb-3 font-semibold">Riwayat Pengiriman</h2>
      <p v-if="!history.length" class="text-sm opacity-75">Belum ada pengiriman.</p>
      <div class="history-list">
        <details v-for="row in history" :key="row.id" class="history-row">
          <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 text-sm"><span class="break-words font-medium">{{ row.recipient_name }}</span><span>{{ row.created_at }} · {{ statusLabel(row.status) }}</span></summary>
          <p class="mt-2 text-xs opacity-75">{{ row.phone }}</p>
          <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ row.message }}</p>
          <p v-if="row.error" class="mt-2 text-sm">{{ row.error }}</p>
        </details>
      </div>
    </section>
    <Dialog :open="confirming" class="blast fixed inset-0 z-[100]" @close="confirming = false">
      <div class="fixed inset-0 bg-black/60" aria-hidden="true" />
      <div class="fixed inset-0 flex items-center justify-center overflow-y-auto p-4">
        <DialogPanel class="confirm-panel w-full max-w-xl space-y-4 rounded-lg p-5">
          <DialogTitle class="text-lg font-semibold">Kirim ke {{ recipients.length }} nomor WhatsApp?</DialogTitle>
          <p class="max-h-24 overflow-y-auto text-sm">{{ recipients.map(a => a.name).join(', ') }}</p>
          <div class="max-h-64 overflow-y-auto whitespace-pre-wrap break-words border-y py-4 text-sm" style="border-color: var(--simanis-border)">{{ message.trim() }}</div>
          <div class="flex justify-end gap-2"><button class="control" @click="confirming = false">Batal</button><button class="control primary" @click="send"><PaperAirplaneIcon class="size-4" /> Kirim Sekarang</button></div>
        </DialogPanel>
      </div>
    </Dialog>
  </div>
</template>

<style scoped>
.blast { color: #172033; letter-spacing: 0; }
:global(.theme-dark .blast) { color: #e5edf8; }
.workspace { display: grid; grid-template-columns: minmax(250px, 1fr) minmax(0, 1.5fr); gap: 32px; }
.field, .control { border: 1px solid var(--simanis-border, #c2d3ea); border-radius: 6px; background: var(--simanis-surface, #fff); color: inherit; }
.field { width: 100%; padding: 10px 12px; font-size: 14px; }
.control { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 38px; padding: 8px 12px; font-size: 14px; font-weight: 600; }
.control:disabled { opacity: .5; cursor: not-allowed; }
.primary { background: var(--simanis-accent-600, #2563eb); border-color: transparent; color: white; }
.recipient-list { max-height: 480px; overflow-y: auto; }
.recipient { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--simanis-border); cursor: pointer; }
.unavailable { opacity: .6; }
input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; accent-color: var(--simanis-accent-600); }
.notice { border: 1px solid #b45309; border-radius: 6px; padding: 12px; }
.history-list { max-height: 420px; overflow-y: auto; }
.history-row { padding: 12px 0; border-bottom: 1px solid var(--simanis-border); }
.confirm-panel { background: var(--simanis-surface, #fff); }
:global(.theme-dark .blast .field), :global(.theme-dark .blast .control:not(.primary)), :global(.theme-dark .blast .confirm-panel) { background: #101827; color: #e5edf8; }
@media (max-width: 760px) { .workspace { grid-template-columns: minmax(0, 1fr); gap: 24px; } .recipient-list { max-height: 300px; } }
</style>
