export default defineNuxtRouteMiddleware(() => {
  const { isAuthenticated, user } = useSession()
  if (!isAuthenticated.value) return navigateTo('/login')
  if (!['super admin', 'superadmin'].includes(String(user.value?.level_user || '').trim().toLowerCase())) {
    return navigateTo('/dashboard')
  }
})
