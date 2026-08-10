<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  ArrowDownTrayIcon,
  ArrowPathIcon,
  CheckCircleIcon,
  DocumentArrowUpIcon,
  ExclamationCircleIcon,
  EyeIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

definePageMeta({ layout: 'default', middleware: 'auth' })

const business = useLegacyBusiness()
const fileInputRef = ref<HTMLInputElement | null>(null)
const selectedFile = ref<File | null>(null)
const dragActive = ref(false)
const processing = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const resultUrl = ref('')
const resultName = ref('')
const segmentCount = ref('')
const outsideShift = ref(4)
const zoom = ref(2)
const lineColor = ref('#111827')
const previewOpen = ref(false)
const destinationModule = ref('')
const destinationMonth = ref(new Date().toISOString().slice(0, 7))
const destinationRecordId = ref('')
const destinationRecords = ref<Record<string, unknown>[]>([])
const loadingDestinations = ref(false)
const destinationError = ref('')
const colorOptions = [
  { value: '#ff0000', label: 'Merah' },
  { value: '#111827', label: 'Hitam' },
  { value: '#2563eb', label: 'Biru' },
  { value: '#059669', label: 'Hijau' },
  { value: '#7c3aed', label: 'Ungu' },
]

const fileSize = computed(() => {
  if (!selectedFile.value) return ''
  const mb = selectedFile.value.size / (1024 * 1024)
  return `${mb.toFixed(mb >= 10 ? 0 : 1)} MB`
})

const canProcess = computed(() => Boolean(
  selectedFile.value
  && destinationModule.value === 'buku_akta'
  && destinationRecordId.value
  && !processing.value,
))

const selectedDestination = computed(() => destinationRecords.value.find(
  row => String(row.id_buku_notaris || '') === destinationRecordId.value,
))

const destinationLabel = (row: Record<string, unknown>) => {
  const number = String(row.no_akta || '-').trim()
  const title = String(row.judul_pekerjaan || row.nama_akta || row.nama_client || 'Tanpa judul').trim()
  const client = String(row.nama_client || '').trim()
  return `No. ${number} - ${title}${client && client !== title ? ` (${client})` : ''}`
}

const unwrapRows = (payload: unknown): Record<string, unknown>[] => {
  const envelope = payload as { data?: unknown }
  const value = envelope?.data ?? payload
  if (Array.isArray(value)) return value as Record<string, unknown>[]
  const nested = value as { data?: unknown }
  return Array.isArray(nested?.data) ? nested.data as Record<string, unknown>[] : []
}

const loadDestinations = async () => {
  destinationRecordId.value = ''
  destinationRecords.value = []
  destinationError.value = ''
  if (destinationModule.value !== 'buku_akta') return

  loadingDestinations.value = true
  try {
    const response = await business.bukuNotaris.getBukuNotaris({ date: destinationMonth.value })
    destinationRecords.value = unwrapRows(response)
    if (!destinationRecords.value.length) {
      destinationError.value = 'Belum ada data Buku Akta Notaris pada periode ini.'
    }
  } catch (error) {
    destinationError.value = (error as { data?: { message?: string } })?.data?.message
      || 'Gagal memuat data Buku Akta Notaris.'
  } finally {
    loadingDestinations.value = false
  }
}

const clearResult = () => {
  if (resultUrl.value) URL.revokeObjectURL(resultUrl.value)
  resultUrl.value = ''
  resultName.value = ''
  segmentCount.value = ''
  previewOpen.value = false
}

const parseFilename = (contentDisposition: string) => {
  const quoted = contentDisposition.match(/filename="([^"]+)"/i)
  if (quoted?.[1]) return quoted[1]
  const plain = contentDisposition.match(/filename=([^;]+)/i)
  if (plain?.[1]) return plain[1].trim()
  return ''
}

const setFile = (file: File | null) => {
  errorMessage.value = ''
  successMessage.value = ''
  clearResult()

  if (!file) {
    selectedFile.value = null
    return
  }

  if (!/\.pdf$/i.test(file.name) || (file.type && file.type !== 'application/pdf')) {
    selectedFile.value = null
    errorMessage.value = 'File harus berformat PDF.'
    return
  }

  selectedFile.value = file
}

const handleFileInput = (event: Event) => {
  const input = event.target as HTMLInputElement
  setFile(input.files?.[0] || null)
}

const handleDrop = (event: DragEvent) => {
  event.preventDefault()
  dragActive.value = false
  setFile(event.dataTransfer?.files?.[0] || null)
}

const downloadResult = () => {
  if (!resultUrl.value) return
  const anchor = document.createElement('a')
  anchor.href = resultUrl.value
  anchor.download = resultName.value || 'akta-garis-otomatis.pdf'
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
}

const processDocument = async () => {
  if (!selectedFile.value || !canProcess.value) return

  processing.value = true
  errorMessage.value = ''
  successMessage.value = ''
  clearResult()

  try {
    const form = new FormData()
    form.append('document', selectedFile.value)
    form.append('destination_module', destinationModule.value)
    form.append('id_buku_notaris', destinationRecordId.value)
    form.append('outside_shift', String(outsideShift.value))
    form.append('zoom', String(zoom.value))
    form.append('line_color', lineColor.value)

    const response = await business.garisAkta.process(form)
    resultName.value = parseFilename(response.filename) || selectedFile.value.name.replace(/\.pdf$/i, '-garis-otomatis.pdf')
    const resultFile = new File([response.blob], resultName.value, { type: 'application/pdf' })
    const uploadForm = new FormData()
    uploadForm.append('dokumens[]', resultFile, resultFile.name)
    uploadForm.append('id_buku_notaris', destinationRecordId.value)
    await business.dokumen.UploadDokumenNotaris(uploadForm)

    resultUrl.value = URL.createObjectURL(response.blob)
    segmentCount.value = response.segments
    successMessage.value = `PDF berhasil diproses dan diunggah ke ${destinationLabel(selectedDestination.value || {})}.`
    previewOpen.value = true
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string }; message?: string })?.data?.message
      || (error as Error)?.message
      || 'Gagal memproses PDF.'
  } finally {
    processing.value = false
  }
}

watch([destinationModule, destinationMonth], loadDestinations)
onMounted(() => loadDestinations())
onBeforeUnmount(() => clearResult())
</script>

<template>
  <div class="space-y-5">
    <section class="surface-card overflow-hidden">
      <div class="border-b border-slate-200 px-5 py-5 sm:px-7">
        <p class="display-kicker">Buku Reportorium</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 class="text-2xl font-black text-slate-900 sm:text-3xl">Garis Otomatis Akta</h1>
            <p class="mt-2 max-w-2xl text-sm font-semibold text-slate-500">
              Upload PDF akta dan unduh PDF hasil proses garis otomatis tanpa konversi dokumen.
            </p>
          </div>
          <button
            type="button"
            class="inline-flex h-11 items-center gap-2 rounded-2xl bg-blue-600 px-4 text-sm font-extrabold text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-500 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:shadow-none"
            :disabled="!canProcess"
            @click="processDocument"
          >
            <ArrowPathIcon v-if="processing" class="h-5 w-5 animate-spin" />
            <DocumentArrowUpIcon v-else class="h-5 w-5" />
            {{ processing ? 'Memproses' : 'Proses File' }}
          </button>
        </div>
      </div>

      <div class="grid gap-5 p-5 lg:grid-cols-[1fr_320px] sm:p-7">
        <div class="space-y-4">
          <input ref="fileInputRef" type="file" accept="application/pdf,.pdf" class="hidden" @change="handleFileInput">

          <div
            class="flex min-h-[280px] cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed p-6 text-center transition"
            :class="dragActive ? 'border-blue-500 bg-blue-50' : 'border-slate-300 bg-slate-50 hover:border-blue-300 hover:bg-blue-50/50'"
            @click="fileInputRef?.click()"
            @dragover.prevent="dragActive = true"
            @dragleave.prevent="dragActive = false"
            @drop="handleDrop"
          >
            <div class="flex h-16 w-16 items-center justify-center rounded-2xl bg-white text-blue-600 shadow-sm">
              <DocumentArrowUpIcon class="h-8 w-8" />
            </div>
            <h2 class="mt-4 text-lg font-black text-slate-900">
              {{ selectedFile ? selectedFile.name : 'Pilih file PDF' }}
            </h2>
            <p class="mt-2 text-sm font-semibold text-slate-500">
              {{ selectedFile ? fileSize : 'Tarik file ke area ini atau klik untuk memilih.' }}
            </p>
          </div>
        </div>

        <aside class="space-y-4">
          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-black uppercase text-slate-500">Tujuan Dokumen</h2>
            <label class="mt-4 block text-sm font-extrabold text-slate-700" for="destination-module">Modul tujuan</label>
            <select id="destination-module" v-model="destinationModule" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 bg-white px-3 text-sm font-bold text-slate-800 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option value="" disabled>Pilih modul tujuan</option>
              <option value="buku_akta">Buku Akta Notaris</option>
            </select>

            <template v-if="destinationModule === 'buku_akta'">
              <label class="mt-4 block text-sm font-extrabold text-slate-700" for="destination-month">Periode akta</label>
              <input id="destination-month" v-model="destinationMonth" type="month" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 bg-white px-3 text-sm font-bold text-slate-800 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">

              <label class="mt-4 block text-sm font-extrabold text-slate-700" for="destination-record">Data akta tujuan</label>
              <select id="destination-record" v-model="destinationRecordId" :disabled="loadingDestinations || !destinationRecords.length" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 bg-white px-3 text-sm font-bold text-slate-800 disabled:cursor-not-allowed disabled:bg-slate-100 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
                <option value="" disabled>{{ loadingDestinations ? 'Memuat data akta...' : 'Pilih nomor/data akta' }}</option>
                <option v-for="row in destinationRecords" :key="String(row.id_buku_notaris)" :value="String(row.id_buku_notaris)">{{ destinationLabel(row) }}</option>
              </select>
              <p v-if="destinationError" class="mt-2 text-xs font-bold text-rose-600">{{ destinationError }}</p>
            </template>
            <p v-else class="mt-3 text-xs font-semibold text-amber-700">Tentukan modul dan data akta tujuan sebelum memproses PDF.</p>
          </div>

          <div class="rounded-2xl border border-slate-200 bg-white p-4">
            <h2 class="text-sm font-black uppercase text-slate-500">Pengaturan</h2>
            <label class="mt-4 block text-sm font-extrabold text-slate-700" for="outside-shift">Jarak garis keluar</label>
            <input id="outside-shift" v-model.number="outsideShift" type="range" min="0" max="30" step="1" class="mt-3 w-full accent-blue-600">
            <div class="mt-1 text-sm font-black text-slate-900">{{ outsideShift }} pt</div>

            <label class="mt-5 block text-sm font-extrabold text-slate-700" for="zoom">Presisi render</label>
            <select id="zoom" v-model.number="zoom" class="mt-2 h-11 w-full rounded-2xl border border-slate-200 bg-white px-3 text-sm font-bold text-slate-800 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100">
              <option :value="1">Normal</option>
              <option :value="2">Tinggi</option>
              <option :value="3">Sangat tinggi</option>
            </select>

            <label class="mt-5 block text-sm font-extrabold text-slate-700">Warna garis</label>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <button v-for="option in colorOptions" :key="option.value" type="button" class="h-9 w-9 rounded-lg border-2 transition hover:scale-105" :class="lineColor === option.value ? 'border-slate-900 ring-2 ring-blue-200' : 'border-white ring-1 ring-slate-300'" :style="{ backgroundColor: option.value }" :title="option.label" @click="lineColor = option.value" />
              <label class="relative h-9 w-9 cursor-pointer overflow-hidden rounded-lg border-2 border-white ring-1 ring-slate-300" title="Pilih warna lain">
                <input v-model="lineColor" type="color" class="absolute -inset-2 h-14 w-14 cursor-pointer border-0 p-0">
              </label>
              <span class="ml-1 font-mono text-xs font-bold uppercase text-slate-600">{{ lineColor }}</span>
            </div>
          </div>

          <div v-if="successMessage" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800">
            <div class="flex items-start gap-2">
              <CheckCircleIcon class="mt-0.5 h-5 w-5 flex-none" />
              <div>
                <p>{{ successMessage }}</p>
                <p v-if="segmentCount" class="mt-1 text-xs font-black uppercase text-emerald-700">Segmen: {{ segmentCount }}</p>
              </div>
            </div>
            <button type="button" class="mt-3 inline-flex h-10 items-center gap-2 rounded-2xl bg-emerald-600 px-4 text-sm font-extrabold text-white hover:bg-emerald-500" @click="downloadResult">
              <ArrowDownTrayIcon class="h-5 w-5" />
              Download PDF
            </button>
            <button type="button" class="mt-2 inline-flex h-10 items-center gap-2 rounded-2xl border border-emerald-300 bg-white px-4 text-sm font-extrabold text-emerald-700 hover:bg-emerald-100" @click="previewOpen = true">
              <EyeIcon class="h-5 w-5" />
              Lihat Hasil
            </button>
          </div>

          <div v-if="errorMessage" class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-800">
            <div class="flex items-start gap-2">
              <ExclamationCircleIcon class="mt-0.5 h-5 w-5 flex-none" />
              <p>{{ errorMessage }}</p>
            </div>
          </div>
        </aside>
      </div>
    </section>

    <Teleport to="body">
      <div v-if="previewOpen && resultUrl" class="fixed inset-0 z-[200] flex flex-col bg-slate-950" role="dialog" aria-modal="true" aria-label="Preview hasil garis otomatis akta">
        <header class="flex h-16 shrink-0 items-center justify-between gap-4 border-b border-white/10 bg-slate-900 px-4 text-white sm:px-6">
          <div class="min-w-0">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400">Preview Hasil</p>
            <h2 class="truncate text-sm font-black">{{ resultName || 'akta-garis-otomatis.pdf' }}</h2>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <span class="hidden items-center gap-2 rounded-lg border border-white/15 px-3 py-2 text-xs font-bold sm:flex"><span class="h-3 w-3 rounded-full" :style="{ backgroundColor: lineColor }" />{{ lineColor }}</span>
            <button type="button" class="inline-flex h-10 items-center gap-2 rounded-lg bg-emerald-600 px-3 text-xs font-extrabold hover:bg-emerald-500" @click="downloadResult"><ArrowDownTrayIcon class="h-5 w-5" /><span class="hidden sm:inline">Download</span></button>
            <button type="button" class="flex h-10 w-10 items-center justify-center rounded-lg border border-white/20 hover:bg-white/10" title="Tutup preview" @click="previewOpen = false"><XMarkIcon class="h-6 w-6" /></button>
          </div>
        </header>
        <iframe :src="resultUrl" class="min-h-0 flex-1 w-full bg-slate-800" title="Preview PDF garis otomatis akta" />
      </div>
    </Teleport>
  </div>
</template>
