// Real Laravel API response shapes — verified directly against the backend
// controllers (app/Http/Controllers/Student/*, routes/api.php), not guessed.

export type CurrentUser = {
  id: number;
  name: string;
  username: string;
  email: string | null;
  email_verified_at: string | null;
  role: string;
  student_id_number: string | null;
  program_id: number | null;
  is_active: boolean;
  must_change_password: boolean;
  avatar_url: string | null;
  program: { id: number; name: string; department: { id: number; name: string } | null } | null;
  // Present ONLY when role === 'student'.
  student_gated?: boolean;
  student_paused?: boolean;
  /** True when this student's coordinator has the Daily Time Record switched
   *  on. The web SPA hides its DTR nav item when false; mobile hides the Scan
   *  tab for the same reason. */
  student_dtr_enabled?: boolean;
};

export type ActivityTone = 'green' | 'amber' | 'blue' | 'slate';

export type DashboardResponse = {
  stats: {
    entries_submitted_total: number;
    weekly_logs_approved: number;
    weekly_logs_pending: number;
    missing_this_week: number;
  };
  progress: {
    weekly_reports_approved_percent: number;
    ojt_duration_percent: number;
  };
  // Each row carries BOTH the three flattened display fields the Vue SPA reads
  // and the raw SystemLog fields (`SystemLogEntry`), so this feed renders
  // through the same ActivityRow the full Activity Log uses. `text`/`time`
  // are no longer read by the mobile app — kept because the endpoint still
  // sends them and the web dashboard still needs them.
  recent_activity: (SystemLogEntry & { text: string; time: string | null; tone: ActivityTone })[];
  internship: {
    host_company: string | null;
    supervisor: string | null;
    coordinator: string | null;
    department: string | null;
    program: string | null;
    start_date: string | null;
  };
  week: { start: string; end: string };
};

export type EntryStatus = 'draft' | 'submitted';

export type JournalEntrySummary = {
  id: number;
  entry_date: string;
  status: EntryStatus;
  submitted_at: string | null;
  word_count: number;
  content: Record<string, string>;
};

export type Paginated<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
};

export type JournalSection = {
  key: string;
  label: string;
  required?: boolean;
  sipp?: boolean;
  prompt?: string;
};

export type LockedReason = 'not_active' | 'range' | 'bundled' | null;

export type JournalEntryDetail = {
  entry_date: string;
  sections: JournalSection[];
  char_limit: number;
  status: EntryStatus;
  content: Record<string, string>;
  submitted_at: string | null;
  editable: boolean;
  locked_reason: LockedReason;
  student_name: string;
  program: string | null;
  day_label: string;
};

export type CalendarDayStatus = 'submitted' | 'draft' | 'missing' | 'no_entry' | 'future';

export type CalendarDay = { date: string; status: CalendarDayStatus };

export type CalendarResponse = { month: string; days: CalendarDay[] };

// The DB only ever stores 'pending'|'approved'|'returned' (never 'draft') —
// a never-submitted week defaults to 'pending' at the DB level, so
// submitted_at is what actually distinguishes "still drafting" from
// "submitted, awaiting review". deriveWeekState() below is the single place
// that turns (status, submitted_at) into the 4 states a student sees.
export type WeekLogStatus = 'pending' | 'approved' | 'returned' | null;
export type WeekState = 'draft' | 'submitted' | 'approved' | 'returned';

export function deriveWeekState(status: WeekLogStatus, submittedAt: string | null): WeekState {
  if (status === 'approved') return 'approved';
  if (status === 'returned') return 'returned';
  return submittedAt ? 'submitted' : 'draft';
}

export type WeeklyLogSummary = {
  week_start: string;
  week_end: string;
  status: WeekLogStatus;
  supervisor_comment: string | null;
  submitted_at: string | null;
  entries_count: number;
};

export type WeeklyLogsResponse = { weeks: WeeklyLogSummary[] };

export type WeeklyLogDetail = {
  week_start: string;
  week_end: string;
  status: WeekLogStatus;
  supervisor_comment: string | null;
  submitted_at: string | null;
  narrative: string;
  daily_entries: { entry_date: string; status: EntryStatus; content: Record<string, string> }[];
};

export type InfoSheetStatus = 'draft' | 'submitted' | 'approved' | 'rejected' | null;

export type InfoSheetPersonal = {
  last_name: string;
  first_name: string;
  middle_name: string | null;
  parent_guardian_name: string;
  parent_guardian_contact: string | null;
  date_of_birth: string | null;
  sex: string | null;
  home_address: string | null;
  contact_number: string | null;
  email: string | null;
  student_id_number: string | null;
};

export type InfoSheetAcademic = {
  program_course: string | null;
  year_level: string | null; // '1st-year' .. '4th-year'
  department: string | null;
  internship_coordinator: string | null;
  coordinator_contact_no: string | null;
};

export type InfoSheetOjt = {
  company_id: number | null;
  host_company: string | null;
  company_address: string | null;
  company_signatory_moa: string | null;
  office_designation: string | null;
  supervisor_name: string | null;
  supervisor_contact: string | null;
  area_assigned: string | null;
  division_assigned: string | null;
  intern_duty_schedule: string | null;
  ojt_start_date: string | null;
  ojt_end_date: string | null;
};

export type InfoSheet = {
  id: number | null;
  submission_status: InfoSheetStatus;
  rejection_reason: string | null;
  submitted_at: string | null;
  personal_info: InfoSheetPersonal;
  academic_info: InfoSheetAcademic;
  ojt_info: InfoSheetOjt;
  emergency_contact: Record<string, unknown> | null;
};

export type CompanyOption = { id: number; name: string };

export type ReminderPreferences = {
  reminder_enabled: boolean;
  reminder_days: number[] | null; // 1 = Mon .. 7 = Sun
  reminder_time: string | null; // "HH:mm"
  defaults: { days: number[]; time: string };
};

export type NotificationType = 'email' | 'push' | 'in_app';

export type NotificationItem = {
  id: number;
  title: string;
  message: string | null;
  type: NotificationType;
  is_read: boolean;
  sent_at: string;
};

export type NotificationsResponse = {
  data: NotificationItem[];
  unread_count: number;
};

export type SystemLogEntry = {
  id: number;
  action: string;
  description: string | null;
  logged_at: string;
};

// --- Daily Time Record (QR + geofence) -----------------------------------
// Shapes read directly from App\Http\Controllers\Student\DtrController and
// DtrService::progressFor(), not guessed.

/** The exact `dtr_sessions.status` DB enum — verified against the migration. */
export type DtrSessionStatus = 'open' | 'closed' | 'flagged' | 'void';

export type DtrSession = {
  id: number;
  work_date: string | null;
  site: string | null;
  time_in: string | null;
  time_out: string | null;
  minutes_worked: number | null;
  status: DtrSessionStatus;
  adjustment_reason: string | null;
};

export type DtrProgress = {
  minutes_completed: number;
  hours_completed: number;
  hours_required: number;
  /** null when the batch has no required_hours set — render a dash, not 0%. */
  hours_percent: number | null;
};

/** `enabled: false` is NOT an error — the coordinator's programme simply
 *  doesn't use a location-anchored DTR. */
export type DtrOverview =
  | { enabled: false; message: string }
  | {
      enabled: true;
      company: string | null;
      open_session: DtrSession | null;
      week: { start: string; end: string; minutes: number };
      sessions: DtrSession[];
      progress: DtrProgress;
    };

/** What punch() WOULD do with this token right now, so the button can be
 *  labelled honestly before anything is written. */
export type DtrNextAction = 'clock_in' | 'clock_out' | 'blocked_other_site';

export type DtrScanPreview = {
  student: { name: string; username: string | null; student_id_number: string | null };
  site: { label: string; company: string | null; radius_meters: number };
  next_action: DtrNextAction;
  open_session: DtrSession | null;
};

export type DtrPunchResult = {
  action: 'clocked_in' | 'clocked_out';
  distance_meters: number;
  session: DtrSession;
  student_name: string;
  auto_closed_previous: boolean;
  message: string;
};

// --- Weekly Activity Log and Time Log Summary ----------------------------
// The official MDC paper form. Shapes read from
// App\Http\Controllers\Student\WeeklyActivityLogController.

export type WeeklyActivityEntry = {
  id: number;
  weekly_activity_log_id: number;
  inclusive_date_start: string | null;
  inclusive_date_end: string | null;
  activities: string | null;
  documents_records: string | null;
  objectives: string | null;
  supervisor_name: string | null;
  supervisor_position: string | null;
  sort_order: number;
};

export type WeeklyActivityLogSummary = {
  id: number;
  week_start: string;
  week_end: string;
  area_assigned: string | null;
  no_of_hours: string | number | null;
  status: 'draft' | 'submitted';
  submitted_at: string | null;
};

/** Read-only, resolved from the active enrollment — the student never types
 *  these. `faculty_adviser` is the batch coordinator: the paper form says
 *  "Faculty Adviser" and this system has no adviser role. */
export type WeeklyActivityLogHeader = {
  student_name: string | null;
  program: string | null;
  year_level: string | null;
  coordinator_name: string | null;
  company_name: string | null;
  supervisor_name: string | null;
  area_assigned: string | null;
  department_line: string;
  unit_line: string;
  program_and_year: string;
  faculty_adviser: string | null;
};

export type WeeklyActivityLogDetail = WeeklyActivityLogSummary & {
  entries: WeeklyActivityEntry[];
  header: WeeklyActivityLogHeader;
  /** Advisory only — what the DTR recorded for this period, so the student
   *  can see if it disagrees with what they typed. Null when DTR is off. */
  dtr_hours: number | null;
};

// --- Exit Interview -------------------------------------------------------

export type ExitInterviewChoice = 'yes' | 'no';

export type ExitInterviewRecord = {
  id: number;
  submission_status: 'draft' | 'submitted' | 'reviewed';
  submitted_at: string | null;
  reviewed_at: string | null;
  student_info: Record<string, string>;
  responses: Record<string, string>;
};

export type ExitInterviewResponse = {
  interview: ExitInterviewRecord | null;
  header: Record<string, string | null>;
  suggested_total_hours: string | number | null;
  /** Per-question, because question 7 has four printed lines and the rest
   *  have five — the form's own geometry, not an arbitrary cap. */
  answer_char_limits: Record<string, number>;
  answer_char_limit: number;
  /** The placement should be over before this means anything, but the
   *  student is warned rather than blocked — a coordinator may ask for it
   *  during the final week. */
  ojt_completed: boolean;
};
