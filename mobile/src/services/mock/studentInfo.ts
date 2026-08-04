// Real submission lifecycle, matching the actual backend: a sheet starts as
// a draft, is submitted for review, and a coordinator either approves it or
// rejects it (with a reason) — there is no in-between "saved" state that
// isn't one of these four.
export type InfoSheetStatus = 'draft' | 'submitted' | 'approved' | 'rejected';

export interface StudentInfo {
  submissionStatus: InfoSheetStatus;
  rejectionReason?: string;
  personal: {
    lastName: string;
    firstName: string;
    middleName: string;
    studentId: string;
    dob: string;
    sex: string;
    address: string;
    contact: string;
    email: string;
    // Required to submit on the real backend; contact number stays optional.
    parentGuardianName: string;
    parentGuardianContact: string;
  };
  academic: {
    // Program/Department/Coordinator are never student-editable on the real
    // backend — they're derived server-side from the assigned batch. Kept
    // here only for display; the Info Sheet screen must render these
    // read-only, never as text inputs.
    program: string;
    yearLevel: string;
    department: string;
    coordinator: string;
  };
  ojt: {
    hostCompany: string;
    companyAddress: string;
    supervisorName: string;
    supervisorEmail: string;
    startDate: string;
    endDate: string;
    division: string;
  };
}

// A small fixed list standing in for "the coordinator's registered
// companies" (a real dropdown on the backend, not free text) — picking one
// auto-fills the address, which then stays freely editable.
export const mockCompanies: { name: string; address: string }[] = [
  { name: 'TechPH Inc.', address: 'IT Park, Lahug, Cebu City' },
  { name: 'Bohol Quality Corporation', address: 'CPG Avenue, Tagbilaran City, Bohol' },
  { name: 'Metrobank - Tagbilaran Branch', address: 'CPG North Avenue, Tagbilaran City, Bohol' },
  { name: 'Mater Dei Business Solutions', address: 'Cabulijan, Tubigon, Bohol' },
];

export const mockStudentInfo: StudentInfo = {
  submissionStatus: 'approved',
  personal: {
    lastName: 'Dela Cruz',
    firstName: 'Juan',
    middleName: 'Reyes',
    studentId: '2021-IT-001',
    dob: 'May 12, 2002',
    sex: 'Male',
    address: 'Purok 4, Barangay Mabini, Tubigon, Bohol',
    contact: '09171234567',
    email: 'juan.delacruz@student.mdc.edu.ph',
    parentGuardianName: 'Maria Dela Cruz',
    parentGuardianContact: '09179876543',
  },
  academic: {
    program: 'Bachelor of Science in Information Technology',
    yearLevel: '4th Year',
    department: 'Information Technology',
    coordinator: 'Ma. Teresa Reyes',
  },
  ojt: {
    hostCompany: 'TechPH Inc.',
    companyAddress: 'IT Park, Lahug, Cebu City',
    supervisorName: 'Engr. Ramon Villanueva',
    supervisorEmail: 'rvillanueva@techph.com.ph',
    startDate: 'April 7, 2025',
    endDate: 'June 27, 2025',
    division: 'Software Development Team',
  },
};

export const mockDrafts = [
  {
    id: '1',
    title: 'API Rate Limiting Research',
    body: 'Today I started researching rate limiting strategies for REST APIs. We encountered an issue with the third-party payment gateway returning 429 errors…',
    date: 'May 24, 2025',
  },
  {
    id: '2',
    title: 'Sprint Retrospective Notes',
    body: 'Attended the sprint retrospective with the full dev team. The main discussion was around velocity and estimation accuracy for the upcoming release…',
    date: 'May 20, 2025',
  },
  {
    id: '3',
    title: 'Onboarding Module Planning',
    body: 'Met with Engr. Beltran to plan the new employee onboarding module. Key screens identified: welcome, role selection, tutorial carousel…',
    date: 'May 18, 2025',
  },
];

export const mockReportPreview = {
  school: 'MATER DEI COLLEGE',
  address: 'Cabulijan, Tubigon, Bohol',
  label: 'OJT JOURNAL REPORT',
  batchLine: 'Information Technology · Batch 2025-A',
  dateRange: 'April 7 – May 26, 2025',
  student: 'Juan Dela Cruz · 2021-IT-001',
  companyLine: 'Company: TechPH Inc. · Supervisor: Engr. Ramon Villanueva',
  totalEntries: 38,
  weeklyLogsApproved: 6,
  estPages: '~24',
};

export const mockProfile = {
  name: 'Juan Dela Cruz',
  initials: 'JD',
  roleLine: 'Student · IT Department',
  badges: ['Batch 2025-A', '4th Year'],
  email: 'juan.delacruz@student.mdc.edu.ph',
  studentId: '2021-IT-001',
  contact: '09171234567',
  company: 'TechPH Inc.',
  supervisor: 'Engr. Ramon Villanueva',
  coordinator: 'Ma. Teresa Reyes',
  ojtPeriod: 'Apr 7 – Jun 27, 2025',
  notifications: 'Enabled',
  reminderTime: '9:00 PM daily',
};
