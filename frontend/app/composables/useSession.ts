export interface SessionUser {
  name: string
  phone?: string | null
  id_user: number | string
  level_user: string
  email: string
  foto?: string | null
  access_token: string
  token_type?: string
}

const SESSION_COOKIE = 'simanis_session'

export function useSession() {
  const session = useCookie<SessionUser | null>(SESSION_COOKIE, {
    default: () => null,
    sameSite: 'lax',
    maxAge: 60 * 60 * 12,
  })

  const user = computed(() => session.value)
  const token = computed(() => session.value?.access_token ?? '')
  const isAuthenticated = computed(() => Boolean(token.value))

  const setSession = (payload: SessionUser | null) => {
    session.value = payload
  }

  const clearSession = () => {
    session.value = null
  }

  return {
    session,
    user,
    token,
    isAuthenticated,
    setSession,
    clearSession,
  }
}
