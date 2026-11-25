<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { ArrowPathIcon, CameraIcon, PhotoIcon, TrashIcon } from '@heroicons/vue/24/outline'

type OcrField = { value?: string; confidence?: number; warnings?: string[] }
type OcrPayload = { fields?: Record<string, OcrField>; raw_text?: string }
type ApiResponse = { status?: boolean; message?: string; data?: OcrPayload }

defineProps<{ disabled?: boolean }>()
const emit = defineEmits<{
  'image-change': [file: File | null]
  'processing-change': [value: boolean]
  recognized: [payload: OcrPayload]
}>()

const business = useLegacyBusiness()
const uploadInputRef = ref<HTMLInputElement | null>(null)
const cameraInputRef = ref<HTMLInputElement | null>(null)
const imageFile = ref<File | null>(null)
const imagePreview = ref('')
const processing = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const rawText = ref('')
let requestSequence = 0

const runOcr = async () => {
  if (!imageFile.value || processing.value) return
  const sequence = ++requestSequence
  processing.value = true
  emit('processing-change', true)
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const form = new FormData()
    form.append('ktp_image', imageFile.value)
    const response = await business.client.extractKtpOcr(form) as ApiResponse
    if (sequence !== requestSequence) return
    if (!response.status || !response.data) throw new Error(response.message || 'KTP tidak dapat dibaca.')
    rawText.value = String(response.data.raw_text || '')
    emit('recognized', response.data)
    successMessage.value = 'Hasil KTP masuk ke form. Periksa kembali sebelum menyimpan.'
  } catch (error) {
    if (sequence !== requestSequence) return
    errorMessage.value = (error as { data?: { message?: string }; message?: string })?.data?.message
      || (error as Error)?.message || 'Gagal membaca foto KTP.'
  } finally {
    if (sequence === requestSequence) {
      processing.value = false
      emit('processing-change', false)
    }
  }
}

const handleImage = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  input.value = ''
  if (!/^image\/(jpeg|png|webp|bmp|tiff)$/.test(file.type) || file.size > 10 * 1024 * 1024) {
    errorMessage.value = 'Gunakan foto KTP JPG, PNG, WebP, BMP, atau TIFF maksimal 10 MB.'
    return
  }
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
  imageFile.value = file
  imagePreview.value = URL.createObjectURL(file)
  rawText.value = ''
  errorMessage.value = ''
  successMessage.value = ''
  emit('image-change', file)
  await runOcr()
}

const clearImage = () => {
  ++requestSequence
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
  imageFile.value = null
  imagePreview.value = ''
  rawText.value = ''
  errorMessage.value = ''
  successMessage.value = ''
  processing.value = false
  emit('processing-change', false)
  emit('image-change', null)
}

onBeforeUnmount(() => {
  ++requestSequence
  if (imagePreview.value) URL.revokeObjectURL(imagePreview.value)
})
</script>

<template>
  <section class="md:col-span-2 border-b border-slate-200 pb-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h4 class="text-sm font-semibold text-slate-900">Foto KTP</h4>
        <p class="text-xs text-slate-500">Opsional. Hasil pembacaan akan mengisi form di bawah.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" :disabled="disabled || processing" @click="uploadInputRef?.click()">
          <PhotoIcon class="h-4 w-4" /> Unggah KTP
        </button>
        <button type="button" class="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" :disabled="disabled || processing" @click="cameraInputRef?.click()">
          <CameraIcon class="h-4 w-4" /> Ambil Foto
        </button>
      </div>
    </div>
    <input ref="uploadInputRef" type="file" accept="image/jpeg,image/png,image/webp,image/bmp,image/tiff" class="hidden" @change="handleImage" />
    <input ref="cameraInputRef" type="file" accept="image/*" capture="environment" class="hidden" @change="handleImage" />
    <div v-if="imagePreview" class="mt-3 flex flex-wrap items-start gap-3">
      <img :src="imagePreview" alt="Foto KTP dipilih" class="h-32 w-48 rounded-md border border-slate-200 object-contain" />
      <div class="min-w-0 flex-1 space-y-2">
        <p class="break-all text-xs font-medium text-slate-700">{{ imageFile?.name }}</p>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-300 px-2 text-xs font-semibold text-slate-700 disabled:opacity-50" :disabled="disabled || processing" @click="runOcr">
            <ArrowPathIcon class="h-4 w-4" :class="processing ? 'animate-spin' : ''" /> {{ processing ? 'Membaca...' : 'Baca Ulang' }}
          </button>
          <button type="button" class="inline-flex h-8 items-center gap-1 rounded-md border border-slate-300 px-2 text-xs font-semibold text-slate-700 disabled:opacity-50" :disabled="disabled || processing" @click="clearImage">
            <TrashIcon class="h-4 w-4" /> Hapus Foto
          </button>
        </div>
      </div>
    </div>
    <p v-if="errorMessage" role="alert" class="mt-3 text-xs font-semibold text-red-600">{{ errorMessage }}</p>
    <p v-if="successMessage" role="status" class="mt-3 text-xs font-semibold text-emerald-700">{{ successMessage }}</p>
    <details v-if="rawText" class="mt-3 text-xs text-slate-600">
      <summary class="cursor-pointer font-semibold">Teks hasil OCR</summary>
      <pre class="mt-2 max-h-32 overflow-auto whitespace-pre-wrap">{{ rawText }}</pre>
    </details>
  </section>
</template>
