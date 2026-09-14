import { computed } from 'vue'
import { useRuntimeConfig } from '#imports'
import { useApi } from '~/composables/useApi'
import { useSession } from '~/composables/useSession'

type Primitive = string | number | boolean | null | undefined
type QueryParams = Record<string, Primitive>
type BodyPayload = BodyInit | Record<string, unknown>

const cleanPath = (path: string) => path.replace(/^\/+/, '')

export function useLegacyBusinessService() {
  const { request, withBase } = useApi()
  const runtimeConfig = useRuntimeConfig()
  const { token } = useSession()

  const assetBase = computed(() => {
    const explicit = String((runtimeConfig.public as { assetBase?: string }).assetBase || '').trim()
    if (explicit) {
      return explicit.replace(/\/$/, '')
    }

    return String((runtimeConfig.public as { apiBase: string }).apiBase)
      .replace(/\/api\/?$/, '')
      .replace(/\/$/, '')
  })

  const get = <T>(path: string, query?: QueryParams, auth = true) =>
    request<T>(path, {
      method: 'GET',
      auth,
      ...(query ? { query } : {}),
    })

  const post = <T>(path: string, body?: BodyPayload, auth = true) =>
    request<T>(path, {
      method: 'POST',
      auth,
      ...(body !== undefined ? { body } : {}),
    })

  const put = <T>(path: string, body?: BodyPayload, auth = true) =>
    request<T>(path, {
      method: 'PUT',
      auth,
      ...(body !== undefined ? { body } : {}),
    })

  const patch = <T>(path: string, body?: BodyPayload, auth = true) =>
    request<T>(path, {
      method: 'PATCH',
      auth,
      ...(body !== undefined ? { body } : {}),
    })

  const del = <T>(path: string, query?: QueryParams, auth = true) =>
    request<T>(path, {
      method: 'DELETE',
      auth,
      ...(query ? { query } : {}),
    })

  const postForm = <T>(path: string, formData: FormData, auth = true) =>
    request<T>(path, {
      method: 'POST',
      auth,
      body: formData,
    })

  const putForm = <T>(path: string, formData: FormData, auth = true) =>
    request<T>(path, {
      method: 'PUT',
      auth,
      body: formData,
    })

  const buildAssetUrl = (path: string) => `${assetBase.value}/${cleanPath(path)}`

  const downloadBlob = async (path: string, auth = true) => {
    const headers: Record<string, string> = {
      Accept: 'application/octet-stream',
    }

    if (auth && token.value) {
      headers.Authorization = `Bearer ${token.value}`
    }

    const response = await fetch(withBase(path), {
      method: 'GET',
      headers,
    })

    if (!response.ok) {
      let message = 'Gagal mengunduh file.'

      try {
        const payload = await response.json() as { message?: string }
        if (payload?.message) {
          message = payload.message
        }
      } catch {
        // noop
      }

      throw { data: { message }, status: response.status }
    }

    return response.blob()
  }

  const postFormBlob = async (path: string, formData: FormData, auth = true) => {
    const headers: Record<string, string> = {
      Accept: 'application/pdf,application/octet-stream',
    }

    if (auth && token.value) {
      headers.Authorization = `Bearer ${token.value}`
    }

    const response = await fetch(withBase(path), {
      method: 'POST',
      headers,
      body: formData,
    })

    if (!response.ok) {
      let message = 'Gagal memproses file.'

      try {
        const payload = await response.json() as { message?: string; detail?: string }
        message = payload.message || payload.detail || message
      } catch {
        const text = await response.text().catch(() => '')
        if (text) message = text
      }

      throw { data: { message }, status: response.status }
    }

    return {
      blob: await response.blob(),
      filename: response.headers.get('Content-Disposition') || '',
      segments: response.headers.get('X-Garis-Segments') || '',
    }
  }

  const buildReportUrl = (reportPath: string, query?: QueryParams) => {
    const rawUrl = buildAssetUrl(`api/${cleanPath(reportPath).replace(/^api\//, '')}`)
    const [baseUrl, existingQuery = ''] = rawUrl.split('?', 2)
    const searchParams = new URLSearchParams(existingQuery)

    if (query) {
      Object.entries(query).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
          searchParams.set(key, String(value))
        }
      })
    }

    const queryString = searchParams.toString()
    return queryString ? `${baseUrl}?${queryString}` : baseUrl
  }

  const auth = {
    login: (payload: BodyPayload) => post('/auth/login', payload, false),
    SignIn: (payload: BodyPayload) => post('/auth/login', payload, false),
    async SignInGoogle(payload: BodyPayload) {
      try {
        return await post('/auth/google', payload, false)
      } catch {
        return post('/SignInGoogle', payload, false)
      }
    },
    async SignOut(payload?: BodyPayload) {
      try {
        return await post('/auth/logout', payload)
      } catch {
        return post('/auth/SignOut', payload)
      }
    },
    getAuthUser: () => get('/auth/user'),
    CheckWhatsappNumber: (phone: string) => get(`/auth/whatsapp/check/${encodeURIComponent(phone)}`, undefined, false),
    RequestWhatsappOtp: (payload: BodyPayload) => post('/auth/whatsapp/request-otp', payload, false),
    SignInWhatsapp: (payload: BodyPayload) => post('/auth/whatsapp/verify', payload, false),
    DataUser: () => get('/auth/user/DataUser'),
    SaveAccount: (payload: BodyPayload) => post('/auth/user/SaveAccount', payload),
    DeleteAccount: (payload: BodyPayload) => post('/auth/user/DeleteAccount', payload),
    UpdatePassword: (payload: BodyPayload) => post('/auth/user/UpdatePassword', payload),
    ResetUserPassword: (payload: BodyPayload) => post('/auth/user/ResetUserPassword', payload),
    UploadFoto: (formData: FormData) => postForm('/auth/user/UploadFoto', formData),
  }

  const dashboard = {
    getDashboard: () => get('/auth/getDashboard'),
    getPemenang: (query?: QueryParams) => get('/auth/getPemenang', query),
    getTotalPekerjaan: (query?: QueryParams) => get('/auth/getTotalPekerjaan', query),
    getGrafik: (query?: QueryParams) => get('/auth/getGrafik', query),
    getDaftarAsisten: () => get('/auth/getDaftarAsisten'),
  }

  const master = {
    getDataLayanan: () => get('/auth/getDataLayanan'),
    SimpanLayanan: (payload: BodyPayload) => post('/auth/SimpanLayanan', payload),
    DeleteLayanan: (payload: BodyPayload) => post('/auth/DeleteLayanan', payload),
    getDataDokumen: () => get('/auth/getDataDokumen'),
    getStandarDokumen: (payload: BodyPayload) => post('/auth/getStandarDokumen', payload),
    SimpanDokumenStandar: (payload: BodyPayload) => post('/auth/SimpanDokumenStandar', payload),
    DeleteDokumenStandar: (payload: BodyPayload) => post('/auth/DeleteDokumenStandar', payload),
  }

  const settings = {
    getReportSettings: () => get('/auth/report-settings'),
    saveReportSettings: (payload: BodyPayload) => put('/auth/report-settings', payload),
    uploadReportLogo: (formData: FormData) => postForm('/auth/report-settings/logo', formData),
    getDatabaseBackups: () => get('/auth/database-backups'),
    runDatabaseBackup: (payload?: BodyPayload) => post('/auth/database-backups', payload),
    pruneOldDatabaseBackups: () => del('/auth/database-backups/old'),
    downloadDatabaseBackup: (fileName: string) => downloadBlob(`/auth/database-backups/download/${encodeURIComponent(fileName)}`),
    getHaStatus: () => get('/auth/ha-status'),
    getHaWhatsapp: () => get('/auth/ha-whatsapp'),
    saveHaWhatsappSettings: (payload: BodyPayload) => put('/auth/ha-whatsapp/settings', payload),
    syncHaWhatsappWebhook: () => post('/auth/ha-whatsapp/webhook/sync'),
    startHaWhatsapp: () => post('/auth/ha-whatsapp/start'),
    stopHaWhatsapp: () => post('/auth/ha-whatsapp/stop'),
    getHaKtpOcr: () => get('/auth/ha-ktp-ocr'),
    saveHaKtpOcrSettings: (payload: BodyPayload) => put('/auth/ha-ktp-ocr/settings', payload),
    startHaKtpOcr: () => post('/auth/ha-ktp-ocr/start'),
    stopHaKtpOcr: () => post('/auth/ha-ktp-ocr/stop'),
    restartHaKtpOcr: () => post('/auth/ha-ktp-ocr/restart'),
    getGoogleCalendarStatus: () => get('/auth/google-calendar/status'),
    saveGoogleCalendarSettings: (payload: BodyPayload) => put('/auth/google-calendar/settings', payload),
    syncGoogleCalendar: (payload?: BodyPayload) => post('/auth/google-calendar/sync', payload),
    clearGoogleCalendarErrors: () => post('/auth/google-calendar/clear-errors'),
  }

  const client = {
    getDataClient: (payload: BodyPayload) => post('/auth/getDataClient', payload),
    checkClientIdentity: (payload: BodyPayload) => post('/auth/checkClientIdentity', payload),
    SimpanClientBaru: (payload: BodyPayload) => post('/auth/SimpanClientBaru', payload),
    getDataDokumenClient: (payload: BodyPayload) => post('/auth/getDataDokumenClient', payload),
    UploadDokumenClient: (formData: FormData) => postForm('/auth/UploadDokumenClient', formData),
    UpdateDokumenClient: (payload: BodyPayload) => post('/auth/UpdateDokumenClient', payload),
    DeleteDokumenClient: (payload: BodyPayload) => post('/auth/DeleteDokumenClient', payload),
    extractKtpOcr: (formData: FormData) => postForm('/auth/ktp-ocr/extract', formData),
    saveKtpOcrClient: (formData: FormData) => postForm('/auth/ktp-ocr/save-client', formData),
  }

  const bantek = {
    list: () => get('/auth/bantek'),
    create: (payload: BodyPayload) => post('/auth/bantek/create', payload),
    addClients: (payload: BodyPayload) => post('/auth/bantek/add-clients', payload),
    removeClient: (payload: BodyPayload) => post('/auth/bantek/remove-client', payload),
    CetakLabelBantek: (noBantek: string) => buildReportUrl('CetakLabelBantek', { no_bantek: noBantek }),
  }

  const order = {
    getDaftarAkta: (payload: BodyPayload) => post('/auth/getDaftarAkta', payload),
    getDaftarClient: (payload: BodyPayload) => post('/auth/getDaftarClient', payload),
    getBukuPesanan: (payload: BodyPayload) => post('/auth/getBukuPesanan', payload),
    SimpanPesanan: (payload: BodyPayload) => post('/auth/SimpanPesanan', payload),
    SimpanDetailOrder: (payload: BodyPayload) => post('/auth/SimpanDetailOrder', payload),
    UpdateStatusInvoice: (payload: BodyPayload) => post('/auth/UpdateStatusInvoice', payload),
    CariTagihan: (payload: BodyPayload) => post('/auth/CariTagihan', payload),
    getBukuInvoiceTax: () => get('/auth/getBukuInvoiceTax'),
    SimpanNomorInvoiceTax: (payload: BodyPayload) => post('/auth/SimpanNomorInvoiceTax', payload),
    EditInvoiceTax: (payload: BodyPayload) => post('/auth/EditInvoiceTax', payload),
  }

  const bukuNotaris = {
    CekTglAktaNotarisTerakhir: () => get('/auth/CekTglAktaNotarisTerakhir'),
    getBukuNotaris: (payload: BodyPayload) => post('/auth/getBukuNotaris', payload),
    getBukuLamaNotaris: () => get('/auth/getBukuLamaNotaris'),
    SimpanNomorNotaris: (payload: BodyPayload) => post('/auth/SimpanNomorNotaris', payload),
    PreviewAktaNotarisMassal: (payload: BodyPayload) => post('/auth/PreviewAktaNotarisMassal', payload),
    SimpanAktaNotarisMassal: (payload: BodyPayload) => post('/auth/SimpanAktaNotarisMassal', payload),
    SimpanNomorNotarisLama: (payload: BodyPayload) => post('/auth/SimpanNomorNotarisLama', payload),
    DeleteNomorNotaris: (payload: BodyPayload) => post('/auth/DeleteNomorNotaris', payload),
    EditAktaNotaris: (payload: BodyPayload) => post('/auth/EditAktaNotaris', payload),
    UploadExcelNotaris: (formData: FormData) => postForm('/auth/UploadExcelNotaris', formData),
    SimpanJadwalNotaris: (payload: BodyPayload) => post('/auth/SimpanJadwalNotaris', payload),
  }

  const bukuLegalisasi = {
    getBukuLegalisasi: (payload: BodyPayload) => post('/auth/getBukuLegalisasi', payload),
    SimpanNomorLegalisasi: (payload: BodyPayload) => post('/auth/SimpanNomorLegalisasi', payload),
    DeleteNomorLegalisasi: (payload: BodyPayload) => post('/auth/DeleteNomorLegalisasi', payload),
    EditLegalisasi: (payload: BodyPayload) => post('/auth/EditLegalisasi', payload),
    UploadExcelLegalisasi: (formData: FormData) => postForm('/auth/UploadExcelLegalisasi', formData),
  }

  const bukuWarmerking = {
    getBukuWarmerking: (payload: BodyPayload) => post('/auth/getBukuWarmerking', payload),
    SimpanNomorWarmerking: (payload: BodyPayload) => post('/auth/SimpanNomorWarmerking', payload),
    DeleteNomorWarmerking: (payload: BodyPayload) => post('/auth/DeleteNomorWarmerking', payload),
    EditWarmerking: (payload: BodyPayload) => post('/auth/EditWarmerking', payload),
    UploadExcelWarmerking: (formData: FormData) => postForm('/auth/UploadExcelWarmerking', formData),
  }

  const bukuPpat = {
    getBukuPPAT: (payload: BodyPayload) => post('/auth/getBukuPPAT', payload),
    SimpanNomorPPAT: (payload: BodyPayload) => post('/auth/SimpanNomorPPAT', payload),
    DeleteNomorPPAT: (payload: BodyPayload) => post('/auth/DeleteNomorPPAT', payload),
    EditAktaPPAT: (payload: BodyPayload) => post('/auth/EditAktaPPAT', payload),
    UploadExcelPPAT: (formData: FormData) => postForm('/auth/UploadExcelPPAT', formData),
  }

  const bukuRekanan = {
    getBukurekanan: (payload: BodyPayload) => post('/auth/getBukurekanan', payload),
    UploadExcelrekanan: (formData: FormData) => postForm('/auth/UploadExcelrekanan', formData),
    SimpanNomorRekanan: (payload: BodyPayload) => post('/auth/SimpanNomorPPAT', payload),
    EditAktaRekanan: (payload: BodyPayload) => post('/auth/EditAktaPPAT', payload),
    getMaster: (query?: QueryParams) => get('/auth/ppat-rekanan/master', query),
    createMaster: (payload: BodyPayload) => post('/auth/ppat-rekanan/master', payload),
    updateMaster: (id: string | number, payload: BodyPayload) => put(`/auth/ppat-rekanan/master/${id}`, payload),
    deleteMaster: (id: string | number) => del(`/auth/ppat-rekanan/master/${id}`),
    getKeluar: (query?: QueryParams) => get('/auth/ppat-rekanan/keluar', query),
    markKeluar: (payload: BodyPayload) => post('/auth/ppat-rekanan/keluar/mark', payload),
    unmarkKeluar: (payload: BodyPayload) => post('/auth/ppat-rekanan/keluar/unmark', payload),
    getKedalam: (query?: QueryParams) => get('/auth/ppat-rekanan/kedalam', query),
    createKedalam: (payload: BodyPayload) => post('/auth/ppat-rekanan/kedalam', payload),
    updateKedalam: (id: string | number, payload: BodyPayload) => put(`/auth/ppat-rekanan/kedalam/${id}`, payload),
    deleteKedalam: (id: string | number) => del(`/auth/ppat-rekanan/kedalam/${id}`),
  }

  const surat = {
    getBukuSuratNotaris: (payload: BodyPayload) => post('/auth/getBukuSuratNotaris', payload),
    getBukuSuratPPAT: (payload: BodyPayload) => post('/auth/getBukuSuratPPAT', payload),
    SimpanNomorSuratNotaris: (payload: BodyPayload) => post('/auth/SimpanNomorSuratNotaris', payload),
    SimpanNomorSuratPPAT: (payload: BodyPayload) => post('/auth/SimpanNomorSuratPPAT', payload),
    EditSuratNotaris: (payload: BodyPayload) => post('/auth/EditSuratNotaris', payload),
    EditSuratPPAT: (payload: BodyPayload) => post('/auth/EditSuratPPAT', payload),
    UploadSuratNotaris: (formData: FormData) => postForm('/auth/UploadSuratNotaris', formData),
    UploadSuratPPAT: (formData: FormData) => postForm('/auth/UploadSuratPPAT', formData),
    DeleteSuratNotaris: (payload: BodyPayload) => post('/auth/DeleteSuratNotaris', payload),
    DeleteSuratPPAT: (payload: BodyPayload) => post('/auth/DeleteSuratPPAT', payload),
    DeleteNomorSuratNotaris: (payload: BodyPayload) => post('/auth/DeleteNomorSuratNotaris', payload),
    DeleteNomorSuratPPAT: (payload: BodyPayload) => post('/auth/DeleteNomorSuratPPAT', payload),
  }

  const dokumen = {
    getStandarDokumen: (payload: BodyPayload) => post('/auth/getStandarDokumen', payload),

    UploadDokumenNotaris: (formData: FormData) => postForm('/auth/UploadDokumenNotaris', formData),
    UpdateDokumenNotaris: (payload: BodyPayload) => post('/auth/UpdateDokumenNotaris', payload),
    DeleteDokumenNotaris: (payload: BodyPayload) => post('/auth/DeleteDokumenNotaris', payload),
    StandarDokumenNotaris: (payload: BodyPayload) => post('/auth/StandarDokumenNotaris', payload),

    UploadDokumenWarmerking: (formData: FormData) => postForm('/auth/UploadDokumenWarmerking', formData),
    UpdateDokumenWarmerking: (payload: BodyPayload) => post('/auth/UpdateDokumenWarmerking', payload),
    DeleteDokumenWarmerking: (payload: BodyPayload) => post('/auth/DeleteDokumenWarmerking', payload),
    StandarDokumenWarmerkings: (payload: BodyPayload) => post('/auth/StandarDokumenWarmerkings', payload),

    UploadDokumenLegalisasi: (formData: FormData) => postForm('/auth/UploadDokumenLegalisasi', formData),
    UpdateDokumenLegalisasi: (payload: BodyPayload) => post('/auth/UpdateDokumenLegalisasi', payload),
    DeleteDokumenLegalisasi: (payload: BodyPayload) => post('/auth/DeleteDokumenLegalisasi', payload),
    StandarDokumenLegalisasis: (payload: BodyPayload) => post('/auth/StandarDokumenLegalisasis', payload),

    UploadDokumenPPAT: (formData: FormData) => postForm('/auth/UploadDokumenPPAT', formData),
    UpdateDokumenPPAT: (payload: BodyPayload) => post('/auth/UpdateDokumenPPAT', payload),
    DeleteDokumenPPAT: (payload: BodyPayload) => post('/auth/DeleteDokumenPPAT', payload),
    StandarDokumenPPAT: (payload: BodyPayload) => post('/auth/StandarDokumenPPAT', payload),
  }

  const pencarian = {
    SearchData: (payload: BodyPayload) => post('/auth/SearchData', payload),
    getPencarianBukuNotaris: (payload: BodyPayload) => post('/auth/getPencarianBukuNotaris', payload),
    getPencarianBukuWarmerking: (payload: BodyPayload) => post('/auth/getPencarianBukuWarmerking', payload),
    getPencarianBukuLegalisasi: (payload: BodyPayload) => post('/auth/getPencarianBukuLegalisasi', payload),
    getPencarianBukuPPAT: (payload: BodyPayload) => post('/auth/getPencarianBukuPPAT', payload),
    getDokumenClient: (payload: BodyPayload) => post('/auth/getDokumenClient', payload),
  }

  const jadwal = {
    getJadwalNotaris: (payload: BodyPayload) => post('/auth/getJadwalNotaris', payload),
    SimpanJadwal: (payload: BodyPayload) => post('/auth/SimpanJadwal', payload),
  }

  const events = {
    getScheduleParticipants: () => get('/schedule-participants'),
    list: () => get('/events'),
    show: (id: string | number) => get(`/events/${id}`),
    create: (payload: BodyPayload) => post('/events', payload),
    sendReminder: (id: string | number) => post(`/events/${id}/send-reminder`),
    sendReminderBulk: (payload: BodyPayload) => post('/events/send-reminder-bulk', payload),
    update: (id: string | number, payload: BodyPayload) => put(`/events/${id}`, payload),
    patch: (id: string | number, payload: BodyPayload) => patch(`/events/${id}`, payload),
    remove: (id: string | number) => del(`/events/${id}`),
    getScheduleForChatbot: (query?: QueryParams) => get('/get-user-schedule', query, false),
    createEventFromChatbot: (payload: BodyPayload) => post('/create-event-from-chat', payload, false),
  }

  const chat = {
    getContacts: () => get('/auth/chat/contacts'),
    getMessages: (query: QueryParams) => get('/auth/chat/messages', query),
    sendMessage: (payload: BodyPayload) => post('/auth/chat/messages', payload),
  }

  const peminjamanMinuta = {
    getPeminjamanMinuta: (query?: QueryParams) => get('/auth/getPeminjamanMinuta', query),
    SimpanPeminjamanMinuta: (payload: BodyPayload) => post('/auth/SimpanPeminjamanMinuta', payload),
    UpdatePeminjamanMinuta: (id: string | number, payload: BodyPayload) => post(`/auth/UpdatePeminjamanMinuta/${id}`, payload),
    KembalikanPeminjamanMinuta: (id: string | number) => post(`/auth/KembalikanPeminjamanMinuta/${id}`),
    PerpanjangPeminjamanMinuta: (id: string | number, payload: BodyPayload) => post(`/auth/PerpanjangPeminjamanMinuta/${id}`, payload),
    TogglePeminjamanMinuta: (id: string | number) => post(`/auth/TogglePeminjamanMinuta/${id}`),
  }

  const adminWork = {
    getReportoriumJobs: (query?: QueryParams) => get('/auth/admin-work/reportorium-jobs', query),
    getNumberAnomalies: (query?: QueryParams) => get('/auth/admin-work/number-anomalies', query),
    getNumberAnomalyRecord: (query?: QueryParams) => get('/auth/admin-work/number-anomalies/record', query),
    updateNumberAnomalyRecord: (payload: BodyPayload) => put('/auth/admin-work/number-anomalies/record', payload),
    deleteNumberAnomaly: (query?: QueryParams) => del('/auth/admin-work/number-anomalies', query),
    getAsisten: () => get('/auth/admin-work/asisten'),
    reassign: (payload: BodyPayload) => post('/auth/admin-work/reassign', payload),
  }

  const documentAccess = {
    requestDownload: (payload: BodyPayload) => post('/auth/document-access/request-download', payload),
    listRequests: (query?: QueryParams) => get('/auth/document-access/requests', query),
    listMyRequests: (query?: QueryParams) => get('/auth/document-access/requests', { ...(query || {}), mine: 1 }),
    decide: (id: string | number, payload: BodyPayload) => post(`/auth/document-access/requests/${id}/decision`, payload),
    bulkDecide: (payload: BodyPayload) => post('/auth/document-access/requests/bulk-decision', payload),
    downloadUrl: (id: string | number) => withBase(`/auth/document-access/download/${id}`),
    downloadBulkUrl: (ids: Array<string | number>) => withBase(`/auth/document-access/download-bulk?ids=${encodeURIComponent(ids.join(','))}`),
  }

  const scannedDocuments = {
    list: (query?: QueryParams) => get('/auth/scanned-documents', query),
    postingTargets: (query?: QueryParams) => get('/auth/scanned-documents/posting-targets', query),
    postToModule: (id: string | number, payload: BodyPayload) => post(`/auth/scanned-documents/${id}/post`, payload),
    downloadUrl: (id: string | number) => withBase(`/auth/scanned-documents/${id}/download`),
    downloadBlob: (id: string | number) => downloadBlob(`/auth/scanned-documents/${id}/download`),
    delete: (id: string | number) => del(`/auth/scanned-documents/${id}`),
  }

  const tandaTerima = {
    list: (query?: QueryParams) => get('/auth/tanda-terima', query),
    show: (id: string | number) => get(`/auth/tanda-terima/${id}`),
    create: (payload: BodyPayload) => post('/auth/tanda-terima', payload),
    update: (id: string | number, payload: BodyPayload) => put(`/auth/tanda-terima/${id}`, payload),
    patch: (id: string | number, payload: BodyPayload) => patch(`/auth/tanda-terima/${id}`, payload),
    delete: (id: string | number) => del(`/auth/tanda-terima/${id}`),
    getMasuk: (date?: string) => get('/auth/tanda-terima', { date, status: 'Masuk' }),
    getKeluar: (date?: string) => get('/auth/tanda-terima', { date, status: 'Keluar' }),
    UploadTandaTerima: (formData: FormData) => postForm('/auth/UploadTandaTerima', formData),
    DeleteTandaTerima: (payload: BodyPayload) => post('/auth/DeleteTandaTerima', payload),
  }

  const garisAkta = {
    process: (formData: FormData) => postFormBlob('/auth/garis-otomatis-akta/process', formData),
  }

  const workItems = {
    list: (query?: QueryParams) => get('/auth/work-items', query),
    show: (id: string | number) => get(`/auth/work-items/${id}`),
    create: (payload: BodyPayload) => post('/auth/work-items', payload),
    update: (id: string | number, payload: BodyPayload) => put(`/auth/work-items/${id}`, payload),
    transition: (id: string | number, payload: BodyPayload) => post(`/auth/work-items/${id}/transition`, payload),
    options: (query?: QueryParams) => get('/auth/work-items/options', query),
    reportoriumOptions: (query?: QueryParams) => get('/auth/work-items/reportorium-options', query),
    addChecklist: (id: string | number, payload: BodyPayload) => post(`/auth/work-items/${id}/checklists`, payload),
    toggleChecklist: (id: string | number, checklistId: string | number) => patch(`/auth/work-items/${id}/checklists/${checklistId}`, {}),
    addLink: (id: string | number, payload: BodyPayload) => post(`/auth/work-items/${id}/links`, payload),
    removeLink: (id: string | number, linkId: string | number) => del(`/auth/work-items/${id}/links/${linkId}`),
    addCost: (id: string | number, payload: BodyPayload) => post(`/auth/work-items/${id}/costs`, payload),
    createInvoice: (id: string | number, payload: BodyPayload) => post(`/auth/work-items/${id}/invoice`, payload),
  }

  const reports = {
    CetakLaporanNotaris: (date?: string) => buildReportUrl('CetakLaporanNotaris', { date }),
    CetakLaporanLegalisasi: (date?: string) => buildReportUrl('CetakLaporanLegalisasi', { date }),
    CetakLaporanWarmerking: (date?: string) => buildReportUrl('CetakLaporanWarmerking', { date }),
    CetakLaporanPPAT: (date?: string) => buildReportUrl('CetakLaporanPPAT', { date }),
    CetakLaporanrekanan: (date?: string) => buildReportUrl('CetakLaporanrekanan', { date }),
    CetakInvoice: (id?: string | number) => buildReportUrl('CetakInvoice', { id }),
    CetakLabelBantek: (noBantek?: string) => buildReportUrl('CetakLabelBantek', { no_bantek: noBantek }),
    CetakTandaTerima: (id?: string | number) => buildReportUrl('CetakTandaTerima', { id }),
  }

  const assets = {
    foto: (fileName: string) => buildAssetUrl(`foto/${cleanPath(fileName)}`),
    berkasClient: (folderName: string, fileName: string) =>
      buildAssetUrl(`berkasclient/${cleanPath(folderName)}/${cleanPath(fileName)}`),
    berkasNotaris: (fileName: string) => buildAssetUrl(`berkasnotaris/${cleanPath(fileName)}`),
    berkasLegalisasi: (fileName: string) => buildAssetUrl(`berkaslegalisasis/${cleanPath(fileName)}`),
    berkasWarmerking: (fileName: string) => buildAssetUrl(`berkaswarmerkings/${cleanPath(fileName)}`),
    berkasPpat: (fileName: string) => buildAssetUrl(`berkasppat/${cleanPath(fileName)}`),
    suratNotaris: (fileName: string) => buildAssetUrl(`suratnotaris/${cleanPath(fileName)}`),
    suratPpat: (fileName: string) => buildAssetUrl(`suratppats/${cleanPath(fileName)}`),
    api: (path: string) => withBase(path),
  }

  return {
    auth,
    dashboard,
    master,
    settings,
    client,
    bantek,
    order,
    bukuNotaris,
    bukuLegalisasi,
    bukuWarmerking,
    bukuPpat,
    bukuRekanan,
    surat,
    dokumen,
    pencarian,
    jadwal,
    events,
    chat,
    peminjamanMinuta,
    adminWork,
    documentAccess,
    scannedDocuments,
    tandaTerima,
    garisAkta,
    workItems,
    reports,
    assets,
  }
}
