import AsyncStorage from '@react-native-async-storage/async-storage';
import { apiPost, ApiError } from './api';
import { endpoints } from './endpoints';

const QUEUE_KEY = 'interntrack_outbox_journal';
const FAILED_KEY = 'interntrack_outbox_journal_failed';

export type QueuedJournalEntry = {
  entry_date: string;
  status: 'draft' | 'submitted';
  content: Record<string, string>;
  queued_at: string;
};

export type FailedJournalEntry = QueuedJournalEntry & { reason: string };

async function readQueue(): Promise<QueuedJournalEntry[]> {
  try {
    const raw = await AsyncStorage.getItem(QUEUE_KEY);
    return raw ? (JSON.parse(raw) as QueuedJournalEntry[]) : [];
  } catch {
    return [];
  }
}

async function writeQueue(queue: QueuedJournalEntry[]): Promise<void> {
  try {
    await AsyncStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
  } catch {
    // Best-effort — see offlineCache.ts for the same reasoning.
  }
}

async function readFailed(): Promise<FailedJournalEntry[]> {
  try {
    const raw = await AsyncStorage.getItem(FAILED_KEY);
    return raw ? (JSON.parse(raw) as FailedJournalEntry[]) : [];
  } catch {
    return [];
  }
}

async function writeFailed(failed: FailedJournalEntry[]): Promise<void> {
  try {
    await AsyncStorage.setItem(FAILED_KEY, JSON.stringify(failed));
  } catch {
    // best-effort
  }
}

/** One entry per date, matching the backend's own one-entry-per-date model
 * — a second queue for the same date replaces the first rather than
 * stacking, since only the latest content is ever meaningful to send. */
export async function queueEntry(entry: Omit<QueuedJournalEntry, 'queued_at'>): Promise<void> {
  const queue = await readQueue();
  const next = queue.filter((e) => e.entry_date !== entry.entry_date);
  next.push({ ...entry, queued_at: new Date().toISOString() });
  await writeQueue(next);
}

export async function getQueuedEntry(date: string): Promise<QueuedJournalEntry | null> {
  const queue = await readQueue();
  return queue.find((e) => e.entry_date === date) ?? null;
}

export async function getAllQueued(): Promise<QueuedJournalEntry[]> {
  return readQueue();
}

export async function getFailedEntries(): Promise<FailedJournalEntry[]> {
  return readFailed();
}

export async function removeQueued(date: string): Promise<void> {
  const queue = await readQueue();
  await writeQueue(queue.filter((e) => e.entry_date !== date));
}

export async function dismissFailed(date: string): Promise<void> {
  const failed = await readFailed();
  await writeFailed(failed.filter((e) => e.entry_date !== date));
}

/**
 * Attempts to send every queued entry. A network-level failure (still
 * offline) leaves the entry queued for the next attempt. A real server
 * response (e.g. 422 — the week got bundled while offline, the date fell
 * outside the editable range in the meantime) is not something retrying
 * will ever fix, so that entry moves to the failed list instead of
 * retrying forever or silently vanishing — the student's writing is never
 * lost, but they're told plainly it needs attention.
 */
export async function flushQueue(): Promise<{ sent: number; failed: number; stillQueued: number }> {
  const queue = await readQueue();
  if (queue.length === 0) return { sent: 0, failed: 0, stillQueued: 0 };

  let sent = 0;
  let failedCount = 0;
  const failed = await readFailed();

  for (const entry of queue) {
    try {
      await apiPost(endpoints.journalEntries, {
        entry_date: entry.entry_date,
        status: entry.status,
        content: entry.content,
      });
      await removeQueued(entry.entry_date);
      sent += 1;
    } catch (err) {
      const apiErr = err as ApiError;
      if (apiErr.status === null) {
        // Still offline — leave it queued, try again next time.
        continue;
      }
      // A real rejection — stop retrying, surface it instead.
      await removeQueued(entry.entry_date);
      failed.push({ ...entry, reason: apiErr.message });
      failedCount += 1;
    }
  }

  await writeFailed(failed);
  const remaining = await readQueue();
  return { sent, failed: failedCount, stillQueued: remaining.length };
}
