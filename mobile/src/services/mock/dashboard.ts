export type ActivityDot = 'green' | 'blue' | 'orange';

export const mockDashboard = {
  missingDaysBanner:
    'You have 2 missing entries this week (Mon, Tue). Compilation runs every Monday at 12:00 AM.',
  // Mirrors the real backend's actual dashboard stats — daily entries only
  // ever go through draft/submitted, never a per-entry approval; approval
  // only happens at the weekly-log level, after compilation.
  stats: { totalEntries: 38, weeklyLogsApproved: 6, weeklyLogsPending: 1, missingThisWeek: 2 },
  progress: {
    dailyCompletionPct: 76,
    weeklyApprovedPct: 86,
    durationPct: 58,
  },
  activity: [
    { id: '1', dot: 'green' as ActivityDot, text: 'Week 5 journal approved by Engr. Villanueva', time: 'Today, 9:42 AM' },
    { id: '2', dot: 'blue' as ActivityDot, text: 'Journal entry for May 22 submitted', time: 'Yesterday, 6:15 PM' },
    { id: '3', dot: 'orange' as ActivityDot, text: 'Week 4 journal returned for revisions', time: 'May 19, 8:03 AM' },
    { id: '4', dot: 'blue' as ActivityDot, text: 'Journal entry for May 18 submitted', time: 'May 18, 5:47 PM' },
  ],
  internship: {
    batch: 'Batch 2025-A',
    hostCompany: 'TechPH Inc.',
    supervisor: 'Engr. Ramon Villanueva',
    department: 'Information Technology',
    startDate: 'Apr 7, 2025',
    endDate: 'Jun 27, 2025',
    coordinator: 'Ma. Teresa Reyes',
  },
};

export type DashboardData = typeof mockDashboard;
