export default defineNuxtRouteMiddleware((to) => {
  if (/^\/garis-otomatis-akta(?:\/|$)/.test(to.path)) {
    throw createError({ statusCode: 404, statusMessage: 'Halaman tidak ditemukan' })
  }
})
