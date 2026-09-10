/**
 * ISO weekday helpers (1 = Monday .. 7 = Sunday), shared between
 * WeekdayRangePicker.vue and any read-only display of the range it produces
 * (e.g. AdminBatchesPage's batch view), so the two can never describe the
 * same range differently.
 */
export const WEEKDAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
export const WEEKDAY_LETTERS = ['M', 'T', 'W', 'T', 'F', 'S', 'S']

/** Whether ISO weekday `day` falls in the inclusive [start, end] range, wrapping past Sunday when end precedes start (e.g. Sat(6) -> Tue(2)). */
export function isDayInRange(day: number, start: number, end: number): boolean {
  return start <= end ? day >= start && day <= end : day >= start || day <= end
}

/** "Mon" for a single day, "Mon – Fri" for a range (wrapped ranges read correctly too, e.g. "Sat – Tue"). */
export function formatDayRange(start: number, end: number): string {
  const startName = WEEKDAY_NAMES[start - 1] ?? '—'
  const endName = WEEKDAY_NAMES[end - 1] ?? '—'
  return start === end ? startName : `${startName} – ${endName}`
}
