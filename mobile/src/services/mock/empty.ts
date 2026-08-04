// Used instead of src/services/mock/* whenever the signed-in account is a
// freshly registered student (see src/services/accountKind.ts). A brand new
// OJT student hasn't logged anything yet, so their dashboard/calendar/etc.
// should start at zero rather than showing the seeded demo content.
import { DashboardData } from './dashboard';
import { JournalEntry, CalDay } from './journals';
import { WeeklyLog } from './weekly';

export const emptyDashboard: DashboardData = {
  missingDaysBanner: 'No entries yet. Write your first daily journal to get started.',
  stats: { totalEntries: 0, weeklyLogsApproved: 0, weeklyLogsPending: 0, missingThisWeek: 0 },
  progress: { dailyCompletionPct: 0, weeklyApprovedPct: 0, durationPct: 0 },
  activity: [],
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

export const emptyJournals: JournalEntry[] = [];

export const emptyCalendar: CalDay[] = Array.from({ length: 35 }, () => ({ day: null, state: 'empty' as const }));

export const emptyWeeklyLogs: WeeklyLog[] = [];

export const emptyStudentInfo = {
  submissionStatus: 'draft' as const,
  personal: {
    lastName: '',
    firstName: '',
    middleName: '',
    studentId: '',
    dob: '',
    sex: '',
    address: '',
    contact: '',
    email: '',
    parentGuardianName: '',
    parentGuardianContact: '',
  },
  academic: {
    program: 'Not yet set',
    yearLevel: 'Not yet set',
    department: 'Not yet set',
    coordinator: 'Not yet assigned',
  },
  ojt: {
    hostCompany: 'Not yet assigned',
    companyAddress: '—',
    supervisorName: 'Not yet assigned',
    supervisorEmail: '—',
    startDate: '—',
    endDate: '—',
    division: '—',
  },
};

export const emptyDrafts: never[] = [];

export const emptyProfile = {
  name: '',
  initials: '',
  roleLine: 'Student · OJT Program',
  badges: ['New Account'],
  email: '',
  studentId: 'Not yet set',
  contact: 'Not yet set',
  company: 'Not yet assigned',
  supervisor: 'Not yet assigned',
  coordinator: 'Not yet assigned',
  ojtPeriod: 'Not yet started',
  notifications: 'Enabled',
  reminderTime: '9:00 PM daily',
};
