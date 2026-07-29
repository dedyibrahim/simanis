<script setup lang="ts">
import {
  ArrowDownTrayIcon,
  ArrowPathIcon,
  DocumentTextIcon,
  EyeIcon,
  MagnifyingGlassIcon,
  PaperAirplaneIcon,
  TrashIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type ScannedDocumentRow = {
  id: number
  title?: string | null
  original_name?: string
  file_name?: string
  file_path?: string
  mime_type?: string
  extension?: string
  size_bytes?: number
  note?: string | null
  status?: string
  posted_module?: string | null
  posted_record_id?: string | number | null
  posted_document_id?: string | number | null
  posted_file_path?: string | null
  posted_at?: string | null
  created_at?: string
  assistant?: {
    id?: number
    id_user?: string | number
    nama_lengkap?: string
    level_user?: string
  } | null
}

type PostingTarget = {
  id: string | number
  title?: string
  subtitle?: string
}

definePageMeta({
  middleware: 'auth',
})

useHead({
  title: 'Dokumen Scan',
})

const business = useLegacyBusiness()
const { user } = useSession()

const rows = ref<ScannedDocumentRow[]>([])
const loading = ref(false)
const errorMessage = ref('')
const message = ref('')
const search = ref('')
const deletingId = ref<number | null>(null)
const downloadingId = ref<number | null>(null)
const viewingId = ref<number | null>(null)
const postingId = ref<number | null>(null)
const previewDialog = reactive({
  open: false,
  id: 0,
  title: '',
  url: '',
  mimeType: '',
})
const postingDialog = reactive({
  open: false,
  id: 0,
  title: '',
  url: '',
  mimeType: '',
  module: 'client',
  search: '',
  documentName: '',
  fileName: '',
  recordId: '',
  loadingTargets: false,
  targets: [] as PostingTarget[],
  error: '',
})

const isAdmin = computed(() => {
  const role = String(user.value?.level_user || '').trim().toLowerCase()
  return role === 'admin' || role === 'super admin' || role === 'superadmin'
})

const formatDateTime = (value: unknown) => {
  const raw = String(value || '').trim()
  if (!raw) return '-'
  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) return raw
  return date.toLocaleString('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const formatSize = (value: unknown) => {
  const size = Number(value || 0)
  if (!Number.isFinite(size) || size <= 0) return '-'
  if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(2)} MB`
  if (size >= 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${size} B`
}

const toList = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && 'data' in payload) {
    const data = (payload as ApiEnvelope<unknown>).data
    return Array.isArray(data) ? data as T[] : []
  }
  return []
}

const postingModules = [
  { value: 'client', label: 'Dokumen Client' },
  { value: 'buku_notaris', label: 'Buku Akta Notaris' },
  { value: 'buku_ppat', label: 'Buku PPAT' },
  { value: 'buku_legalisasi', label: 'Buku Legalisasi' },
  { value: 'buku_warmerking', label: 'Buku Waarmerking' },
  { value: 'surat_notaris', label: 'Surat Notaris' },
  { value: 'surat_ppat', label: 'Surat PPAT' },
  { value: 'tanda_terima', label: 'Tanda Terima' },
]

const moduleLabel = (value: unknown) =>
  postingModules.find(item => item.value === String(value || ''))?.label || String(value || '-')

const loadRows = async () => {
  loading.value = true
  errorMessage.value = ''
  message.value = ''

  try {
    const query: Record<string, string | number> = { limit: 150 }
    if (search.value.trim()) query.search = search.value.trim()
    const response = await business.scannedDocuments.list(query) as ApiEnvelope<ScannedDocumentRow[]>
    rows.value = toList<ScannedDocumentRow>(response)
    message.value = response.message || ''
  } catch (error) {
    rows.value = []
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat dokumen scan.'
  } finally {
    loading.value = false
  }
}

const downloadDocument = async (row: ScannedDocumentRow) => {
  if (!row.id || downloadingId.value || !import.meta.client) return

  downloadingId.value = row.id
  errorMessage.value = ''

  try {
    const blob = await business.scannedDocuments.downloadBlob(row.id) as Blob
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = row.original_name || row.file_name || `dokumen-scan-${row.id}.${row.extension || 'pdf'}`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengunduh dokumen scan.'
  } finally {
    downloadingId.value = null
  }
}

const closePreview = () => {
  previewDialog.open = false
  previewDialog.id = 0
  previewDialog.title = ''
  previewDialog.mimeType = ''
  if (previewDialog.url) {
    window.URL.revokeObjectURL(previewDialog.url)
  }
  previewDialog.url = ''
}

const closePosting = () => {
  postingDialog.open = false
  postingDialog.id = 0
  postingDialog.title = ''
  postingDialog.mimeType = ''
  postingDialog.module = 'client'
  postingDialog.search = ''
  postingDialog.documentName = ''
  postingDialog.fileName = ''
  postingDialog.recordId = ''
  postingDialog.targets = []
  postingDialog.error = ''
  if (postingDialog.url) {
    window.URL.revokeObjectURL(postingDialog.url)
  }
  postingDialog.url = ''
}

const viewDocument = async (row: ScannedDocumentRow) => {
  if (!row.id || viewingId.value || !import.meta.client) return

  viewingId.value = row.id
  errorMessage.value = ''

  try {
    const blob = await business.scannedDocuments.downloadBlob(row.id) as Blob
    closePreview()
    previewDialog.id = row.id
    previewDialog.url = window.URL.createObjectURL(blob)
    previewDialog.title = row.original_name || row.title || `Dokumen scan #${row.id}`
    previewDialog.mimeType = blob.type || row.mime_type || ''
    previewDialog.open = true
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal membuka preview dokumen scan.'
  } finally {
    viewingId.value = null
  }
}

const isPreviewImage = computed(() => previewDialog.mimeType.toLowerCase().startsWith('image/'))
const isPostingPreviewImage = computed(() => postingDialog.mimeType.toLowerCase().startsWith('image/'))

const loadPostingTargets = async () => {
  postingDialog.loadingTargets = true
  postingDialog.error = ''

  try {
    const response = await business.scannedDocuments.postingTargets({
      module: postingDialog.module,
      search: postingDialog.search,
    }) as ApiEnvelope<PostingTarget[]>
    postingDialog.targets = toList<PostingTarget>(response)
  } catch (error) {
    postingDialog.targets = []
    postingDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal mencari target posting.'
  } finally {
    postingDialog.loadingTargets = false
  }
}

const openPosting = async (row: ScannedDocumentRow) => {
  if (!row.id || postingId.value || !import.meta.client) return

  postingId.value = row.id
  errorMessage.value = ''
  closePreview()
  closePosting()

  try {
    const blob = await business.scannedDocuments.downloadBlob(row.id) as Blob
    postingDialog.id = row.id
    postingDialog.url = window.URL.createObjectURL(blob)
    postingDialog.title = row.original_name || row.title || `Dokumen scan #${row.id}`
    postingDialog.mimeType = blob.type || row.mime_type || ''
    postingDialog.documentName = row.title || row.original_name || `Dokumen scan #${row.id}`
    postingDialog.fileName = row.original_name || row.file_name || `dokumen-scan-${row.id}.${row.extension || 'pdf'}`
    postingDialog.open = true
    await loadPostingTargets()
  } catch (error) {
    closePosting()
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal membuka form posting dokumen scan.'
  } finally {
    postingId.value = null
  }
}

const submitPosting = async () => {
  if (!postingDialog.id || postingId.value) return
  if (!postingDialog.recordId) {
    postingDialog.error = 'Pilih target tujuan terlebih dahulu.'
    return
  }

  postingId.value = postingDialog.id
  postingDialog.error = ''

  try {
    const response = await business.scannedDocuments.postToModule(postingDialog.id, {
      module: postingDialog.module,
      record_id: postingDialog.recordId,
      document_name: postingDialog.documentName,
      file_name: postingDialog.fileName,
    }) as ApiEnvelope
    message.value = response.message || 'Dokumen scan berhasil diposting.'
    closePosting()
    await loadRows()
  } catch (error) {
    postingDialog.error = (error as { data?: { message?: string } })?.data?.message || 'Gagal posting dokumen scan.'
  } finally {
    postingId.value = null
  }
}

const deleteDocument = async (row: ScannedDocumentRow) => {
  if (!row.id || deletingId.value || !import.meta.client) return
  const name = row.original_name || row.title || `dokumen #${row.id}`
  if (!window.confirm(`Hapus dokumen scan "${name}"?`)) return

  deletingId.value = row.id
  errorMessage.value = ''
  try {
    const response = await business.scannedDocuments.delete(row.id) as ApiEnvelope
    message.value = response.message || 'Dokumen scan berhasil dihapus.'
    await loadRows()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus dokumen scan.'
  } finally {
    deletingId.value = null
  }
}

onMounted(() => {
  void loadRows()
})

onBeforeUnmount(() => {
  closePreview()
  closePosting()
})
</script>

<template>
  <div class="space-y-6">
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p class="text-xs font-bold uppercase tracking-[0.3em] text-blue-500">Scanner Kantor</p>
          <h1 class="mt-2 text-2xl font-bold text-slate-900">Dokumen Scan</h1>
          <p class="mt-2 text-sm text-slate-500">
            {{ isAdmin ? 'Admin melihat semua hasil scan.' : 'Halaman ini menampilkan dokumen hasil scan milik akun Anda.' }}
          </p>
        </div>

        <button
          type="button"
          class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
          :disabled="loading"
          @click="loadRows"
        >
          <ArrowPathIcon class="h-5 w-5" :class="{ 'animate-spin': loading }" />
          Refresh
        </button>
      </div>

      <div class="mt-5 flex flex-col gap-3 lg:flex-row lg:items-center">
        <div class="relative flex-1">
          <MagnifyingGlassIcon class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400" />
          <input
            v-model="search"
            type="search"
            class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm outline-none transition focus:border-blue-400 focus:bg-white"
            placeholder="Cari nama file, catatan, atau asisten..."
            @keyup.enter="loadRows"
          >
        </div>
        <button
          type="button"
          class="rounded-2xl bg-blue-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-blue-500"
          @click="loadRows"
        >
          Cari
        </button>
      </div>
    </section>

    <div v-if="errorMessage" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
      {{ errorMessage }}
    </div>
    <div v-else-if="message" class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
      {{ message }}
    </div>

    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100 text-sm">
          <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-widest text-slate-500">
            <tr>
              <th class="px-5 py-4">Dokumen</th>
              <th class="px-5 py-4">Asisten</th>
              <th class="px-5 py-4">Ukuran</th>
              <th class="px-5 py-4">Waktu Scan</th>
              <th class="px-5 py-4 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading">
              <td colspan="5" class="px-5 py-8 text-center text-slate-500">Memuat dokumen scan...</td>
            </tr>
            <tr v-else-if="!rows.length">
              <td colspan="5" class="px-5 py-8 text-center text-slate-500">Belum ada dokumen scan.</td>
            </tr>
            <template v-else>
              <tr v-for="row in rows" :key="row.id" class="align-top transition hover:bg-slate-50">
                <td class="px-5 py-4">
                  <div class="flex gap-3">
                    <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                      <DocumentTextIcon class="h-5 w-5" />
                    </div>
                    <div>
                      <p class="font-bold text-slate-900">{{ row.title || row.original_name || '-' }}</p>
                      <p class="mt-1 break-all text-xs text-slate-500">{{ row.original_name }}</p>
                      <p v-if="row.status === 'posted'" class="mt-2 inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                        Diposting ke {{ moduleLabel(row.posted_module) }} #{{ row.posted_record_id }}
                      </p>
                      <p v-if="row.note" class="mt-2 text-xs text-slate-500">{{ row.note }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-4 text-slate-700">
                  {{ row.assistant?.nama_lengkap || '-' }}
                  <p v-if="row.assistant?.id_user" class="mt-1 text-xs text-slate-400">{{ row.assistant.id_user }}</p>
                </td>
                <td class="px-5 py-4 text-slate-700">{{ formatSize(row.size_bytes) }}</td>
                <td class="px-5 py-4 text-slate-700">{{ formatDateTime(row.created_at) }}</td>
                <td class="px-5 py-4">
                  <div class="flex justify-end gap-2">
                    <button
                      type="button"
                      class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                      :disabled="viewingId === row.id"
                      @click="viewDocument(row)"
                    >
                      <ArrowPathIcon v-if="viewingId === row.id" class="h-4 w-4 animate-spin" />
                      <EyeIcon v-else class="h-4 w-4" />
                      {{ viewingId === row.id ? 'Membuka...' : 'View' }}
                    </button>
                    <button
                      v-if="row.status !== 'posted'"
                      type="button"
                      class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-60"
                      :disabled="postingId === row.id"
                      @click="openPosting(row)"
                    >
                      <ArrowPathIcon v-if="postingId === row.id" class="h-4 w-4 animate-spin" />
                      <PaperAirplaneIcon v-else class="h-4 w-4" />
                      Posting
                    </button>
                    <button
                      type="button"
                      class="inline-flex items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 transition hover:bg-blue-100"
                      :disabled="downloadingId === row.id"
                      @click="downloadDocument(row)"
                    >
                      <ArrowPathIcon v-if="downloadingId === row.id" class="h-4 w-4 animate-spin" />
                      <ArrowDownTrayIcon v-else class="h-4 w-4" />
                      {{ downloadingId === row.id ? 'Mengunduh...' : 'Download' }}
                    </button>
                    <button
                      type="button"
                      class="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700 transition hover:bg-red-100 disabled:opacity-60"
                      :disabled="deletingId === row.id || row.status === 'posted'"
                      @click="deleteDocument(row)"
                    >
                      <TrashIcon class="h-4 w-4" />
                      Hapus
                    </button>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="postingDialog.open" class="fixed inset-0 z-[90] flex flex-col bg-slate-950 text-white">
      <header class="flex flex-shrink-0 items-center justify-between gap-4 border-b border-white/10 bg-slate-900 px-5 py-4">
        <div class="min-w-0">
          <p class="text-xs font-bold uppercase tracking-[0.3em] text-emerald-200">Posting Dokumen Scan</p>
          <h2 class="mt-1 truncate text-lg font-bold">{{ postingDialog.title }}</h2>
          <p class="mt-1 text-sm text-slate-400">Preview dokumen, pilih modul tujuan, lalu pindahkan file ke folder modul.</p>
        </div>
        <button
          type="button"
          class="rounded-2xl border border-white/15 p-2 text-white transition hover:bg-white/10"
          @click="closePosting"
        >
          <XMarkIcon class="h-6 w-6" />
        </button>
      </header>

      <main class="grid min-h-0 flex-1 gap-4 bg-slate-950 p-4 xl:grid-cols-[minmax(0,1fr)_28rem]">
        <section class="min-h-0 overflow-hidden rounded-3xl border border-white/10 bg-slate-900">
          <img
            v-if="isPostingPreviewImage"
            :src="postingDialog.url"
            :alt="postingDialog.title"
            class="mx-auto h-full max-h-full max-w-full object-contain"
          >
          <iframe
            v-else
            :src="postingDialog.url"
            class="h-full w-full rounded-3xl border-0 bg-white"
            title="Preview posting dokumen scan"
          ></iframe>
        </section>

        <aside class="min-h-0 overflow-y-auto rounded-3xl border border-white/10 bg-white/10 p-5 shadow-2xl backdrop-blur-xl">
          <div v-if="postingDialog.error" class="mb-4 rounded-2xl border border-red-300/30 bg-red-500/10 px-4 py-3 text-sm font-semibold text-red-100">
            {{ postingDialog.error }}
          </div>

          <label class="block">
            <span class="text-sm font-bold text-slate-100">Modul tujuan</span>
            <select
              v-model="postingDialog.module"
              class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-400"
              @change="postingDialog.recordId = ''; loadPostingTargets()"
            >
              <option v-for="item in postingModules" :key="item.value" :value="item.value">
                {{ item.label }}
              </option>
            </select>
          </label>

          <label class="mt-4 block">
            <span class="text-sm font-bold text-slate-100">Cari target</span>
            <div class="mt-2 flex gap-2">
              <input
                v-model="postingDialog.search"
                type="search"
                class="min-w-0 flex-1 rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-400"
                placeholder="Cari nomor, nama client, atau keterangan..."
                @keyup.enter="loadPostingTargets"
              >
              <button
                type="button"
                class="rounded-2xl bg-blue-500 px-4 py-3 text-sm font-bold text-white transition hover:bg-blue-400 disabled:opacity-60"
                :disabled="postingDialog.loadingTargets"
                @click="loadPostingTargets"
              >
                Cari
              </button>
            </div>
          </label>

          <div class="mt-4 rounded-2xl border border-white/10 bg-slate-950/70 p-3">
            <p class="text-xs font-bold uppercase tracking-widest text-slate-400">Pilih target</p>
            <p v-if="postingDialog.loadingTargets" class="mt-3 text-sm text-slate-300">Memuat target...</p>
            <p v-else-if="!postingDialog.targets.length" class="mt-3 text-sm text-slate-400">Target belum ditemukan. Coba kata kunci lain.</p>
            <div v-else class="mt-3 max-h-64 space-y-2 overflow-y-auto pr-1">
              <label
                v-for="target in postingDialog.targets"
                :key="String(target.id)"
                class="flex cursor-pointer gap-3 rounded-xl border p-3 transition"
                :class="String(postingDialog.recordId) === String(target.id) ? 'border-emerald-300 bg-emerald-500/15' : 'border-white/10 bg-white/5 hover:bg-white/10'"
              >
                <input
                  v-model="postingDialog.recordId"
                  type="radio"
                  class="mt-1 h-4 w-4 border-white/20 bg-slate-900 text-emerald-500"
                  :value="String(target.id)"
                >
                <span class="min-w-0">
                  <span class="block text-sm font-bold text-white">{{ target.title || target.id }}</span>
                  <span class="mt-1 block truncate text-xs text-slate-400">{{ target.subtitle || target.id }}</span>
                </span>
              </label>
            </div>
          </div>

          <label class="mt-4 block">
            <span class="text-sm font-bold text-slate-100">Nama dokumen di modul</span>
            <input
              v-model="postingDialog.documentName"
              type="text"
              class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-400"
              placeholder="Contoh: SK Kemenkumham"
            >
          </label>

          <label class="mt-4 block">
            <span class="text-sm font-bold text-slate-100">Nama file final</span>
            <input
              v-model="postingDialog.fileName"
              type="text"
              class="mt-2 w-full rounded-2xl border border-white/10 bg-slate-950 px-4 py-3 text-sm text-white outline-none transition focus:border-emerald-400"
              placeholder="Contoh: SK Kemenkumham.pdf"
            >
          </label>

          <button
            type="button"
            class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-emerald-500 px-4 py-3 text-sm font-bold text-white transition hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="postingId === postingDialog.id || !postingDialog.recordId || !postingDialog.documentName"
            @click="submitPosting"
          >
            <ArrowPathIcon v-if="postingId === postingDialog.id" class="h-5 w-5 animate-spin" />
            <PaperAirplaneIcon v-else class="h-5 w-5" />
            {{ postingId === postingDialog.id ? 'Memposting...' : 'Posting ke Modul' }}
          </button>
        </aside>
      </main>
    </div>

    <div v-if="previewDialog.open" class="fixed inset-0 z-[80] flex flex-col bg-slate-950">
      <header class="flex flex-shrink-0 items-center justify-between gap-4 border-b border-white/10 bg-slate-900 px-5 py-4 text-white">
        <div class="min-w-0">
          <p class="text-xs font-bold uppercase tracking-[0.3em] text-blue-200">Preview Dokumen Scan</p>
          <h2 class="mt-1 truncate text-lg font-bold">{{ previewDialog.title }}</h2>
        </div>
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="inline-flex items-center gap-2 rounded-2xl border border-white/15 px-4 py-2 text-sm font-bold text-white transition hover:bg-white/10"
            @click="downloadDocument({ id: previewDialog.id, original_name: previewDialog.title } as ScannedDocumentRow)"
          >
            <ArrowDownTrayIcon class="h-5 w-5" />
            Download
          </button>
          <button
            type="button"
            class="rounded-2xl border border-white/15 p-2 text-white transition hover:bg-white/10"
            @click="closePreview"
          >
            <XMarkIcon class="h-6 w-6" />
          </button>
        </div>
      </header>

      <main class="min-h-0 flex-1 bg-slate-950 p-3">
        <div class="h-full overflow-auto rounded-2xl bg-slate-900">
          <img
            v-if="isPreviewImage"
            :src="previewDialog.url"
            :alt="previewDialog.title"
            class="mx-auto h-full max-h-full max-w-full object-contain"
          >
          <iframe
            v-else
            :src="previewDialog.url"
            class="h-full w-full rounded-2xl border-0 bg-white"
            title="Preview dokumen scan"
          ></iframe>
        </div>
      </main>
    </div>
  </div>
</template>
