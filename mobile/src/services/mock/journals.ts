// Only 'submitted'/'draft' are real, stored per-entry statuses — the backend
// never stores "missing" against an entry (only the calendar derives that,
// day by day, for days with no entry at all; see CalDayState below).
export type JournalStatus = 'submitted' | 'draft';

export interface JournalEntry {
  id: string;
  day: string;
  dayName: string;
  title: string;
  dateLabel: string;
  status: JournalStatus;
}

export const mockJournals: JournalEntry[] = [
  { id: '1', day: '26', dayName: 'Mon', title: 'UI Component Design & Testing', dateLabel: 'May 26, 2025', status: 'submitted' },
  { id: '2', day: '23', dayName: 'Fri', title: 'Backend API Integration', dateLabel: 'May 23, 2025', status: 'submitted' },
  { id: '3', day: '22', dayName: 'Thu', title: 'Database Schema Optimization', dateLabel: 'May 22, 2025', status: 'submitted' },
  { id: '4', day: '21', dayName: 'Wed', title: 'Team Stand-up & Sprint Planning', dateLabel: 'May 21, 2025', status: 'submitted' },
  { id: '5', day: '20', dayName: 'Tue', title: 'Bug Fixing & Code Review', dateLabel: 'May 20, 2025', status: 'submitted' },
  { id: '6', day: '19', dayName: 'Mon', title: 'Feature Development – Login Module', dateLabel: 'May 19, 2025', status: 'submitted' },
  { id: '7', day: '16', dayName: 'Fri', title: 'Documentation Update', dateLabel: 'May 16, 2025', status: 'submitted' },
];

// Calendar grid for May 2025, matching the mockup exactly (0 = empty leading cell).
export type CalDayState = 'submitted' | 'missing' | 'noentry' | 'today' | 'empty';
export interface CalDay {
  day: number | null;
  state: CalDayState;
}

export const mockCalendarMay2025: CalDay[] = [
  { day: null, state: 'empty' }, { day: null, state: 'empty' }, { day: null, state: 'empty' }, { day: null, state: 'empty' },
  { day: 1, state: 'noentry' }, { day: 2, state: 'noentry' }, { day: 3, state: 'submitted' },
  { day: 4, state: 'submitted' }, { day: 5, state: 'submitted' }, { day: 6, state: 'submitted' }, { day: 7, state: 'submitted' },
  { day: 8, state: 'noentry' }, { day: 9, state: 'noentry' }, { day: 10, state: 'submitted' },
  { day: 11, state: 'submitted' }, { day: 12, state: 'submitted' }, { day: 13, state: 'submitted' }, { day: 14, state: 'submitted' },
  { day: 15, state: 'noentry' }, { day: 16, state: 'noentry' }, { day: 17, state: 'submitted' },
  { day: 18, state: 'submitted' }, { day: 19, state: 'submitted' }, { day: 20, state: 'submitted' }, { day: 21, state: 'submitted' },
  { day: 22, state: 'noentry' }, { day: 23, state: 'noentry' }, { day: 24, state: 'missing' },
  { day: 25, state: 'missing' }, { day: 26, state: 'today' }, { day: 27, state: 'noentry' }, { day: 28, state: 'noentry' },
];
