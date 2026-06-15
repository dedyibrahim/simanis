type ApiMethod =
  | 'GET'
  | 'POST'
  | 'PUT'
  | 'PATCH'
  | 'DELETE'
  | 'HEAD'
  | 'OPTIONS'
  | 'TRACE'
  | 'CONNECT'
  | 'get'
  | 'post'
  | 'put'
  | 'patch'
  | 'delete'
  | 'head'
  | 'options'
  | 'trace'
  | 'connect'

type RequestOptions = {
  auth?: boolean
  method?: ApiMethod
  headers?: Record<string, string>
  body?: BodyInit | Record<string, unknown>
  params?: Record<string, string | number | boolean | null | undefined>
  query?: Record<string, string | number | boolean | null | undefined>
}

export function useApi() {
  const config = useRuntimeConfig()
  const { token } = useSession()

  const withBase = (path: string) => {
    if (/^https?:\/\//.test(path)) {
      return path
    }

    return `${config.public.apiBase.replace(/\/$/, '')}/${path.replace(/^\//, '')}`
  }

  const request = async <T>(path: string, options: RequestOptions = {}) => {
    const { auth = true, headers, ...rest } = options
    const mergedHeaders: Record<string, string> = {
      Accept: 'application/json',
      ...(headers as Record<string, string> | undefined),
    }

    if (auth && token.value) {
      mergedHeaders.Authorization = `Bearer ${token.value}`
    }

    return $fetch<T>(withBase(path), {
      ...(rest.query ? { query: rest.query } : {}),
      ...(rest.params ? { params: rest.params } : {}),
      ...(rest.body ? { body: rest.body } : {}),
      ...(rest.method ? { method: rest.method } : {}),
      headers: mergedHeaders,
    })
  }

  return {
    apiBase: config.public.apiBase,
    request,
    withBase,
  }
}
