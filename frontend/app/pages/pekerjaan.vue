<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  ArrowPathIcon, BanknotesIcon, CheckCircleIcon, ChevronRightIcon, ClipboardDocumentCheckIcon,
  ClockIcon, DocumentDuplicateIcon, LinkIcon, MagnifyingGlassIcon, PlusIcon,
  UserCircleIcon, XMarkIcon,
} from '@heroicons/vue/24/outline'

definePageMeta({ layout: 'default', middleware: 'auth' })

type Row = Record<string, any>
const business = useLegacyBusiness()
const { user } = useSession()
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const message = ref('')
const rows = ref<Row[]>([])
const options = ref<Row>({ clients: [], users: [], transitions: {} })
const search = ref('')
const activeWorkflowStatus = ref('draft')
const createOpen = ref(false)
const editOpen = ref(false)
const detailOpen = ref(false)
const detail = ref<Row | null>(null)
const activeTab = ref('overview')
const form = reactive({ title: '', category: 'custom', client_id: '', assignee_id: '', priority: 'normal', due_date: '', notes: '' })
const editForm = reactive({ title: '', category: 'custom', client_id: '', assignee_id: '', priority: 'normal', due_date: '', notes: '' })
const checklistForm = reactive({ title: '', item_type: 'checklist', is_required: true })
const costForm = reactive({ description: '', quantity: 1, unit_price: 0, taxable: false })
const linkForm = reactive({ module_type: 'buku_akta_notaris', record_id: '', label: '', relationship_type: 'output', date: new Date().toISOString().slice(0, 7) })
const reportoriumOptions = ref<Row[]>([])
const invoiceForm = reactive({ invoice_type: 'non_tax', discount: 0 })
const invoicePreview = reactive({ open: false, url: '', title: '' })
const invoiceFrame = ref<HTMLIFrameElement | null>(null)

const statusMeta: Record<string, { label: string; tone: string }> = {
  draft: { label: 'Draft', tone: 'bg-slate-100 text-slate-700' },
  verification: { label: 'Verifikasi', tone: 'bg-blue-100 text-blue-700' },
  in_progress: { label: 'Diproses', tone: 'bg-cyan-100 text-cyan-700' },
  waiting_client: { label: 'Menunggu Klien', tone: 'bg-amber-100 text-amber-700' },
  ready_to_sign: { label: 'Siap Tanda Tangan', tone: 'bg-violet-100 text-violet-700' },
  completed: { label: 'Selesai', tone: 'bg-emerald-100 text-emerald-700' },
  ready_to_bill: { label: 'Siap Ditagihkan', tone: 'bg-orange-100 text-orange-700' },
  invoiced: { label: 'Invoice Terbit', tone: 'bg-rose-100 text-rose-700' },
}
const columns = ['draft', 'verification', 'in_progress', 'waiting_client', 'ready_to_sign', 'completed', 'ready_to_bill', 'invoiced']
const tabs = [
  { id: 'overview', label: 'Ringkasan' }, { id: 'checklist', label: 'Checklist' },
  { id: 'costs', label: 'Output, Biaya & Invoice' }, { id: 'activity', label: 'Aktivitas' },
]

const unwrap = (response: any) => response?.data?.data ?? response?.data ?? response
const filteredRows = computed(() => {
  const keyword = search.value.trim().toLowerCase()
  return keyword ? rows.value.filter(row => [row.work_number, row.title, row.nama_client, row.assignee_name].some(value => String(value || '').toLowerCase().includes(keyword))) : rows.value
})
const rowsByStatus = (status: string) => filteredRows.value.filter(row => row.status === status)
const visibleWorkflowRows = computed(() => rowsByStatus(activeWorkflowStatus.value))
const nextStatuses = computed(() => detail.value ? (options.value.transitions?.[detail.value.status] || []) : [])
const requiredProgress = computed(() => {
  const items = detail.value?.checklists || []
  const required = items.filter((item: Row) => item.is_required)
  return { done: required.filter((item: Row) => item.is_completed).length, total: required.length }
})
const regularChecklistItems = computed(() => (detail.value?.checklists || []).filter((item: Row) => (item.item_type || 'checklist') === 'checklist'))
const permitItems = computed(() => (detail.value?.checklists || []).filter((item: Row) => item.item_type === 'permit'))
const costSubtotal = computed(() => (detail.value?.costs || []).reduce((sum: number, item: Row) => sum + Number(item.quantity) * Number(item.unit_price), 0))
const isInvoiceAdmin = computed(() => ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'].includes(String(user.value?.level_user || '').trim().toUpperCase()))
const printInvoiceUrl = computed(() => detail.value?.legacy_order_id ? business.reports.CetakInvoice(detail.value.legacy_order_id) : '')

const currency = (value: any) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0))
const dateLabel = (value: any) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(value)) : '-'
const dateTimeLabel = (value: any) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '-'
const statusLabel = (status: string) => statusMeta[status]?.label || status
const selectWorkflowStatus = (status: string) => {
  activeWorkflowStatus.value = status
  search.value = ''
}

const loadRows = async () => {
  loading.value = true; error.value = ''
  try { rows.value = unwrap(await business.workItems.list({ search: search.value })) || [] }
  catch (e: any) { error.value = e?.data?.message || 'Gagal memuat workflow pekerjaan.' }
  finally { loading.value = false }
}
const loadOptions = async () => { options.value = unwrap(await business.workItems.options()) || options.value }
const openCreate = () => { Object.assign(form, { title: '', category: 'custom', client_id: '', assignee_id: String(user.value?.id_user || ''), priority: 'normal', due_date: '', notes: '' }); createOpen.value = true }
const openEdit = () => {
  if (!detail.value) return
  Object.assign(editForm, {
    title: detail.value.title || '', category: detail.value.category || 'custom',
    client_id: detail.value.client_id || '', assignee_id: detail.value.assignee_id || '',
    priority: detail.value.priority || 'normal', due_date: String(detail.value.due_date || '').slice(0, 10),
    notes: detail.value.notes || '',
  })
  editOpen.value = true
}
const saveEdit = async () => {
  if (!detail.value || !editForm.title.trim()) return
  saving.value = true; error.value = ''
  try {
    detail.value = unwrap(await business.workItems.update(detail.value.id, editForm))
    message.value = 'Pekerjaan berhasil diperbarui.'; editOpen.value = false; await loadRows()
  } catch (e: any) { error.value = e?.data?.message || 'Gagal memperbarui pekerjaan.' }
  finally { saving.value = false }
}
const createWork = async () => {
  if (!form.title.trim()) return
  saving.value = true; error.value = ''
  try { const response = await business.workItems.create(form); message.value = response?.message || 'Pekerjaan dibuat.'; createOpen.value = false; await loadRows(); await openDetail(unwrap(response)) }
  catch (e: any) { error.value = e?.data?.message || 'Gagal membuat pekerjaan.' }
  finally { saving.value = false }
}
const openDetail = async (row: Row) => {
  saving.value = true
  try { detail.value = unwrap(await business.workItems.show(row.id)); activeTab.value = 'overview'; detailOpen.value = true }
  catch (e: any) { error.value = e?.data?.message || 'Gagal memuat detail pekerjaan.' }
  finally { saving.value = false }
}
const reloadDetail = async () => { if (detail.value) detail.value = unwrap(await business.workItems.show(detail.value.id)) }
const transitionTo = async (status: string) => {
  if (!detail.value) return
  saving.value = true; error.value = ''
  try { detail.value = unwrap(await business.workItems.transition(detail.value.id, { status })); await loadRows() }
  catch (e: any) { error.value = e?.data?.message || 'Status tidak dapat diperbarui.' }
  finally { saving.value = false }
}
const addChecklist = async () => { if (!detail.value || !checklistForm.title.trim()) return; await business.workItems.addChecklist(detail.value.id, checklistForm); checklistForm.title = ''; await reloadDetail() }
const toggleChecklist = async (item: Row) => { if (!detail.value) return; await business.workItems.toggleChecklist(detail.value.id, item.id); await reloadDetail() }
const addCost = async () => { if (!detail.value || !costForm.description.trim()) return; await business.workItems.addCost(detail.value.id, costForm); Object.assign(costForm, { description: '', quantity: 1, unit_price: 0, taxable: false }); await reloadDetail() }
const loadReportoriumOptions = async () => {
  linkForm.record_id = ''; reportoriumOptions.value = []
  if (linkForm.module_type === 'custom') return
  reportoriumOptions.value = unwrap(await business.workItems.reportoriumOptions({ module_type: linkForm.module_type, date: linkForm.date })) || []
}
const addLink = async () => {
  if (!detail.value) return
  const selected = reportoriumOptions.value.find(row => String(row.id) === linkForm.record_id)
  const label = linkForm.module_type === 'custom' ? linkForm.label : selected?.label
  if (!label) return
  await business.workItems.addLink(detail.value.id, { module_type: linkForm.module_type, record_id: linkForm.module_type === 'custom' ? null : linkForm.record_id, label, relationship_type: 'output' })
  linkForm.label = ''; linkForm.record_id = ''; await reloadDetail()
}
const removeLink = async (link: Row) => { if (!detail.value) return; await business.workItems.removeLink(detail.value.id, link.id); await reloadDetail() }
const issueInvoice = async () => {
  if (!detail.value || !confirm('Terbitkan invoice untuk pekerjaan ini?')) return
  saving.value = true; error.value = ''
  try {
    const response = await business.workItems.createInvoice(detail.value.id, invoiceForm)
    message.value = response?.message || 'Invoice diterbitkan.'
    activeWorkflowStatus.value = 'invoiced'
    search.value = ''
    await reloadDetail()
    await loadRows()
  }
  catch (e: any) { error.value = e?.data?.message || 'Gagal menerbitkan invoice.' }
  finally { saving.value = false }
}
const printInvoice = () => {
  if (!detail.value?.legacy_order_id) return
  invoicePreview.url = business.reports.CetakInvoice(detail.value.legacy_order_id)
  invoicePreview.title = `Invoice ${detail.value.work_number}`
  invoicePreview.open = true
}
const closeInvoicePreview = () => Object.assign(invoicePreview, { open: false, url: '', title: '' })
const printInvoiceFrame = () => {
  const frameWindow = invoiceFrame.value?.contentWindow
  if (frameWindow) frameWindow.print()
}

watch([() => linkForm.module_type, () => linkForm.date], loadReportoriumOptions)
onMounted(async () => { await Promise.all([loadOptions(), loadRows()]); await loadReportoriumOptions() })
</script>

<template>
  <div class="space-y-4">
    <section class="surface-card px-5 py-5 sm:px-7">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="display-kicker">Workflow Kantor</p><h1 class="mt-1 text-2xl font-black text-slate-900">Informasi Pekerjaan</h1><p class="mt-1 text-sm text-slate-500">Pantau pekerjaan dari draft, output reportorium, sampai invoice terbit.</p></div>
        <button class="inline-flex h-10 items-center gap-2 rounded-lg bg-slate-950 px-4 text-sm font-bold text-white hover:bg-slate-800" @click="openCreate"><PlusIcon class="h-5 w-5" />Pekerjaan Baru</button>
      </div>
      <div class="mt-5 flex flex-wrap gap-2">
        <label class="relative min-w-[260px] flex-1"><MagnifyingGlassIcon class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400"/><input v-model="search" class="h-10 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-sm" placeholder="Cari nomor, pekerjaan, klien, atau asisten..." @keyup.enter="loadRows"></label>
        <button class="h-10 rounded-lg border border-slate-200 px-3 text-sm font-bold" @click="loadRows"><ArrowPathIcon class="h-5 w-5" :class="{ 'animate-spin': loading }"/></button>
      </div>
    </section>

    <p v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{{ error }}</p>
    <p v-if="message" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">{{ message }}</p>

    <section class="surface-card overflow-hidden">
      <nav class="grid grid-cols-2 gap-y-3 border-b border-slate-200 px-4 py-4 sm:grid-cols-4 xl:grid-cols-8">
        <button
          v-for="(status, index) in columns"
          :key="status"
          class="group relative flex min-h-16 flex-col items-center justify-start gap-1 px-1 text-center transition"
          :class="activeWorkflowStatus === status ? 'text-blue-700' : 'text-slate-500 hover:text-slate-800'"
          @click="selectWorkflowStatus(status)"
        >
          <span v-if="index < columns.length - 1" class="absolute left-[calc(50%+18px)] top-4 hidden h-0.5 w-[calc(100%-36px)] xl:block" :class="columns.indexOf(activeWorkflowStatus) > index ? 'bg-blue-500' : 'bg-slate-200'"/>
          <span class="relative z-[1] grid h-8 w-8 place-items-center rounded-full border-2 text-xs font-black transition" :class="columns.indexOf(activeWorkflowStatus) >= index ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white text-slate-400'">{{ index + 1 }}</span>
          <span class="text-[11px] font-black leading-tight">{{ statusLabel(status) }}</span>
          <span class="text-[10px] font-bold text-slate-400">{{ rowsByStatus(status).length }} pekerjaan</span>
        </button>
      </nav>
      <div>
        <div v-if="visibleWorkflowRows.length" class="w-full">
          <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50/80 px-4 py-3"><div><p class="text-xs font-black text-slate-800">{{ statusLabel(activeWorkflowStatus) }}</p><p class="text-[11px] text-slate-500">{{ visibleWorkflowRows.length }} pekerjaan pada tahap ini</p></div></div>
          <table class="w-full table-fixed text-left">
            <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-black uppercase text-slate-500"><tr><th class="w-28 px-4 py-3 sm:w-36">Nomor</th><th class="px-3 py-3">Pekerjaan</th><th class="hidden w-1/5 px-3 py-3 md:table-cell">Klien</th><th class="hidden w-1/6 px-3 py-3 lg:table-cell">Asisten</th><th class="hidden w-28 px-3 py-3 sm:table-cell">Deadline</th><th class="w-14 px-3 py-3 text-center">Aksi</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="row in visibleWorkflowRows" :key="row.id" class="cursor-pointer transition hover:bg-blue-50/60" @click="openDetail(row)">
                <td class="px-4 py-3"><span class="block truncate text-[10px] font-black text-blue-600">{{ row.work_number }}</span><span class="mt-1 block text-[9px] font-bold uppercase text-slate-400">{{ row.priority }}</span></td>
                <td class="px-3 py-3"><p class="truncate text-sm font-black text-slate-900">{{ row.title }}</p><p class="mt-1 truncate text-[11px] text-slate-500 md:hidden">{{ row.nama_client || 'Tanpa klien' }}</p></td>
                <td class="hidden truncate px-3 py-3 text-xs text-slate-600 md:table-cell">{{ row.nama_client || '-' }}</td>
                <td class="hidden truncate px-3 py-3 text-xs text-slate-600 lg:table-cell">{{ row.assignee_name || '-' }}</td>
                <td class="hidden px-3 py-3 text-xs font-bold text-slate-600 sm:table-cell">{{ dateLabel(row.due_date) }}</td>
                <td class="px-3 py-3 text-center"><span class="inline-grid h-8 w-8 place-items-center rounded-md border border-slate-200 bg-white text-slate-500"><ChevronRightIcon class="h-4 w-4"/></span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="grid min-h-52 place-items-center text-center"><div><ClipboardDocumentCheckIcon class="mx-auto h-9 w-9 text-slate-300"/><p class="mt-3 text-sm font-bold text-slate-500">Belum ada pekerjaan pada tahap {{ statusLabel(activeWorkflowStatus) }}.</p></div></div>
      </div>
    </section>

    <Teleport to="body"><div v-if="createOpen" class="fixed inset-0 z-[210] grid place-items-center bg-slate-950/70 p-4"><form class="w-full max-w-2xl rounded-lg bg-white shadow-2xl" @submit.prevent="createWork"><header class="flex items-center justify-between border-b px-5 py-4"><div><p class="text-xs font-black uppercase text-blue-600">Pekerjaan Baru</p><h2 class="text-xl font-black">Buat workflow pekerjaan</h2></div><button type="button" @click="createOpen=false"><XMarkIcon class="h-6 w-6"/></button></header><div class="grid gap-4 p-5 sm:grid-cols-2"><label class="sm:col-span-2"><span class="text-xs font-bold">Judul pekerjaan</span><input v-model="form.title" required class="mt-1 h-10 w-full rounded-lg border px-3"/></label><label><span class="text-xs font-bold">Jenis</span><select v-model="form.category" class="mt-1 h-10 w-full rounded-lg border px-3"><option value="custom">Custom</option><option value="reportorium">Reportorium</option><option value="mixed">Campuran</option></select></label><label><span class="text-xs font-bold">Prioritas</span><select v-model="form.priority" class="mt-1 h-10 w-full rounded-lg border px-3"><option value="low">Rendah</option><option value="normal">Normal</option><option value="high">Tinggi</option><option value="urgent">Mendesak</option></select></label><label><span class="text-xs font-bold">Klien</span><select v-model="form.client_id" class="mt-1 h-10 w-full rounded-lg border px-3"><option value="">Tanpa klien</option><option v-for="item in options.clients" :key="item.id" :value="item.id">{{ item.label }}</option></select></label><label><span class="text-xs font-bold">Penanggung jawab</span><select v-model="form.assignee_id" class="mt-1 h-10 w-full rounded-lg border px-3"><option value="">Belum ditugaskan</option><option v-for="item in options.users" :key="item.id" :value="item.id">{{ item.label }}</option></select></label><label><span class="text-xs font-bold">Deadline</span><input v-model="form.due_date" type="date" class="mt-1 h-10 w-full rounded-lg border px-3"/></label><label class="sm:col-span-2"><span class="text-xs font-bold">Catatan</span><textarea v-model="form.notes" rows="3" class="mt-1 w-full rounded-lg border p-3"/></label></div><footer class="flex justify-end gap-2 border-t px-5 py-4"><button type="button" class="h-10 rounded-lg border px-4 font-bold" @click="createOpen=false">Batal</button><button class="h-10 rounded-lg bg-slate-950 px-5 font-bold text-white" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Buat Pekerjaan' }}</button></footer></form></div></Teleport>

    <Teleport to="body"><div v-if="detailOpen && detail" class="workflow-workspace fixed inset-0 z-[205] flex flex-col"><header class="workflow-header flex h-16 items-center justify-between border-b px-4 sm:px-6"><div class="flex min-w-0 items-center gap-3"><button type="button" class="workflow-control h-9 rounded-lg border px-3 text-xs font-black" @click="detailOpen=false">Kembali</button><div class="min-w-0"><p class="workflow-accent text-[10px] font-black uppercase">{{ detail.work_number }}</p><h2 class="truncate text-lg font-black">{{ detail.title }}</h2></div></div><div class="flex items-center gap-2"><button type="button" class="workflow-control h-9 rounded-lg border px-3 text-xs font-black" @click="openEdit">Edit</button><button v-if="printInvoiceUrl" type="button" class="relative z-10 h-9 rounded-lg bg-emerald-600 px-3 text-xs font-black text-white hover:bg-emerald-700" @click="printInvoice">Cetak Invoice</button><select v-if="nextStatuses.length" class="workflow-control h-9 rounded-lg border px-3 text-xs font-bold" :disabled="saving" @change="transitionTo(($event.target as HTMLSelectElement).value)"><option value="">Pindah status...</option><option v-for="status in nextStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select><span class="rounded-lg px-3 py-2 text-xs font-black" :class="statusMeta[detail.status]?.tone">{{ statusLabel(detail.status) }}</span><button type="button" class="workflow-control h-10 w-10 rounded-lg border" @click="detailOpen=false"><XMarkIcon class="mx-auto h-5 w-5"/></button></div></header>
      <div class="flex min-h-0 flex-1 flex-col lg:flex-row"><aside class="w-full shrink-0 border-b bg-slate-950 p-4 text-white lg:w-72 lg:border-b-0 lg:border-r"><div class="space-y-4"><div><p class="text-[10px] font-black uppercase text-slate-400">Klien</p><p class="mt-1 font-bold">{{ detail.nama_client || form.client_id || 'Belum dipilih' }}</p></div><div><p class="text-[10px] font-black uppercase text-slate-400">Penanggung Jawab</p><p class="mt-1 font-bold">{{ detail.assignee_name || 'Belum ditugaskan' }}</p></div><div class="grid grid-cols-2 gap-2"><div class="rounded-lg bg-white/10 p-3"><p class="text-[10px] text-slate-400">Output</p><p class="text-xl font-black">{{ detail.links?.length || 0 }}</p></div><div class="rounded-lg bg-white/10 p-3"><p class="text-[10px] text-slate-400">Checklist</p><p class="text-xl font-black">{{ requiredProgress.done }}/{{ requiredProgress.total }}</p></div></div><div><p class="text-[10px] font-black uppercase text-slate-400">Deadline</p><p class="mt-1 font-bold">{{ dateLabel(detail.due_date) }}</p></div></div></aside>
        <main class="min-h-0 flex-1 overflow-y-auto"><nav class="workflow-tabs sticky top-0 z-10 flex overflow-x-auto border-b px-4"><button v-for="tab in tabs" :key="tab.id" class="h-12 whitespace-nowrap border-b-2 px-4 text-xs font-black" :class="activeTab===tab.id ? 'workflow-tab-active':'border-transparent'" @click="activeTab=tab.id">{{ tab.label }}</button></nav><div class="mx-auto max-w-5xl p-4 sm:p-6">
          <section v-if="activeTab==='costs'" class="mb-4 space-y-3">
            <form class="surface-card grid gap-3 p-4 lg:grid-cols-[180px_140px_1fr_auto]" @submit.prevent="addLink">
              <div class="lg:col-span-4"><p class="text-xs font-black uppercase text-slate-400">Output pekerjaan</p><p class="mt-1 text-xs text-slate-500">Tautkan hasil Reportorium atau output custom. Hasil yang ditautkan otomatis masuk ke tabel komponen biaya.</p></div>
              <select v-model="linkForm.module_type" class="h-10 rounded-lg border px-3 text-sm"><option value="buku_akta_notaris">Buku Akta Notaris</option><option value="buku_legalisasi">Buku Legalisasi</option><option value="buku_waarmerking">Buku Waarmerking</option><option value="buku_ppat">Buku PPAT</option><option value="custom">Output Custom</option></select>
              <input v-if="linkForm.module_type!=='custom'" v-model="linkForm.date" type="month" class="h-10 rounded-lg border px-3 text-sm"/>
              <select v-if="linkForm.module_type!=='custom'" v-model="linkForm.record_id" class="h-10 rounded-lg border px-3 text-sm"><option value="">Pilih record...</option><option v-for="item in reportoriumOptions" :key="item.id" :value="String(item.id)">{{ item.label }}</option></select>
              <input v-else v-model="linkForm.label" class="h-10 rounded-lg border px-3 text-sm lg:col-span-2" placeholder="Nama output custom..."/>
              <button class="h-10 rounded-lg bg-slate-950 px-4 text-sm font-bold text-white">Tautkan</button>
            </form>
          </section>
          <section v-if="activeTab==='overview'" class="grid gap-4 md:grid-cols-2"><div class="surface-card p-5"><p class="text-xs font-black uppercase text-slate-400">Ringkasan</p><dl class="mt-4 space-y-3 text-sm"><div class="flex justify-between"><dt>Jenis</dt><dd class="font-bold uppercase">{{ detail.category }}</dd></div><div class="flex justify-between"><dt>Prioritas</dt><dd class="font-bold uppercase">{{ detail.priority }}</dd></div><div class="flex justify-between"><dt>Dibuat</dt><dd class="font-bold">{{ dateTimeLabel(detail.created_at) }}</dd></div><div class="flex justify-between"><dt>Billing</dt><dd class="font-bold uppercase">{{ detail.billing_status }}</dd></div></dl></div><div class="surface-card p-5"><p class="text-xs font-black uppercase text-slate-400">Catatan</p><p class="mt-4 whitespace-pre-line text-sm leading-6 text-slate-600">{{ detail.notes || 'Belum ada catatan.' }}</p></div></section>
          <section v-else-if="activeTab==='checklist'" class="space-y-4">
            <form class="surface-card grid gap-3 p-4 sm:grid-cols-[160px_1fr_auto_auto]" @submit.prevent="addChecklist">
              <select v-model="checklistForm.item_type" class="h-10 rounded-lg border px-3 text-sm font-bold"><option value="checklist">Checklist</option><option value="permit">Perizinan</option></select>
              <input v-model="checklistForm.title" class="h-10 min-w-0 rounded-lg border px-3 text-sm" :placeholder="checklistForm.item_type === 'permit' ? 'Tambah perizinan yang harus diselesaikan...' : 'Tambah checklist pekerjaan...'"/>
              <label class="flex items-center gap-2 text-xs font-bold"><input v-model="checklistForm.is_required" type="checkbox"/>Wajib</label>
              <button class="h-10 rounded-lg bg-slate-950 px-4 text-sm font-bold text-white">Tambah</button>
            </form>
            <div class="grid gap-4 lg:grid-cols-2">
              <div class="surface-card overflow-hidden"><header class="border-b px-4 py-3"><p class="text-xs font-black uppercase text-slate-400">Checklist pekerjaan</p><p class="mt-1 text-[11px] text-slate-500">Dokumen dan pemeriksaan internal.</p></header><div class="divide-y"><button v-for="item in regularChecklistItems" :key="item.id" class="flex w-full items-center gap-3 p-4 text-left hover:bg-slate-50" @click="toggleChecklist(item)"><span class="grid h-6 w-6 place-items-center rounded-md border" :class="item.is_completed ? 'border-emerald-500 bg-emerald-500 text-white':'border-slate-300'"><CheckCircleIcon v-if="item.is_completed" class="h-4 w-4"/></span><span class="flex-1 text-sm font-bold" :class="item.is_completed && 'line-through text-slate-400'">{{ item.title }}</span><span v-if="item.is_required" class="text-[10px] font-black uppercase text-rose-500">Wajib</span></button><p v-if="!regularChecklistItems.length" class="p-6 text-center text-sm text-slate-500">Belum ada checklist.</p></div></div>
              <div class="surface-card overflow-hidden"><header class="border-b px-4 py-3"><p class="text-xs font-black uppercase text-amber-500">Perizinan</p><p class="mt-1 text-[11px] text-slate-500">Izin eksternal yang harus selesai.</p></header><div class="divide-y"><button v-for="item in permitItems" :key="item.id" class="flex w-full items-center gap-3 p-4 text-left hover:bg-slate-50" @click="toggleChecklist(item)"><span class="grid h-6 w-6 place-items-center rounded-md border" :class="item.is_completed ? 'border-emerald-500 bg-emerald-500 text-white':'border-amber-400'"><CheckCircleIcon v-if="item.is_completed" class="h-4 w-4"/></span><span class="flex-1 text-sm font-bold" :class="item.is_completed && 'line-through text-slate-400'">{{ item.title }}</span><span v-if="item.is_required" class="text-[10px] font-black uppercase text-rose-500">Wajib</span></button><p v-if="!permitItems.length" class="p-6 text-center text-sm text-slate-500">Belum ada perizinan.</p></div></div>
            </div>
          </section>
          <section v-else-if="activeTab==='links'" class="space-y-4"><form class="surface-card grid gap-3 p-4 sm:grid-cols-4" @submit.prevent="addLink"><select v-model="linkForm.module_type" class="h-10 rounded-lg border px-3 text-sm"><option value="buku_akta_notaris">Buku Akta Notaris</option><option value="buku_legalisasi">Buku Legalisasi</option><option value="buku_waarmerking">Buku Waarmerking</option><option value="buku_ppat">Buku PPAT</option><option value="custom">Output Custom</option></select><input v-if="linkForm.module_type!=='custom'" v-model="linkForm.date" type="month" class="h-10 rounded-lg border px-3 text-sm"/><select v-if="linkForm.module_type!=='custom'" v-model="linkForm.record_id" class="h-10 rounded-lg border px-3 text-sm"><option value="">Pilih record...</option><option v-for="item in reportoriumOptions" :key="item.id" :value="String(item.id)">{{ item.label }}</option></select><input v-else v-model="linkForm.label" class="h-10 rounded-lg border px-3 text-sm sm:col-span-2" placeholder="Nama output custom..."/><button class="h-10 rounded-lg bg-slate-950 px-4 text-sm font-bold text-white">Tautkan</button></form><div class="grid gap-3 sm:grid-cols-2"><article v-for="link in detail.links" :key="link.id" class="surface-card flex items-start gap-3 p-4"><DocumentDuplicateIcon class="h-6 w-6 text-blue-600"/><div class="min-w-0 flex-1"><p class="text-[10px] font-black uppercase text-slate-400">{{ link.module_type.replaceAll('_',' ') }}</p><p class="mt-1 font-bold">{{ link.label }}</p><p class="text-xs text-slate-400">{{ link.record_id || 'Custom' }}</p></div><button class="text-slate-400 hover:text-rose-600" title="Lepas tautan" @click="removeLink(link)"><XMarkIcon class="h-5 w-5"/></button></article></div></section>
          <section v-else-if="activeTab==='costs'" class="space-y-4"><form class="surface-card grid gap-3 p-4 sm:grid-cols-[1fr_90px_160px_auto_auto]" @submit.prevent="addCost"><input v-model="costForm.description" class="h-10 rounded-lg border px-3 text-sm" placeholder="Komponen biaya..."/><input v-model.number="costForm.quantity" type="number" min="0.01" step="0.01" class="h-10 rounded-lg border px-3 text-sm"/><input v-model.number="costForm.unit_price" type="number" min="0" class="h-10 rounded-lg border px-3 text-sm"/><label class="flex items-center gap-2 text-xs font-bold"><input v-model="costForm.taxable" type="checkbox"/>Kena pajak</label><button class="h-10 rounded-lg bg-slate-950 px-4 text-sm font-bold text-white">Tambah</button></form><div class="surface-card overflow-hidden"><table class="w-full"><thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Komponen</th><th class="px-4 py-3">Qty</th><th class="px-4 py-3">Harga</th><th class="px-4 py-3">Jumlah</th></tr></thead><tbody><tr v-for="item in detail.costs" :key="item.id" class="border-t"><td class="px-4 py-3 text-sm font-bold">{{ item.description }}</td><td class="px-4 py-3 text-sm">{{ item.quantity }}</td><td class="px-4 py-3 text-sm">{{ currency(item.unit_price) }}</td><td class="px-4 py-3 text-sm font-bold">{{ currency(Number(item.quantity)*Number(item.unit_price)) }}</td></tr></tbody><tfoot><tr class="border-t bg-slate-50"><td colspan="3" class="px-4 py-3 text-right text-sm font-black">Subtotal</td><td class="px-4 py-3 font-black">{{ currency(costSubtotal) }}</td></tr></tfoot></table></div><div v-if="detail.status==='ready_to_bill' && isInvoiceAdmin" class="surface-card flex flex-wrap items-end gap-3 p-4"><label><span class="text-xs font-bold">Jenis invoice</span><select v-model="invoiceForm.invoice_type" class="mt-1 h-10 rounded-lg border px-3"><option value="non_tax">Non Tax</option><option value="tax">Tax (11%)</option></select></label><label><span class="text-xs font-bold">Diskon nominal</span><input v-model.number="invoiceForm.discount" type="number" min="0" class="mt-1 h-10 rounded-lg border px-3"/></label><button class="h-10 rounded-lg bg-emerald-600 px-5 text-sm font-black text-white" @click="issueInvoice"><BanknotesIcon class="mr-2 inline h-5 w-5"/>Terbitkan Invoice</button></div><article v-for="invoice in detail.invoices" :key="invoice.id" class="surface-card flex items-center justify-between p-4"><div><p class="text-xs font-black uppercase text-emerald-600">Invoice Terbit</p><p class="mt-1 text-lg font-black">{{ invoice.invoice_number }}</p></div><p class="text-lg font-black">{{ currency(invoice.grand_total) }}</p></article></section>
          <section v-else class="surface-card divide-y"><article v-for="activity in detail.activities" :key="activity.id" class="flex gap-3 p-4"><span class="mt-1 h-2.5 w-2.5 rounded-full bg-blue-500"/><div><p class="text-sm font-bold">{{ activity.description }}</p><p class="mt-1 text-xs text-slate-400">{{ dateTimeLabel(activity.created_at) }}</p></div></article></section>
        </div></main>
      </div>
    </div></Teleport>

    <Teleport to="body"><div v-if="editOpen" class="fixed inset-0 z-[220] grid place-items-center bg-slate-950/70 p-4"><form class="w-full max-w-2xl rounded-lg bg-white text-slate-900 shadow-2xl" @submit.prevent="saveEdit"><header class="flex items-center justify-between border-b px-5 py-4"><div><p class="text-xs font-black uppercase text-blue-600">Edit Pekerjaan</p><h2 class="text-xl font-black">Perbarui informasi workflow</h2></div><button type="button" class="h-9 w-9 rounded-lg border" @click="editOpen=false"><XMarkIcon class="mx-auto h-5 w-5"/></button></header><div class="grid gap-4 p-5 sm:grid-cols-2"><label class="sm:col-span-2"><span class="text-xs font-bold">Judul pekerjaan</span><input v-model="editForm.title" required class="mt-1 h-10 w-full rounded-lg border px-3"/></label><label><span class="text-xs font-bold">Jenis</span><select v-model="editForm.category" class="mt-1 h-10 w-full rounded-lg border px-3"><option value="custom">Custom</option><option value="reportorium">Reportorium</option><option value="mixed">Campuran</option></select></label><label><span class="text-xs font-bold">Prioritas</span><select v-model="editForm.priority" class="mt-1 h-10 w-full rounded-lg border px-3"><option value="low">Rendah</option><option value="normal">Normal</option><option value="high">Tinggi</option><option value="urgent">Mendesak</option></select></label><label><span class="text-xs font-bold">Klien</span><select v-model="editForm.client_id" class="mt-1 h-10 w-full rounded-lg border px-3"><option value="">Tanpa klien</option><option v-for="item in options.clients" :key="item.id" :value="item.id">{{ item.label }}</option></select></label><label><span class="text-xs font-bold">Penanggung jawab</span><select v-model="editForm.assignee_id" class="mt-1 h-10 w-full rounded-lg border px-3"><option value="">Belum ditugaskan</option><option v-for="item in options.users" :key="item.id" :value="item.id">{{ item.label }}</option></select></label><label><span class="text-xs font-bold">Deadline</span><input v-model="editForm.due_date" type="date" class="mt-1 h-10 w-full rounded-lg border px-3"/></label><label class="sm:col-span-2"><span class="text-xs font-bold">Catatan</span><textarea v-model="editForm.notes" rows="3" class="mt-1 w-full rounded-lg border p-3"/></label></div><footer class="flex justify-end gap-2 border-t px-5 py-4"><button type="button" class="h-10 rounded-lg border px-4 font-bold" @click="editOpen=false">Batal</button><button class="h-10 rounded-lg bg-slate-950 px-5 font-bold text-white" :disabled="saving">{{ saving ? 'Menyimpan...' : 'Simpan Perubahan' }}</button></footer></form></div></Teleport>

    <Teleport to="body"><div v-if="invoicePreview.open" class="fixed inset-0 z-[230] flex flex-col bg-slate-950"><header class="flex h-16 shrink-0 items-center justify-between border-b border-white/10 bg-slate-900 px-4 text-white sm:px-6"><div><p class="text-[10px] font-black uppercase text-emerald-400">Preview Invoice</p><h2 class="text-base font-black">{{ invoicePreview.title }}</h2></div><div class="flex items-center gap-2"><button class="h-10 rounded-lg bg-emerald-600 px-4 text-sm font-black hover:bg-emerald-700" @click="printInvoiceFrame">Cetak</button><a :href="invoicePreview.url" target="_blank" rel="noopener" class="grid h-10 place-items-center rounded-lg border border-white/20 px-4 text-sm font-black hover:bg-white/10">Buka PDF</a><button class="grid h-10 w-10 place-items-center rounded-lg border border-white/20 hover:bg-white/10" @click="closeInvoicePreview"><XMarkIcon class="h-5 w-5"/></button></div></header><iframe ref="invoiceFrame" :src="invoicePreview.url" class="min-h-0 flex-1 border-0 bg-white" title="Preview invoice"/></div></Teleport>
  </div>
</template>

<style>
.workflow-workspace {
  background: #f1f5f9;
  color: #0f172a;
}
.workflow-header,
.workflow-tabs {
  border-color: #cbd5e1;
  background: #ffffff;
  color: #0f172a;
}
.workflow-control {
  border-color: #cbd5e1;
  background: #ffffff;
  color: #0f172a;
}
.workflow-control:hover { background: #f8fafc; }
.workflow-accent,
.workflow-tab-active {
  border-color: var(--simanis-accent-600, #2563eb) !important;
  color: var(--simanis-accent-600, #2563eb) !important;
}
.workflow-tabs > button:not(.workflow-tab-active) { color: #475569; }

.theme-dark .workflow-workspace {
  background: #020617;
  color: #f8fafc;
}
.theme-dark .workflow-header,
.theme-dark .workflow-tabs {
  border-color: #334155;
  background: #0f172a;
  color: #f8fafc;
}
.theme-dark .workflow-control {
  border-color: #475569;
  background: #172033;
  color: #f8fafc;
}
.theme-dark .workflow-control:hover { background: #1e293b; }
.theme-dark .workflow-tabs > button:not(.workflow-tab-active) { color: #cbd5e1; }
.theme-dark .workflow-workspace .surface-card {
  border-color: #334155 !important;
  background: #0f172a !important;
  color: #f8fafc !important;
}
.theme-dark .workflow-workspace input,
.theme-dark .workflow-workspace select,
.theme-dark .workflow-workspace textarea,
.theme-dark .z-\[220\] input,
.theme-dark .z-\[220\] select,
.theme-dark .z-\[220\] textarea {
  border-color: #475569 !important;
  background: #172033 !important;
  color: #f8fafc !important;
}
.theme-dark .z-\[220\] form {
  border: 1px solid #334155;
  background: #0f172a !important;
  color: #f8fafc !important;
}
.theme-dark .workflow-workspace .text-slate-900,
.theme-dark .workflow-workspace .text-slate-800,
.theme-dark .workflow-workspace .text-slate-700,
.theme-dark .workflow-workspace .text-slate-600,
.theme-dark .z-\[220\] .text-slate-900,
.theme-dark .z-\[220\] .text-slate-800,
.theme-dark .z-\[220\] .text-slate-700,
.theme-dark .z-\[220\] .text-slate-600 { color: #f8fafc !important; }
.theme-dark .workflow-workspace .bg-slate-100,
.theme-dark .workflow-workspace .bg-slate-50,
.theme-dark .workflow-workspace .bg-white { background: #172033 !important; }
</style>
