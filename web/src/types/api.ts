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

export type User = {
  id: number
  name: string
  email: string
  role: 'student' | 'supervisor' | 'coordinator' | 'admin'
  is_active: boolean
  program?: Program | null
}

export type Batch = {
  id: number
  name: string
  start_date: string
  end_date: string
  required_hours: number
  working_days_per_week: number
  daily_reminder_time: string
  program: Program
  coordinator: User
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
  label: string
  prompt: string
}

export type JournalEntryStatus = 'draft' | 'submitted' | 'overdue' | 'missing'

export type JournalEntryDetail = {
  entry_date: string
  sections: JournalTemplateSection[]
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

export type WeeklyLogDetail = {
  week_start: string
  week_end: string
  status: WeeklyLogStatus
  supervisor_comment: string | null
  narrative: string
  issues_concerns: string
  solutions: string
  recommendations: string
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

export type InfoSheet = {
  id: number | null
  submission_status: 'draft' | 'submitted' | 'approved' | null
  submitted_at: string | null
  personal_info: InfoSheetPersonalInfo | null
  academic_info: InfoSheetAcademicInfo | null
  ojt_info: InfoSheetOjtInfo | null
  emergency_contact: Record<string, unknown> | null
}
