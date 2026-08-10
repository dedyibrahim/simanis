<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import {
  ArrowDownTrayIcon,
  ArrowPathIcon,
  CheckCircleIcon,
  DocumentArrowUpIcon,
  ExclamationCircleIcon,
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

const fileSize = computed(() => {
  if (!selectedFile.value) return ''
  const mb = selectedFile.value.size / (1024 * 1024)
  return `${mb.toFixed(mb >= 10 ? 0 : 1)} MB`
})

const canProcess = computed(() => Boolean(selectedFile.value) && !processing.value)

const clearResult = () => {
  if (resultUrl.value) URL.revokeObjectURL(resultUrl.value)
  resultUrl.value = ''
  resultName.value = ''
  segmentCount.value = ''
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

  if (!/\.(doc|docx)$/i.test(file.name)) {
    selectedFile.value = null
    errorMessage.value = 'File harus berformat .doc atau .docx.'
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
  if (!selectedFile.value || processing.value) return

  processing.value = true
  errorMessage.value = ''
  successMessage.value = ''
  clearResult()

  try {
    const form = new FormData()
    form.append('document', selectedFile.value)
    form.append('outside_shift', String(outsideShift.value))
    form.append('zoom', String(zoom.value))

    const response = await business.garisAkta.process(form)
    resultUrl.value = URL.createObjectURL(response.blob)
    resultName.value = parseFilename(response.filename) || selectedFile.value.name.replace(/\.(doc|docx)$/i, '-garis-otomatis.pdf')
    segmentCount.value = response.segments
    successMessage.value = 'PDF garis otomatis siap ditinjau.'
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string }; message?: string })?.data?.message
      || (error as Error)?.message
      || 'Gagal memproses DOC/DOCX.'
  } finally {
    processing.value = false
  }
}

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
              Upload DOC atau DOCX akta dan unduh PDF hasil proses garis otomatis.
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
          <input ref="fileInputRef" type="file" accept=".doc,.docx" class="hidden" @change="handleFileInput">

          <div v-if="resultUrl" class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
              <div>
                <p class="text-xs font-black uppercase text-slate-400">Preview Hasil</p>
                <h2 class="mt-1 text-sm font-black text-slate-900">{{ resultName || 'akta-garis-otomatis.pdf' }}</h2>
              </div>
              <div class="flex flex-wrap gap-2">
                <button type="button" class="h-10 rounded-2xl border border-slate-200 bg-white px-4 text-sm font-extrabold text-slate-700 hover:bg-slate-50" @click="fileInputRef?.click()">
                  Ganti File
                </button>
                <button type="button" class="inline-flex h-10 items-center gap-2 rounded-2xl bg-emerald-600 px-4 text-sm font-extrabold text-white hover:bg-emerald-500" @click="downloadResult">
                  <ArrowDownTrayIcon class="h-5 w-5" />
                  Download
                </button>
              </div>
            </div>
            <iframe
              :src="resultUrl"
              class="h-[70vh] min-h-[520px] w-full bg-slate-100"
              title="Preview PDF garis otomatis akta"
            />
          </div>

          <div
            v-else
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
              {{ selectedFile ? selectedFile.name : 'Pilih file DOC/DOCX' }}
            </h2>
            <p class="mt-2 text-sm font-semibold text-slate-500">
              {{ selectedFile ? fileSize : 'Tarik file ke area ini atau klik untuk memilih.' }}
            </p>
          </div>
        </div>

        <aside class="space-y-4">
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
  </div>
</template>
