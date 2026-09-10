export type Department = {
  id: number
  code: string
  name: string
  dean_name: string | null
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

/**
 * The student's own daily-journal reminder "alarm". `reminder_days` uses ISO day
 * numbers (1 = Monday .. 7 = Sunday); null on either field means "fall back to
 * my batch", and `defaults` says what that fallback currently resolves to.
 */
export type ReminderPreferences = {
  reminder_enabled: boolean
  reminder_days: number[] | null
  reminder_time: string | null
  defaults: {
    days: number[]
    time: string
  }
}

export type Batch = {
  id: number
  name: string
  academic_year?: string
  semester?: string
  start_date: string
  end_date: string
  required_hours: number
  /** ISO weekday (1=Mon..7=Sun) of the working-week range, picked via WeekdayRangePicker. */
  working_days_start: number
  working_days_end: number
  daily_reminder_time: string
  is_active?: boolean
  journal_template_id?: number | null
  /**
   * Which OJT mechanic the cohort runs under. `supervisor` is how every batch
   * behaved before the choice existed and is the column default, so an older
   * payload without the field is read as supervisor-supported.
   */
  ojt_type?: OjtType
  /** Roster size — non-zero freezes `ojt_type` on the edit form. */
  interns_count?: number
  program: Program
  coordinator?: User
  journal_template?: JournalTemplateRecord | null
}

export type OjtType = 'supervisor' | 'coordinator'

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
  login_supervisor: { id: number; name: string; username?: string; email: string } | null
}

export type EnrollmentOptionSupervisor = {
  id: number
  name: string
  username?: string
  email: string
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

export type BulkImportOutcome = 'created_and_emailed' | 'created_email_failed' | 'skipped_invalid'

export type BulkImportRow = {
  row: number
  first_name: string
  middle_name: string | null
  last_name: string
  sex: 'male' | 'female' | null
  student_id_number: string
  email: string
  valid: boolean
  errors: string[]
}

export type BulkImportResultRow = BulkImportRow & {
  outcome: BulkImportOutcome
  temporary_password: string | null
}

export type BulkImportPreviewResponse = {
  rows: BulkImportRow[]
  valid_count: number
  invalid_count: number
}

export type BulkImportConfirmResponse = {
  results: BulkImportResultRow[]
  created_count: number
}

export type CoordinatorSupervisorUser = {
  id: number
  name: string
  username?: string
  email: string
  is_active: boolean
  companies: { id: number; name: string; position: string | null }[]
  batches: { id: number; name: string }[]
}

/**
 * Full detail for one supervisor — backs the Supervisors tab's "View" action,
 * the same role InternDetail plays for a student. Adds the per-company
 * position and the actual roster of interns assigned to them (the list row
 * only shows batch names, not who), which is why View is worth having beyond
 * the row itself.
 */
export type SupervisorDetail = {
  id: number
  name: string
  email: string | null
  username?: string | null
  avatar_url: string | null
  is_active: boolean
  companies: { id: number; name: string; position: string | null }[]
  interns: {
    id: number
    name: string
    status: BatchStudentStatus
    batch: { id: number; name: string } | null
    company: { id: number; name: string } | null
  }[]
}

/**
 * Credential Manager (coordinator profile popover). One shape for both
 * populations a coordinator provisions — `context` carries the program code for
 * an intern and the company name(s) for a supervisor, since neither has a
 * counterpart on the other.
 */
export type CredentialAccount = {
  id: number
  name: string
  username: string
  email: string | null
  role: 'student' | 'supervisor'
  is_active: boolean
  identifier: string | null
  context: string | null
}

export type CredentialIssueResult = {
  id: number
  name: string
  username: string
  email: string | null
  role: 'student' | 'supervisor'
  /** true = sent, false = delivery failed, null = no address on file. */
  emailed: boolean | null
  temporary_password: string
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
  // 'week_submitted' replaced the old 'bundled': a compiled weekly log no
  // longer freezes its daily entries — only sending that week to the
  // supervisor does.
  locked_reason?: 'range' | 'not_active' | 'week_submitted' | null
  student_name: string
  program: string | null
  // Weekday name of the entry date, e.g. "Sunday" (rendered "Sunday (MM-DD-YYYY)").
  day_label: string
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
  /** How many of this week's daily entries are submitted — what Compile draws from. */
  submitted_entries_count: number
  sipp_notes: WeeklySippDay[]
  daily_entries: WeeklyLogDailyEntry[]
}

export type WeeklyActivityEntryRecord = {
  id: number
  weekly_activity_log_id: number
  // Nullable since the grid auto-saves half-typed rows — a row exists as soon
  // as any one cell has something in it.
  inclusive_date_start: string | null
  inclusive_date_end: string | null
  activities: string | null
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

/** One row of the coordinator's Weekly and Time Log Summary list. */
export type CoordinatorWeeklyActivityLogRow = {
  id: number
  student_id: number
  student_name: string
  student_id_number: string | null
  program: string
  week_start: string | null
  week_end: string | null
  area_assigned: string | null
  no_of_hours: string | number | null
  entries_count: number
}

export type WeeklyActivityLogHeader = {
  student_name: string | null
  program: string | null
  year_level: string | null
  coordinator_name: string | null
  company_name: string | null
  supervisor_name: string | null
  area_assigned: string | null
  department_line: string
  unit_line: string
  program_and_year: string
  faculty_adviser: string | null
}

export type CoordinatorWeeklyActivityLogsResponse = {
  programs: { id: number; name: string; code?: string }[]
  logs: {
    data: CoordinatorWeeklyActivityLogRow[]
    current_page: number
    last_page: number
    total: number
  }
}

/** One sheet as the coordinator reads it — read-only, no editing anywhere. */
export type CoordinatorWeeklyActivityLogDetail = {
  id: number
  student_id: number
  week_start: string | null
  week_end: string | null
  area_assigned: string | null
  no_of_hours: string | number | null
  header: WeeklyActivityLogHeader
  entries: Array<{
    id: number
    inclusive_date_start: string | null
    inclusive_date_end: string | null
    activities: string | null
    documents_records: string | null
    objectives: string | null
    supervisor_name: string | null
    supervisor_position: string | null
  }>
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
  template_sections: ReviewTemplateSection[]
}

export type CompanySupervisorRecord = {
  id: number
  user_id: number | null
  name: string | null
  position: string | null
  display_name: string
  is_login: boolean
  user: { id: number; name: string; username?: string; email: string } | null
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

/**
 * The slice of a journal template that rides on a REVIEW payload: enough to
 * order a daily entry's fields and label them the way the coordinator wrote
 * them. Deliberately a subset of the authoring type `JournalTemplateSection`
 * above — a reviewer never needs `prompt` or `required`, and `label` is
 * nullable here because a legacy section may carry none, in which case the
 * humanised key stands in (see lib/journalContent.ts).
 */
export type ReviewTemplateSection = {
  key: string
  label: string | null
  sipp: boolean
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
  template_sections: ReviewTemplateSection[]
}


// One intern's whole weekly-journal notebook — the per-student surface behind
// the "Journals" action on My Interns. `week_number` is the same 1-based
// position the PDF prints, counted over ALL of that student's logs, so a gap in
// this list means that week exists but has not been submitted.
export type SupervisorNotebookWeek = {
  id: number
  week_number: number
  week_start: string
  week_end: string
  status: SupervisorReviewStatus
  submitted_at: string | null
  reviewed_at: string | null
  reviewable: boolean
  has_comment: boolean
  entries_count: number
}

export type SupervisorInternNotebook = {
  student: {
    id: number
    name: string
    student_id_number: string | null
    avatar_url: string | null
    program: string
    company: string
    batch: string
    enrollment_status: BatchStudentStatus | null
  }
  totals: {
    total: number
    pending: number
    approved: number
    returned: number
    drafts_hidden: number
  }
  weeks: SupervisorNotebookWeek[]
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
    // Hours actually clocked via the QR/geofence DTR, against the batch's
    // required_hours. NULL — not zero — when the batch coordinator has DTR
    // switched off, so the UI can tell "no hours yet" apart from "hours are
    // not tracked for this programme" and render the duration gauge instead.
    hours: {
      minutes_completed: number
      hours_completed: number
      hours_required: number | null
      hours_percent: number | null
    } | null
  }
  recent_activity: StudentDashboardActivity[]
  internship: StudentDashboardInternship
  week: { start: string; end: string }
}

// ── Internship Program Student Exit Interview ────────────────────────────
// The CABM paper form (docs/reference/INTERNSHIP PROGRAM STUDENT EXIT
// INTERVIEW - BUSINESS.pdf). `responses` is deliberately an open map keyed
// q1..q14 plus q2_choice/q7_choice/q10_choice/q11_choice — the question set
// belongs to the form, not to the schema.

export type ExitInterviewStatus = 'draft' | 'submitted' | 'reviewed'

export type ExitInterviewComplianceChoice = 'complete' | 'pending'

export type StudentExitInterviewResponse = {
  interview: {
    id: number
    submission_status: ExitInterviewStatus
    submitted_at: string | null
    reviewed_at: string | null
    student_info: {
      department_position?: string | null
      total_hours?: string | null
      date_of_interview?: string | null
    }
    responses: Record<string, string | null>
  } | null
  header: {
    student_name: string | null
    program: string | null
    company: string | null
    training_period: string | null
    coordinator_name: string | null
    assigned_division: string | null
  }
  /** Banked DTR hours, or null where the coordinator does not run the DTR. */
  suggested_total_hours: number | null
  /** Keyed by question — q7 has four printed lines, the rest have five. */
  answer_char_limits: Record<string, number>
  answer_char_limit: number
  ojt_completed: boolean
}

export type CoordinatorExitInterviewRow = {
  id: number
  student_id: number
  student_name: string
  student_id_number: string | null
  program: string
  submission_status: ExitInterviewStatus
  submitted_at: string | null
  reviewed_at: string | null
  compliance: ExitInterviewComplianceChoice | null
}

export type CoordinatorExitInterviewsResponse = {
  programs: { id: number; name: string; code?: string }[]
  interviews: {
    data: CoordinatorExitInterviewRow[]
    current_page: number
    last_page: number
    total: number
  }
}

export type CoordinatorExitInterviewDetail = {
  id: number
  submission_status: ExitInterviewStatus
  submitted_at: string | null
  reviewed_at: string | null
  reviewed_by: string | null
  header: Record<string, string>
  responses: Record<string, string | null>
  coordinator_section: {
    compliance?: ExitInterviewComplianceChoice | null
    pending_detail?: string | null
    remarks?: string | null
  }
}

/** One in-scope intern's answer to a single question, in the Summary Report. */
export type ExitInterviewSummaryAnswer = {
  student_id: number
  student_name: string
  student_id_number: string | null
  program: string
  choice: 'yes' | 'no' | null
  text: string
}

/** One question's worth of gathered answers — the whole point of the report is
 *  reading this list per question rather than per student. */
export type ExitInterviewSummaryQuestion = {
  key: string
  number: number
  section: string
  text: string
  choice_key: string | null
  tally: { yes: number; no: number; unanswered: number } | null
  answers: ExitInterviewSummaryAnswer[]
}

export type CoordinatorExitInterviewSummaryResponse = {
  programs: { id: number; name: string; code?: string }[]
  academic_years: string[]
  academic_year: string | null
  total_respondents: number
  questions: ExitInterviewSummaryQuestion[]
}
