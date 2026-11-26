<script setup lang="ts">
type Document = { id_berkas?: string; nama_dokumen?: string; nama_berkas?: string; nama_folder?: string }
const props = defineProps<{ client: { id: string; name: string } }>()
const emit = defineEmits<{ busy: [value: boolean] }>()
const business = useLegacyBusiness()
const documents = ref<Document[]>([])
const files = ref<File[]>([])
const input = ref<HTMLInputElement | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const message = ref('')
let requestId = 0
const load = async () => {
  const version = ++requestId
  const id = props.client.id
  documents.value = []
  error.value = ''
  if (!id) return
  loading.value = true
  try {
    const response = await business.client.getDataDokumenClient({ id_client: id }) as { data?: Document[] }
    if (version === requestId) documents.value = Array.isArray(response.data) ? response.data : []
  } catch (e) {
    if (version === requestId) error.value = (e as { data?: { message?: string } }).data?.message || 'Gagal memuat dokumen client.'
  } finally {
    if (version === requestId) loading.value = false
  }
}
watch(() => props.client.id, () => {
  files.value = []
  if (input.value) input.value.value = ''
  message.value = ''
  void load()
}, { immediate: true })
const onFiles = (event: Event) => {
  files.value = Array.from((event.target as HTMLInputElement).files || [])
  message.value = ''
  error.value = ''
}
const upload = async () => {
  if (saving.value || !files.value.length || !props.client.id) return
  saving.value = true
  emit('busy', true)
  error.value = ''
  message.value = ''
  try {
    const data = new FormData()
    data.append('id_client', props.client.id)
    files.value.forEach(file => data.append('dokumens[]', file, file.name))
    const response = await business.client.UploadDokumenClient(data) as { status?: boolean; message?: string }
    if (response.status === false) throw new Error(response.message || 'Upload gagal.')
    files.value = []
    if (input.value) input.value.value = ''
    message.value = 'Dokumen berhasil disimpan ke Data Client.'
    await load()
  } catch (e) {
    error.value = (e as { data?: { message?: string }; message?: string }).data?.message || (e as Error).message || 'Upload dokumen gagal.'
  } finally {
    saving.value = false
    emit('busy', false)
  }
}
const documentUrl = (document: Document) => document.nama_folder && document.nama_berkas
  ? business.assets.berkasClient(document.nama_folder, document.nama_berkas) : ''
</script>
<template>
  <section class="min-w-0 space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
    <div class="border-b border-slate-200 pb-3">
      <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Dokumen client</p>
      <h4 class="mt-1 break-words text-lg font-bold text-slate-900">{{ client.name }}</h4>
      <p class="mt-1 text-xs text-slate-500">File di bagian ini disimpan langsung ke Data Client ini.</p>
    </div>
      <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
        <input ref="input" type="file" multiple :disabled="saving" :aria-label="`Pilih dokumen untuk ${client.name}`" class="w-full rounded-xl border border-dashed border-slate-300 bg-white p-4 text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:font-semibold file:text-blue-700" @change="onFiles">
        <p class="text-xs text-slate-500">Bisa pilih beberapa file sekaligus. Pastikan pemilik dokumen sudah sesuai.</p>
        <ul v-if="files.length" class="space-y-2" aria-label="File yang akan diupload">
          <li v-for="(file, index) in files" :key="index" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2">
            <span class="min-w-0 break-all text-xs text-slate-700">{{ file.name }}</span>
            <button type="button" :disabled="saving" :aria-label="`Batalkan file ${file.name}`" class="shrink-0 text-xs font-semibold text-red-600 disabled:opacity-50" @click="files.splice(index, 1)">Batalkan</button>
          </li>
        </ul>
        <button type="button" :disabled="saving || !files.length" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50" @click="upload">{{ saving ? 'Mengupload...' : 'Upload ke Data Client' }}</button>
      </div>
      <p v-if="error" role="alert" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>
      <p v-if="message" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{{ message }}</p>
      <div class="flex items-center justify-between gap-3">
        <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-600">Dokumen client yang tersimpan</h4>
        <button type="button" :disabled="loading || saving" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 disabled:opacity-50" @click="load">Refresh</button>
      </div>
      <p v-if="loading" class="text-sm text-slate-500">Memuat dokumen...</p>
      <p v-else-if="!documents.length && !error" class="text-sm text-slate-500">Belum ada dokumen untuk client ini.</p>
      <ul v-else class="divide-y divide-slate-200 rounded-xl border border-slate-200">
        <li v-for="(document, index) in documents" :key="document.id_berkas || index" class="flex flex-wrap items-center justify-between gap-3 p-3">
          <div class="min-w-0">
            <p class="break-words text-sm font-semibold text-slate-900">{{ document.nama_dokumen || document.nama_berkas }}</p>
            <p class="break-all text-xs text-slate-500">{{ document.nama_berkas }}</p>
          </div>
          <a v-if="documentUrl(document)" :href="documentUrl(document)" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700">Lihat dokumen ↗</a>
        </li>
      </ul>
  </section>
</template>
