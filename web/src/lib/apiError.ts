import axios from 'axios'
import type { LaravelValidationErrorBody } from '@/types/api'

/**
 * Coarse buckets an API failure falls into, so pages can vary copy/severity
 * without re-deriving the status-code logic themselves each time.
 */
export type ApiErrorKind = 'network' | 'validation' | 'auth' | 'not_found' | 'server' | 'unknown'

export type CategorizedError = {
  kind: ApiErrorKind
  message: string
  fieldErrors?: Record<string, string[]>
}

const FALLBACK_MESSAGES: Record<ApiErrorKind, string> = {
  network: 'Unable to reach the server. Check your connection and try again.',
  validation: 'Please fix the errors below.',
  auth: 'You are not allowed to do that.',
  not_found: 'That record could not be found.',
  server: 'Something went wrong on our end. Please try again.',
  unknown: 'Something went wrong. Please try again.',
}

/**
 * Turns any thrown value from an Axios call into a {kind, message} pair.
 * `fallback` overrides the generic per-kind message when the response body
 * has none of its own (e.g. a plain network drop has no server message).
 */
export function categorizeError(error: unknown, fallback?: string): CategorizedError {
  if (!axios.isAxiosError(error)) {
    return { kind: 'unknown', message: fallback ?? FALLBACK_MESSAGES.unknown }
  }

  if (!error.response) {
    return { kind: 'network', message: fallback ?? FALLBACK_MESSAGES.network }
  }

  const status = error.response.status
  const body = error.response.data as LaravelValidationErrorBody | undefined

  if (status === 422) {
    return {
      kind: 'validation',
      message: body?.message ?? fallback ?? FALLBACK_MESSAGES.validation,
      fieldErrors: body?.errors,
    }
  }

  if (status === 401 || status === 403) {
    return { kind: 'auth', message: body?.message ?? fallback ?? FALLBACK_MESSAGES.auth }
  }

  if (status === 404) {
    return { kind: 'not_found', message: body?.message ?? fallback ?? FALLBACK_MESSAGES.not_found }
  }

  if (status >= 500) {
    return { kind: 'server', message: fallback ?? FALLBACK_MESSAGES.server }
  }

  return { kind: 'unknown', message: body?.message ?? fallback ?? FALLBACK_MESSAGES.unknown }
}
