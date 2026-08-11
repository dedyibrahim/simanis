<script setup lang="ts">
import {
  ArrowPathIcon,
  CalendarDaysIcon,
  ChevronLeftIcon,
  ChevronRightIcon,
  ClockIcon,
  MapPinIcon,
  PaperAirplaneIcon,
  PencilSquareIcon,
  PlusIcon,
  TrashIcon,
  UserGroupIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'

type ApiEnvelope<T = unknown> = {
  status?: boolean
  message?: string
  data?: T
}

type RawRecord = Record<string, unknown>

type UserOption = {
  id: string | number
  id_user?: string
  nama_lengkap?: string
  name?: string
  level_user?: string
}

type CalendarEvent = {
  id: string | number
  title: string
  location: string
  description: string
  start: Date
  end: Date
  users: UserOption[]
  creator: UserOption | null
  source: 'events' | 'legacy'
  color: string
}

type CalendarCell = {
  dateKey: string
  dateNumber: number
  inCurrentMonth: boolean
  isToday: boolean
  events: CalendarEvent[]
}

type ScheduleForm = {
  title: string
  location: string
  description: string
  start_datetime: string
  end_datetime: string
  users: Array<string | number>
}

type ReminderTimeframe = 'daily' | 'weekly' | 'monthly'

type BulkReminderForm = {
  timeframe: ReminderTimeframe
  base_date: string
}

const DEFAULT_LOCATION = 'Tanda Tangan di Kantor'
const LOCATION_OPTIONS = [DEFAULT_LOCATION, 'Di Luar Kantor', 'Lainnya'] as const

definePageMeta({
  middleware: 'auth',
})

useHead({
  title: 'Jadwal Notaris',
})

const business = useLegacyBusiness()
const { isDark } = useThemeMode()
const route = useRoute()

if (route.path === '/jadwal-notaris') {
  await navigateTo({ path: '/dashboard', query: { view: 'schedule' } }, { replace: true })
}

const loading = ref(false)
const loadingUsers = ref(false)
const saving = ref(false)
const deleting = ref(false)
const sendingReminder = ref(false)
const sendingBulkReminder = ref(false)
const message = ref('')
const errorMessage = ref('')

const sourceMode = ref<'events' | 'legacy'>('events')
const events = ref<CalendarEvent[]>([])
const allUsers = ref<UserOption[]>([])

const cursorDate = ref(new Date())
const selectedDateKey = ref('')

const createDialogOpen = ref(false)
const detailDialogOpen = ref(false)
const bulkReminderDialogOpen = ref(false)
const selectedEvent = ref<CalendarEvent | null>(null)
const scheduleDialogMode = ref<'create' | 'edit'>('create')
const editingEventId = ref<string | number | ''>('')
const userSearch = ref('')
const calendarView = ref<'month' | 'week' | 'day'>('month')
const formRangeMode = ref(false)
const rangeStartDate = ref('')
const rangeEndDate = ref('')
const rangeStartTime = ref('09:00')
const rangeEndTime = ref('10:00')
const customLocation = ref('')

const form = reactive<ScheduleForm>({
  title: '',
  location: DEFAULT_LOCATION,
  description: '',
  start_datetime: '',
  end_datetime: '',
  users: [],
})

const bulkReminderForm = reactive<BulkReminderForm>({
  timeframe: 'daily',
  base_date: '',
})

const weekdays = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab']

const toList = <T>(payload: unknown): T[] => {
  if (Array.isArray(payload)) return payload as T[]
  if (payload && typeof payload === 'object' && Array.isArray((payload as ApiEnvelope<T[]>).data)) {
    return (payload as ApiEnvelope<T[]>).data || []
  }
  return []
}

const pad = (value: number) => String(value).padStart(2, '0')

const formatDateKey = (date: Date) =>
  `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`

const parseDateValue = (value: unknown): Date | null => {
  const raw = String(value || '').trim()
  if (!raw) return null

  if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
    const parsed = new Date(`${raw}T00:00:00`)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T')
  const parsed = new Date(normalized)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

const toInputDateTime = (date: Date) =>
  `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`

const formatDateTime = (date: Date) =>
  new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)

const formatTime = (date: Date) =>
  new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)

const startOfDay = (date: Date) => {
  const copy = new Date(date)
  copy.setHours(0, 0, 0, 0)
  return copy
}

const addDays = (date: Date, amount: number) => {
  const copy = new Date(date)
  copy.setDate(copy.getDate() + amount)
  return copy
}

const startOfWeek = (date: Date) => {
  const day = startOfDay(date)
  return addDays(day, -day.getDay())
}

const eventColorStyle = (event: CalendarEvent) => {
  const custom = String(event.color || '').trim()
  if (/^#([0-9A-Fa-f]{3}){1,2}$/.test(custom)) {
    return { backgroundColor: custom, color: '#ffffff' }
  }

  const location = event.location.toLowerCase()
  if (location.includes('kantor')) {
    return { backgroundColor: '#0f172a', color: '#ffffff' }
  }
  if (location.includes('luar')) {
    return { backgroundColor: '#2563eb', color: '#ffffff' }
  }
  return { backgroundColor: '#64748b', color: '#ffffff' }
}

const normalizeUsers = (value: unknown): UserOption[] => {
  if (!Array.isArray(value)) return []

  return value
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const row = item as RawRecord
      const id = (row.id as string | number) ?? (row.id_user as string | number)
      if (id === null || id === undefined || String(id) === '') return null

      return {
        id,
        nama_lengkap: String(row.nama_lengkap || row.name || ''),
        name: String(row.name || row.nama_lengkap || ''),
      } satisfies UserOption
    })
    .filter(Boolean) as UserOption[]
}

const normalizeEventRow = (row: RawRecord, index: number, source: 'events' | 'legacy'): CalendarEvent | null => {
  const title = String(row.title || row.name || row.judul || 'Jadwal').trim()
  const startDate = parseDateValue(row.start_datetime || row.start || row.tanggal_mulai || row.tanggal)
  const endDate = parseDateValue(row.end_datetime || row.end || row.tanggal_selesai) || startDate

  if (!startDate || !endDate) {
    return null
  }

  const rawId = (row.id as string | number) ?? (row.id_jadwal as string | number)
  const fallbackId = `${source}-${index}-${formatDateKey(startDate)}-${title}`

  const creatorRaw = row.creator
  let creator: UserOption | null = null
  if (creatorRaw && typeof creatorRaw === 'object') {
    const c = creatorRaw as RawRecord
    const creatorId = (c.id as string | number) ?? (c.id_user as string | number)
    if (creatorId !== null && creatorId !== undefined) {
      creator = {
        id: creatorId,
        nama_lengkap: String(c.nama_lengkap || c.name || ''),
        name: String(c.name || c.nama_lengkap || ''),
      }
    }
  }

  return {
    id: rawId ?? fallbackId,
    title,
    location: String(row.location || row.jenis || '').trim(),
    description: String(row.description || row.keterangan || '').trim(),
    start: startDate,
    end: endDate >= startDate ? endDate : startDate,
    users: normalizeUsers(row.users),
    creator,
    source,
    color: String(row.color || '').trim(),
  }
}

const normalizeEvents = (payload: RawRecord[], source: 'events' | 'legacy') =>
  payload
    .map((row, index) => normalizeEventRow(row, index, source))
    .filter(Boolean) as CalendarEvent[]

const clearNotice = () => {
  message.value = ''
  errorMessage.value = ''
}

const currentPeriodLabel = computed(() => {
  if (calendarView.value === 'month') {
    return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(cursorDate.value)
  }

  if (calendarView.value === 'week') {
    const start = startOfWeek(cursorDate.value)
    const end = addDays(start, 6)
    const startLabel = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short' }).format(start)
    const endLabel = new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }).format(end)
    return `${startLabel} - ${endLabel}`
  }

  return new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }).format(cursorDate.value)
})

const eventsByDate = computed(() => {
  const map = new Map<string, CalendarEvent[]>()

  events.value.forEach((event) => {
    const startAt = new Date(event.start)
    let endAt = new Date(event.end)

    // Treat midnight end as exclusive to prevent spillover into the next day.
    if (
      endAt.getTime() > startAt.getTime()
      && endAt.getHours() === 0
      && endAt.getMinutes() === 0
      && endAt.getSeconds() === 0
      && endAt.getMilliseconds() === 0
    ) {
      endAt = new Date(endAt.getTime() - 1)
    }

    const start = new Date(startAt)
    const end = new Date(endAt)
    start.setHours(0, 0, 0, 0)
    end.setHours(0, 0, 0, 0)

    if (end < start) {
      end.setTime(start.getTime())
    }

    const cursor = new Date(start)
    while (cursor <= end) {
      const key = formatDateKey(cursor)
      const list = map.get(key) || []
      list.push(event)
      map.set(key, list)
      cursor.setDate(cursor.getDate() + 1)
    }
  })

  map.forEach((list, key) => {
    map.set(
      key,
      [...list].sort((a, b) =>
        a.start.getTime() - b.start.getTime() || a.title.localeCompare(b.title, 'id-ID'),
      ),
    )
  })

  return map
})

const calendarCells = computed(() => {
  const year = cursorDate.value.getFullYear()
  const month = cursorDate.value.getMonth()
  const first = new Date(year, month, 1)
  const startOffset = first.getDay()
  const start = new Date(year, month, 1 - startOffset)
  const todayKey = formatDateKey(new Date())

  return Array.from({ length: 42 }, (_, index) => {
    const date = new Date(start)
    date.setDate(start.getDate() + index)
    const dateKey = formatDateKey(date)

    return {
      dateKey,
      dateNumber: date.getDate(),
      inCurrentMonth: date.getMonth() === month,
      isToday: dateKey === todayKey,
      events: eventsByDate.value.get(dateKey) || [],
    } satisfies CalendarCell
  })
})

const weekColumns = computed(() => {
  const start = startOfWeek(cursorDate.value)
  const todayKey = formatDateKey(new Date())

  return Array.from({ length: 7 }, (_, index) => {
    const date = addDays(start, index)
    const dateKey = formatDateKey(date)
    return {
      date,
      dateKey,
      label: new Intl.DateTimeFormat('id-ID', { weekday: 'short' }).format(date),
      dateNumber: date.getDate(),
      events: eventsByDate.value.get(dateKey) || [],
      isToday: dateKey === todayKey,
      isSelected: selectedDateKey.value === dateKey,
    }
  })
})

const dayColumns = computed(() => {
  if (calendarView.value === 'week') {
    return weekColumns.value
  }

  const date = startOfDay(cursorDate.value)
  const dateKey = formatDateKey(date)
  const todayKey = formatDateKey(new Date())
  return [{
    date,
    dateKey,
    label: new Intl.DateTimeFormat('id-ID', { weekday: 'long' }).format(date),
    dateNumber: date.getDate(),
    events: eventsByDate.value.get(dateKey) || [],
    isToday: dateKey === todayKey,
    isSelected: selectedDateKey.value === dateKey,
  }]
})

const selectedDateLabel = computed(() => {
  const parsed = parseDateValue(selectedDateKey.value)
  if (!parsed) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  }).format(parsed)
})

const selectedDateEvents = computed(() =>
  (eventsByDate.value.get(selectedDateKey.value) || []).slice().sort((a, b) =>
    a.start.getTime() - b.start.getTime() || a.title.localeCompare(b.title, 'id-ID'),
  ),
)

const filteredUsers = computed(() => {
  const keyword = userSearch.value.trim().toLowerCase()
  if (!keyword) return allUsers.value

  return allUsers.value.filter((user) =>
    String(user.nama_lengkap || user.name || '').toLowerCase().includes(keyword),
  )
})

const hasUser = (id: string | number) =>
  form.users.some(userId => String(userId) === String(id))

const toggleUser = (id: string | number) => {
  if (hasUser(id)) {
    form.users = form.users.filter(userId => String(userId) !== String(id))
    return
  }

  form.users = [...form.users, id]
}

const selectedUserNames = computed(() =>
  allUsers.value
    .filter(user => hasUser(user.id))
    .map(user => String(user.nama_lengkap || user.name || user.id)),
)

const scheduleDialogTitle = computed(() =>
  scheduleDialogMode.value === 'edit' ? 'Edit Jadwal Notaris' : 'Tambah Jadwal Notaris',
)

const scheduleDialogSubmitLabel = computed(() => {
  if (saving.value) {
    return scheduleDialogMode.value === 'edit' ? 'Menyimpan Perubahan...' : 'Menyimpan...'
  }
  return scheduleDialogMode.value === 'edit' ? 'Simpan Perubahan' : 'Simpan Jadwal'
})

const setDefaultForm = (dateKey = selectedDateKey.value || formatDateKey(new Date())) => {
  const baseDate = parseDateValue(dateKey) || new Date()
  const start = new Date(baseDate)
  start.setHours(9, 0, 0, 0)
  const end = new Date(baseDate)
  end.setHours(10, 0, 0, 0)

  form.title = ''
  form.location = DEFAULT_LOCATION
  customLocation.value = ''
  form.description = ''
  form.start_datetime = toInputDateTime(start)
  form.end_datetime = toInputDateTime(end)
  form.users = []
  formRangeMode.value = false
  rangeStartDate.value = formatDateKey(start)
  rangeEndDate.value = formatDateKey(end)
  rangeStartTime.value = `${pad(start.getHours())}:${pad(start.getMinutes())}`
  rangeEndTime.value = `${pad(end.getHours())}:${pad(end.getMinutes())}`
  userSearch.value = ''
}

const setEditForm = (event: CalendarEvent) => {
  const locationRaw = String(event.location || '').trim()
  const locationLower = locationRaw.toLowerCase()
  const isInsideLocation = locationLower.includes('dalam') || locationLower.includes('kantor')
  const isOutsideLocation = locationLower.includes('luar')

  form.title = event.title
  customLocation.value = ''
  if ((LOCATION_OPTIONS as readonly string[]).includes(locationRaw)) {
    form.location = locationRaw
  } else if (isOutsideLocation) {
    form.location = 'Di Luar Kantor'
  } else if (locationRaw === '' || isInsideLocation) {
    form.location = DEFAULT_LOCATION
  } else {
    form.location = 'Lainnya'
    customLocation.value = locationRaw
  }
  form.description = event.description || ''
  form.start_datetime = toInputDateTime(event.start)
  form.end_datetime = toInputDateTime(event.end)
  form.users = event.users.map(user => user.id)
  formRangeMode.value = false
  rangeStartDate.value = formatDateKey(event.start)
  rangeEndDate.value = formatDateKey(event.end)
  rangeStartTime.value = `${pad(event.start.getHours())}:${pad(event.start.getMinutes())}`
  rangeEndTime.value = `${pad(event.end.getHours())}:${pad(event.end.getMinutes())}`
  userSearch.value = ''
}

const openCreateDialog = () => {
  scheduleDialogMode.value = 'create'
  editingEventId.value = ''
  setDefaultForm()
  createDialogOpen.value = true
}

const openBulkReminderDialog = () => {
  clearNotice()
  bulkReminderForm.timeframe = 'daily'
  bulkReminderForm.base_date = formatDateKey(new Date())
  bulkReminderDialogOpen.value = true
}

const closeCreateDialog = () => {
  createDialogOpen.value = false
  scheduleDialogMode.value = 'create'
  editingEventId.value = ''
  setDefaultForm()
}

const closeBulkReminderDialog = () => {
  bulkReminderDialogOpen.value = false
}

const openDetailDialog = (event: CalendarEvent) => {
  selectedEvent.value = event
  detailDialogOpen.value = true
}

const openEditDialog = () => {
  if (!selectedEvent.value) return

  if (selectedEvent.value.source !== 'events') {
    errorMessage.value = 'Edit manual hanya tersedia untuk data kalender baru.'
    return
  }

  clearNotice()
  scheduleDialogMode.value = 'edit'
  editingEventId.value = selectedEvent.value.id
  setEditForm(selectedEvent.value)
  detailDialogOpen.value = false
  createDialogOpen.value = true
}

const closeDetailDialog = () => {
  detailDialogOpen.value = false
  selectedEvent.value = null
}

const selectDay = (dateKey: string) => {
  selectedDateKey.value = dateKey
  const parsed = parseDateValue(dateKey)
  if (parsed) {
    cursorDate.value = parsed
  }
}

const shiftMonth = async (step: number) => {
  const next = new Date(cursorDate.value)
  if (calendarView.value === 'month') {
    next.setMonth(next.getMonth() + step)
  } else if (calendarView.value === 'week') {
    next.setDate(next.getDate() + (step * 7))
  } else {
    next.setDate(next.getDate() + step)
  }
  cursorDate.value = next
  selectedDateKey.value = formatDateKey(next)
  await loadEvents()
}

const goToday = async () => {
  const today = new Date()
  cursorDate.value = today
  selectedDateKey.value = formatDateKey(today)
  await loadEvents()
}

const loadUsers = async () => {
  loadingUsers.value = true
  try {
    const response = await business.events.getScheduleParticipants() as ApiEnvelope<UserOption[]>
    allUsers.value = toList<UserOption>(response)
  } catch {
    allUsers.value = []
  } finally {
    loadingUsers.value = false
  }
}

const loadEvents = async () => {
  loading.value = true
  clearNotice()

  const legacyMonth = `${cursorDate.value.getFullYear()}-${pad(cursorDate.value.getMonth() + 1)}`
  let eventsError: unknown = null

  try {
    const response = await business.events.list() as ApiEnvelope<RawRecord[]>
    const list = toList<RawRecord>(response)
    events.value = normalizeEvents(list, 'events')
    sourceMode.value = 'events'
    message.value = response.message || ''
    loading.value = false
    return
  } catch (error) {
    eventsError = error
  }

  try {
    const response = await business.jadwal.getJadwalNotaris({ date: legacyMonth }) as ApiEnvelope<RawRecord[]>
    const list = toList<RawRecord>(response)
    events.value = normalizeEvents(list, 'legacy')
    sourceMode.value = 'legacy'
    message.value = response.message || ''
  } catch (error) {
    events.value = []
    sourceMode.value = 'legacy'
    const legacyMessage = (error as { data?: { message?: string } })?.data?.message
    const eventsMessage = (eventsError as { data?: { message?: string } })?.data?.message
    errorMessage.value = legacyMessage || eventsMessage || 'Gagal memuat data jadwal notaris.'
  } finally {
    loading.value = false
  }
}

const toConflictDateLabel = (value: unknown) => {
  const parsed = parseDateValue(value)
  return parsed ? formatDateTime(parsed) : String(value || '-')
}

const extractConflictAlertMessage = (error: unknown) => {
  const err = error as {
    statusCode?: number
    status?: number
    data?: {
      message?: string
      data?: { conflicts?: Array<Record<string, unknown>> }
    }
    response?: {
      status?: number
      data?: {
        message?: string
        data?: { conflicts?: Array<Record<string, unknown>> }
      }
    }
  }

  const status = Number(err.statusCode || err.status || err.response?.status || 0)
  if (status !== 409) return null

  const payload = err.data || err.response?.data
  let text = payload?.message || 'Jadwal bentrok pada rentang waktu yang dipilih. Silakan pilih waktu lain.'
  const conflicts = Array.isArray(payload?.data?.conflicts) ? payload.data?.conflicts : []

  if (conflicts.length) {
    const lines = conflicts
      .slice(0, 5)
      .map((row, index) => {
        const title = String(row?.title || 'Agenda')
        const start = toConflictDateLabel(row?.start_datetime)
        const end = toConflictDateLabel(row?.end_datetime)
        return `${index + 1}. ${title} (${start} - ${end})`
      })
    text += `\n\nBentrok dengan:\n${lines.join('\n')}`
  }

  return text
}

const handleConflictAlert = (error: unknown) => {
  const text = extractConflictAlertMessage(error)
  if (!text) return false

  errorMessage.value = text
  if (process.client) {
    window.alert(text)
  }
  return true
}

const saveSchedule = async () => {
  if (saving.value) return
  clearNotice()

  const title = form.title.trim()
  const resolvedLocation = form.location === 'Lainnya'
    ? customLocation.value.trim()
    : form.location
  let startDateTime = form.start_datetime
  let endDateTime = form.end_datetime

  if (formRangeMode.value) {
    if (!rangeStartDate.value || !rangeEndDate.value) {
      errorMessage.value = 'Tanggal mulai dan tanggal akhir rentang wajib diisi.'
      return
    }

    startDateTime = `${rangeStartDate.value}T${rangeStartTime.value || '00:00'}`
    endDateTime = `${rangeEndDate.value}T${rangeEndTime.value || rangeStartTime.value || '23:59'}`
  }

  if (!title || !startDateTime || !endDateTime) {
    errorMessage.value = 'Judul, tanggal mulai, dan tanggal selesai wajib diisi.'
    return
  }

  if (!resolvedLocation) {
    errorMessage.value = 'Lokasi wajib diisi.'
    return
  }

  const start = parseDateValue(startDateTime)
  const end = parseDateValue(endDateTime)
  if (!start || !end || end <= start) {
    errorMessage.value = 'Waktu selesai harus lebih besar dari waktu mulai.'
    return
  }

  saving.value = true

  if (scheduleDialogMode.value === 'edit') {
    if (!editingEventId.value) {
      errorMessage.value = 'Data jadwal tidak valid untuk diedit.'
      saving.value = false
      return
    }

    try {
      const response = await business.events.update(editingEventId.value, {
        title,
        location: resolvedLocation,
        description: form.description.trim(),
        start_datetime: startDateTime,
        end_datetime: endDateTime,
        users: [...form.users],
      }) as ApiEnvelope

      sourceMode.value = 'events'
      message.value = response.message || 'Jadwal berhasil diperbarui.'
      closeCreateDialog()
      await loadEvents()
    } catch (error) {
      if (handleConflictAlert(error)) return
      errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal memperbarui jadwal.'
    } finally {
      saving.value = false
    }

    return
  }

  try {
    const response = await business.events.create({
      title,
      location: resolvedLocation,
      description: form.description.trim(),
      start_datetime: startDateTime,
      end_datetime: endDateTime,
      users: [...form.users],
    }) as ApiEnvelope

    sourceMode.value = 'events'
    message.value = response.message || 'Jadwal berhasil disimpan.'
    closeCreateDialog()
    await loadEvents()
    saving.value = false
    return
  } catch (eventsError) {
    if (handleConflictAlert(eventsError)) {
      saving.value = false
      return
    }

    if (form.users.length > 0) {
      const eventsMessage = (eventsError as { data?: { message?: string } })?.data?.message
      errorMessage.value = eventsMessage || 'Gagal menyimpan jadwal asisten. Silakan ulangi.'
      saving.value = false
      return
    }

    try {
      const jenis =
        form.location === 'Tanda Tangan di Kantor'
          ? 'Di Dalam'
          : form.location === 'Di Luar Kantor'
            ? 'Di Luar'
            : 'Lainnya'

      const response = await business.jadwal.SimpanJadwal({
        name: title,
        jenis,
        start: startDateTime,
        end: endDateTime,
      }) as ApiEnvelope

      message.value = response.message || 'Jadwal berhasil disimpan.'
      closeCreateDialog()
      await loadEvents()
      return
    } catch (legacyError) {
      const legacyMessage = (legacyError as { data?: { message?: string } })?.data?.message
      const eventsMessage = (eventsError as { data?: { message?: string } })?.data?.message
      errorMessage.value = legacyMessage || eventsMessage || 'Gagal menyimpan jadwal.'
    } finally {
      saving.value = false
    }
  }

  saving.value = false
}

const deleteSchedule = async () => {
  if (!selectedEvent.value || deleting.value) return
  if (selectedEvent.value.source !== 'events') {
    errorMessage.value = 'Hapus jadwal hanya tersedia untuk data kalender baru.'
    return
  }

  deleting.value = true
  clearNotice()

  try {
    const response = await business.events.remove(selectedEvent.value.id) as ApiEnvelope
    message.value = response.message || 'Jadwal berhasil dihapus.'
    closeDetailDialog()
    await loadEvents()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal menghapus jadwal.'
  } finally {
    deleting.value = false
  }
}

const sendReminder = async () => {
  if (!selectedEvent.value || sendingReminder.value) return

  if (selectedEvent.value.source !== 'events') {
    errorMessage.value = 'Reminder WA hanya tersedia untuk data kalender baru.'
    return
  }

  sendingReminder.value = true
  clearNotice()

  try {
    const response = await business.events.sendReminder(selectedEvent.value.id) as ApiEnvelope
    message.value = response.message || 'Reminder acara berhasil dikirim.'
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengirim reminder WA.'
  } finally {
    sendingReminder.value = false
  }
}

const sendBulkReminder = async () => {
  if (sendingBulkReminder.value) return

  clearNotice()
  sendingBulkReminder.value = true

  try {
    const response = await business.events.sendReminderBulk({
      timeframe: bulkReminderForm.timeframe,
      base_date: bulkReminderForm.base_date || formatDateKey(new Date()),
    }) as ApiEnvelope

    message.value = response.message || 'Reminder massal berhasil dikirim.'
    closeBulkReminderDialog()
    await loadEvents()
  } catch (error) {
    errorMessage.value = (error as { data?: { message?: string } })?.data?.message || 'Gagal mengirim reminder massal.'
  } finally {
    sendingBulkReminder.value = false
  }
}

watch(
  events,
  () => {
    if (!selectedDateKey.value) {
      selectedDateKey.value = formatDateKey(new Date())
    }
  },
  { immediate: true },
)

watch(calendarView, (mode) => {
  const parsed = parseDateValue(selectedDateKey.value) || new Date()
  if (mode === 'month') {
    cursorDate.value = new Date(parsed.getFullYear(), parsed.getMonth(), 1)
    return
  }

  cursorDate.value = parsed
})

onMounted(() => {
  selectedDateKey.value = formatDateKey(new Date())
  void Promise.all([loadUsers(), loadEvents()])
})
</script>

<template>
  <div class="space-y-6" :class="isDark ? 'text-slate-100' : ''">
    <SurfaceCard class="relative overflow-hidden border border-slate-200/80 p-0 shadow-xl shadow-slate-200/60" :class="isDark ? '!border-slate-800 !shadow-black/30' : ''">
      <div class="pointer-events-none absolute inset-0 bg-gradient-to-br" :class="isDark ? 'from-slate-900 via-slate-900/95 to-slate-800/90' : 'from-cyan-100/60 via-white to-blue-100/60'"></div>
      <div class="relative grid gap-6 p-6 sm:p-8 lg:grid-cols-[1.4fr_1fr] lg:items-end">
        <div>
          <p class="display-kicker">Agenda</p>
          <h2 class="mt-2 font-display text-4xl sm:text-5xl" :class="isDark ? 'text-slate-100' : 'text-slate-900'">Jadwal Notaris</h2>
          <p class="mt-3 max-w-2xl text-sm leading-6" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
            Kalender operasional untuk jadwal signing, kunjungan, dan aktivitas kantor notaris.
          </p>
        </div>
        <div class="rounded-2xl border p-4 shadow-sm backdrop-blur-sm" :class="isDark ? 'border-slate-700 bg-slate-900/70' : 'border-white/80 bg-white/70'">
          <p class="text-xs font-semibold uppercase tracking-wider" :class="isDark ? 'text-slate-400' : 'text-slate-500'">Sumber Data</p>
          <p class="mt-2 text-sm" :class="isDark ? 'text-slate-200' : 'text-slate-700'">
            {{ sourceMode === 'events' ? 'Kalender Event (/events)' : 'Kalender Legacy (/auth/getJadwalNotaris)' }}
          </p>
          <button
            type="button"
            class="mt-4 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="loading"
            @click="loadEvents"
          >
            <ArrowPathIcon class="h-4 w-4" />
            {{ loading ? 'Memuat...' : 'Refresh Kalender' }}
          </button>
        </div>
      </div>
    </SurfaceCard>

    <SurfaceCard class="p-6 sm:p-7" :class="isDark ? '!border-slate-800' : ''">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex items-center rounded-xl border border-slate-200 bg-white p-1">
          <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-700 transition hover:bg-slate-100"
            @click="shiftMonth(-1)"
          >
            <ChevronLeftIcon class="h-5 w-5" />
          </button>
          <button
            type="button"
            class="inline-flex h-9 items-center rounded-lg px-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-100"
            @click="goToday"
          >
            Hari Ini
          </button>
          <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-700 transition hover:bg-slate-100"
            @click="shiftMonth(1)"
          >
            <ChevronRightIcon class="h-5 w-5" />
          </button>
        </div>

        <div class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-4 py-2" :class="isDark ? '!border-slate-700 !bg-slate-800/80' : ''">
          <CalendarDaysIcon class="h-4 w-4 text-slate-500" />
          <p class="text-sm font-semibold" :class="isDark ? 'text-slate-100' : 'text-slate-700'">{{ currentPeriodLabel }}</p>
        </div>

        <div class="inline-flex items-center rounded-xl border border-slate-200 bg-white p-1" :class="isDark ? '!border-slate-700 !bg-slate-900' : ''">
          <button
            type="button"
            class="h-9 rounded-lg px-3 text-xs font-semibold transition"
            :class="calendarView === 'month' ? 'bg-slate-950 text-white' : (isDark ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
            @click="calendarView = 'month'"
          >
            Bulan
          </button>
          <button
            type="button"
            class="h-9 rounded-lg px-3 text-xs font-semibold transition"
            :class="calendarView === 'week' ? 'bg-slate-950 text-white' : (isDark ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
            @click="calendarView = 'week'"
          >
            Minggu
          </button>
          <button
            type="button"
            class="h-9 rounded-lg px-3 text-xs font-semibold transition"
            :class="calendarView === 'day' ? 'bg-slate-950 text-white' : (isDark ? 'text-slate-300 hover:bg-slate-800' : 'text-slate-600 hover:bg-slate-100')"
            @click="calendarView = 'day'"
          >
            Hari
          </button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="inline-flex h-10 items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:bg-blue-100"
            :class="isDark ? '!border-blue-500/40 !bg-blue-500/15 !text-blue-200 hover:!bg-blue-500/25' : ''"
            @click="openBulkReminderDialog"
          >
            <PaperAirplaneIcon class="h-4 w-4" />
            Reminder Massal
          </button>

          <button
            type="button"
            class="inline-flex h-10 items-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800"
            @click="openCreateDialog"
          >
            <PlusIcon class="h-4 w-4" />
            Tambah Jadwal
          </button>
        </div>
      </div>

      <p v-if="message" class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
        {{ message }}
      </p>
      <p v-if="errorMessage" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        {{ errorMessage }}
      </p>
    </SurfaceCard>

    <div class="grid gap-6 xl:grid-cols-[1.8fr_1fr]">
      <SurfaceCard class="overflow-hidden p-0" :class="isDark ? '!border-slate-800' : ''">
        <template v-if="calendarView === 'month'">
          <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-100/90" :class="isDark ? '!border-slate-700 !bg-slate-800/70' : ''">
            <div
              v-for="dayName in weekdays"
              :key="dayName"
              class="px-2 py-2 text-center text-xs font-semibold uppercase tracking-wider"
              :class="isDark ? 'text-slate-300' : 'text-slate-600'"
            >
              {{ dayName }}
            </div>
          </div>

          <div class="grid grid-cols-7">
            <button
              v-for="cell in calendarCells"
              :key="cell.dateKey"
              type="button"
              class="min-h-[130px] border-b border-r border-slate-100 p-2 text-left align-top transition hover:bg-slate-50"
              :class="[
                !cell.inCurrentMonth ? 'bg-slate-50/70 text-slate-400' : 'bg-white text-slate-700',
                selectedDateKey === cell.dateKey ? 'ring-2 ring-inset ring-blue-300' : '',
                isDark ? '!border-slate-800 !bg-slate-900 !text-slate-200 hover:!bg-slate-800/70' : '',
              ]"
              @click="selectDay(cell.dateKey)"
            >
              <div class="flex items-center justify-between">
                <span
                  class="inline-flex h-7 min-w-7 items-center justify-center rounded-full px-1 text-xs font-semibold"
                  :class="cell.isToday ? 'bg-slate-950 text-white' : (isDark ? 'text-slate-300' : 'text-slate-600')"
                >
                  {{ cell.dateNumber }}
                </span>
                <span v-if="cell.events.length" class="text-[11px] font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                  {{ cell.events.length }} agenda
                </span>
              </div>

              <div class="mt-2 space-y-1">
                <button
                  v-for="event in cell.events.slice(0, 2)"
                  :key="`${cell.dateKey}-${event.id}`"
                  type="button"
                  class="w-full truncate rounded-md px-2 py-1 text-left text-[11px] font-semibold ring-1 ring-inset"
                  :style="eventColorStyle(event)"
                  @click.stop="openDetailDialog(event)"
                >
                  {{ formatTime(event.start) }} {{ event.title }}
                </button>

                <p v-if="cell.events.length > 2" class="truncate text-[11px] font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                  +{{ cell.events.length - 2 }} agenda lainnya
                </p>
              </div>
            </button>
          </div>
        </template>

        <template v-else-if="calendarView === 'week'">
          <div class="max-h-[680px] overflow-y-auto overflow-x-hidden p-4">
            <div class="grid grid-cols-1 gap-3">
              <article
                v-for="column in dayColumns"
                :key="`week-${column.dateKey}`"
                class="min-w-0 rounded-2xl border border-slate-200 bg-white p-3 transition"
                :class="[
                  column.isSelected ? 'ring-2 ring-inset ring-blue-300' : '',
                  isDark ? '!border-slate-700 !bg-slate-900' : '',
                ]"
              >
                <button
                  type="button"
                  class="w-full rounded-xl border border-transparent p-2 text-left transition hover:border-slate-200 hover:bg-slate-50"
                  :class="isDark ? 'hover:!border-slate-700 hover:!bg-slate-800/70' : ''"
                  @click="selectDay(column.dateKey)"
                >
                  <p class="text-[11px] font-semibold uppercase tracking-wider" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    {{ column.label }}
                  </p>
                  <p class="mt-1 text-3xl font-bold leading-none" :class="isDark ? 'text-slate-100' : 'text-slate-900'">
                    {{ column.dateNumber }}
                  </p>
                  <span
                    class="mt-2 inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold uppercase"
                    :class="column.isToday ? 'bg-slate-950 text-white' : (isDark ? 'bg-slate-700 text-slate-100' : 'bg-slate-100 text-slate-600')"
                  >
                    {{ column.events.length }} agenda
                  </span>
                </button>

                <div class="mt-3 space-y-2">
                  <div
                    v-if="!column.events.length"
                    class="rounded-xl border border-dashed border-slate-200 px-3 py-3 text-sm leading-5 text-slate-500"
                    :class="isDark ? '!border-slate-700 !text-slate-400' : ''"
                  >
                    Tidak ada agenda.
                  </div>

                  <button
                    v-for="event in column.events"
                    :key="`${column.dateKey}-${event.id}`"
                    type="button"
                    class="w-full rounded-lg px-2 py-1.5 text-left text-xs font-semibold ring-1 ring-inset break-words whitespace-normal"
                    :style="eventColorStyle(event)"
                    @click="openDetailDialog(event)"
                  >
                    {{ formatTime(event.start) }} {{ event.title }}
                  </button>
                </div>
              </article>
            </div>
          </div>
        </template>

        <template v-else>
          <div class="p-4">
            <article
              v-for="column in dayColumns"
              :key="`day-${column.dateKey}`"
              class="rounded-2xl border border-slate-200 bg-white p-4"
              :class="isDark ? '!border-slate-700 !bg-slate-900' : ''"
            >
              <button
                type="button"
                class="w-full rounded-xl border border-transparent p-2 text-left transition hover:border-slate-200 hover:bg-slate-50"
                :class="isDark ? 'hover:!border-slate-700 hover:!bg-slate-800/70' : ''"
                @click="selectDay(column.dateKey)"
              >
                <p class="text-sm font-semibold" :class="isDark ? 'text-slate-200' : 'text-slate-700'">
                  {{ column.label }}, {{ column.dateNumber }}
                </p>
                <p class="mt-1 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                  {{ column.events.length }} agenda
                </p>
              </button>

              <div class="mt-3 space-y-2">
                <div
                  v-if="!column.events.length"
                  class="rounded-xl border border-dashed border-slate-200 px-3 py-3 text-sm leading-5 text-slate-500"
                  :class="isDark ? '!border-slate-700 !text-slate-400' : ''"
                >
                  Tidak ada agenda.
                </div>

                <button
                  v-for="event in column.events"
                  :key="`${column.dateKey}-${event.id}`"
                  type="button"
                  class="w-full truncate rounded-lg px-3 py-2 text-left text-sm font-semibold ring-1 ring-inset"
                  :style="eventColorStyle(event)"
                  @click="openDetailDialog(event)"
                >
                  {{ formatTime(event.start) }} {{ event.title }}
                </button>
              </div>
            </article>
          </div>
        </template>
      </SurfaceCard>

      <SurfaceCard class="p-6" :class="isDark ? '!border-slate-800 !bg-slate-900' : ''">
        <p class="display-kicker">Agenda Harian</p>
        <h3 class="mt-2 text-2xl font-semibold" :class="isDark ? 'text-slate-100' : 'text-slate-900'">{{ selectedDateLabel }}</h3>

        <div v-if="loading" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600" :class="isDark ? '!border-slate-700 !bg-slate-800/70 !text-slate-300' : ''">
          Memuat agenda...
        </div>

        <div v-else-if="!selectedDateEvents.length" class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600" :class="isDark ? '!border-slate-700 !bg-slate-800/70 !text-slate-300' : ''">
          Belum ada agenda di tanggal ini.
        </div>

        <div v-else class="mt-4 space-y-3">
          <button
            v-for="event in selectedDateEvents"
            :key="`agenda-${event.id}`"
            type="button"
            class="w-full rounded-xl border border-slate-200 bg-white p-3 text-left transition hover:-translate-y-0.5 hover:shadow-sm"
            :class="isDark ? '!border-slate-700 !bg-slate-900 hover:!bg-slate-800/70' : ''"
            @click="openDetailDialog(event)"
          >
            <p class="text-sm font-semibold" :class="isDark ? 'text-slate-100' : 'text-slate-900'">{{ event.title }}</p>
            <p class="mt-1 text-xs" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
              {{ formatTime(event.start) }} - {{ formatTime(event.end) }}
            </p>
            <p class="mt-2 text-xs" :class="isDark ? 'text-slate-300' : 'text-slate-600'">{{ event.location || 'Lokasi belum ditentukan' }}</p>
          </button>
        </div>
      </SurfaceCard>
    </div>

    <Teleport to="body">
      <div v-if="createDialogOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/60" @click="closeCreateDialog" />
        <div class="relative z-10 flex max-h-[88vh] w-full max-w-xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl sm:p-5">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="display-kicker">Input Jadwal</p>
              <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ scheduleDialogTitle }}</h3>
            </div>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-600 transition hover:bg-slate-50"
              @click="closeCreateDialog"
            >
              <XMarkIcon class="h-4 w-4" />
            </button>
          </div>

          <div class="mt-3 overflow-y-auto pr-1">
            <div class="grid gap-2.5 sm:grid-cols-2">
              <label class="sm:col-span-2 flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Judul Acara</span>
              <input
                v-model="form.title"
                type="text"
                placeholder="Contoh: Signing Akta Jual Beli"
                class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </label>

            <label class="sm:col-span-2 flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Lokasi</span>
              <select
                v-model="form.location"
                class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              >
                <option v-for="item in LOCATION_OPTIONS" :key="item" :value="item">{{ item }}</option>
              </select>
            </label>

            <label v-if="form.location === 'Lainnya'" class="sm:col-span-2 flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Detail Lokasi</span>
              <input
                v-model="customLocation"
                type="text"
                placeholder="Contoh: Ruko Emerald Blok A2, Bogor"
                class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </label>

            <label class="sm:col-span-2 inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2">
              <input
                v-model="formRangeMode"
                type="checkbox"
                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              <span class="text-sm font-semibold text-slate-700">Buat jadwal rentang tanggal</span>
            </label>

            <template v-if="!formRangeMode">
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Mulai</span>
                <input
                  v-model="form.start_datetime"
                  type="datetime-local"
                  class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>

              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Selesai</span>
                <input
                  v-model="form.end_datetime"
                  type="datetime-local"
                  class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>
            </template>

            <template v-else>
              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Mulai</span>
                <input
                  v-model="rangeStartDate"
                  type="date"
                  class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>

              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Akhir</span>
                <input
                  v-model="rangeEndDate"
                  type="date"
                  class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>

              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Jam Mulai</span>
                <input
                  v-model="rangeStartTime"
                  type="time"
                  class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>

              <label class="flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Jam Akhir</span>
                <input
                  v-model="rangeEndTime"
                  type="time"
                  class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </label>
            </template>

            <label class="sm:col-span-2 flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Keterangan</span>
              <textarea
                v-model="form.description"
                rows="2"
                class="rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </label>

            <div class="sm:col-span-2 space-y-2">
              <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Asisten</p>

              <input
                v-model="userSearch"
                type="text"
                placeholder="Cari nama user..."
                class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />

              <div class="max-h-32 overflow-y-auto rounded-xl border border-slate-200 p-2">
                <p v-if="loadingUsers" class="px-2 py-1 text-sm text-slate-500">Memuat user...</p>
                <p v-else-if="!filteredUsers.length" class="px-2 py-1 text-sm text-slate-500">User tidak ditemukan.</p>

                <label
                  v-for="user in filteredUsers"
                  :key="`user-${user.id}`"
                  class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-700 transition hover:bg-slate-50"
                >
                  <input
                    type="checkbox"
                    class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                    :checked="hasUser(user.id)"
                    @change="toggleUser(user.id)"
                  />
                   <span class="min-w-0 flex-1 truncate">{{ user.nama_lengkap || user.name || user.id }}</span>
                   <span v-if="user.level_user" class="shrink-0 text-[10px] font-bold uppercase text-slate-400">{{ user.level_user }}</span>
                </label>
              </div>

              <div v-if="selectedUserNames.length" class="flex flex-wrap gap-1.5">
                <span
                  v-for="name in selectedUserNames"
                  :key="`selected-${name}`"
                  class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600"
                >
                  {{ name }}
                </span>
              </div>
            </div>
            </div>
          </div>

          <div class="mt-4 flex justify-end gap-2 border-t border-slate-100 pt-3">
            <button
              type="button"
              class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="closeCreateDialog"
            >
              Batal
            </button>
            <button
              type="button"
              class="h-10 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="saving"
              @click="saveSchedule"
            >
              {{ scheduleDialogSubmitLabel }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="bulkReminderDialogOpen" class="fixed inset-0 z-[55] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/65" @click="closeBulkReminderDialog" />
        <div class="relative z-10 w-full max-w-md rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="display-kicker">WhatsApp Reminder</p>
              <h3 class="mt-2 text-xl font-semibold text-slate-900">Kirim Reminder Massal</h3>
              <p class="mt-1 text-sm text-slate-500">
                Kirim reminder untuk agenda sesuai periode yang dipilih.
              </p>
            </div>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-600 transition hover:bg-slate-50"
              @click="closeBulkReminderDialog"
            >
              <XMarkIcon class="h-4 w-4" />
            </button>
          </div>

          <div class="mt-4 grid gap-3">
            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Periode</span>
              <select
                v-model="bulkReminderForm.timeframe"
                class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              >
                <option value="daily">Harian</option>
                <option value="weekly">Mingguan</option>
                <option value="monthly">Bulanan</option>
              </select>
            </label>

            <label class="flex flex-col gap-1.5">
              <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Tanggal Dasar</span>
              <input
                v-model="bulkReminderForm.base_date"
                type="date"
                class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
              />
            </label>
          </div>

          <div class="mt-5 flex justify-end gap-2 border-t border-slate-100 pt-3">
            <button
              type="button"
              class="h-10 rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              :disabled="sendingBulkReminder"
              @click="closeBulkReminderDialog"
            >
              Batal
            </button>
            <button
              type="button"
              class="inline-flex h-10 items-center gap-2 rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="sendingBulkReminder"
              @click="sendBulkReminder"
            >
              <PaperAirplaneIcon class="h-4 w-4" />
              {{ sendingBulkReminder ? 'Mengirim...' : 'Kirim Reminder' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="detailDialogOpen && selectedEvent" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <button type="button" class="absolute inset-0 bg-slate-900/65" @click="closeDetailDialog" />
        <div class="relative z-10 w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl">
          <div class="flex items-start justify-between gap-3">
            <h3 class="text-xl font-semibold text-slate-900">{{ selectedEvent.title }}</h3>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-300 text-slate-600 transition hover:bg-slate-50"
              @click="closeDetailDialog"
            >
              <XMarkIcon class="h-4 w-4" />
            </button>
          </div>

          <div class="mt-4 space-y-3 text-sm text-slate-700">
            <p class="flex items-start gap-2">
              <ClockIcon class="mt-0.5 h-4 w-4 text-slate-500" />
              <span>{{ formatDateTime(selectedEvent.start) }} - {{ formatDateTime(selectedEvent.end) }}</span>
            </p>

            <p class="flex items-start gap-2">
              <MapPinIcon class="mt-0.5 h-4 w-4 text-slate-500" />
              <span>{{ selectedEvent.location || 'Lokasi belum ditentukan' }}</span>
            </p>

            <p v-if="selectedEvent.description" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
              {{ selectedEvent.description }}
            </p>

            <div class="flex items-start gap-2">
              <UserGroupIcon class="mt-0.5 h-4 w-4 text-slate-500" />
              <div class="space-y-1">
                <p v-if="selectedEvent.users.length">
                  <span
                    v-for="user in selectedEvent.users"
                    :key="`evt-user-${selectedEvent.id}-${user.id}`"
                    class="mr-1 inline-flex rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-600"
                  >
                    {{ user.nama_lengkap || user.name || user.id }}
                  </span>
                </p>
                <p v-else class="text-xs text-slate-500">Tidak ada asisten terlibat.</p>
              </div>
            </div>
          </div>

          <div class="mt-5 border-t border-slate-100 pt-3">
            <p class="mb-3 text-xs text-slate-500">
              Sumber: {{ selectedEvent.source === 'events' ? 'Events API' : 'Legacy API' }}
            </p>

            <div v-if="selectedEvent.source === 'events'" class="grid grid-cols-1 gap-2 sm:grid-cols-3">
              <button
                type="button"
                class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="deleting || sendingReminder"
                @click="openEditDialog"
              >
                <PencilSquareIcon class="h-4 w-4" />
                Edit Manual
              </button>

              <button
                type="button"
                class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="sendingReminder"
                @click="sendReminder"
              >
                <PaperAirplaneIcon class="h-4 w-4" />
                {{ sendingReminder ? 'Mengirim...' : 'Send Reminder WA' }}
              </button>

              <button
                type="button"
                class="inline-flex h-10 w-full items-center justify-center gap-2 whitespace-nowrap rounded-xl border border-red-200 bg-red-50 px-4 text-sm font-semibold text-red-700 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="deleting || sendingReminder"
                @click="deleteSchedule"
              >
                <TrashIcon class="h-4 w-4" />
                {{ deleting ? 'Menghapus...' : 'Hapus Jadwal' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
