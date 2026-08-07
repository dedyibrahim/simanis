<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import {
  ArrowPathIcon,
  CameraIcon,
  CheckCircleIcon,
  DocumentMagnifyingGlassIcon,
  PhotoIcon,
  StopIcon,
} from '@heroicons/vue/24/outline'

definePageMeta({ layout: 'default', middleware: 'auth' })

type ApiResponse<T = unknown> = { status?: boolean; message?: string; data?: T }
type OcrField = { value?: string; confidence?: number; warnings?: string[] }
type OcrPayload = { fields?: Record<string, OcrField>; raw_text?: string; metadata?: Record<string, unknown> }

const business = useLegacyBusiness()
const videoRef = ref<HTMLVideoElement | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const stream = ref<MediaStream | null>(null)
const imageFile = ref<File | null>(null)
const imagePreview = ref('')
const cameraError = ref('')
const processing = ref(false)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const rawText = ref('')
const hasResult = ref(false)
const clientType = ref<'Perorangan' | 'Badan Hukum'>('Perorangan')

const fields = reactive({
  nik: '',
  nama: '',
  tempat_tgl_lahir: '',
  jenis_kelamin: '',
  gol_darah: '',
  alamat: '',
  rt_rw: '',
  kel_desa: '',
  kecamatan: '',
  agama: '',
  status_perkawinan: '',
  pekerjaan: '',
  kewarganegaraan: '',
  berlaku_hingga: '',
  contact_number: '',
  email: '',
})

const confidence = reactive<Record<string, number | null>>({})
const fieldDefinitions = [
  { key: 'nik', label: 'NIK', span: true },
  { key: 'nama', label: 'Nama Client', span: true },
  { key: 'tempat_tgl_lahir', label: 'Tempat/Tanggal Lahir' },
  { key: 'jenis_kelamin', label: 'Jenis Kelamin' },
  { key: 'gol_darah', label: 'Golongan Darah' },
  { key: 'agama', label: 'Agama' },
  { key: 'alamat', label: 'Alamat', span: true },
  { key: 'rt_rw', label: 'RT/RW' },
  { key: 'kel_desa', label: 'Kelurahan/Desa' },
  { key: 'kecamatan', label: 'Kecamatan' },
  { key: 'status_perkawinan', label: 'Status Perkawinan' },
  { key: 'pekerjaan', label: 'Pekerjaan' },
  { key: 'kewarganegaraan', label: 'Kewarganegaraan' },
  { key: 'berlaku_hingga', label: 'Berlaku Hingga' },
] as const

const stopCamera = () => {
  stream.value?.getTracks().forEach(track => track.stop())
  stream.value = null
  if (videoRef.value) videoRef.value.srcObject = null
}

const startCamera = async () => {
  cameraError.value = ''
  stopCamera()
  try {
    stream.value = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
      audio: false,
    })
    if (videoRef.value) {
      videoRef.value.srcObject = stream.value
      await videoRef.value.play()
    }
  } catch (error) {
    cameraError.value = 'Kamera tidak dapat dibuka. Izinkan akses kamera atau gunakan unggah gambar.'
  }
}

const setImageFile = async (file: File) => {
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
  imageFile.value = file
  imagePreview.value = URL.createObjectURL(file)
  successMessage.value = ''
  errorMessage.value = ''
  await runOcr()
}

const captureKtp = async () => {
  const video = videoRef.value
  if (!video?.videoWidth || !video.videoHeight) {
    cameraError.value = 'Kamera belum siap mengambil gambar.'
    return
  }
  const canvas = document.createElement('canvas')
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height)
  const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.94))
  if (blob) await setImageFile(new File([blob], `ktp-${Date.now()}.jpg`, { type: 'image/jpeg' }))
}

const handleUpload = async (event: Event) => {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (file) await setImageFile(file)
}

const readField = (source: Record<string, OcrField>, ...keys: string[]) => {
  for (const key of keys) {
    const value = String(source[key]?.value || '').trim()
    if (value) return { value, confidence: source[key]?.confidence ?? null }
  }
  return { value: '', confidence: null }
}

const applyOcrResult = (payload: OcrPayload) => {
  const source = payload.fields || {}
  const aliases: Record<string, string[]> = {
    nik: ['nik'], nama: ['nama'], tempat_tgl_lahir: ['tempat_tgl_lahir', 'tempat_tgl_lahir'],
    jenis_kelamin: ['jenis_kelamin'], gol_darah: ['gol_darah'], alamat: ['alamat'], rt_rw: ['rt_rw'],
    kel_desa: ['kel_desa'], kecamatan: ['kecamatan'], agama: ['agama'], status_perkawinan: ['status_perkawinan'],
    pekerjaan: ['pekerjaan'], kewarganegaraan: ['kewarganegaraan'], berlaku_hingga: ['berlaku_hingga'],
  }
  Object.entries(aliases).forEach(([target, keys]) => {
    const result = readField(source, ...keys)
    ;(fields as Record<string, string>)[target] = result.value
    confidence[target] = result.confidence
  })
  fields.nik = fields.nik.replace(/\D/g, '').slice(0, 16)
  rawText.value = String(payload.raw_text || '')
  hasResult.value = true
}

const runOcr = async () => {
  if (!imageFile.value || processing.value) return
  processing.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const form = new FormData()
    form.append('ktp_image', imageFile.value)
    const response = await business.client.extractKtpOcr(form) as ApiResponse<OcrPayload>
    if (!response.status || !response.data) throw new Error(response.message || 'OCR gagal memproses gambar.')
    applyOcrResult(response.data)
    successMessage.value = response.message || 'KTP berhasil dibaca. Silakan koreksi hasilnya.'
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string }; message?: string })?.data?.message
      || (error as Error)?.message || 'Gagal memproses gambar KTP.'
  } finally {
    processing.value = false
  }
}

const buildAddress = () => [
  fields.alamat,
  fields.rt_rw ? `RT/RW ${fields.rt_rw}` : '',
  fields.kel_desa,
  fields.kecamatan,
].filter(Boolean).join(', ')

const confidencePercent = (value: number | null | undefined) => {
  if (value == null || Number.isNaN(Number(value))) return null
  return Math.round(Number(value) <= 1 ? Number(value) * 100 : Number(value))
}

const saveClient = async () => {
  errorMessage.value = ''
  successMessage.value = ''
  if (!imageFile.value) return void (errorMessage.value = 'Ambil atau unggah foto KTP terlebih dahulu.')
  if (!/^\d{16}$/.test(fields.nik)) return void (errorMessage.value = 'NIK harus tepat 16 digit.')
  if (!fields.nama.trim()) return void (errorMessage.value = 'Nama client wajib diisi.')
  saving.value = true
  try {
    const form = new FormData()
    form.append('ktp_image', imageFile.value)
    form.append('no_identitas', fields.nik)
    form.append('nama_client', fields.nama.trim())
    form.append('jenis_client', clientType.value)
    form.append('alamat_client', buildAddress())
    form.append('contact_number', fields.contact_number)
    form.append('email', fields.email)
    const response = await business.client.saveKtpOcrClient(form) as ApiResponse
    if (!response.status) throw new Error(response.message || 'Data client gagal disimpan.')
    successMessage.value = response.message || 'Data client berhasil disimpan.'
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string }; message?: string })?.data?.message
      || (error as Error)?.message || 'Data client gagal disimpan.'
  } finally {
    saving.value = false
  }
}

onMounted(() => void startCamera())
onBeforeUnmount(() => {
  stopCamera()
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
})
</script>

<template>
  <div class="space-y-5">
    <section class="surface-card overflow-hidden">
      <div class="border-b border-slate-200 px-5 py-5 sm:px-7">
        <p class="display-kicker">Data Klien</p>
        <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 class="text-2xl font-black text-slate-900 sm:text-3xl">OCR KTP</h1>
            <p class="mt-1 text-sm text-slate-500">Ambil foto KTP, koreksi hasil pembacaan, lalu simpan ke master client.</p>
          </div>
          <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">
            <DocumentMagnifyingGlassIcon class="h-5 w-5 text-blue-600" />
            Review sebelum simpan
          </div>
        </div>
      </div>
    </section>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1.05fr)_minmax(430px,0.95fr)]">
      <section class="surface-card overflow-hidden">
        <div class="border-b border-slate-200 px-5 py-4">
          <p class="text-sm font-extrabold text-slate-900">Kamera KTP</p>
          <p class="mt-1 text-xs text-slate-500">Posisikan KTP mendatar dan pastikan seluruh sisi kartu terlihat.</p>
        </div>
        <div class="space-y-4 p-4 sm:p-5">
          <div class="relative aspect-[16/10] overflow-hidden rounded-lg bg-slate-950">
            <img v-if="imagePreview" :src="imagePreview" alt="Foto KTP" class="h-full w-full object-contain" />
            <video v-else ref="videoRef" playsinline muted class="h-full w-full object-cover" />
            <div class="pointer-events-none absolute inset-[8%] rounded-lg border-2 border-dashed border-white/70" />
            <div v-if="processing" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-950/75 text-white">
              <ArrowPathIcon class="h-9 w-9 animate-spin" />
              <p class="mt-3 text-sm font-extrabold">Membaca KTP...</p>
            </div>
          </div>

          <p v-if="cameraError" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-700">{{ cameraError }}</p>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <button type="button" class="simanis-gradient-button inline-flex h-11 items-center justify-center gap-2 rounded-lg px-3 text-xs font-extrabold text-white" @click="captureKtp">
              <CameraIcon class="h-5 w-5" /> Ambil Foto
            </button>
            <button type="button" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-xs font-extrabold text-slate-700" @click="fileInputRef?.click()">
              <PhotoIcon class="h-5 w-5" /> Unggah
            </button>
            <button type="button" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-xs font-extrabold text-slate-700" @click="startCamera">
              <ArrowPathIcon class="h-5 w-5" /> Kamera
            </button>
            <button type="button" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-xs font-extrabold text-slate-700 disabled:opacity-40" :disabled="!imageFile || processing" @click="runOcr">
              <DocumentMagnifyingGlassIcon class="h-5 w-5" /> OCR Ulang
            </button>
          </div>
          <input ref="fileInputRef" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="hidden" @change="handleUpload" />
          <button v-if="stream" type="button" class="inline-flex items-center gap-2 text-xs font-bold text-slate-500" @click="stopCamera"><StopIcon class="h-4 w-4" /> Matikan kamera</button>
          <details v-if="rawText" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
            <summary class="cursor-pointer text-xs font-extrabold text-slate-700">Lihat raw OCR</summary>
            <pre class="mt-3 max-h-52 overflow-auto whitespace-pre-wrap text-xs text-slate-600">{{ rawText }}</pre>
          </details>
        </div>
      </section>

      <section class="surface-card overflow-hidden xl:sticky xl:top-4">
        <div class="border-b border-slate-200 px-5 py-4">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-sm font-extrabold text-slate-900">Koreksi Hasil OCR</p>
              <p class="mt-1 text-xs text-slate-500">Semua field dapat diperbaiki sebelum disimpan.</p>
            </div>
            <CheckCircleIcon class="h-7 w-7" :class="hasResult ? 'text-emerald-500' : 'text-slate-300'" />
          </div>
        </div>
        <form class="space-y-4 p-4 sm:p-5" @submit.prevent="saveClient">
          <div class="grid grid-cols-2 gap-2 rounded-lg bg-slate-100 p-1">
            <button v-for="type in ['Perorangan', 'Badan Hukum'] as const" :key="type" type="button" class="h-9 rounded-md text-xs font-extrabold transition" :class="clientType === type ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'" @click="clientType = type">{{ type }}</button>
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <label v-for="definition in fieldDefinitions" :key="definition.key" class="block" :class="definition.span ? 'sm:col-span-2' : ''">
              <span class="mb-1.5 flex items-center justify-between text-[11px] font-extrabold uppercase text-slate-500">
                {{ definition.label }}
                <span v-if="confidencePercent(confidence[definition.key]) != null" class="text-[10px] text-blue-600">{{ confidencePercent(confidence[definition.key]) }}%</span>
              </span>
              <textarea v-if="definition.key === 'alamat'" v-model="fields[definition.key]" rows="2" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-blue-500" />
              <input v-else v-model="fields[definition.key]" :inputmode="definition.key === 'nik' ? 'numeric' : 'text'" class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 outline-none focus:border-blue-500" />
            </label>
            <label class="block"><span class="mb-1.5 block text-[11px] font-extrabold uppercase text-slate-500">Nomor Kontak</span><input v-model="fields.contact_number" class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm" /></label>
            <label class="block"><span class="mb-1.5 block text-[11px] font-extrabold uppercase text-slate-500">Email</span><input v-model="fields.email" type="email" class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm" /></label>
          </div>

          <p v-if="errorMessage" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">{{ errorMessage }}</p>
          <p v-if="successMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">{{ successMessage }}</p>
          <button type="submit" class="simanis-gradient-button h-11 w-full rounded-lg text-sm font-extrabold text-white disabled:cursor-not-allowed disabled:opacity-50" :disabled="saving || processing || !imageFile">
            {{ saving ? 'Menyimpan...' : `Simpan ke Client ${clientType}` }}
          </button>
        </form>
      </section>
    </div>
  </div>
</template>
