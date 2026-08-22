import api from '@/lib/axios'

/**
 * Shared Daily Time Record client logic.
 *
 * Two surfaces punch the clock — the in-app camera scanner on the student's
 * DTR page, and the landing page a printed QR opens in the phone's own browser
 * — and they must agree on how a scanned payload becomes a recorded punch.
 * Same reasoning as DtrService on the backend.
 */

/** A site token is exactly 32 characters (Str::random(32) on the server). */
export const SITE_TOKEN_LENGTH = 32

export type PunchAction = 'clocked_in' | 'clocked_out'

export type PunchResult = {
  action: PunchAction
  distance_meters: number
  message: string
  /** The account this punch landed on, echoed back as a receipt. */
  student_name: string | null
  /**
   * A forgotten session from a previous day was closed to make way for this
   * clock-in. The student has to be told: the day they believed they banked is
   * now a flagged row waiting on their supervisor.
   */
  auto_closed_previous: boolean
  session: {
    time_in: string | null
    time_out: string | null
    minutes_worked: number | null
  }
}

/**
 * What the server would do with this token right now — the site, the direction
 * of the next punch, and WHO it would be recorded against.
 */
export type ScanPreview = {
  student: { name: string | null; username: string | null; student_id_number: string | null }
  site: { label: string; company: string | null; radius_meters: number }
  next_action: 'clock_in' | 'clock_out' | 'blocked_other_site'
  open_session: { site: string | null; time_in: string | null } | null
}

/**
 * Resolve a scanned token to its site WITHOUT punching anything.
 *
 * Both scan surfaces confirm through this before writing, for the same reason
 * DtrService is one chokepoint on the backend: the in-app scanner and the QR
 * landing page must not disagree about what a code means. It also settles the
 * question the client cannot answer on its own — a token is opaque, so only
 * the server knows which site it names and whether this student may use it.
 *
 * Deliberately takes no coordinates: a student who scanned the wrong sheet of
 * paper finds out before being asked for a location permission.
 */
export async function resolveSite(siteToken: string): Promise<ScanPreview> {
  // Leading /api/ is mandatory: the shared Axios instance has no baseURL, so a
  // relative path would resolve against the current page.
  const response = await api.get<ScanPreview>('/api/student/dtr/scan', {
    params: { site_token: siteToken },
  })

  return response.data
}

/**
 * Pull the site token out of whatever the camera decoded.
 *
 * The QR encodes a full URL (`https://host/student/dtr/scan?s=<token>`) so that
 * a phone's native camera app can open it directly. A scanner reading that same
 * code therefore gets a URL, not a token — but a bare token is also accepted so
 * a hand-typed or differently-generated code still works.
 *
 * Returns null for anything that is not one of ours, which is what lets the
 * scanner keep looking instead of firing a doomed request at the first random
 * QR code that wanders into frame.
 */
export function extractSiteToken(decoded: string): string | null {
  const value = decoded.trim()

  if (!value) return null

  // Bare token.
  if (new RegExp(`^[A-Za-z0-9]{${SITE_TOKEN_LENGTH}}$`).test(value)) {
    return value
  }

  // URL form. Parsed with a base so a relative path still resolves; a value
  // that is not a URL at all throws and is simply not one of ours.
  try {
    const token = new URL(value, window.location.origin).searchParams.get('s')

    return token && token.length === SITE_TOKEN_LENGTH ? token : null
  } catch {
    return null
  }
}

/**
 * PERMISSION_DENIED, duck-typed.
 *
 * Deliberately not `err instanceof GeolocationPositionError`: that global is
 * not reliably defined across the browsers students use, and referencing an
 * undefined identifier from inside a catch block throws a ReferenceError that
 * swallows the real error and leaves the UI stuck with no message. `code === 1`
 * is PERMISSION_DENIED in the spec and is safe to read off any object.
 */
export function isPermissionDenied(error: unknown): boolean {
  return typeof error === 'object' && error !== null && (error as { code?: number }).code === 1
}

/**
 * The browser's current position.
 *
 * enableHighAccuracy asks for the GPS radio rather than a coarse network fix —
 * a wifi-derived position can be hundreds of metres out, which is the
 * difference between passing and failing a 150m geofence. maximumAge: 0
 * forbids a cached position, so the reading always belongs to this punch and
 * cannot be a stale fix from the last time the student opened a map.
 */
function readPosition(options: PositionOptions): Promise<GeolocationPosition> {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new Error('unsupported'))
      return
    }

    navigator.geolocation.getCurrentPosition(resolve, reject, options)
  })
}

/**
 * The browser's current position, in two stages.
 *
 * **Stage 1 — GPS.** enableHighAccuracy asks for the GPS radio rather than a
 * coarse network fix, and maximumAge: 0 forbids a cached position so the
 * reading always belongs to this punch rather than being a stale fix from the
 * last time the student opened a map.
 *
 * **Stage 2 — network fallback.** Interns work inside concrete buildings, where
 * a GPS lock frequently never arrives and stage 1 simply times out. Refusing
 * the punch there would block a student standing exactly where they should be,
 * which is the worse failure — so a second, coarser attempt is made, and it is
 * allowed to reuse a recent cached fix.
 *
 * This does NOT weaken the geofence. The distance check is still the gate, and
 * a coarse fix that lands outside the radius is rejected exactly as before; a
 * bad one is simply more likely to fail than to wrongly pass. Whatever accuracy
 * the browser reports is recorded on the punch, so a supervisor reviewing a
 * session can see it was taken on a ±500m fix and judge accordingly.
 *
 * A PERMISSION_DENIED is re-thrown immediately rather than retried: the answer
 * will not change on a second ask, and retrying only delays the message that
 * tells the student how to fix it.
 */
export async function currentPosition(timeout = 15000): Promise<GeolocationPosition> {
  try {
    return await readPosition({ enableHighAccuracy: true, timeout, maximumAge: 0 })
  } catch (error) {
    if (isPermissionDenied(error) || (error instanceof Error && error.message === 'unsupported')) {
      throw error
    }

    return await readPosition({
      enableHighAccuracy: false,
      timeout: 10000,
      // A fix from the last minute is fine here — this branch already means
      // no live GPS lock is available.
      maximumAge: 60000,
    })
  }
}

/**
 * Human-readable reason a location read failed, for anything but a denial.
 *
 * By the time this is reached both the GPS and the coarse network attempt have
 * already failed, so "move nearer a window" is no longer the most useful
 * advice. The two causes that actually strand people are named instead:
 * location switched off on the device, and an in-app browser (Messenger,
 * Facebook, Instagram) whose host app has no location permission of its own —
 * those WebViews can open the camera perfectly well and still never return a
 * position, which makes the failure look like an app bug.
 */
export function locationErrorMessage(error: unknown): string {
  if (error instanceof Error && error.message === 'unsupported') {
    return 'This browser cannot report your location, so it cannot be used to clock in.'
  }

  return (
    'Your location could not be read. Check that Location is switched on for your phone, ' +
    'and if you opened this from a chat app, tap the menu and choose "Open in browser" — ' +
    'in-app browsers often cannot report location.'
  )
}

/**
 * Record the punch. The server decides whether this is a clock-in or a
 * clock-out — the client never assumes, because the authoritative open-session
 * state lives in the database.
 */
export async function punchAtSite(
  siteToken: string,
  position: GeolocationPosition,
): Promise<PunchResult> {
  // Leading /api/ is mandatory: the shared Axios instance has no baseURL, so a
  // relative path would resolve against the current page and hit the SPA
  // fallback instead of the API.
  const response = await api.post<PunchResult>('/api/student/dtr/punch', {
    site_token: siteToken,
    latitude: position.coords.latitude,
    longitude: position.coords.longitude,
    accuracy: position.coords.accuracy != null ? Math.round(position.coords.accuracy) : null,
  })

  return response.data
}
