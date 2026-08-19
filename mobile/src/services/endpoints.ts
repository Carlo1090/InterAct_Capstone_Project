// Centralized Laravel routes — verified directly against routes/api.php and
// each controller, not guessed. mobile/login + mobile/logout are the
// mobile-only bearer-token endpoints (see MobileAuthController); every
// student/* route below is the exact same contract the web SPA uses.
export const endpoints = {
  login: '/api/mobile/login',
  logout: '/api/mobile/logout',
  me: '/api/user',

  dashboard: '/api/student/dashboard',

  journalEntries: '/api/student/journal-entries',
  journalEntry: (date: string) => `/api/student/journal-entries/${date}`,
  journalEntryPdf: (date: string) => `/api/student/journal-entries/${date}/pdf`,
  journalCalendar: '/api/student/journal-calendar',

  weeklyLogs: '/api/student/weekly-logs',
  weeklyLog: (weekStart: string) => `/api/student/weekly-logs/${weekStart}`,
  weeklyLogSubmit: (weekStart: string) => `/api/student/weekly-logs/${weekStart}/submit`,
  weeklyLogPdf: (weekStart: string) => `/api/student/weekly-logs/${weekStart}/pdf`,

  infoSheet: '/api/student/info-sheet',
  infoSheetPdf: '/api/student/info-sheet/pdf',
  companies: '/api/student/companies',

  reminderPreferences: '/api/student/reminder-preferences',

  profile: '/api/profile',
  profilePassword: '/api/profile/password',
  profilePhoto: '/api/profile/photo',
  profileActivity: '/api/profile/activity',

  notifications: '/api/notifications',
  notificationsReadAll: '/api/notifications/read-all',
  notificationRead: (id: number) => `/api/notifications/${id}/read`,
};
