export type Department = {
  id: number
  code: string
  name: string
  is_active: boolean
  programs_count?: number
}

export type Program = {
  id: number
  code?: string
  name: string
  is_active: boolean
  department: Department
}

export type DepartmentProgramSummary = {
  id: number
  code?: string
  name: string
  is_active: boolean
  active_interns_count: number
  total_interns_count: number
}

export type DepartmentCoordinator = {
  id: number
  name: string
  email: string
}

export type DepartmentStudentSummary = {
  id: number
  name: string
  email: string
  program: { id: number; name: string } | null
}

export type DepartmentDetail = Department & {
  active_interns_count: number
  programs: DepartmentProgramSummary[]
  coordinators: DepartmentCoordinator[]
  students: DepartmentStudentSummary[]
  companies: { id: number; name: string }[]
}

export type User = {
  id: number
  name: string
  username?: string
  email: string
  role: 'student' | 'supervisor' | 'coordinator' | 'admin'
  is_active: boolean
  must_change_password: boolean
  avatar_url?: string | null
  program?: Program | null
  departments_coordinated?: Department[]
}

export type ProfileActivityLog = {
  id: number
  logged_at: string
  action: string
  description: string | null
  ip_address: string | null
  user_id: number
}

export type AppNotification = {
  id: number
  user_id: number
  title: string
  message: string | null
  type: string | null
  is_read: boolean
  sent_at: string
}

export type Batch = {
  id: number
  name: string
  academic_year?: string
  semester?: string
  start_date: string
  end_date: string
  required_hours: number
  working_days_per_week: number
  daily_reminder_time: string
  is_active?: boolean
  journal_template_id?: number | null
  program: Program
  coordinator?: User
  journal_template?: JournalTemplateRecord | null
}

export type EnrollableStudent = {
  id: number
  name: string
  email: string
  student_id_number: string | null
  program_id: number | null
}

export type EnrollmentOptionCompany = {
  id: number
  name: string
}

export type EnrollmentOptionSupervisor = {
  id: number
  name: string
  email: string
  company_ids: number[]
}

export type EnrollmentOptionProgram = {
  id: number
  name: string
  code?: string
}

export type EnrollmentOptionBatch = {
  id: number
  name: string
  program_id: number
}

export type EnrollmentOptions = {
  companies: EnrollmentOptionCompany[]
  supervisors: EnrollmentOptionSupervisor[]
  programs?: EnrollmentOptionProgram[]
  batches?: EnrollmentOptionBatch[]
}

export type CoordinatorInternUser = {
  id: number
  name: string
  email: string
  student_id_number: string | null
  program: { id: number; code?: string; name: string } | null
  enrolled: boolean
  enrollment: {
    id: number
    batch: { id: number; name: string; program_id: number }
    company: { id: number; name: string } | null
    supervisor: { id: number; name: string; email: string } | null
  } | null
}

export type CoordinatorSupervisorUser = {
  id: number
  name: string
  email: string
  is_active: boolean
  companies: { id: number; name: string; position: string | null }[]
  batches: { id: number; name: string }[]
}

export type BatchStudentStatus = 'active' | 'completed' | 'dropped'

export type BatchStudentRecord = {
  id: number
  status: BatchStudentStatus
  assigned_division: string | null
  enrolled_at: string
  archived_at: string | null
  student: Pick<User, 'id' | 'name' | 'email'> & { student_id_number: string | null }
  batch: { id: number; name: string; program_id: number }
  company: EnrollmentOptionCompany
  supervisor: EnrollmentOptionSupervisor
}

export type BatchDetail = Batch & {
  batch_students: BatchStudentRecord[]
}

export type BatchRosterRow = {
  id: number
  status: BatchStudentStatus
  assigned_division: string | null
  enrolled_at: string
  archived_at: string | null
  student: { id: number; name: string; email: string; student_id_number: string | null }
  company: { id: number; name: string } | null
  supervisor: { id: number; name: string; email: string } | null
}

export type BatchRosterResponse = {
  batch: { id: number; name: string; program_id: number }
  students: BatchRosterRow[]
}

export type RosterFilters = {
  batches: { id: number; name: string }[]
  statuses: BatchStudentStatus[]
}

export type RosterResponse = {
  students: BatchStudentRecord[]
  filters: RosterFilters
}

export type PaginatedResponse<T> = {
  data: T[]
  total?: number
}

export type LaravelValidationErrorBody = {
  message: string
  errors?: Record<string, string[]>
}

export type InfoSheetPersonalInfo = {
  last_name: string
  first_name: string
  middle_name?: string | null
  parent_guardian_name?: string | null
  parent_guardian_contact?: string | null
  date_of_birth?: string | null
  sex?: string | null
  home_address?: string | null
  contact_number?: string | null
  email?: string | null
  student_id_number?: string | null
}

export type InfoSheetAcademicInfo = {
  program_course?: string | null
  year_level?: string | null
  department?: string | null
  internship_coordinator?: string | null
  coordinator_contact_no?: string | null
}

export type InfoSheetOjtInfo = {
  company_id?: number | null
  host_company?: string | null
  company_address?: string | null
  company_signatory_moa?: string | null
  office_designation?: string | null
  supervisor_name?: string | null
  supervisor_contact?: string | null
  area_assigned?: string | null
  division_assigned?: string | null
  intern_duty_schedule?: string | null
  ojt_start_date?: string | null
  ojt_end_date?: string | null
}

export type JournalTemplateSection = {
  key: string
  label: string
  prompt: string
  required: boolean
  sipp: boolean
}

/** A program as it appears nested under a template's `programs` pivot list. */
export type TemplateProgram = {
  id: number
  code?: string
  name: string
  is_active: boolean
}

/**
 * A program as it appears in the Journal Templates page's `programs[]` list —
 * carries `assigned_template_id` (the template already claiming it, if any)
 * so the UI can grey out programs unavailable to a NEW claim.
 */
export type JournalTemplateProgramOption = TemplateProgram & {
  assigned_template_id: number | null
}

export type JournalTemplateRecord = {
  id: number
  name: string
  sections: JournalTemplateSection[]
  char_limit: number
  is_active: boolean
  programs: TemplateProgram[]
}

export type JournalEntryStatus = 'draft' | 'submitted' | 'overdue' | 'missing'

export type JournalEntryDetail = {
  entry_date: string
  sections: JournalTemplateSection[]
  char_limit: number
  status: JournalEntryStatus
  content: Record<string, string>
  submitted_at: string | null
  editable: boolean
  locked_reason?: 'range' | 'not_active' | 'bundled' | null
  student_name: string
  program: string | null
  entry_ordinal: number
  entry_ordinal_label: string
}

export type JournalEntrySummary = {
  id: number
  entry_date: string
  status: JournalEntryStatus
  content: Record<string, string>
  submitted_at: string | null
  word_count: number
}

export type CalendarDayStatus = 'submitted' | 'draft' | 'missing' | 'no_entry' | 'future'

export type CalendarDay = {
  date: string
  status: CalendarDayStatus
}

export type JournalCalendar = {
  month: string
  days: CalendarDay[]
}

export type WeeklyLogStatus = 'pending' | 'approved' | 'returned' | null

export type WeeklyLogSummary = {
  week_start: string
  week_end: string
  status: WeeklyLogStatus
  supervisor_comment: string | null
  submitted_at: string | null
  entries_count: number
}

export type WeeklyLogDailyEntry = {
  entry_date: string
  status: JournalEntryStatus
  content: Record<string, string>
}

export type WeeklySippField = {
  key: string
  label: string
  text: string
}

export type WeeklySippDay = {
  entry_date: string
  fields: WeeklySippField[]
}

export type WeeklyLogDetail = {
  week_start: string
  week_end: string
  status: WeeklyLogStatus
  supervisor_comment: string | null
  submitted_at: string | null
  narrative: string
  sipp_notes: WeeklySippDay[]
  daily_entries: WeeklyLogDailyEntry[]
}

export type WeeklyActivityEntryRecord = {
  id: number
  weekly_activity_log_id: number
  inclusive_date_start: string
  inclusive_date_end: string
  activities: string
  documents_records: string | null
  objectives: string | null
  supervisor_name: string | null
  supervisor_position: string | null
  sort_order: number
}

export type WeeklyActivityLogRecord = {
  id: number
  student_id: number
  batch_id: number
  weekly_log_id: number | null
  week_start: string
  week_end: string
  area_assigned: string | null
  no_of_hours: string | number | null
  status: 'draft' | 'submitted' | 'approved'
  submitted_at: string | null
  entries?: WeeklyActivityEntryRecord[]
}

export type InfoSheetStatus = 'draft' | 'submitted' | 'approved' | 'rejected'

export type InfoSheet = {
  id: number | null
  submission_status: InfoSheetStatus | null
  rejection_reason?: string | null
  submitted_at: string | null
  personal_info: InfoSheetPersonalInfo | null
  academic_info: InfoSheetAcademicInfo | null
  ojt_info: InfoSheetOjtInfo | null
  emergency_contact: Record<string, unknown> | null
}

export type StudentCompanyOption = {
  id: number
  name: string
}

export type StudentInfoSheetSummary = {
  id: number
  name: string
  email: string
  program: Program | null
  batch_enrollment: { company: { name: string } | null } | null
  submission_status: InfoSheetStatus | null
}

export type InfoSheetDetail = InfoSheet & {
  student: Pick<User, 'id' | 'name' | 'email'>
}

export type SystemSettingsMap = {
  system_name: string | null
  institution_name: string | null
  institution_address: string | null
  system_email: string | null
}

export type WeeklyBundlingResult = {
  week_start: string
  week_end: string
  compiled: number
  skipped_submitted: number
}

export type ArchivePurgeResult = {
  purged: number
  protected: number
  cutoff: string
}

export type SystemLogRecord = {
  id: number
  logged_at: string
  action: string
  description: string | null
  ip_address: string | null
  user: { id: number; name: string; role: User['role'] }
}

export type AnnualSippProgram = {
  id: number
  name: string
  code?: string
}

export type AnnualSippRow = {
  id: number
  student_name: string
  entry_date: string
  issues_concerns: string
  solutions: string
  recommendations: string
  included: boolean
}

export type AnnualSippMeta = {
  heading: string
  signatory_prepared_name: string
  signatory_prepared_title: string
  signatory_certified_name: string
  signatory_certified_title: string
}

export type AnnualSippIndex = {
  programs: AnnualSippProgram[]
  academic_years: string[]
}

export type AnnualSippReport = {
  program: AnnualSippProgram
  academic_year: string
  status: 'draft' | 'finalized'
  rows: AnnualSippRow[]
  meta: AnnualSippMeta
}

export type HteProgram = {
  id: number
  name: string
  code?: string
}

export type HteRow = {
  id: number | string
  host_establishment: string
  student_name: string
  program: string
  gender: string
  duration: string
  included: boolean
  is_manual: boolean
}

export type HteMeta = {
  signatory_prepared_name: string
  signatory_prepared_title: string
  signatory_certified_name: string
  signatory_certified_title: string
}

export type HteIndex = {
  programs: HteProgram[]
  academic_years: string[]
}

export type HteReport = {
  academic_year: string
  program_id: number | null
  status: 'draft' | 'finalized'
  rows: HteRow[]
  meta: HteMeta
}

/** GROUP Student Information Sheet — one document per company per academic year. */
export type GroupInfoSheetRow = {
  id: number | string
  last_name: string
  first_name: string
  middle_initial: string
  program_year: string
  contact_number: string
  parent_guardian_name: string
  parent_guardian_contact: string
  included: boolean
  is_manual: boolean
}

/** The coordinator-typed Internship Company Information block. */
export type GroupInfoSheetCompany = {
  host_company: string
  company_address: string
  company_signatory_moa: string
  office_designation: string
  supervisor_name: string
  supervisor_contact: string
  intern_duty_schedule: string
  area_assigned: string
  ojt_start_date: string
  ojt_end_date: string
}

export type GroupInfoSheetCompanyOption = {
  id: number
  name: string
  academic_years: string[]
}

export type GroupInfoSheetIndex = {
  academic_years: string[]
  companies: GroupInfoSheetCompanyOption[]
}

export type GroupInfoSheet = {
  academic_year: string
  company_id: number
  company_name: string
  status: 'draft' | 'finalized'
  department_line: string
  company: GroupInfoSheetCompany
  rows: GroupInfoSheetRow[]
}

export type CoordinatorDashboardStats = {
  active_interns: number
  journals_submitted_this_week: number
  journals_missing_this_week: number
  active_batches: number
  students_behind: number
}

export type StudentBehind = {
  student_id: number
  name: string
  company: string
  missing_count: number
}

export type CoordinatorDashboard = {
  stats: CoordinatorDashboardStats
  students_behind: StudentBehind[]
  week: { start: string; end: string }
}

export type JournalActivityRow = {
  student_id: number
  student_name: string
  company_id: number | null
  company: string
  program: string
  submitted_count: number
  missing_count: number
  day_status: 'submitted' | 'missing' | null
  submitted_at: string | null
}

export type JournalActivityDetailSection = {
  key: string
  label: string
  text: string | null
}

export type JournalActivityDetail = {
  student_id: number
  student_name: string
  entry_date: string
  status: string
  submitted_at: string | null
  sections: JournalActivityDetailSection[]
}

export type JournalActivityResponse = {
  from: string
  to: string
  is_single_day: boolean
  companies: { id: number; name: string }[]
  programs: { id: number; name: string; code?: string }[]
  rows: JournalActivityRow[]
}

export type CoordinatorWeeklyJournalRow = {
  id: number
  student_id: number
  student_name: string
  student_id_number: string | null
  program: string
  week_start: string
  week_end: string
  status: SupervisorReviewStatus
  submitted_at: string | null
}

export type CoordinatorWeeklyJournalsResponse = {
  programs: { id: number; name: string; code?: string }[]
  logs: {
    data: CoordinatorWeeklyJournalRow[]
    current_page: number
    last_page: number
    total: number
  }
}

// Same shape as SupervisorJournalDetail minus `reviewable` — coordinators
// observe; review verdicts belong to supervisors.
export type CoordinatorWeeklyJournalDetail = {
  id: number
  student: { id: number; name: string; student_id_number: string | null }
  week_start: string
  week_end: string
  status: SupervisorReviewStatus
  supervisor_comment: string | null
  narrative: string
  submitted_at: string | null
  reviewed_at: string | null
  daily_entries: { entry_date: string; status: JournalEntryStatus; content: Record<string, string> }[]
}

export type CompanySupervisorRecord = {
  id: number
  user_id: number | null
  name: string | null
  position: string | null
  display_name: string
  is_login: boolean
  user: { id: number; name: string; email: string } | null
}

export type CoordinatorCompany = {
  id: number
  name: string
  address: string
  location: string | null
  industry: string | null
  head_name: string | null
  head_contact_number: string | null
  head_email: string | null
  department_head: string | null
  contact_number: string | null
  description: string | null
  is_active: boolean
  active_interns_count?: number
  supervisors?: CompanySupervisorRecord[]
}

export type CoordinatorInfoSheetRow = {
  student_id: number
  name: string
  student_id_number: string | null
  program: string
  company: string
  info_sheet_id: number | null
  submission_status: InfoSheetStatus | null
  submitted_at?: string | null
}

export type CoordinatorInfoSheetDetail = {
  student: { id: number; name: string; email: string }
  sheet: {
    id: number
    submission_status: InfoSheetStatus | null
    rejection_reason?: string | null
    submitted_at: string | null
    personal_info: Record<string, unknown> | null
    academic_info: Record<string, unknown> | null
    ojt_info: Record<string, unknown> | null
  } | null
}

export type SupervisorInternRow = {
  student_id: number
  name: string
  student_id_number: string | null
  program: string
  company: string
  batch: string
  status: BatchStudentStatus
  pending_count: number
  approved_count: number
  returned_count: number
}

// Shared by the Coordinator Interns page and the Supervisor My Interns page —
// both hit their own scoped "show one intern" endpoint but return this same
// shape, so one InternDetailModal.vue renders either.
export type InternDetail = {
  id: number
  name: string
  email: string | null
  username?: string
  avatar_url: string | null
  student_id_number: string | null
  program: { id: number; code?: string; name: string } | null
  profile: {
    middle_name: string | null
    date_of_birth: string | null
    sex: string | null
    contact_number: string | null
    home_address: string | null
    year_level: string | null
  } | null
  enrollment: {
    status?: BatchStudentStatus
    batch: { id: number; name: string } | null
    company: { id: number; name: string } | null
    supervisor?: { id: number; name: string; email: string } | null
  } | null
}

export type SupervisorReviewStatus = 'pending' | 'approved' | 'returned'

export type SupervisorReviewedLog = {
  id: number
  student_name: string
  week_start: string
  week_end: string
  status: SupervisorReviewStatus
  reviewed_at: string | null
}

export type SupervisorDashboard = {
  stats: {
    my_interns: number
    pending_reviews: number
    approved_total: number
    returned_total: number
  }
  recently_reviewed: SupervisorReviewedLog[]
}

export type SupervisorJournalRow = {
  id: number
  student_id: number
  student_name: string
  student_id_number: string | null
  week_start: string
  week_end: string
  status: SupervisorReviewStatus
  submitted_at: string | null
  entries_count: number
}

export type SupervisorJournalDetail = {
  id: number
  student: { id: number; name: string; student_id_number: string | null }
  week_start: string
  week_end: string
  status: SupervisorReviewStatus
  supervisor_comment: string | null
  narrative: string
  submitted_at: string | null
  reviewed_at: string | null
  reviewable: boolean
  daily_entries: { entry_date: string; status: JournalEntryStatus; content: Record<string, string> }[]
}

export type StudentDashboardStats = {
  entries_submitted_total: number
  weekly_logs_approved: number
  weekly_logs_pending: number
  missing_this_week: number
}

export type StudentDashboardActivity = {
  text: string
  time: string | null
  tone: 'green' | 'amber' | 'blue' | 'slate'
}

export type StudentDashboardInternship = {
  host_company: string | null
  supervisor: string | null
  coordinator: string | null
  department: string | null
  program: string | null
  start_date: string | null
}

export type StudentDashboard = {
  stats: StudentDashboardStats
  progress: {
    weekly_reports_approved_percent: number
    ojt_duration_percent: number
  }
  recent_activity: StudentDashboardActivity[]
  internship: StudentDashboardInternship
  week: { start: string; end: string }
}
