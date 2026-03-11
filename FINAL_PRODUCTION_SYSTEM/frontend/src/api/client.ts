const API_BASE = '/activate/admin_v2.php'

let csrfToken = ''

export class AuthError extends Error {
  constructor() {
    super('Authentication required')
    this.name = 'AuthError'
  }
}

export class ApiError extends Error {
  code?: string
  status: number
  constructor(message: string, status: number, code?: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
  }
}

async function parseErrorResponse(res: Response): Promise<never> {
  try {
    const data = await res.json()
    throw new ApiError(
      data.error || data.message || `Request failed (${res.status})`,
      res.status,
      data.error_code
    )
  } catch (e) {
    if (e instanceof ApiError) throw e
    throw new ApiError(`Request failed (${res.status})`, res.status)
  }
}

/** Check JSON body for success:false and throw with the server error message */
function assertSuccess<T>(data: T): T {
  const obj = data as Record<string, unknown>
  if (obj && obj.success === false && typeof obj.error === 'string') {
    throw new ApiError(obj.error, 400, obj.error_code as string | undefined)
  }
  return data
}

export async function fetchCsrfToken(): Promise<string> {
  const res = await fetch(`${API_BASE}?action=get_csrf`, { credentials: 'include' })
  const data = await res.json()
  csrfToken = data.csrf_token
  return csrfToken
}

export function setCsrfToken(token: string): void {
  csrfToken = token
}

export function getCsrfToken(): string {
  return csrfToken
}

export async function apiGet<T = unknown>(
  action: string,
  params?: Record<string, string | number | boolean>
): Promise<T> {
  const url = new URL(API_BASE, window.location.origin)
  url.searchParams.set('action', action)

  if (params) {
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, String(value))
      }
    }
  }

  const res = await fetch(url.toString(), { credentials: 'include' })

  if (res.status === 401) throw new AuthError()
  if (!res.ok) return parseErrorResponse(res)

  const data: T = await res.json()
  return assertSuccess(data)
}

export async function apiPostJson<T = unknown>(
  action: string,
  body?: Record<string, unknown>
): Promise<T> {
  const url = new URL(API_BASE, window.location.origin)
  url.searchParams.set('action', action)

  const res = await fetch(url.toString(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...body, csrf_token: csrfToken }),
    credentials: 'include',
  })

  if (res.status === 401) throw new AuthError()
  if (!res.ok) return parseErrorResponse(res)

  const data: T = await res.json()
  return assertSuccess(data)
}

export async function apiPost<T = unknown>(
  action: string,
  body?: Record<string, unknown>
): Promise<T> {
  const url = new URL(API_BASE, window.location.origin)
  url.searchParams.set('action', action)

  const formData = new FormData()
  formData.append('csrf_token', csrfToken)

  if (body) {
    for (const [key, value] of Object.entries(body)) {
      if (value !== undefined && value !== null) {
        if (value instanceof File) {
          formData.append(key, value)
        } else {
          formData.append(key, String(value))
        }
      }
    }
  }

  const res = await fetch(url.toString(), {
    method: 'POST',
    body: formData,
    credentials: 'include',
  })

  if (res.status === 401) throw new AuthError()
  if (!res.ok) return parseErrorResponse(res)

  const data: T = await res.json()
  return assertSuccess(data)
}
