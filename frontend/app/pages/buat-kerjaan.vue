<script setup lang="ts">
const { confirmAction } = useAppDialog()
import { ArrowLeftIcon, ArrowRightIcon, CheckIcon, PlusIcon, XMarkIcon, MagnifyingGlassIcon, DocumentTextIcon, BuildingOffice2Icon, PencilSquareIcon, ClipboardDocumentCheckIcon, CalendarDaysIcon, UsersIcon, TrashIcon } from '@heroicons/vue/24/outline'
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Buat Kerjaan' })

type Envelope<T> = { data?: T; message?: string }
type Client = { id_client: string; nama_client: string; nama_pencarian?: string }
type Person = { id_client: string; nama_client: string; status_kedudukan: string; id_mewakili: string; mewakili: string }
type JobType = 'notaris' | 'ppat' | 'legalisasi' | 'waarmerking'
type Job = { id: number; id_order: string; type: JobType; title: string; id_user: string; assignee_name?: string; status: string; book_number?: string; book_id?: string | null; event_id?: number; scheduled_start?: string | null; scheduled_end?: string | null; scheduled_location?: string | null; details: Record<string, string>; penghadap: Person[] }
type Order = { id_order: string; nama_pesanan: string; keterangan_order?: string; id_user: string; status_order: string; jobs: Job[] }
type User = { id_user: string; nama_lengkap: string }
type DraftJob = { type: JobType; title: string; id_user: string; details: Record<string, string>; penghadap: Array<{ id_client: string; nama_client: string; status_kedudukan: string; id_mewakili: string | null; mewakili: string }> }
const business = useLegacyBusiness()
const { user } = useSession()
const month = ref(new Date().toLocaleDateString('sv-SE').slice(0, 7))
const search = ref('')
const orders = ref<Order[]>([])
const users = ref<User[]>([])
const aktaOptions = ref<Array<{ id_akta: string; nama_akta: string }>>([])
const aktaLoading = ref(false)
const aktaError = ref(false)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const message = ref('')
const editorOpen = ref(false)
const editorMode = ref<'create' | 'add' | 'edit'>('create')
const orderId = ref('')
const jobId = ref(0)
const orderForm = reactive({ nama_pesanan: '', id_user: '', keterangan_order: '' })
const emptyDetails = () => ({ jenis_akta: '', keterangan_surat: '', no_hak_milik: '', luas_tanah: '', luas_bangunan: '', harga_transaksi: '', nop: '', harga_njop: '', tgl_bphtb: '', harga_bphtb: '', tgl_pph: '', harga_pph: '' })
const jobForm = reactive({ type: 'notaris' as JobType, title: '', id_user: '', details: emptyDetails() })
const penghadap = ref<Person[]>([])
const queued = ref<DraftJob[]>([])
const clientQuery = ref('')
const clientOptions = ref<Client[]>([])
const clientId = ref('')
const mewakiliQuery = ref('')
const mewakiliOptions = ref<Client[]>([])
const mewakiliId = ref('')
const kedudukan = ref('')
const scheduleOpen = ref(false)
const scheduleJob = ref<Job | null>(null)
const scheduleForm = reactive({ start_datetime: '', end_datetime: '', location: '' })
const labels: Record<JobType, string> = { notaris: 'Notaris', ppat: 'PPAT', legalisasi: 'Legalisasi', waarmerking: 'Waarmerking' }
const bookPaths: Record<JobType, string> = { notaris: '/buku_akta', ppat: '/buku_ppat', legalisasi: '/buku_legalisasi', waarmerking: '/buku_waarmerking' }
const statuses: Record<string, string> = { draft: 'Belum dijadwalkan', scheduled: 'Jadwal TTD', signed: 'Sudah TTD', numbered: 'Sudah bernomor' }
const isAdmin = computed(() => ['ADMIN', 'SUPER ADMIN', 'SUPERADMIN'].includes(String(user.value?.level_user || '').trim().toUpperCase()))
const canCreate = computed(() => String(user.value?.level_user || '').trim().toUpperCase() !== 'ARSIP')
const canManageOrder = (order: Order) => isAdmin.value || order.id_user === String(user.value?.id_user || '')
const clientTarget = 'client'
const representativeTarget = 'mewakili'
const canEdit = (job: Job) => job.status === 'draft'
const canDeleteJob = (order: Order, job: Job) => canCreate.value && canManageOrder(order) && order.status_order === 'Proses' && ['draft', 'scheduled', 'signed'].includes(job.status) && !job.book_id && !job.book_number
const canSchedule = (job: Job) => job.status === 'draft' || job.status === 'scheduled'
const canSign = (job: Job) => job.status === 'scheduled'
const canTakeNumber = (job: Job) => job.status === 'signed'
const canAddOrder = (order: Order) => canCreate.value && canManageOrder(order) && order.status_order === 'Proses'
const canComplete = (order: Order) => canCreate.value && canManageOrder(order) && order.status_order === 'Proses' && order.jobs.length > 0 && order.jobs.every(job => job.status === 'numbered')
const canSignJob = (job: Job) => canSign(job) && (isAdmin.value || job.id_user === String(user.value?.id_user || ''))
const canNumberJob = (job: Job) => canTakeNumber(job) && (isAdmin.value || job.id_user === String(user.value?.id_user || ''))
const isCreate = computed(() => editorMode.value === 'create')
const isEdit = computed(() => editorMode.value === 'edit')
const isPpat = computed(() => jobForm.type === 'ppat')
const needsAkta = computed(() => jobForm.type === 'notaris' || jobForm.type === 'ppat')
const isSurat = computed(() => jobForm.type === 'legalisasi' || jobForm.type === 'waarmerking')
const input = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:ring-4 focus:ring-blue-50 disabled:bg-slate-50 disabled:text-slate-400'
const primaryButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50'
const secondaryButton = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50'
const editorStep = ref(1)
const typeSelected = ref(false)
const editorBody = ref<HTMLElement | null>(null)
const statusFilter = ref('all')
const steps = [
  { title: 'Jenis pekerjaan', description: 'Pilih layanan yang dibutuhkan' },
  { title: 'Detail pekerjaan', description: 'Lengkapi informasi utamanya' },
  { title: 'Penghadap', description: 'Tentukan pihak yang terlibat' },
  { title: 'Tinjau & simpan', description: 'Periksa sebelum melanjutkan' },
]
const typeOptions = [
  { value: 'notaris' as JobType, icon: DocumentTextIcon, description: 'Pembuatan akta dan layanan kenotariatan.', hint: 'Pilih jenis akta' },
  { value: 'ppat' as JobType, icon: BuildingOffice2Icon, description: 'Akta pertanahan, objek, dan transaksi.', hint: 'Data objek & transaksi' },
  { value: 'legalisasi' as JobType, icon: PencilSquareIcon, description: 'Pengesahan tanda tangan pada surat.', hint: 'Judul & keterangan surat' },
  { value: 'waarmerking' as JobType, icon: ClipboardDocumentCheckIcon, description: 'Pendaftaran surat di bawah tangan.', hint: 'Judul & keterangan surat' },
]
const progressStages = ['Draf', 'Jadwal TTD', 'Sudah TTD', 'Bernomor']
const progressIndex = (status: string) => ['draft', 'scheduled', 'signed', 'numbered'].indexOf(status)
const allJobs = computed(() => orders.value.flatMap(order => order.jobs))
const summary = computed(() => ({ active: orders.value.filter(order => order.status_order === 'Proses').length, scheduled: allJobs.value.filter(job => job.status === 'scheduled').length, signed: allJobs.value.filter(job => job.status === 'signed').length }))
const userName = (id: string) => users.value.find(item => String(item.id_user) === String(id))?.nama_lengkap || id || 'Belum dipilih'
const actName = (id: string) => actNames.value[id] || aktaOptions.value.find(item => String(item.id_akta) === String(id))?.nama_akta || id
const chooseType = (type: JobType) => { if (!isEdit.value) { jobForm.type = type; typeSelected.value = true } }
const moveToStep = (step: number) => {
  editorStep.value = step
  error.value = ''
  nextTick(() => {
    editorBody.value?.scrollTo({ top: 0 })
    editorBody.value?.querySelector<HTMLElement>('[data-step-heading]')?.focus()
  })
}
const closeEditor = async () => {
  if (saving.value) return
  const dirty = jobForm.title.trim() || penghadap.value.length || queued.value.length || (isCreate.value && orderForm.nama_pesanan.trim())
  if (dirty && !await confirmAction('Tutup form? Perubahan yang belum disimpan akan hilang.')) return
  editorOpen.value = false
}
const validateStep = (step: number) => {
  if (step === 1 && !typeSelected.value) { error.value = 'Pilih salah satu jenis pekerjaan untuk melanjutkan.'; return false }
  if (step === 2) {
    if (isCreate.value && (!orderForm.nama_pesanan.trim() || !orderForm.id_user)) { error.value = 'Isi nama pesanan dan pilih penanggung jawab pesanan.'; return false }
    if (!jobForm.title.trim() || !jobForm.id_user) { error.value = 'Isi judul pekerjaan dan pilih penanggung jawab.'; return false }
    if (needsAkta.value && !jobForm.details.jenis_akta) { error.value = 'Pilih jenis akta untuk pekerjaan ini.'; return false }
    if (isPpat.value) {
      const invalidAmount = ppatFields.find(field => field.type === 'number' && String(jobForm.details[field.key] ?? '').trim() !== '' && (!Number.isFinite(Number(jobForm.details[field.key])) || Number(jobForm.details[field.key]) < 0))
      if (invalidAmount) { error.value = invalidAmount.label + ' harus berupa angka nol atau lebih.'; return false }
    }
  }
  if (step === 3 && !penghadap.value.length) { error.value = 'Tambahkan minimal satu penghadap untuk melanjutkan.'; return false }
  return true
}
const nextStep = () => {
  error.value = ''
  if (editorStep.value === 3 && (clientQuery.value.trim() || clientId.value || kedudukan.value.trim() || mewakiliQuery.value.trim() || mewakiliId.value)) {
    const before = penghadap.value.length
    addPerson()
    if (penghadap.value.length === before) return
  }
  if (validateStep(editorStep.value)) moveToStep(Math.min(4, editorStep.value + 1))
}
const revisitStep = (step: number) => { if (step < editorStep.value) moveToStep(step) }
const reviewJobs = computed(() => isCreate.value ? [...queued.value, payload()] : [payload()])
const ppatFields: Array<{ key: keyof ReturnType<typeof emptyDetails>; label: string; type?: string; placeholder?: string }> = [
  { key: 'no_hak_milik', label: 'Nomor hak milik', placeholder: 'Contoh: SHM 1234' },
  { key: 'harga_transaksi', label: 'Harga transaksi (Rp)', type: 'number' },
  { key: 'luas_tanah', label: 'Luas tanah (m²)', type: 'number' },
  { key: 'luas_bangunan', label: 'Luas bangunan (m²)', type: 'number' },
  { key: 'nop', label: 'NOP' }, { key: 'harga_njop', label: 'NJOP (Rp)', type: 'number' },
  { key: 'tgl_bphtb', label: 'Tanggal BPHTB', type: 'date' }, { key: 'harga_bphtb', label: 'BPHTB (Rp)', type: 'number' },
  { key: 'tgl_pph', label: 'Tanggal PPh', type: 'date' }, { key: 'harga_pph', label: 'PPh (Rp)', type: 'number' },
]
const filtered = computed(() => orders.value.filter(order => {
  const keyword = search.value.trim().toLowerCase()
  const matchesStatus = statusFilter.value === 'all' || (statusFilter.value === 'complete' ? order.status_order === 'Selesai' : order.status_order === 'Proses')
  return matchesStatus && (!keyword || [order.id_order, order.nama_pesanan, ...order.jobs.flatMap(job => [job.title, ...job.penghadap.map(person => person.nama_client)])].some(value => value.toLowerCase().includes(keyword)))
}))
const fail = (e: unknown, fallback: string) => {
  const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } })?.data
  error.value = Object.values(data?.errors || {}).flat().find(Boolean) || data?.message || fallback
}
let listSequence = 0
const load = async () => {
  const sequence = ++listSequence
  loading.value = true
  error.value = ''
  try {
    const response = await business.workOrders.list(month.value) as Envelope<Order[]>
    if (sequence === listSequence) orders.value = response.data || []
  } catch (e) { if (sequence === listSequence) { orders.value = []; fail(e, 'Gagal memuat pekerjaan.') } }
  finally { if (sequence === listSequence) loading.value = false }
}
const actNames = ref<Record<string, string>>({})
let aktaSequence = 0
const loadAkta = async () => {
  const sequence = ++aktaSequence
  const type = jobForm.type
  aktaOptions.value = []
  aktaLoading.value = false
  aktaError.value = false
  if (type !== 'notaris' && type !== 'ppat') return
  aktaLoading.value = true
  try {
    const rows = ((await business.workOrders.actTypes(type)) as Envelope<Array<{ id_akta: string; nama_akta: string }>>).data || []
    for (const row of rows) actNames.value[String(row.id_akta)] = row.nama_akta
    if (sequence === aktaSequence) aktaOptions.value = rows
  } catch { if (sequence === aktaSequence) aktaError.value = true }
  finally { if (sequence === aktaSequence) aktaLoading.value = false }
}
const clientSearching = ref(false)
const representativeSearching = ref(false)
const lookupSequences = { client: 0, mewakili: 0 }
const lookupTimers: Partial<Record<'client' | 'mewakili', ReturnType<typeof setTimeout>>> = {}
const clientOptionLabel = (item: Client) => item.nama_pencarian || item.nama_client
const resolveClientOption = (value: string, options: Client[]) => {
  const query = value.trim().toLocaleLowerCase()
  return options.find(item => clientOptionLabel(item).trim().toLocaleLowerCase() === query)
}
const lookup = (value: string, target: 'client' | 'mewakili') => {
  const sequence = ++lookupSequences[target]
  clearTimeout(lookupTimers[target])
  const options = target === 'client' ? clientOptions : mewakiliOptions
  const selected = target === 'client' ? clientId : mewakiliId
  const pending = target === 'client' ? clientSearching : representativeSearching
  const match = resolveClientOption(value, options.value)
  selected.value = match ? String(match.id_client) : ''
  if (match) { pending.value = false; return }
  options.value = []
  pending.value = value.trim().length >= 2
  if (!pending.value) return
  lookupTimers[target] = setTimeout(async () => {
    try {
      const rows = ((await business.order.getDaftarClient({ nama_penghadap: value.trim() })) as Envelope<Client[]>).data || []
      if (sequence === lookupSequences[target]) {
        options.value = rows
        const exactMatch = resolveClientOption(value, rows)
        selected.value = exactMatch ? String(exactMatch.id_client) : ''
      }
    } catch { if (sequence === lookupSequences[target]) error.value = 'Pencarian client gagal. Coba ketik ulang nama client.' }
    finally { if (sequence === lookupSequences[target]) pending.value = false }
  }, 300)
}
const cancelLookups = () => {
  for (const target of ['client', 'mewakili'] as const) {
    ++lookupSequences[target]
    clearTimeout(lookupTimers[target])
  }
  clientSearching.value = false
  representativeSearching.value = false
}
const resetJob = () => {
  cancelLookups()
  typeSelected.value = false
  clientOptions.value = []
  mewakiliOptions.value = []
  jobForm.type = 'notaris'
  jobForm.title = ''
  jobForm.id_user = orderForm.id_user || String(user.value?.id_user || '')
  Object.assign(jobForm.details, emptyDetails())
  penghadap.value = []
  clientQuery.value = ''
  mewakiliQuery.value = ''
  clientId.value = ''
  mewakiliId.value = ''
  kedudukan.value = ''
  void loadAkta()
}
const create = () => {
  editorMode.value = 'create'
  orderForm.nama_pesanan = ''
  orderForm.id_user = String(user.value?.id_user || '')
  orderForm.keterangan_order = ''
  queued.value = []
  resetJob()
  error.value = ''
  moveToStep(1)
  editorOpen.value = true
}
const add = (order: Order) => {
  moveToStep(1)
  editorMode.value = 'add'
  orderId.value = order.id_order
  queued.value = []
  orderForm.nama_pesanan = order.nama_pesanan
  orderForm.id_user = order.id_user
  resetJob()
  error.value = ''
  editorOpen.value = true
}
const edit = (job: Job) => {
  cancelLookups()
  moveToStep(1)
  typeSelected.value = true
  queued.value = []
  clientOptions.value = []
  mewakiliOptions.value = []
  clientQuery.value = ''; clientId.value = ''; mewakiliQuery.value = ''; mewakiliId.value = ''; kedudukan.value = ''
  editorMode.value = 'edit'
  orderId.value = job.id_order
  jobId.value = job.id
  jobForm.type = job.type
  jobForm.title = job.title
  jobForm.id_user = job.id_user
  Object.assign(jobForm.details, emptyDetails(), job.details || {})
  penghadap.value = job.penghadap.map(row => ({ ...row, id_mewakili: String(row.id_mewakili || '') }))
  void loadAkta()
  error.value = ''
  editorOpen.value = true
}
const addPerson = () => {
  const person = clientQuery.value.trim()
    ? resolveClientOption(clientQuery.value, clientOptions.value)
    : clientOptions.value.find(row => String(row.id_client) === clientId.value)
  const representative = mewakiliQuery.value.trim()
    ? resolveClientOption(mewakiliQuery.value, mewakiliOptions.value)
    : mewakiliOptions.value.find(row => String(row.id_client) === mewakiliId.value)
  if (mewakiliQuery.value.trim() && !representative) { error.value = 'Pilih pihak yang diwakili dari saran autocomplete atau kosongkan kolomnya.'; return }
  if (!person || !kedudukan.value.trim()) { error.value = 'Pilih client dari saran autocomplete dan isi status kedudukan.'; return }
  if (penghadap.value.some(row => row.id_client === String(person.id_client) && row.status_kedudukan === kedudukan.value.trim() && row.id_mewakili === String(representative?.id_client || ''))) { error.value = 'Penghadap dengan kedudukan ini sudah ditambahkan.'; return }
  if (representative?.id_client === person.id_client) { error.value = 'Penghadap tidak dapat mewakili dirinya sendiri.'; return }
  penghadap.value.push({ id_client: String(person.id_client), nama_client: person.nama_client, status_kedudukan: kedudukan.value.trim(), id_mewakili: representative ? String(representative.id_client) : '', mewakili: representative?.nama_client || '' })
  cancelLookups()
  clientOptions.value = []; mewakiliOptions.value = []
  clientQuery.value = ''; clientId.value = ''; mewakiliQuery.value = ''; mewakiliId.value = ''; kedudukan.value = ''; error.value = ''
}
const payload = (): DraftJob => ({ type: jobForm.type, title: jobForm.title.trim(), id_user: jobForm.id_user, details: { ...jobForm.details }, penghadap: penghadap.value.map(row => ({ id_client: row.id_client, nama_client: row.nama_client, status_kedudukan: row.status_kedudukan, id_mewakili: row.id_mewakili || null, mewakili: row.mewakili })) })
const valid = () => {
  if (!jobForm.title.trim() || !jobForm.id_user || !penghadap.value.length) { error.value = 'Judul, penanggung jawab, dan minimal satu penghadap wajib diisi.'; return false }
  if ((jobForm.type === 'notaris' || jobForm.type === 'ppat') && !jobForm.details.jenis_akta) { error.value = 'Jenis akta wajib dipilih.'; return false }
  return true
}
const queue = () => { if (valid()) { queued.value.push(payload()); resetJob(); moveToStep(1) } }
const save = async () => {
  if (saving.value) return
  if (editorStep.value !== 4) return
  for (const step of [1, 2, 3]) {
    if (!validateStep(step)) { const problem = error.value; moveToStep(step); error.value = problem; return }
  }
  if (!valid()) return
  saving.value = true; error.value = ''
  try {
    const result = editorMode.value === 'create'
      ? await business.workOrders.create({ ...orderForm, jobs: [...queued.value, payload()] })
      : editorMode.value === 'add'
        ? await business.workOrders.addJob(orderId.value, payload())
        : await business.workOrders.updateJob(jobId.value, payload())
    message.value = (result as Envelope<unknown>).message || 'Pekerjaan tersimpan.'
    editorOpen.value = false
    if (isCreate.value) { month.value = new Date().toLocaleDateString('sv-SE').slice(0, 7); search.value = ''; statusFilter.value = 'all' }
    await load()
  } catch (e) { fail(e, 'Gagal menyimpan pekerjaan.') }
  finally { saving.value = false }
}
const openSchedule = (job: Job) => {
  scheduleJob.value = job
  scheduleForm.start_datetime = String(job.scheduled_start || '').replace(' ', 'T').slice(0, 16)
  scheduleForm.end_datetime = String(job.scheduled_end || '').replace(' ', 'T').slice(0, 16)
  scheduleForm.location = job.scheduled_location || ''
  error.value = ''
  scheduleOpen.value = true
}
const saveSchedule = async () => {
  if (!scheduleJob.value || saving.value) return
  saving.value = true; error.value = ''
  try {
    const result = await business.workOrders.schedule(scheduleJob.value.id, { ...scheduleForm }) as Envelope<unknown>
    message.value = result.message || 'Jadwal tersimpan.'
    scheduleOpen.value = false
    await load()
  } catch (e) { fail(e, 'Gagal menyimpan jadwal.') }
  finally { saving.value = false }
}
const cancelSchedule = async (job: Job) => {
  if (saving.value || !await confirmAction(`Batalkan jadwal TTD untuk ${job.title}? Pekerjaan tetap tersimpan.`)) return
  saving.value = true
  error.value = ''
  try {
    const result = await business.workOrders.cancelSchedule(job.id) as Envelope<unknown>
    message.value = result.message || 'Jadwal dibatalkan.'
    await load()
  } catch (e) { fail(e, 'Gagal membatalkan jadwal.') }
  finally { saving.value = false }
}
const deleteJob = async (order: Order, job: Job) => {
  if (saving.value || !canDeleteJob(order, job)) return
  const last = order.jobs.length === 1
  const approved = await confirmAction(
    `Hapus pekerjaan “${job.title}”? Data penghadap dan jadwal TTD terkait akan ikut dihapus.${last ? ' Karena ini pekerjaan terakhir, pesanan kosong juga akan dihapus.' : ' Pekerjaan lain dalam pesanan ini tetap tersimpan.'}`,
    { title: 'Hapus pekerjaan?', confirmText: 'Hapus pekerjaan', danger: true },
  )
  if (!approved || saving.value) return
  saving.value = true
  error.value = ''
  try {
    const result = await business.workOrders.deleteJob(job.id) as Envelope<unknown>
    message.value = result.message || 'Pekerjaan berhasil dihapus.'
    await load()
  } catch (e) { fail(e, 'Gagal menghapus pekerjaan.') }
  finally { saving.value = false }
}
const sign = async (job: Job) => {
  if (!await confirmAction(`Konfirmasi TTD untuk ${job.title} sudah selesai?`)) return
  try {
    const result = await business.workOrders.confirmSigning(job.id) as Envelope<unknown>
    message.value = result.message || 'TTD dikonfirmasi.'
    await load()
  } catch (e) { fail(e, 'Gagal mengonfirmasi TTD.') }
}
const complete = async (order: Order) => {
  if (!await confirmAction('Selesaikan pesanan ini dan siapkan detail invoice?')) return
  error.value = ''
  try {
    const result = await business.workOrders.complete(order.id_order) as Envelope<unknown>
    message.value = result.message || 'Pesanan selesai.'
    await load()
  } catch (e) { fail(e, 'Gagal menyelesaikan pesanan.') }
}
watch(() => jobForm.type, () => { jobForm.details.jenis_akta = ''; void loadAkta() }, { flush: 'sync' })
onMounted(async () => {
  try { users.value = ((await business.dashboard.getDaftarAsisten()) as Envelope<User[]>).data || [] }
  catch (e) { fail(e, 'Gagal memuat daftar pengguna.') }
  await load()
})

const trapDialogFocus = (event: KeyboardEvent) => {
  if (event.key !== 'Tab') return
  const root = event.currentTarget as HTMLElement
  const elements = Array.from(root.querySelectorAll<HTMLElement>('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]')).filter(element => element.getClientRects().length > 0)
  const first = elements[0]
  const last = elements[elements.length - 1]
  if (!first || !last) return
  if (event.shiftKey && (document.activeElement === first || !elements.includes(document.activeElement as HTMLElement))) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && (document.activeElement === last || !elements.includes(document.activeElement as HTMLElement))) { event.preventDefault(); first.focus() }
}
let previousBodyOverflow: string | undefined
let previousFocus: HTMLElement | null = null
const releaseDialog = () => {
  if (previousBodyOverflow !== undefined) { document.body.style.overflow = previousBodyOverflow; previousBodyOverflow = undefined }
  previousFocus?.focus()
  previousFocus = null
}
watch(() => editorOpen.value || scheduleOpen.value, async open => {
  if (!import.meta.client) return
  if (open) {
    previousBodyOverflow = document.body.style.overflow
    previousFocus = document.activeElement as HTMLElement | null
    document.body.style.overflow = 'hidden'
    await nextTick()
    document.querySelector<HTMLElement>('[aria-modal="true"] [data-step-heading], [aria-modal="true"] input')?.focus()
  } else { cancelLookups(); releaseDialog() }
})
onBeforeUnmount(() => { cancelLookups(); if (import.meta.client) releaseDialog() })
</script>

<template>
  <div class="space-y-6">
    <SurfaceCard class="p-6 sm:p-7">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
          <p class="display-kicker">Informasi Pekerjaan</p>
          <h3 class="mt-2 text-2xl font-semibold text-slate-900">Buat Kerjaan</h3>
          <p class="mt-2 text-sm text-slate-500">Kelola pekerjaan dari pencatatan penghadap sampai pengambilan nomor.</p>
        </div>
        <button
          v-if="canCreate"
          type="button"
          class="inline-flex h-11 shrink-0 items-center gap-2 rounded-xl bg-slate-950 px-5 text-sm font-semibold text-white transition hover:bg-slate-800"
          @click="create"
        >
          <PlusIcon class="h-4 w-4" />
          Buat Kerjaan
        </button>
        <p v-else class="text-xs font-semibold uppercase tracking-wider text-slate-500">Mode Arsip (Read Only)</p>
      </div>
    </SurfaceCard>

    <div class="grid gap-4 sm:grid-cols-3">
      <SurfaceCard class="flex h-full items-center gap-4 p-5"><span class="rounded-xl bg-blue-50 p-3 text-blue-600"><DocumentTextIcon class="h-5 w-5" /></span><div><p class="text-xs font-medium text-slate-500">Pesanan aktif</p><p class="mt-1 text-2xl font-semibold text-slate-900">{{ loading ? '—' : summary.active }}</p></div></SurfaceCard>
      <SurfaceCard class="flex h-full items-center gap-4 p-5"><span class="rounded-xl bg-amber-50 p-3 text-amber-600"><CalendarDaysIcon class="h-5 w-5" /></span><div><p class="text-xs font-medium text-slate-500">Menunggu konfirmasi TTD</p><p class="mt-1 text-2xl font-semibold text-slate-900">{{ loading ? '—' : summary.scheduled }}</p></div></SurfaceCard>
      <SurfaceCard class="flex h-full items-center gap-4 p-5"><span class="rounded-xl bg-emerald-50 p-3 text-emerald-600"><ClipboardDocumentCheckIcon class="h-5 w-5" /></span><div><p class="text-xs font-medium text-slate-500">Siap ambil nomor</p><p class="mt-1 text-2xl font-semibold text-slate-900">{{ loading ? '—' : summary.signed }}</p></div></SurfaceCard>
    </div>
    <p v-if="message" role="status" class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><CheckIcon class="h-5 w-5 shrink-0" />{{ message }}</p>
    <p v-if="error && !editorOpen && !scheduleOpen" role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

    <section class="space-y-5" aria-label="Daftar pesanan">
      <SurfaceCard class="p-6 sm:p-7">
        <div class="flex flex-wrap items-end gap-3">
        <label class="min-w-0 flex-1 text-xs font-semibold text-slate-600">Cari pesanan atau penghadap
          <span class="relative mt-2 block"><MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-3 h-5 w-5 text-slate-400" /><input v-model="search" type="search" placeholder="Nama pesanan, pekerjaan, atau penghadap…" :class="input" class="pl-10" /></span>
        </label>
        <label class="text-xs font-semibold text-slate-600">Bulan pesanan<input v-model="month" type="month" :class="input" class="mt-2" @change="load" /></label>
        <label class="text-xs font-semibold text-slate-600">Status<select v-model="statusFilter" :class="input" class="mt-2"><option value="all">Semua status</option><option value="active">Masih diproses</option><option value="complete">Selesai</option></select></label>
        <NuxtLink to="/order_masuk" :class="secondaryButton" class="shrink-0">Pesanan lama<ArrowRightIcon class="h-4 w-4" /></NuxtLink>
        </div>
      </SurfaceCard>
      <div class="flex items-center justify-between"><h2 class="text-sm font-semibold text-slate-900">Daftar pesanan <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ filtered.length }}</span></h2><span class="text-xs text-slate-400">Periode bulan yang dipilih</span></div>
      <div v-if="loading" role="status" class="space-y-3"><div v-for="index in 2" :key="index" class="h-48 animate-pulse rounded-2xl border border-slate-200 bg-slate-100" /><span class="sr-only">Memuat pekerjaan…</span></div>
      <div v-else-if="!filtered.length" class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-14 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-50 text-blue-500"><DocumentTextIcon class="h-7 w-7" /></span>
        <h3 class="mt-4 text-base font-semibold text-slate-900">{{ orders.length ? 'Pesanan tidak ditemukan' : 'Mulai pekerjaan pertama Anda' }}</h3>
        <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-slate-500">{{ orders.length ? 'Coba kata kunci atau status lain untuk menemukan pesanan.' : 'Belum ada pesanan pada bulan ini. Buat pekerjaan dan catat penghadap dalam beberapa langkah.' }}</p>
        <button v-if="orders.length" type="button" :class="secondaryButton" class="mt-5" @click="search = ''; statusFilter = 'all'">Reset pencarian</button>
        <button v-else-if="canCreate" type="button" :class="primaryButton" class="mt-5" @click="create"><PlusIcon class="h-4 w-4" />Buat Kerjaan</button>
      </div>
      <div v-else class="space-y-5">
        <SurfaceCard v-for="order in filtered" :key="order.id_order" class="overflow-hidden p-0">
          <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 px-6 py-5 sm:px-7">
            <div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><span class="font-mono text-xs text-slate-400">{{ order.id_order }}</span><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="order.status_order === 'Selesai' ? 'bg-emerald-100 text-emerald-700' : 'bg-blue-100 text-blue-700'">{{ order.status_order === 'Selesai' ? 'Selesai' : 'Sedang diproses' }}</span></div><h3 class="mt-1 break-words font-semibold text-slate-900">{{ order.nama_pesanan }}</h3><p v-if="order.keterangan_order" class="mt-1 text-xs leading-5 text-slate-500">{{ order.keterangan_order }}</p></div>
            <div class="flex flex-wrap items-center gap-2"><span class="mr-2 text-xs text-slate-500">{{ order.jobs.filter(job => job.status === 'numbered').length }}/{{ order.jobs.length }} pekerjaan bernomor</span><button v-if="canAddOrder(order)" type="button" :class="secondaryButton" @click="add(order)"><PlusIcon class="h-4 w-4" />Tambah pekerjaan</button><button v-if="canComplete(order)" type="button" :class="primaryButton" @click="complete(order)"><CheckIcon class="h-4 w-4" />Selesaikan pesanan</button></div>
          </div>
          <div class="divide-y divide-slate-100">
            <div v-for="job in order.jobs" :key="job.id" class="p-5">
              <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.8fr)]">
                <div><span class="rounded-md bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-600">{{ labels[job.type] }}</span><h4 class="mt-2 break-words text-sm font-semibold text-slate-900">{{ job.title }}</h4><div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-500"><span class="flex items-center gap-1.5"><UsersIcon class="h-4 w-4 shrink-0" />{{ job.penghadap.map(row => row.nama_client).join(', ') || 'Belum ada penghadap' }}</span><span>PJ: {{ job.assignee_name || job.id_user }}</span><span v-if="job.book_number" class="font-semibold text-blue-700">Nomor {{ job.book_number }}</span></div></div>
                <div class="flex flex-col justify-center">
                  <ol class="flex" aria-label="Progres pekerjaan"><li v-for="(stage, index) in progressStages" :key="stage" class="relative flex flex-1 flex-col items-center gap-2 text-center" :aria-current="progressIndex(job.status) === index ? 'step' : undefined"><div v-if="index < 3" class="absolute left-1/2 right-[-50%] top-3 h-0.5" :class="progressIndex(job.status) > index ? 'bg-blue-500' : 'bg-slate-100'" /><span class="relative flex h-6 w-6 items-center justify-center rounded-full border-2 text-[10px] font-bold" :class="progressIndex(job.status) >= index ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-400'"><CheckIcon v-if="progressIndex(job.status) > index || job.status === 'numbered'" class="h-3.5 w-3.5" /><template v-else>{{ index + 1 }}</template></span><span class="text-[10px] font-medium sm:text-xs" :class="progressIndex(job.status) >= index ? 'text-blue-700' : 'text-slate-400'">{{ stage }}</span></li></ol>
                </div>
              </div>
              <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                <span class="mr-auto text-xs text-slate-400">{{ job.status === 'draft' ? 'Berikutnya: tentukan jadwal tanda tangan.' : job.status === 'scheduled' ? 'Konfirmasi setelah tanda tangan berlangsung.' : job.status === 'signed' ? 'TTD selesai. Pekerjaan siap mengambil nomor.' : 'Nomor sudah ditautkan ke pekerjaan ini.' }}</span>
                <button v-if="canCreate && canManageOrder(order) && canEdit(job)" type="button" class="rounded-lg px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100" @click="edit(job)">Edit draf</button>
                <button v-if="canDeleteJob(order, job)" type="button" :disabled="saving" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50" @click="deleteJob(order, job)"><TrashIcon class="h-4 w-4" />Hapus pekerjaan</button>
                <button v-if="canCreate && canManageOrder(order) && canSchedule(job)" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-100" @click="openSchedule(job)"><CalendarDaysIcon class="h-4 w-4" />{{ job.event_id ? 'Ubah jadwal' : 'Jadwalkan TTD' }}</button>
                <button v-if="canCreate && canManageOrder(order) && job.status === 'scheduled'" type="button" :disabled="saving" class="rounded-lg px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-50" @click="cancelSchedule(job)">Batalkan jadwal</button>
                <button v-if="canSignJob(job)" type="button" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700" @click="sign(job)">Konfirmasi TTD</button>
                <NuxtLink v-if="canNumberJob(job)" :to="{ path: bookPaths[job.type], query: { work_item: String(job.id) } }" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">Ambil nomor<ArrowRightIcon class="h-4 w-4" /></NuxtLink>
              </div>
            </div>
          </div>
        </SurfaceCard>
      </div>
    </section>

    <Teleport to="body">
      <div v-if="editorOpen" class="fixed inset-0 z-[60] bg-slate-950/50 p-0 backdrop-blur-sm sm:p-4 lg:p-6" @keydown.esc.stop.prevent="closeEditor">
        <form role="dialog" aria-modal="true" aria-labelledby="work-editor-title" class="mx-auto flex h-full max-w-6xl flex-col overflow-hidden bg-white text-slate-900 shadow-2xl sm:rounded-2xl" @keydown="trapDialogFocus" @submit.prevent="editorStep < 4 ? nextStep() : save()">
          <header class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 sm:px-7">
            <div><p class="text-[11px] font-semibold uppercase tracking-wider text-blue-600">{{ isEdit ? 'Perbarui pekerjaan' : queued.length ? 'Pekerjaan ke-' + (queued.length + 1) : 'Pesanan & pekerjaan' }}</p><h2 id="work-editor-title" class="mt-1 text-lg font-bold">{{ isCreate ? 'Buat Kerjaan' : isEdit ? 'Edit pekerjaan' : 'Tambah pekerjaan' }}</h2></div>
            <button type="button" aria-label="Tutup form" :disabled="saving" class="rounded-xl p-2.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700 disabled:opacity-50" @click="closeEditor"><XMarkIcon class="h-5 w-5" /></button>
          </header>
          <div class="flex min-h-0 flex-1 flex-col md:flex-row">
            <nav aria-label="Langkah pembuatan pekerjaan" class="shrink-0 border-b border-slate-200 bg-slate-50 px-4 py-4 md:w-64 md:border-b-0 md:border-r md:px-5 md:py-7">
              <ol class="grid grid-cols-4 gap-2 md:grid-cols-1 md:gap-2">
                <li v-for="(step, index) in steps" :key="step.title"><button type="button" :aria-current="editorStep === index + 1 ? 'step' : undefined" :disabled="index + 1 >= editorStep || saving" class="flex w-full flex-col items-center gap-2 rounded-xl p-2 text-left transition md:flex-row md:gap-3 md:p-3" :class="editorStep === index + 1 ? 'bg-blue-50 text-blue-700 ring-1 ring-blue-100' : index + 1 < editorStep ? 'text-slate-700 hover:bg-slate-100' : 'text-slate-400'" @click="revisitStep(index + 1)"><span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold" :class="editorStep === index + 1 ? 'bg-blue-600 text-white shadow-sm' : index + 1 < editorStep ? 'bg-emerald-100 text-emerald-700' : 'border border-slate-200 bg-white text-slate-400'"><CheckIcon v-if="index + 1 < editorStep" class="h-4 w-4" /><template v-else>{{ index + 1 }}</template></span><span><span class="block text-center text-[10px] font-semibold sm:text-xs md:text-left md:text-sm">{{ step.title }}</span><span class="mt-1 hidden text-[11px] font-normal leading-4 text-slate-400 md:block">{{ step.description }}</span></span></button></li>
              </ol>
              <div class="mt-8 hidden rounded-xl border border-slate-200 bg-white p-4 md:block"><p class="text-xs font-semibold text-slate-700">Satu pesanan, beberapa pekerjaan</p><p class="mt-2 text-xs leading-5 text-slate-500">Anda bisa menambahkan pekerjaan lain pada langkah terakhir. Jadwal dan nomor dicatat per pekerjaan.</p><p v-if="queued.length" class="mt-3 text-xs font-semibold text-blue-600">{{ queued.length }} pekerjaan siap disimpan</p></div>
            </nav>
            <div ref="editorBody" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-6 sm:px-8 sm:py-8">
              <div class="mx-auto max-w-2xl">
                <p class="text-xs font-medium text-slate-400">Langkah {{ editorStep }} dari 4</p>
                <h3 data-step-heading tabindex="-1" class="mt-1 text-xl font-bold tracking-tight text-slate-900 outline-none">{{ steps[editorStep - 1]?.title }}</h3>
                <p class="mb-6 mt-2 text-sm leading-6 text-slate-500">{{ editorStep === 1 ? 'Mulai dengan memilih jenis layanan. Form akan menyesuaikan pilihan Anda.' : editorStep === 2 ? 'Isi informasi utama. Data yang belum tersedia dapat dilengkapi sebelum pengambilan nomor.' : editorStep === 3 ? 'Pilih penghadap dari data client agar tidak perlu dimasukkan ulang saat mengambil nomor.' : 'Pastikan detail dan penghadap sudah sesuai. Jadwal TTD bisa dibuat setelah pekerjaan tersimpan.' }}</p>
                <p v-if="error" role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p>

                <div v-if="editorStep === 1" class="grid gap-3 sm:grid-cols-2">
                  <button v-for="option in typeOptions" :key="option.value" type="button" :aria-pressed="typeSelected && jobForm.type === option.value" :disabled="isEdit" class="relative flex flex-col items-start rounded-2xl border-2 p-5 text-left transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-500 disabled:cursor-default" :class="typeSelected && jobForm.type === option.value ? 'border-blue-600 bg-blue-50/60' : 'border-slate-200 bg-white hover:border-blue-300 hover:bg-slate-50'" @click="chooseType(option.value)"><span class="mb-4 rounded-xl p-2.5" :class="typeSelected && jobForm.type === option.value ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500'"><component :is="option.icon" class="h-6 w-6" /></span><span class="text-base font-bold">{{ labels[option.value] }}</span><span class="mt-1 text-xs leading-5 text-slate-500">{{ option.description }}</span><span class="mt-4 text-[11px] font-semibold text-blue-600">{{ option.hint }}</span><span v-if="typeSelected && jobForm.type === option.value" class="absolute right-4 top-4 flex h-5 w-5 items-center justify-center rounded-full bg-blue-600 text-white"><CheckIcon class="h-3.5 w-3.5" /></span></button>
                  <p v-if="isEdit" class="text-xs leading-5 text-slate-500 sm:col-span-2">Jenis pekerjaan yang sudah dibuat tidak dapat diubah.</p>
                </div>

                <div v-else-if="editorStep === 2" class="space-y-6">
                  <section v-if="isCreate" class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50/60 p-5">
                    <div><h4 class="text-sm font-semibold">Informasi pesanan</h4><p class="mt-1 text-xs text-slate-500">Wadah untuk satu atau beberapa pekerjaan terkait.</p></div>
                    <label class="block text-xs font-semibold text-slate-600">Nama pesanan <span class="text-red-500">*</span><input v-model="orderForm.nama_pesanan" maxlength="255" :class="input" class="mt-2" placeholder="Contoh: Pengurusan dokumen PT Maju Bersama" /></label>
                    <label class="block text-xs font-semibold text-slate-600">Penanggung jawab pesanan <span class="text-red-500">*</span><select v-model="orderForm.id_user" :class="input" class="mt-2"><option value="">Pilih penanggung jawab</option><option v-for="item in users" :key="item.id_user" :value="item.id_user">{{ item.nama_lengkap }}</option></select></label>
                    <label class="block text-xs font-semibold text-slate-600">Catatan pesanan <span class="font-normal text-slate-400">(opsional)</span><textarea v-model="orderForm.keterangan_order" :class="input" class="mt-2 h-20 py-3" placeholder="Catatan untuk tim yang menangani pesanan" /></label>
                  </section>
                  <section class="space-y-4"><div class="flex items-center justify-between"><h4 class="text-sm font-semibold">Detail pekerjaan</h4><span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">{{ labels[jobForm.type] }}</span></div>
                    <label class="block text-xs font-semibold text-slate-600">{{ isSurat ? 'Judul surat / pekerjaan' : 'Judul pekerjaan' }} <span class="text-red-500">*</span><input v-model="jobForm.title" maxlength="255" :class="input" class="mt-2" :placeholder="isPpat ? 'Contoh: Jual beli tanah Bapak Ahmad' : 'Tuliskan judul yang mudah dikenali'" /></label>
                    <label class="block text-xs font-semibold text-slate-600">Penanggung jawab pekerjaan <span class="text-red-500">*</span><select v-model="jobForm.id_user" :class="input" class="mt-2"><option value="">Pilih penanggung jawab</option><option v-for="item in users" :key="item.id_user" :value="item.id_user">{{ item.nama_lengkap }}</option></select></label>
                    <label v-if="needsAkta" class="block text-xs font-semibold text-slate-600">Jenis akta <span class="text-red-500">*</span><select v-model="jobForm.details.jenis_akta" :disabled="aktaLoading" :class="input" class="mt-2"><option value="">{{ aktaLoading ? 'Memuat jenis akta…' : 'Pilih jenis akta ' + labels[jobForm.type] }}</option><option v-for="item in aktaOptions" :key="item.id_akta" :value="item.id_akta">{{ item.nama_akta }}</option></select></label>
                    <p v-if="needsAkta && aktaError" role="alert" class="text-xs text-red-600">Daftar jenis akta belum berhasil dimuat. <button type="button" class="font-semibold underline" @click="loadAkta">Coba lagi</button></p>
                    <label v-if="isSurat" class="block text-xs font-semibold text-slate-600">Keterangan surat <span class="font-normal text-slate-400">(opsional)</span><textarea v-model="jobForm.details.keterangan_surat" :class="input" class="mt-2 h-24 py-3" placeholder="Isi ringkasan atau keterangan surat" /></label>
                  </section>
                  <details v-if="isPpat" class="group rounded-2xl border border-slate-200 p-5"><summary class="cursor-pointer text-sm font-semibold text-slate-800">Data objek & transaksi <span class="ml-1 text-xs font-normal text-slate-400">Opsional saat ini</span></summary><p class="mt-2 text-xs leading-5 text-slate-500">Isi data yang sudah tersedia. Sisanya dapat dilengkapi saat mengambil nomor.</p><div class="mt-5 grid gap-4 sm:grid-cols-2"><label v-for="field in ppatFields" :key="field.key" class="text-xs font-semibold text-slate-600">{{ field.label }}<input v-model="jobForm.details[field.key]" :type="field.type || 'text'" :min="field.type === 'number' ? 0 : undefined" :step="field.type === 'number' ? 'any' : undefined" :placeholder="field.placeholder" :class="input" class="mt-2" /></label></div></details>
                  <p class="text-xs text-slate-400"><span class="text-red-500">*</span> Wajib diisi untuk melanjutkan</p>
                </div>

                <div v-else-if="editorStep === 3" class="space-y-5">
                  <div v-if="penghadap.length" class="space-y-3"><div v-for="(row, index) in penghadap" :key="index" class="flex items-start gap-3 rounded-xl border border-blue-100 bg-blue-50/50 p-4"><span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700">{{ index + 1 }}</span><div class="min-w-0 flex-1"><p class="break-words text-sm font-semibold text-slate-900">{{ row.nama_client }}</p><p class="mt-1 text-xs text-slate-500">{{ row.status_kedudukan }}<template v-if="row.mewakili"> · Mewakili {{ row.mewakili }}</template></p></div><button type="button" :aria-label="'Hapus penghadap ' + row.nama_client" class="rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" @click="penghadap.splice(index, 1)"><TrashIcon class="h-4 w-4" /></button></div></div>
                  <section class="space-y-4 rounded-2xl border border-slate-200 p-5"><h4 class="flex items-center gap-2 text-sm font-semibold"><UsersIcon class="h-5 w-5 text-blue-600" />{{ penghadap.length ? 'Tambah penghadap lainnya' : 'Tambahkan penghadap' }}</h4>
                    <label class="block text-xs font-semibold text-slate-600">Cari client <span class="text-red-500">*</span>
                      <input v-model="clientQuery" type="text" list="work-order-client-options" autocomplete="off" :class="input" class="mt-2" placeholder="Cari nama penghadap" @input="lookup(clientQuery, clientTarget)" />
                      <span class="mt-1.5 block text-[11px] font-normal text-slate-400">Ketik minimal 2 karakter, lalu pilih client dari saran autocomplete.</span>
                    </label>
                    <datalist id="work-order-client-options"><option v-for="item in clientOptions" :key="String(item.id_client)" :value="clientOptionLabel(item)" /></datalist>
                    <p v-if="clientSearching" role="status" class="text-xs text-slate-500">Mencari client…</p>
                    <p v-else-if="clientQuery.trim().length >= 2 && !clientOptions.length" role="status" class="text-xs text-slate-500">Tidak ada hasil. Coba nama lain atau daftarkan client terlebih dahulu.</p>
                    <label class="block text-xs font-semibold text-slate-600">Status kedudukan <span class="text-red-500">*</span><input v-model="kedudukan" maxlength="100" :class="input" class="mt-2" placeholder="Contoh: Diri sendiri, Direktur, atau Kuasa" /></label>
                    <details class="rounded-xl bg-slate-50 px-4 py-3">
                      <summary class="cursor-pointer text-xs font-semibold text-slate-600">Mewakili pihak lain? <span class="font-normal text-slate-400">Opsional</span></summary>
                      <div class="mt-4 space-y-3">
                        <label class="block text-xs font-semibold text-slate-600">Cari pihak yang diwakili
                          <input v-model="mewakiliQuery" type="text" list="work-order-representative-options" autocomplete="off" :class="input" class="mt-2" placeholder="Cari nama pihak yang diwakili" @input="lookup(mewakiliQuery, representativeTarget)" />
                          <span class="mt-1.5 block text-[11px] font-normal text-slate-400">Pilih dari saran autocomplete. Kosongkan jika tidak mewakili pihak lain.</span>
                        </label>
                        <datalist id="work-order-representative-options"><option v-for="item in mewakiliOptions" :key="String(item.id_client)" :value="clientOptionLabel(item)" /></datalist>
                        <p v-if="representativeSearching" role="status" class="text-xs text-slate-400">Mencari pihak yang diwakili…</p>
                        <p v-else-if="mewakiliQuery.trim().length >= 2 && !mewakiliOptions.length" role="status" class="text-xs text-slate-500">Tidak ada hasil. Coba nama pihak yang diwakili lainnya.</p>
                      </div>
                    </details>
                    <button type="button" :class="secondaryButton" class="w-full text-blue-700" @click="addPerson"><PlusIcon class="h-4 w-4" />Tambahkan penghadap</button>
                  </section>
                  <p class="text-xs leading-5 text-slate-400">Minimal satu penghadap. Data ini akan digunakan kembali pada form pengambilan nomor.</p>
                </div>

                <div v-else class="space-y-5">
                  <div class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-800">{{ isEdit ? 'Perubahan akan disimpan pada pekerjaan ini.' : 'Pekerjaan akan disimpan sebagai draf. Setelah itu, Anda dapat menjadwalkan TTD.' }}</div>
                  <section v-if="isCreate" class="rounded-2xl border border-slate-200 p-5"><div class="mb-4 flex items-center justify-between"><h4 class="text-sm font-semibold">Informasi pesanan</h4><button type="button" class="text-xs font-semibold text-blue-600 hover:underline" @click="moveToStep(2)">Ubah</button></div><dl class="space-y-3 text-sm"><div><dt class="text-xs text-slate-400">Nama pesanan</dt><dd class="mt-1 break-words font-semibold text-slate-800">{{ orderForm.nama_pesanan }}</dd></div><div><dt class="text-xs text-slate-400">Penanggung jawab pesanan</dt><dd class="mt-1 text-slate-700">{{ userName(orderForm.id_user) }}</dd></div><div v-if="orderForm.keterangan_order"><dt class="text-xs text-slate-400">Catatan</dt><dd class="mt-1 whitespace-pre-wrap break-words text-slate-600">{{ orderForm.keterangan_order }}</dd></div></dl></section>
                  <div class="flex items-center justify-between"><h4 class="text-sm font-semibold">{{ reviewJobs.length }} pekerjaan dalam {{ isCreate ? 'pesanan' : 'tinjauan' }}</h4><button type="button" class="text-xs font-semibold text-blue-600 hover:underline" @click="moveToStep(2)">Ubah pekerjaan saat ini</button></div>
                  <article v-for="(item, index) in reviewJobs" :key="index" class="overflow-hidden rounded-2xl border border-slate-200"><header class="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50 px-5 py-3"><div class="flex items-center gap-2"><span class="text-xs font-semibold text-blue-700">{{ labels[item.type] }}</span><span v-if="index === reviewJobs.length - 1" class="text-[10px] text-slate-400">Pekerjaan saat ini</span></div><button v-if="isCreate && index < queued.length" type="button" :aria-label="'Hapus pekerjaan ' + item.title" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600" @click="queued.splice(index, 1)"><TrashIcon class="h-4 w-4" /></button></header><div class="space-y-4 p-5"><div><h5 class="break-words font-semibold text-slate-900">{{ item.title }}</h5><p class="mt-1 text-xs text-slate-500">Penanggung jawab: {{ userName(item.id_user) }}</p><p v-if="item.details.jenis_akta" class="mt-2 text-xs text-slate-500">Jenis akta: {{ actName(item.details.jenis_akta) }}</p><p v-if="item.details.keterangan_surat" class="mt-2 whitespace-pre-wrap break-words text-xs text-slate-500">{{ item.details.keterangan_surat }}</p></div><dl v-if="item.type === 'ppat'" class="grid grid-cols-2 gap-3"><template v-for="field in ppatFields" :key="field.key"><div v-if="item.details[field.key]"><dt class="text-[11px] text-slate-400">{{ field.label }}</dt><dd class="mt-1 break-words text-xs text-slate-700">{{ item.details[field.key] }}</dd></div></template></dl><div class="border-t border-slate-100 pt-3"><p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Penghadap · {{ item.penghadap.length }}</p><div v-for="person in item.penghadap" :key="person.id_client + person.status_kedudukan + person.id_mewakili" class="py-1.5"><p class="text-sm font-medium text-slate-700">{{ person.nama_client }}</p><p class="mt-0.5 text-xs text-slate-400">{{ person.status_kedudukan }}<template v-if="person.mewakili"> · Mewakili {{ person.mewakili }}</template></p></div></div></div></article>
                  <button v-if="isCreate" type="button" :class="secondaryButton" class="w-full border-dashed text-blue-700" @click="queue"><PlusIcon class="h-4 w-4" />Tambah pekerjaan lain ke pesanan ini</button>
                </div>
              </div>
            </div>
          </div>
          <footer class="flex shrink-0 items-center justify-between gap-3 border-t border-slate-200 bg-white px-5 py-4 sm:px-7"><button type="button" :class="secondaryButton" :disabled="saving" @click="editorStep > 1 ? moveToStep(editorStep - 1) : closeEditor()"><ArrowLeftIcon v-if="editorStep > 1" class="h-4 w-4" />{{ editorStep > 1 ? 'Kembali' : 'Batal' }}</button><span class="hidden text-xs text-slate-400 sm:block">{{ editorStep === 4 ? 'Periksa kembali sebelum menyimpan' : 'Data belum disimpan' }}</span><button type="submit" :class="primaryButton" :disabled="saving"><template v-if="editorStep < 4">Lanjut<ArrowRightIcon class="h-4 w-4" /></template><template v-else><CheckIcon v-if="!saving" class="h-4 w-4" />{{ saving ? 'Menyimpan…' : isEdit ? 'Simpan perubahan' : isCreate ? 'Simpan pesanan' : 'Simpan pekerjaan' }}</template></button></footer>
        </form>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="scheduleOpen" class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
        <form role="dialog" aria-modal="true" aria-labelledby="schedule-title" class="w-full max-w-lg overflow-hidden rounded-2xl bg-white text-slate-900 shadow-2xl" @keydown="trapDialogFocus" @keydown.esc.stop.prevent="!saving && (scheduleOpen = false)" @submit.prevent="saveSchedule">
          <header class="flex items-center justify-between border-b border-slate-100 px-6 py-4"><div><h2 id="schedule-title" class="text-lg font-bold">Jadwal tanda tangan</h2><p class="mt-1 text-xs text-slate-500">{{ scheduleJob?.title }}</p></div><button type="button" aria-label="Tutup jadwal" :disabled="saving" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100" @click="scheduleOpen = false"><XMarkIcon class="h-5 w-5" /></button></header>
          <div class="space-y-4 p-6"><p v-if="error" role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ error }}</p><label class="block text-xs font-semibold text-slate-600">Mulai<input v-model="scheduleForm.start_datetime" type="datetime-local" required :class="input" class="mt-2" /></label><label class="block text-xs font-semibold text-slate-600">Selesai<input v-model="scheduleForm.end_datetime" type="datetime-local" required :min="scheduleForm.start_datetime || undefined" :class="input" class="mt-2" /></label><label class="block text-xs font-semibold text-slate-600">Lokasi <span class="font-normal text-slate-400">(opsional)</span><input v-model="scheduleForm.location" maxlength="255" :class="input" class="mt-2" placeholder="Contoh: Ruang rapat kantor" /></label><p class="rounded-xl bg-blue-50 px-4 py-3 text-xs leading-5 text-blue-700">Jadwal akan terhubung ke kalender. Pastikan penanggung jawab tersedia pada waktu yang dipilih.</p></div>
          <footer class="flex justify-end gap-2 border-t border-slate-100 px-6 py-4"><button type="button" :class="secondaryButton" :disabled="saving" @click="scheduleOpen = false">Batal</button><button type="submit" :class="primaryButton" :disabled="saving">{{ saving ? 'Menyimpan…' : 'Simpan jadwal' }}</button></footer>
        </form>
      </div>
    </Teleport>
  </div>
</template>
