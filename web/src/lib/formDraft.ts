import { watch } from 'vue'

/**
 * Keeps what a user has typed alive across a page refresh.
 *
 * This is a Vue SPA, so every reload re-mounts the page component and each
 * ref/reactive re-initialises to its empty default — everything typed is gone.
 * Drafts are mirrored into sessionStorage and restored, silently, on mount.
 *
 * sessionStorage (NOT localStorage) is deliberate: it survives refresh,
 * back/forward and a crash within the same tab, but is wiped when the tab
 * closes. InternTrack is used on shared Mater Dei College lab machines, where a
 * half-typed Student Information Sheet (name, address, contact number, guardian
 * details) must not still be sitting there for whoever uses the browser next.
 *
 * SECURITY — read before adding a call site:
 * What gets persisted is exactly what `read()` returns, so the allowlist IS the
 * function you write. NEVER return a password, token, or any other credential
 * from it. Web storage is plain text readable by any JavaScript on the page, so
 * a single XSS bug anywhere in the SPA would turn a persisted password into
 * stolen credentials, and on a shared machine it stays readable via DevTools
 * until the tab closes. Passwords already have a correct home — the browser's
 * own password manager, via `autocomplete="current-password"`, which is already
 * wired up on the login form.
 */

const PREFIX = 'interntrack:draft:'

const storageKey = (key: string): string => `${PREFIX}${key}`

/**
 * sessionStorage can throw rather than merely fail: Safari private mode and a
 * full quota both raise. Persistence is a convenience, so every access is
 * best-effort — losing the draft is exactly the behaviour we had before this
 * existed, whereas letting it throw would break the form itself.
 */
const readRaw = (key: string): string | null => {
  try {
    return sessionStorage.getItem(storageKey(key))
  } catch {
    return null
  }
}

const writeRaw = (key: string, value: string): void => {
  try {
    sessionStorage.setItem(storageKey(key), value)
  } catch {
    /* storage unavailable or full — drop the draft rather than break typing */
  }
}

const removeRaw = (key: string): void => {
  try {
    sessionStorage.removeItem(storageKey(key))
  } catch {
    /* nothing to do — see readRaw */
  }
}

export interface FormDraftOptions {
  /** Coalesce writes while typing. */
  debounceMs?: number
  /**
   * Restore immediately on setup (default). Set false for a form that populates
   * itself from the API on mount — otherwise the server response lands AFTER the
   * restore and silently overwrites the user's draft. Those call sites should
   * call `restore()` themselves once loading has finished.
   */
  autoRestore?: boolean
}

export interface FormDraft {
  /**
   * Apply the stored draft now. Only needed with `autoRestore: false`; returns
   * true if a draft existed and was applied.
   */
  restore: () => boolean
  /**
   * Drop the stored draft. Call this once the data is safely persisted
   * server-side (a successful submit/save), so a completed action doesn't leave
   * its input behind.
   */
  clear: () => void
  /** Whether a stored draft is currently present. */
  hasDraft: () => boolean
}

/**
 * Mirror a form's fields into sessionStorage and restore them on mount.
 *
 * Must be called during component setup — it registers a `watch`, so it
 * inherits the component's scope and is torn down with it.
 *
 * @param key    Stable identifier for this form, namespaced internally.
 * @param read   Returns the fields to persist. THIS IS THE ALLOWLIST — see the
 *               security note above; never return a credential.
 * @param apply  Writes a restored draft back into the form's state. Receives a
 *               Partial, since a draft may predate a field being added.
 */
export function useFormDraft<T extends object>(
  key: string,
  read: () => T,
  apply: (draft: Partial<T>) => void,
  options: FormDraftOptions = {},
): FormDraft {
  const { debounceMs = 300, autoRestore = true } = options

  // --- restore (silent: no banner, the fields simply reappear filled in) ---
  const restore = (): boolean => {
    const raw = readRaw(key)
    if (raw === null) return false

    try {
      const parsed: unknown = JSON.parse(raw)
      // Guard the shape: a hand-edited or stale-format entry must not throw.
      if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
        apply(parsed as Partial<T>)
        return true
      }
      removeRaw(key)
      return false
    } catch {
      // Corrupt JSON — discard it rather than leaving it to fail again.
      removeRaw(key)
      return false
    }
  }

  if (autoRestore) restore()

  // --- persist ---
  let timer: ReturnType<typeof setTimeout> | undefined

  const flush = (value: T): void => {
    try {
      writeRaw(key, JSON.stringify(value))
    } catch {
      /* value wasn't serialisable (cycles, etc.) — skip this write */
    }
  }

  watch(
    read,
    (value) => {
      if (timer) clearTimeout(timer)
      timer = setTimeout(() => flush(value), debounceMs)
    },
    { deep: true },
  )

  return {
    restore,
    hasDraft: () => readRaw(key) !== null,
    clear: () => {
      if (timer) clearTimeout(timer)
      removeRaw(key)
    },
  }
}
