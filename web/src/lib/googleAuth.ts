/**
 * Google OAuth entry points.
 *
 * These are plain browser navigations (window.location.href), never XHR — the
 * whole point is to hand the browser to Google and let it come back. They are
 * WEB routes on the Laravel API, not /api/* endpoints.
 *
 * In dev, VITE_BACKEND_URL is normally unset and the returned path is relative,
 * so Vite's `/auth/google` proxy forwards it to the API. In production the SPA
 * and the API sit on different hosts, so VITE_BACKEND_URL must be set to the
 * API origin — the same split that makes avatar URLs absolute.
 */
const apiOrigin = (import.meta.env.VITE_BACKEND_URL ?? '').replace(/\/$/, '')

/** Prove you own a Gmail address (and set it on your account). Requires a session. */
export const googleVerifyUrl = (): string => `${apiOrigin}/auth/google/verify`

/** Sign in with an address already verified through Google. */
export const googleLoginUrl = (): string => `${apiOrigin}/auth/google/login`

/**
 * Human-readable copy for the ?google_error= / ?email_error= codes the callback
 * redirects back with. Kept in one place so the login page and the dashboard
 * banner cannot drift apart.
 */
export const GOOGLE_ERROR_MESSAGES: Record<string, string> = {
  not_linked:
    'That Google account is not linked to an InternTrack account. Sign in with your username and password first, then use "Verify with Google" in Edit Profile.',
  deactivated: 'This account has been deactivated. Please contact your coordinator.',
  unverified_google_email: 'Google could not confirm that email address belongs to you.',
  email_taken: 'That email address is already used by another InternTrack account.',
  google_failed: 'We could not complete the Google sign-in. Please try again.',
  invalid_state: 'That sign-in link expired or was invalid. Please try again.',
}

export const googleErrorMessage = (code: string | null): string => {
  if (!code) return ''
  return GOOGLE_ERROR_MESSAGES[code] ?? 'Something went wrong with Google sign-in. Please try again.'
}

/**
 * Reads a one-shot status param off the current URL and strips it, so a refresh
 * (or a later navigation) does not replay the same banner.
 */
export const consumeQueryParam = (key: string): string | null => {
  const params = new URLSearchParams(window.location.search)
  const value = params.get(key)

  if (value === null) return null

  params.delete(key)
  const query = params.toString()
  window.history.replaceState({}, '', window.location.pathname + (query ? `?${query}` : ''))

  return value
}
