/**
 * Every date and time the mobile app renders goes through here.
 *
 * THE BUG THIS FIXES: "today" was computed as
 * `new Date().toISOString().slice(0, 10)`, and `toISOString()` is always UTC.
 * Every user of this app is in Manila (UTC+8), so between local midnight and
 * 08:00 the UTC date is still YESTERDAY — the calendar highlighted the wrong
 * day, "Write Today" opened the wrong date, and a journal written early in
 * the morning was filed against the previous day. It looked like the app was
 * "running late", which is exactly what it was doing, for eight hours out of
 * every twenty-four.
 *
 * It was also computed at MODULE SCOPE in calendar.tsx, so it was frozen at
 * whenever the JS bundle first loaded — an app left open (or backgrounded)
 * across midnight kept highlighting the previous day indefinitely.
 *
 * The rules, matching the web app's documented convention:
 *   - A DATE from the API ("2026-08-30", or a date-cast column serialised as
 *     "2026-08-30T00:00:00.000000Z") is SLICED, never parsed. Parsing shifts
 *     it by a day wherever the device timezone disagrees with the server.
 *   - "Today" comes from the device's LOCAL calendar fields, never from UTC.
 *   - A real INSTANT (a timestamp with a timezone marker, e.g. a DTR punch)
 *     may be parsed, because it genuinely identifies a moment in time.
 */

/** Local YYYY-MM-DD. Never UTC — see the header. */
export function todayISO(): string {
  return toISODate(new Date());
}

/** Formats a Date's LOCAL calendar fields as YYYY-MM-DD. */
export function toISODate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

/** Current local month as YYYY-MM. */
export function currentMonth(): string {
  return todayISO().slice(0, 7);
}

/**
 * A Date positioned at LOCAL midnight of the given date string.
 *
 * The `T00:00:00` suffix is load-bearing: `new Date('2026-08-30')` is parsed
 * as UTC midnight by spec, while `new Date('2026-08-30T00:00:00')` is parsed
 * in local time. Only the latter renders back as the same calendar day
 * everywhere.
 */
export function parseISODate(iso: string): Date {
  return new Date(`${iso.slice(0, 10)}T00:00:00`);
}

export function formatDate(
  iso: string,
  opts: Intl.DateTimeFormatOptions = { month: 'long', day: 'numeric', year: 'numeric' }
): string {
  return parseISODate(iso).toLocaleDateString('en-US', opts);
}

/** e.g. "Sunday" — matches the server's own `day_label` (`format('l')`). */
export function weekdayLong(iso: string): string {
  return formatDate(iso, { weekday: 'long' });
}

/** e.g. "Sun" */
export function weekdayShort(iso: string): string {
  return formatDate(iso, { weekday: 'short' });
}

/** e.g. "Aug 30 – Sep 5, 2026" */
export function dateRangeLabel(start: string, end: string, withYear = true): string {
  const s = formatDate(start, { month: 'short', day: 'numeric' });
  const e = formatDate(end, { month: 'short', day: 'numeric' });
  return withYear ? `${s} – ${e}, ${parseISODate(end).getFullYear()}` : `${s} – ${e}`;
}

/** e.g. "September 2026", from a YYYY-MM string. */
export function monthLabel(month: string): string {
  const [y, m] = month.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
}

/** Shifts a YYYY-MM string by whole months. */
export function shiftMonthString(month: string, delta: number): string {
  const [y, m] = month.split('-').map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/**
 * Standard 12-hour clock — "2:05 PM", not "14:05".
 *
 * Only for a genuine INSTANT: a string carrying a timezone marker, which
 * really does identify a moment and so is safe to parse. Returns an em dash
 * for null rather than "Invalid Date".
 */
export function formatTime(instant: string | null | undefined): string {
  if (!instant) return '—';
  const d = new Date(instant);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

/** Standard 12-hour date + time, e.g. "Aug 30, 2026, 2:05 PM". */
export function formatDateTime(instant: string | null | undefined): string {
  if (!instant) return '—';
  const d = new Date(instant);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
  });
}

/**
 * A wall-clock "HH:mm" (or "HH:mm:ss") rendered as standard time.
 * Split, not parsed — it carries no date, so there is no instant for a Date
 * to represent.
 */
export function formatWallClock(value: string | null | undefined): string {
  if (!value) return '—';
  const [rawH, rawM] = value.split(':');
  const h = Number(rawH);
  const m = Number(rawM);
  if (!Number.isFinite(h) || !Number.isFinite(m)) return '—';
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h % 12 === 0 ? 12 : h % 12;
  return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
}

// --- Wall-clock log timestamps -------------------------------------------
// `system_logs.logged_at` has no Eloquent cast, so the API returns a bare
// "Y-m-d H:i:s" with NO timezone marker. It is never parsed with
// `new Date(string)` — see the header. Its components are split out and
// rebuilt as a LOCAL Date, which is correct because the server and every
// user share Asia/Manila.

function wallClockParts(raw: string) {
  const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
  if (!m) return null;
  return {
    year: Number(m[1]),
    month: Number(m[2]),
    day: Number(m[3]),
    hour: Number(m[4]),
    minute: Number(m[5]),
    second: Number(m[6] ?? '0'),
  };
}

/** The calendar day a log line belongs to, as YYYY-MM-DD. Sliced, not parsed. */
export function logDayKey(raw: string): string {
  return raw.slice(0, 10);
}

/** "Today" / "Yesterday" / "Mon, 8 Sep" for a YYYY-MM-DD key. */
export function dayHeading(dayKey: string): string {
  const today = todayISO();
  if (dayKey === today) return 'Today';

  const yesterday = new Date();
  yesterday.setDate(yesterday.getDate() - 1);
  if (dayKey === toISODate(yesterday)) return 'Yesterday';

  const d = parseISODate(dayKey);
  const sameYear = d.getFullYear() === new Date().getFullYear();
  return d.toLocaleDateString('en-US', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    ...(sameYear ? {} : { year: 'numeric' }),
  });
}

/**
 * "Just now" / "12 minutes ago" / "3 hours ago" — the form people actually
 * read a recent event in. The exact time is still shown alongside, so
 * nothing is lost.
 *
 * A FUTURE timestamp is reported as "Just now" rather than "in 3 hours".
 * That is not cosmetic: this project has already shipped a bug where
 * `logged_at` was stamped from the DB server's clock in a different timezone
 * and every row read as being in the future. Clamping means a clock skew
 * degrades to a slightly-wrong "just now" instead of something obviously
 * broken, and the absolute time beside it stays truthful either way.
 */
export function relativeLogTime(raw: string): string {
  const p = wallClockParts(raw);
  if (!p) return '';

  const then = new Date(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
  if (Number.isNaN(then.getTime())) return '';

  const seconds = Math.round((Date.now() - then.getTime()) / 1000);
  if (seconds < 60) return 'Just now';

  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;

  const days = Math.floor(hours / 24);
  if (days < 7) return `${days} day${days === 1 ? '' : 's'} ago`;

  return formatDate(raw.slice(0, 10), { month: 'short', day: 'numeric' });
}

/** The clock time on its own — "2:05 PM" — for the secondary line. */
export function logClockTime(raw: string): string {
  const p = wallClockParts(raw);
  if (!p) return raw;
  const period = p.hour >= 12 ? 'PM' : 'AM';
  const hour12 = p.hour % 12 === 0 ? 12 : p.hour % 12;
  return `${hour12}:${String(p.minute).padStart(2, '0')} ${period}`;
}
