import AsyncStorage from '@react-native-async-storage/async-storage';

// Bulk, growable data (unlike SecureStore, which is meant for small secrets)
// for locally-created accounts (see src/services/localAccounts.ts). Once a
// real backend is live, none of this is used — journalEntries/dashboard
// hooks always try the real endpoint first.

// Structured entry content — mirrors the real backend's fixed field shape:
// every entry always has a Daily Accomplishment (required), plus an optional
// SIPP Report trio added/removed as one unit. `body` (below) is always kept
// as a flattened, human-readable join of these, so anything that just needs
// a plain-text summary (Dashboard activity feed, etc.) doesn't need to know
// about this structure at all. `sections` is the source of truth for
// reconstructing the form exactly when editing.
export interface JournalSections {
  dailyAccomplishment: string;
  sipp?: {
    issues?: string;
    solutions?: string;
    recommendations?: string;
  };
}

export interface LocalJournalEntry {
  id: string;
  dateISO: string; // YYYY-MM-DD
  title: string;
  body: string;
  charCount: number;
  sections: JournalSections;
}

function entriesKey(email: string) {
  return `interntrack_entries_${email}`;
}

function createdAtKey(email: string) {
  return `interntrack_created_at_${email}`;
}

export async function getEntries(email: string): Promise<LocalJournalEntry[]> {
  try {
    const raw = await AsyncStorage.getItem(entriesKey(email));
    return raw ? (JSON.parse(raw) as LocalJournalEntry[]) : [];
  } catch {
    return [];
  }
}

export async function getEntryById(email: string, id: string): Promise<LocalJournalEntry | null> {
  const entries = await getEntries(email);
  return entries.find((e) => e.id === id) ?? null;
}

export async function updateEntry(
  email: string,
  id: string,
  updates: Omit<LocalJournalEntry, 'id'>
): Promise<LocalJournalEntry[]> {
  const entries = await getEntries(email);
  const next = entries.map((e) => (e.id === id ? { ...updates, id } : e));
  await AsyncStorage.setItem(entriesKey(email), JSON.stringify(next));
  return next;
}

export async function addEntry(
  email: string,
  entry: Omit<LocalJournalEntry, 'id'>
): Promise<LocalJournalEntry[]> {
  const entries = await getEntries(email);
  const withId: LocalJournalEntry = { ...entry, id: `${Date.now()}` };
  const next = [withId, ...entries];
  await AsyncStorage.setItem(entriesKey(email), JSON.stringify(next));
  return next;
}

// --- Drafts ---------------------------------------------------------------
export interface LocalDraft {
  id: string;
  dateISO: string;
  title: string;
  body: string;
  charCount: number;
  updatedAt: string; // ISO timestamp, for "last edited" sorting
  sections: JournalSections;
}

function draftsKey(email: string) {
  return `interntrack_drafts_${email}`;
}

export async function getDrafts(email: string): Promise<LocalDraft[]> {
  try {
    const raw = await AsyncStorage.getItem(draftsKey(email));
    const drafts = raw ? (JSON.parse(raw) as LocalDraft[]) : [];
    return drafts.sort((a, b) => (a.updatedAt < b.updatedAt ? 1 : -1));
  } catch {
    return [];
  }
}

export async function getDraft(email: string, draftId: string): Promise<LocalDraft | null> {
  const drafts = await getDrafts(email);
  return drafts.find((d) => d.id === draftId) ?? null;
}

/** Creates a new draft, or updates an existing one if draftId is provided. */
export async function saveDraft(
  email: string,
  draftId: string | null,
  fields: { dateISO: string; title: string; body: string; charCount: number; sections: JournalSections }
): Promise<LocalDraft> {
  const drafts = await getDrafts(email);
  const now = new Date().toISOString();

  if (draftId) {
    const idx = drafts.findIndex((d) => d.id === draftId);
    if (idx !== -1) {
      const updated: LocalDraft = { ...drafts[idx], ...fields, updatedAt: now };
      drafts[idx] = updated;
      await AsyncStorage.setItem(draftsKey(email), JSON.stringify(drafts));
      return updated;
    }
  }

  const created: LocalDraft = { id: `${Date.now()}`, ...fields, updatedAt: now };
  await AsyncStorage.setItem(draftsKey(email), JSON.stringify([created, ...drafts]));
  return created;
}

export async function deleteDraft(email: string, draftId: string): Promise<void> {
  const drafts = await getDrafts(email);
  const next = drafts.filter((d) => d.id !== draftId);
  await AsyncStorage.setItem(draftsKey(email), JSON.stringify(next));
}

// --- Editable Student Info Sheet ------------------------------------------
function studentInfoKey(email: string) {
  return `interntrack_studentinfo_${email}`;
}

export async function getSavedStudentInfo<T>(email: string): Promise<T | null> {
  try {
    const raw = await AsyncStorage.getItem(studentInfoKey(email));
    return raw ? (JSON.parse(raw) as T) : null;
  } catch {
    return null;
  }
}

export async function saveStudentInfo<T>(email: string, data: T): Promise<void> {
  await AsyncStorage.setItem(studentInfoKey(email), JSON.stringify(data));
}

export async function ensureAccountCreatedAt(email: string): Promise<string> {
  const existing = await AsyncStorage.getItem(createdAtKey(email));
  if (existing) return existing;
  const now = new Date().toISOString().slice(0, 10);
  await AsyncStorage.setItem(createdAtKey(email), now);
  return now;
}

function isWeekday(d: Date) {
  const day = d.getUTCDay();
  return day !== 0 && day !== 6;
}

function countWeekdaysBetween(startISO: string, endISO: string): number {
  const start = new Date(startISO + 'T00:00:00Z');
  const end = new Date(endISO + 'T00:00:00Z');
  let count = 0;
  for (let d = new Date(start); d <= end; d.setUTCDate(d.getUTCDate() + 1)) {
    if (isWeekday(d)) count++;
  }
  return Math.max(count, 1);
}

function mostRecentMonday(dateISO: string): string {
  const d = new Date(dateISO + 'T00:00:00Z');
  const day = d.getUTCDay(); // 0=Sun..6=Sat
  const diff = day === 0 ? 6 : day - 1; // days since Monday
  d.setUTCDate(d.getUTCDate() - diff);
  return d.toISOString().slice(0, 10);
}

/** Weekdays from this week's Monday through today with no submitted entry —
 * mirrors how the real backend derives "missing this week" (a working day
 * with no submitted entry), not a stored per-entry status. */
function countMissingWeekdaysThisWeek(entries: LocalJournalEntry[], todayISO: string): number {
  const submittedDates = new Set(entries.map((e) => e.dateISO));
  const monday = mostRecentMonday(todayISO);
  let missing = 0;
  for (
    let d = new Date(monday + 'T00:00:00Z');
    d <= new Date(todayISO + 'T00:00:00Z');
    d.setUTCDate(d.getUTCDate() + 1)
  ) {
    const iso = d.toISOString().slice(0, 10);
    if (isWeekday(d) && !submittedDates.has(iso)) missing++;
  }
  return missing;
}

/**
 * Derives dashboard stats/progress/activity purely from what the student has
 * actually written. There is never a per-entry "approved" status on the real
 * backend — only weekly logs go through supervisor review — so a purely
 * local account (no supervisor to review anything) honestly keeps
 * weeklyLogsApproved/weeklyLogsPending at 0; progress is measured against
 * weekdays elapsed since sign-up rather than an arbitrary fixed number.
 */
export async function computeDashboardFromEntries(email: string) {
  const entries = await getEntries(email);
  const createdAt = await ensureAccountCreatedAt(email);
  const today = new Date().toISOString().slice(0, 10);
  const weekdaysElapsed = countWeekdaysBetween(createdAt, today);

  const totalEntries = entries.length;
  const dailyCompletionPct = Math.min(100, Math.round((totalEntries / weekdaysElapsed) * 100));
  const missingThisWeek = countMissingWeekdaysThisWeek(entries, today);

  const activity = entries.slice(0, 6).map((e) => ({
    id: e.id,
    dot: 'blue' as const,
    text: `Journal entry "${e.title || 'Untitled'}" submitted`,
    time: e.dateISO,
  }));

  return {
    missingDaysBanner:
      totalEntries === 0
        ? 'No entries yet. Write your first daily journal to get started.'
        : `${totalEntries} entr${totalEntries === 1 ? 'y' : 'ies'} logged so far. Keep it up!`,
    stats: { totalEntries, weeklyLogsApproved: 0, weeklyLogsPending: 0, missingThisWeek },
    progress: { dailyCompletionPct, weeklyApprovedPct: 0, durationPct: 0 },
    activity,
    internship: {
      batch: 'Not yet assigned',
      hostCompany: 'Not yet assigned',
      supervisor: 'Not yet assigned',
      department: 'Not yet assigned',
      startDate: '—',
      endDate: '—',
      coordinator: 'Not yet assigned',
    },
  };
}

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export async function computeJournalListFromEntries(email: string) {
  const entries = await getEntries(email);
  return entries.map((e) => {
    const d = new Date(e.dateISO + 'T00:00:00Z');
    return {
      id: e.id,
      day: String(d.getUTCDate()),
      dayName: DAY_NAMES[d.getUTCDay()],
      title: e.title || 'Untitled Entry',
      dateLabel: d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }),
      status: 'submitted' as const,
    };
  });
}

export async function computeCalendarFromEntries(email: string, year: number, monthIndex0: number) {
  const entries = await getEntries(email);
  const submittedDates = new Set(entries.map((e) => e.dateISO));
  const firstOfMonth = new Date(Date.UTC(year, monthIndex0, 1));
  const leadingBlanks = firstOfMonth.getUTCDay();
  const daysInMonth = new Date(Date.UTC(year, monthIndex0 + 1, 0)).getUTCDate();
  const todayISO = new Date().toISOString().slice(0, 10);

  const days: { day: number | null; state: 'submitted' | 'missing' | 'noentry' | 'today' | 'empty' }[] = [];
  for (let i = 0; i < leadingBlanks; i++) days.push({ day: null, state: 'empty' });
  for (let day = 1; day <= daysInMonth; day++) {
    const iso = `${year}-${String(monthIndex0 + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const d = new Date(Date.UTC(year, monthIndex0, day));
    const weekend = !isWeekday(d);
    let state: 'submitted' | 'missing' | 'noentry' | 'today' = weekend ? 'noentry' : 'noentry';
    if (submittedDates.has(iso)) state = 'submitted';
    if (iso === todayISO) state = 'today';
    days.push({ day, state });
  }
  return days;
}

function weeklyBuckets(entries: LocalJournalEntry[]) {
  // Group by Monday-start week — the real backend always compiles a
  // Monday-through-Sunday span, never Sunday-start.
  const buckets = new Map<string, LocalJournalEntry[]>();
  for (const e of entries) {
    const key = mostRecentMonday(e.dateISO);
    const list = buckets.get(key) ?? [];
    list.push(e);
    buckets.set(key, list);
  }
  return Array.from(buckets.entries()).sort((a, b) => (a[0] < b[0] ? 1 : -1));
}

function weekTitleAndRange(weekStartISO: string) {
  const start = new Date(weekStartISO + 'T00:00:00Z');
  const end = new Date(start);
  end.setUTCDate(start.getUTCDate() + 6);
  const fmt = (d: Date) => d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' });
  return { start, end, range: `${fmt(start)}–${fmt(end)}` };
}

// --- Weekly narrative (local-only draft/submit lifecycle) ------------------
// Mirrors the real backend's own student-facing states for a weekly log: a
// week starts as an unsubmitted 'draft' the student can freely edit, and
// becomes 'pending' once they explicitly submit it. A purely local account
// has no supervisor, so 'approved'/'returned' never happen here — only the
// demo account's seeded mock data demonstrates those states.
interface WeeklyNarrativeRecord {
  narrative: string;
  status: 'draft' | 'pending';
  submittedAt?: string;
}

function weeklyNarrativeKey(email: string) {
  return `interntrack_weekly_narrative_${email}`;
}

async function getWeeklyNarrativeMap(email: string): Promise<Record<string, WeeklyNarrativeRecord>> {
  try {
    const raw = await AsyncStorage.getItem(weeklyNarrativeKey(email));
    return raw ? (JSON.parse(raw) as Record<string, WeeklyNarrativeRecord>) : {};
  } catch {
    return {};
  }
}

export async function getWeeklyNarrative(email: string, weekId: string): Promise<WeeklyNarrativeRecord | null> {
  const map = await getWeeklyNarrativeMap(email);
  return map[weekId] ?? null;
}

export async function saveWeeklyNarrative(email: string, weekId: string, narrative: string): Promise<void> {
  const map = await getWeeklyNarrativeMap(email);
  const existing = map[weekId];
  // Saving never re-opens an already-submitted week for a local account —
  // there is no supervisor to hand it back, so once 'pending' it stays
  // 'pending' until a real backend (or this app's own submit flow) changes it.
  map[weekId] = { narrative, status: existing?.status ?? 'draft', submittedAt: existing?.submittedAt };
  await AsyncStorage.setItem(weeklyNarrativeKey(email), JSON.stringify(map));
}

export async function submitWeeklyNarrative(email: string, weekId: string, narrative: string): Promise<void> {
  const map = await getWeeklyNarrativeMap(email);
  map[weekId] = { narrative, status: 'pending', submittedAt: new Date().toISOString() };
  await AsyncStorage.setItem(weeklyNarrativeKey(email), JSON.stringify(map));
}

export async function computeWeeklyLogsFromEntries(email: string) {
  const entries = await getEntries(email);
  if (entries.length === 0) return [];

  const weeks = weeklyBuckets(entries);
  const narrativeMap = await getWeeklyNarrativeMap(email);

  return weeks.map(([weekStartISO, list], i) => {
    const { range } = weekTitleAndRange(weekStartISO);
    const record = narrativeMap[weekStartISO];
    const status = record?.status ?? 'draft';
    return {
      id: weekStartISO,
      title: `Week ${weeks.length - i} · ${range}`,
      sub: `${list.length} ${list.length === 1 ? 'entry' : 'entries'} · ${status === 'pending' ? 'Submitted, awaiting review' : 'Not yet submitted'}`,
      status,
    };
  });
}

export async function computeWeeklyLogDetailFromEntries(email: string, weekId: string) {
  const entries = await getEntries(email);
  const weeks = weeklyBuckets(entries);
  const index = weeks.findIndex(([weekStartISO]) => weekStartISO === weekId);

  if (index === -1) return null;

  const [, list] = weeks[index];
  const { range } = weekTitleAndRange(weekId);
  const record = await getWeeklyNarrative(email, weekId);

  return {
    id: weekId,
    title: `Week ${weeks.length - index} · ${range}`,
    sub: `${list.length} ${list.length === 1 ? 'entry' : 'entries'}`,
    status: record?.status ?? ('draft' as const),
    narrative: record?.narrative ?? '',
  };
}
