import { shallowRef } from 'vue'

export type DialogOptions = { title?: string; confirmText?: string; cancelText?: string; danger?: boolean }
export type AppDialogRequest = Required<DialogOptions> & { id: number; message: string; kind: 'confirm' | 'notice' }

export function createAppDialog() {
  const state = shallowRef<AppDialogRequest | null>(null)
  const queue: Array<{ request: AppDialogRequest; finish: (accepted: boolean) => void }> = []
  let active: (typeof queue)[number] | undefined
  let sequence = 0
  const advance = () => { active = queue.shift(); state.value = active?.request || null }
  const request = (message: string, kind: 'confirm' | 'notice', options: DialogOptions = {}) => {
    if ([active, ...queue].some(item => item?.request.message === message && item.request.kind === kind)) return Promise.resolve(false)
    return new Promise<boolean>(finish => {
      const danger = options.danger ?? /hapus|nonaktifkan|lepaskan/i.test(message)
      queue.push({ request: { id: ++sequence, message, kind, danger,
        title: options.title || (kind === 'notice' ? 'Informasi' : danger ? 'Konfirmasi penghapusan' : 'Konfirmasi tindakan'),
        confirmText: options.confirmText || (kind === 'notice' ? 'Mengerti' : danger ? 'Ya, hapus' : 'Lanjutkan'),
        cancelText: options.cancelText || 'Batal',
      }, finish })
      if (!active) advance()
    })
  }
  const resolve = (accepted: boolean) => {
    const current = active
    if (!current) return
    active = undefined
    state.value = null
    current.finish(accepted)
    advance()
  }
  const cancelAll = () => {
    const pending = [active, ...queue]
    active = undefined; queue.length = 0; state.value = null
    for (const item of pending) item?.finish(false)
  }
  return { state, resolve, cancelAll,
    confirmAction: (message: string, options?: DialogOptions) => request(message, 'confirm', options),
    showNotice: async (message: string, options?: DialogOptions) => { await request(message, 'notice', options) },
  }
}
