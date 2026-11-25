export default defineNuxtRouteMiddleware(to => {
  if (to.path === '/jadwal-notaris') {
    return navigateTo({ path: '/dashboard', query: { view: 'schedule' } }, { replace: true })
  }
})
