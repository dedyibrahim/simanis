export default defineNuxtRouteMiddleware(() => {
  const { isAuthenticated } = useSession()

  if (!isAuthenticated.value) {
    return navigateTo('/login')
  }
})
