export default defineNuxtRouteMiddleware(() => {
  const { isAuthenticated, user } = useSession()

  if (!isAuthenticated.value) {
    return navigateTo('/login')
  }

  const role = String(user.value?.level_user || '').trim().toLowerCase()
  const allowed = role === 'admin' || role === 'super admin' || role === 'superadmin'

  if (!allowed) {
    return navigateTo('/dashboard')
  }
})
