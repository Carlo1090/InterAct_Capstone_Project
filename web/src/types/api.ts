export type Department = {
  id: number
  code: string
  name: string
  programs_count?: number
}

export type Program = {
  id: number
  code?: string
  name: string
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

export type DepartmentDetail = Department & {
  active_interns_count: number
  programs: DepartmentProgramSummary[]
  coordinators: DepartmentCoordinator[]
}

export type User = {
  id: number
  name: string
  email: string
  role: 'student' | 'supervisor' | 'coordinator' | 'admin'
  is_active: boolean
  must_change_password: boolean
  program?: Program | null
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
}

export type EnrollmentOptionProgram = {
  id: number
  name: string
  code?: string
}

export type EnrollmentOptions = {
  companies: EnrollmentOptionCompany[]
  supervisors: EnrollmentOptionSupervisor[]
  programs?: EnrollmentOptionProgram[]
}

export type BatchStudentStatus = 'active' | 'completed' | 'dropped'

export type BatchStudentRecord = {
  id: number
  status: BatchStudentStatus
  assigned_division: string | null
  enrolled_at: string
  student: Pick<User, 'id' | 'name' | 'email'> & { student_id_number: string | null }
  batch: { id: number; name: string; program_id: number }
  company: EnrollmentOptionCompany
  supervisor: EnrollmentOptionSupervisor
}

export type BatchDetail = Batch & {
  batch_students: BatchStudentRecord[]
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

export type InfoSheetPersonalInfo = {
  last_name: string
  first_name: string
  middle_name?: string | null
  parent_guardian_name?: string | null
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

export type JournalTemplateRecord = {
  id: number
  program_id: number
  name: string
  sections: JournalTemplateSection[]
  word_limit: number
  is_active: boolean
  program?: Program
}

export type JournalEntryStatus = 'draft' | 'submitted' | 'overdue' | 'missing'

export type JournalEntryDetail = {
  entry_date: string
  sections: JournalTemplateSection[]
  word_limit: number
  status: JournalEntryStatus
  content: Record<string, string>
  submitted_at: string | null
  editable: boolean
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

export type CompanySupervisorRecord = {
  id: number
  position: string
  user: User
}

export type Company = {
  id: number
  name: string
  address: string
  location: string | null
  industry: string | null
  contact_number: string | null
  head_name: string | null
  department_head: string | null
  is_active: boolean
  active_interns_count: number
  total_interns_count: number
}

export type CompanyDetail = Company & {
  supervisors: CompanySupervisorRecord[]
  departments: Department[]
}

export type InfoSheet = {
  id: number | null
  submission_status: 'draft' | 'submitted' | 'approved' | null
  submitted_at: string | null
  personal_info: InfoSheetPersonalInfo | null
  academic_info: InfoSheetAcademicInfo | null
  ojt_info: InfoSheetOjtInfo | null
  emergency_contact: Record<string, unknown> | null
}

export type StudentInfoSheetSummary = {
  id: number
  name: string
  email: string
  program: Program | null
  batch_enrollment: { company: { name: string } | null } | null
  submission_status: 'draft' | 'submitted' | 'approved' | null
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

export type JournalActivityResponse = {
  from: string
  to: string
  is_single_day: boolean
  companies: { id: number; name: string }[]
  rows: JournalActivityRow[]
}

export type CompanySupervisorRecord = {
  id: number
  user_id: number
  position: string | null
  user: { id: number; name: string; email: string } | null
}

export type CoordinatorCompany = {
  id: number
  name: string
  address: string
  location: string | null
  industry: string | null
  head_name: string | null
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
  submission_status: string | null
}

export type CoordinatorInfoSheetDetail = {
  student: { id: number; name: string; email: string }
  sheet: {
    id: number
    submission_status: string | null
    submitted_at: string | null
    personal_info: Record<string, unknown> | null
    academic_info: Record<string, unknown> | null
    ojt_info: Record<string, unknown> | null
  } | null
}
