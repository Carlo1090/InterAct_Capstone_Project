/**
 * What each feature can and cannot do without a connection.
 *
 * This exists because "works offline" is not one thing, and pretending it is
 * would be the dishonest option. Three genuinely different levels:
 *
 *   read_write  — usable end to end offline. Reads come from cache, and
 *                 writes are queued on the device and sent automatically
 *                 when the connection returns.
 *
 *   read        — the last-known data is readable, but writing needs a
 *                 connection. Either the server is the only thing that can
 *                 decide the outcome, or a queued write could be silently
 *                 wrong by the time it lands.
 *
 *   online_only — nothing useful can happen offline, and the screen says so
 *                 rather than showing an empty shell.
 *
 * The reason strings are user-facing on purpose. A student who cannot do
 * something should be told why in one sentence, not left guessing whether
 * the app is broken.
 */
export type OfflineLevel = 'read_write' | 'read' | 'online_only';

export type OfflineCapability = {
  level: OfflineLevel;
  /** Shown when offline. One sentence, plain language, no jargon. */
  note: string;
};

export const OFFLINE_CAPABILITIES = {
  dashboard: {
    level: 'read',
    note: 'Showing your last saved dashboard. Figures update when you reconnect.',
  },
  calendar: {
    level: 'read',
    note: 'Showing your last saved calendar. Tap a day to write — entries save on this device.',
  },
  journalList: {
    level: 'read',
    note: 'Showing your last saved journals. New entries you write offline appear once they sync.',
  },
  /** The one full read-write surface: see journalOutbox. */
  journalWrite: {
    level: 'read_write',
    note: "You can write offline. Entries save on this device and send themselves once you're back online.",
  },
  weeklyLogs: {
    level: 'read',
    note: 'Showing your last saved weekly reports. Saving or submitting a narrative needs a connection.',
  },
  weeklyActivityLog: {
    level: 'read',
    note: 'Showing your last saved Weekly and Time Log Summary. Editing rows needs a connection.',
  },
  infoSheet: {
    level: 'read',
    note: 'Showing your last saved information sheet. Submitting it needs a connection.',
  },
  exitInterview: {
    level: 'read',
    note: 'Showing your last saved answers. Saving or submitting needs a connection.',
  },
  notifications: {
    level: 'read',
    note: 'Showing your last saved notifications.',
  },
  activityLog: {
    level: 'read',
    note: 'Showing your last saved activity history.',
  },
  profile: {
    level: 'read',
    note: 'Showing your last saved profile. Changing your photo or password needs a connection.',
  },
  reminderSettings: {
    level: 'read',
    note: 'Showing your saved reminder settings. Changing them needs a connection — your phone alarms keep working either way.',
  },
  /**
   * Deliberately online_only, and NOT queued like a journal entry.
   *
   * A journal is the student's own words and is just as true an hour later.
   * A clock-in is a claim about where they physically were at a moment in
   * time — sending it later would record a time and place nobody observed,
   * which is exactly what the geofence exists to prevent.
   */
  dtr: {
    level: 'online_only',
    note: 'Clocking in or out records where and when you are, so it needs a live connection.',
  },
  /** The file is generated server-side; there is nothing to render offline. */
  pdf: {
    level: 'online_only',
    note: 'Downloading a PDF needs a connection.',
  },
} as const satisfies Record<string, OfflineCapability>;

export type OfflineFeature = keyof typeof OFFLINE_CAPABILITIES;

export function offlineNoteFor(feature: OfflineFeature): string {
  return OFFLINE_CAPABILITIES[feature].note;
}

export function offlineLevelFor(feature: OfflineFeature): OfflineLevel {
  return OFFLINE_CAPABILITIES[feature].level;
}

/** True when the feature accepts writes with no connection. */
export function canWriteOffline(feature: OfflineFeature): boolean {
  return OFFLINE_CAPABILITIES[feature].level === 'read_write';
}
