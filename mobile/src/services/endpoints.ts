// Centralized Laravel routes. Fields marked UNVERIFIED must be confirmed
// against the real backend controllers before switching off mock data —
// the roadmap defines the tables/phases but not exact JSON response shapes.
export const endpoints = {
  login: '/api/login', // UNVERIFIED — confirm token field name (token vs access_token)
  register: '/api/register', // UNVERIFIED — confirm required fields on the Laravel side
  logout: '/api/logout',
  dashboard: '/api/student/dashboard', // UNVERIFIED — aggregate endpoint may not exist until Phase 6/7
  journalEntries: '/api/journal-entries',
  journalDraft: '/api/journal-entries/draft',
  weeklyLogs: '/api/weekly-logs',
  studentInfoSheet: '/api/student-information-sheets', // GET + PATCH (UNVERIFIED — confirm PATCH is the right verb for updates)
  reportExport: '/api/reports/journal',
  registerDevice: '/api/devices', // UNVERIFIED — not named explicitly in Phase 6/7 backend bullets
};
