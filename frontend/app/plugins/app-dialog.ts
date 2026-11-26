import { createAppDialog } from '~/utils/appDialog'

export default defineNuxtPlugin(nuxtApp => {
  const appDialog = createAppDialog()
  nuxtApp.hook('page:start', () => appDialog.cancelAll())
  return { provide: { appDialog } }
})
