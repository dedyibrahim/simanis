<script setup lang="ts">
import {
  ArrowPathIcon,
  CheckCircleIcon,
  MagnifyingGlassIcon,
  PencilSquareIcon,
  PlusIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type MinutaRow = {
  id: string | number
  no_akta?: string
  no_bundle?: string
  nama_peminjam?: string
  keperluan?: string
  tanggal_pinjam?: string
  tanggal_kembali?: string
  status?: string
  keterangan?: string
}

type MinutaForm = {
  id: string | number | null
  no_akta: string
  no_bundle: string
  keperluan: string
  tanggal_pinjam: string
  tanggal_kembali: string
  keterangan: string
}

definePageMeta({
  middleware: 'auth',
})

useHead({
  title: 'Peminjaman Minuta',
})

const business = useLegacyBusiness()

const loading = ref(false)
const search = ref('')
const monthFilter = ref('')
const statusFilter = ref('')
const rows = ref<MinutaRow[]>([])
const message = ref('')
const errorMessage = ref('')
const page = ref(1)
const pageSize = ref(10)

const dialogOpen = ref(false)
const saving = ref(false)
const editedIndex = ref(-1)

const dialogPerpanjangOpen = ref(false)
const savingPerpanjang = ref(false)
const selectedItem = ref<MinutaRow | null>(null)
const tanggalPerpanjang = ref('')

const form = reactive<MinutaForm>({
  id: null,
  no_akta: '',
  no_bundle: '',
  keperluan: '',
  tanggal_pinjam: '',
  tanggal_kembali: '',
  keterangan: '',
})

const toDateInput = (value: unknown) => {
  const text = String(value || '').trim()
  if (!text) return ''
  return text.slice(0, 10)
}

const formatDate = (value: unknown) => {
  const raw = toDateInput(value)
  if (!raw) return '-'
  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) return raw
  const day = String(date.getDate()).padStart(2, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const year = date.getFullYear()
  return `${day}-${month}-${year}`
}

const formTitle = computed(() =>
  editedIndex.value === -1
    ? 'Tambah Peminjaman Minuta'
    : 'Edit Peminjaman Minuta',
)

const searchKeyword = computed(() => search.value.trim().toLowerCase())

const statusOptions = [
  { label: 'Semua status', value: '' },
  { label: 'Dipinjam', value: 'Dipinjam' },
  { label: 'Terlambat', value: 'Terlambat' },
  { label: 'Dikembalikan', value: 'Dikembalikan' },
]

const filteredRows = computed(() => {
  if (!searchKeyword.value) return rows.value

  return rows.value.filter((row) => {
    const content = [
      row.no_akta,
      row.no_bundle,
      row.nama_peminjam,
      row.keperluan,
      row.tanggal_pinjam,
      row.tanggal_kembali,
      row.status,
      row.keterangan,
    ]
      .map(value => String(value || '').toLowerCase())
      .join(' ')

    return content.includes(searchKeyword.value)
  })
})

const totalItems = computed(() => filteredRows.value.length)

const totalPages = computed(() => Math.max(1, Math.ceil(totalItems.value / pageSize.value)))

const paginatedRows = computed(() => {
  const start = (page.value - 1) * pageSize.value
  return filteredRows.value.slice(start, start + pageSize.value)
})

const pageStart = computed(() => (totalItems.value ? ((page.value - 1) * pageSize.value) + 1 : 0))
const pageEnd = computed(() => Math.min(page.value * pageSize.value, totalItems.value))

const statusStyle = (status: unknown) => {
  const value = String(status || '').toLowerCase()
  if (value === 'terlambat') return 'border-red-200 bg-red-50 text-red-700'
  if (value === 'dipinjam') return 'border-amber-200 bg-amber-50 text-amber-700'
  if (value === 'dikembalikan') return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  return 'border-slate-200 bg-slate-50 text-slate-700'
}

const isReturned = (row: MinutaRow) => String(row.status || '').toLowerCase() === 'dikembalikan'

const toggleButtonLabel = (row: MinutaRow) => (isReturned(row) ? 'Pinjamkan Lagi' : 'Tandai Kembali')

const toggleButtonClass = (row: MinutaRow) =>
  isReturned(row)
    ? 'inline-flex h-8 items-center gap-1 rounded-lg border border-blue-300 bg-blue-50 px-2.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-100'
    : 'inline-flex h-8 items-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100'

const resetForm = () => {
  form.id = null
  form.no_akta = ''
  form.no_bundle = ''
  form.keperluan = ''
  form.tanggal_pinjam = ''
  form.tanggal_kembali = ''
  form.keterangan = ''
  editedIndex.value = -1
}

const clearNotice = () => {
  message.value = ''
  errorMessage.value = ''
}

const toList = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && Array.isArray((payload as ApiEnvelope<T[]>).data)) {
    return (payload as ApiEnvelope<T[]>).data || []
  }
  return []
}

const loadData = async () => {
  loading.value = true
  clearNotice()
  try {
    const query: Record<string, string> = {}
    if (monthFilter.value) {
      query.date = monthFilter.value
    }
    if (statusFilter.value) {
      query.status = statusFilter.value
    }
    if (search.value.trim()) {
      query.search = search.value.trim()
    }
    const response = await business.peminjamanMinuta.getPeminjamanMinuta(query) as ApiEnvelope<MinutaRow[]>
    const list = toList<MinutaRow>(response)
    rows.value = list.map(item => ({
      ...item,
      tanggal_pinjam: toDateInput(item.tanggal_pinjam),
      tanggal_kembali: toDateInput(item.tanggal_kembali),
    }))
    page.value = 1
    message.value = ''
  } catch (error) {
    rows.value = []
    page.value = 1
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memuat data peminjaman minuta.'
  } finally {
    loading.value = false
  }
}

const openCreateForm = () => {
  resetForm()
  dialogOpen.value = true
}

const openEditForm = (row: MinutaRow) => {
  editedIndex.value = rows.value.findIndex(item => String(item.id) === String(row.id))
  form.id = row.id
  form.no_akta = String(row.no_akta || '')
  form.no_bundle = String(row.no_bundle || '')
  form.keperluan = String(row.keperluan || '')
  form.tanggal_pinjam = toDateInput(row.tanggal_pinjam)
  form.tanggal_kembali = toDateInput(row.tanggal_kembali)
  form.keterangan = String(row.keterangan || '')
  dialogOpen.value = true
}

const closeForm = () => {
  dialogOpen.value = false
  resetForm()
}

const saveForm = async () => {
  if (saving.value) return
  if (!import.meta.client) return

  const actionLabel = editedIndex.value === -1 || !form.id ? 'menyimpan peminjaman baru' : 'mengupdate peminjaman ini'
  if (!window.confirm(`Yakin ingin ${actionLabel}?`)) return

  saving.value = true
  clearNotice()

  try {
    const payload: Record<string, unknown> = {
      no_akta: form.no_akta,
      no_bundle: form.no_bundle,
      keperluan: form.keperluan,
      tanggal_pinjam: form.tanggal_pinjam,
      tanggal_kembali: form.tanggal_kembali,
      keterangan: form.keterangan,
    }

    let response: ApiEnvelope
    if (editedIndex.value === -1 || !form.id) {
      response = await business.peminjamanMinuta.SimpanPeminjamanMinuta(payload) as ApiEnvelope
    } else {
      response = await business.peminjamanMinuta.UpdatePeminjamanMinuta(form.id, payload) as ApiEnvelope
    }

    message.value = response.message || 'Data peminjaman minuta berhasil disimpan.'
    closeForm()
    await loadData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menyimpan data peminjaman minuta.'
  } finally {
    saving.value = false
  }
}

const togglePengembalian = async (row: MinutaRow) => {
  if (!row.id || !import.meta.client) return

  const confirmText = isReturned(row)
    ? 'Status akan diubah kembali ke dipinjam. Lanjutkan?'
    : 'Tandai minuta ini sudah dikembalikan?'

  if (!window.confirm(confirmText)) return

  clearNotice()
  try {
    const response = await business.peminjamanMinuta.TogglePeminjamanMinuta(row.id) as ApiEnvelope
    message.value = response.message || 'Status peminjaman berhasil diperbarui.'
    await loadData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperbarui status peminjaman.'
  }
}

const openPerpanjang = (row: MinutaRow) => {
  selectedItem.value = row
  tanggalPerpanjang.value = toDateInput(row.tanggal_kembali)
  dialogPerpanjangOpen.value = true
}

const closePerpanjang = () => {
  dialogPerpanjangOpen.value = false
  selectedItem.value = null
  tanggalPerpanjang.value = ''
}

const savePerpanjang = async () => {
  if (!selectedItem.value?.id || !tanggalPerpanjang.value || savingPerpanjang.value) return
  if (!import.meta.client) return
  if (!window.confirm('Yakin ingin memperpanjang tanggal pengembalian minuta ini?')) return

  savingPerpanjang.value = true
  clearNotice()
  try {
    const response = await business.peminjamanMinuta.PerpanjangPeminjamanMinuta(selectedItem.value.id, {
      tanggal_kembali: tanggalPerpanjang.value,
    }) as ApiEnvelope
    message.value = response.message || 'Tanggal pengembalian berhasil diperpanjang.'
    closePerpanjang()
    await loadData()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperpanjang peminjaman.'
  } finally {
    savingPerpanjang.value = false
  }
}

const prevPage = () => {
  if (page.value > 1) page.value -= 1
}

const nextPage = () => {
  if (page.value < totalPages.value) page.value += 1
}

watch([totalItems, pageSize], () => {
  if (page.value > totalPages.value) {
    page.value = totalPages.value
  }
  if (page.value < 1) {
    page.value = 1
  }
})

watch(search, () => {
  page.value = 1
})

watch(monthFilter, () => {
  page.value = 1
  void loadData()
})

watch(statusFilter, () => {
  page.value = 1
  void loadData()
})

onMounted(() => {
  void loadData()
})
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <div class="flex flex-col gap-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <p class="display-kicker">Ringkasan</p>
            <h2 class="mt-2 text-2xl font-semibold text-slate-900">Data Peminjaman Minuta</h2>
          </div>

          <button
            type="button"
            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800"
            @click="openCreateForm"
          >
            <PlusIcon class="h-4 w-4" />
            Tambah Peminjaman
          </button>
        </div>

        <div class="rounded-2xl border border-slate-200/80 bg-slate-50/70 p-3">
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-[180px_190px_minmax(260px,1fr)_120px]">
          <label class="min-w-0">
            <span class="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Periode</span>
            <input
              v-model="monthFilter"
              type="month"
              class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 shadow-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
            />
          </label>

          <label class="min-w-0">
            <span class="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Status</span>
            <select
              v-model="statusFilter"
              class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 shadow-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
            >
              <option v-for="option in statusOptions" :key="option.value || 'all'" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="min-w-0 md:col-span-2 xl:col-span-1">
            <span class="mb-1 block text-xs font-semibold uppercase tracking-wider text-slate-500">Pencarian</span>
            <div class="relative">
              <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                v-model="search"
                type="text"
                placeholder="Cari no akta, bundle, peminjam, keperluan..."
                class="h-11 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm text-slate-700 shadow-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                @keyup.enter="loadData"
              />
            </div>
          </label>

          <button
            type="button"
            class="mt-5 h-11 rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="loading"
            @click="loadData"
          >
            {{ loading ? 'Memuat...' : 'Refresh' }}
          </button>

          </div>
        </div>
      </div>

      <p v-if="message" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ message }}
      </p>
      <p v-if="errorMessage" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errorMessage }}
      </p>
    </SurfaceCard>

    <SurfaceCard class="overflow-hidden p-0">
      <div class="border-b border-slate-200 bg-slate-50 px-6 py-4">
        <p class="text-sm font-semibold text-slate-800">Data Operasional</p>
      </div>

      <div class="p-6">
        <div v-if="loading" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Memuat data peminjaman minuta...
        </div>

        <div v-else-if="!rows.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Data peminjaman minuta belum tersedia.
        </div>

        <div v-else-if="!filteredRows.length" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
          Data dengan kata kunci tersebut tidak ditemukan.
        </div>

        <div v-else class="space-y-3">
          <div class="overflow-x-auto rounded-xl border border-slate-200">
          <table class="min-w-full divide-y divide-slate-200 bg-white">
            <thead class="bg-slate-100/80">
              <tr>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No Akta</th>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">No Bundle</th>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Peminjam</th>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Tgl Pinjam</th>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Tgl Kembali</th>
                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Status</th>
                <th class="w-[220px] px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-slate-600">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in paginatedRows" :key="String(row.id)">
                <td class="px-3 py-2 text-sm text-slate-700">{{ row.no_akta || '-' }}</td>
                <td class="px-3 py-2 text-sm text-slate-700">{{ row.no_bundle || '-' }}</td>
                <td class="px-3 py-2 text-sm text-slate-700">{{ row.nama_peminjam || '-' }}</td>
                <td class="px-3 py-2 text-sm text-slate-700">{{ formatDate(row.tanggal_pinjam) }}</td>
                <td class="px-3 py-2 text-sm text-slate-700">{{ formatDate(row.tanggal_kembali) }}</td>
                <td class="px-3 py-2 text-sm">
                  <span
                    class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold"
                    :class="statusStyle(row.status)"
                  >
                    {{ row.status || '-' }}
                  </span>
                </td>
                <td class="px-3 py-2">
                  <div class="flex flex-wrap gap-2">
                    <button
                      type="button"
                      class="inline-flex h-8 items-center gap-1 rounded-lg border border-slate-300 px-2.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                      @click="openEditForm(row)"
                    >
                      <PencilSquareIcon class="h-3.5 w-3.5" />
                      Edit
                    </button>

                    <button
                      v-if="String(row.status || '').toLowerCase() !== 'dikembalikan'"
                      type="button"
                      class="inline-flex h-8 items-center gap-1 rounded-lg border border-amber-300 bg-amber-50 px-2.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-100"
                      @click="openPerpanjang(row)"
                    >
                      <ArrowPathIcon class="h-3.5 w-3.5" />
                      Perpanjang
                    </button>

                    <button
                      type="button"
                      :class="toggleButtonClass(row)"
                      @click="togglePengembalian(row)"
                    >
                      <CheckCircleIcon class="h-3.5 w-3.5" />
                      {{ toggleButtonLabel(row) }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

          <div class="flex flex-col gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-slate-600">
              Menampilkan {{ pageStart }}-{{ pageEnd }} dari {{ totalItems }} data
            </p>

            <div class="flex flex-wrap items-center gap-2">
              <label class="inline-flex items-center gap-2 text-xs text-slate-600">
                <span>Baris</span>
                <select
                  v-model.number="pageSize"
                  class="h-8 rounded-lg border border-slate-300 bg-white px-2 text-xs text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                >
                  <option :value="10">10</option>
                  <option :value="25">25</option>
                  <option :value="50">50</option>
                </select>
              </label>

              <button
                type="button"
                class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="page <= 1"
                @click="prevPage"
              >
                Prev
              </button>

              <span class="text-xs font-semibold text-slate-600">
                Hal {{ page }} / {{ totalPages }}
              </span>

              <button
                type="button"
                class="h-8 rounded-lg border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="page >= totalPages"
                @click="nextPage"
              >
                Next
              </button>
            </div>
          </div>
        </div>
      </div>
    </SurfaceCard>

    <Teleport to="body">
      <div v-if="dialogOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeForm" />
        <div class="relative z-10 w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <h3 class="text-lg font-semibold text-slate-900">{{ formTitle }}</h3>
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 text-slate-600 transition hover:bg-slate-50"
              @click="closeForm"
            >
              <XMarkIcon class="h-4 w-4" />
            </button>
          </div>

          <div class="mt-4 grid gap-3">
            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">No Akta</span>
              <input v-model="form.no_akta" type="text" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>

            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">No Bundle</span>
              <input v-model="form.no_bundle" type="text" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>

            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Keperluan</span>
              <input v-model="form.keperluan" type="text" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Pinjam</span>
                <input v-model="form.tanggal_pinjam" type="date" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
              </label>
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Kembali</span>
                <input v-model="form.tanggal_kembali" type="date" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
              </label>
            </div>

            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Keterangan</span>
              <textarea v-model="form.keterangan" rows="2" class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
            </label>
          </div>

          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="closeForm">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50" :disabled="saving" @click="saveForm">
              {{ saving ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="dialogPerpanjangOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/65" @click="closePerpanjang" />
        <div class="relative z-10 w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <h3 class="text-lg font-semibold text-slate-900">Perpanjangan Peminjaman</h3>

          <label class="mt-4 flex flex-col gap-1.5">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Kembali Baru</span>
            <input v-model="tanggalPerpanjang" type="date" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" />
          </label>

          <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="closePerpanjang">
              Batal
            </button>
            <button type="button" class="h-10 rounded-xl bg-amber-500 px-4 text-sm font-semibold text-white transition hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50" :disabled="savingPerpanjang || !tanggalPerpanjang" @click="savePerpanjang">
              {{ savingPerpanjang ? 'Menyimpan...' : 'Simpan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
