# PROJECT.md

Guidance for Claude Code (claude.ai/code) and any developer working in this
repository. **This is the single source of truth for current architecture,
conventions, and domain rules**, and is kept up to date as the project changes.

- Repository: https://github.com/Carlo1090/InterAct_Capstone_Project.git
- Historical detail — why a rule exists, which bug produced it, which designs
  were superseded — is archived in `docs/PROJECT_HISTORY.md`. Where that file
  disagrees with this one, **this one wins**.
- Deployment runbook: `docs/DEPLOYMENT.md`. Cron/email operator setup:
  `docs/CRON-AND-EMAIL-SETUP.txt`.

## Project Overview

InternTrack is a capstone OJT/internship monitoring system for Mater Dei College
(Bohol, Philippines), built by a 4-person team (Group 1). It digitizes SIPP
internship processes: student profiles, weekly activity journals, supervisor
review, and compliance reporting.

This is a **monorepo** with three parts:

- **repo root** — Laravel 13 REST API (PHP 8.3, MySQL/SQLite). There is no
  `api/` subfolder; the Laravel app lives at the top level (`app/`, `routes/`,
  `database/`).
- `web/` — Vue 3 SPA (Vite, Tailwind CSS v4, TypeScript).
- `mobile/` — React Native / Expo app (Expo SDK 56, TypeScript, expo-router).
  Currently the default Expo template scaffolding only — **deferred to Phase 7;
  do not wire real auth or endpoints into it unless asked.** It has its own
  `mobile/CLAUDE.md` (importing `mobile/AGENTS.md`) requiring the versioned docs
  at `docs.expo.dev/versions/v56.0.0/` be checked before any mobile code.

## Tech Stack (do not change without asking)

- Laravel 13, PHP 8.3, MySQL (SQLite for local/testing)
- Vue 3 (Composition API, `<script setup>`, TypeScript), Vue Router, Pinia
- Tailwind CSS v4 (no v3 syntax, no `tailwind.config.js` patterns v4 removed)
- Axios with the CSRF/Sanctum cookie flow (`withCredentials`, `withXSRFToken`)
- Auth: Laravel Breeze + Sanctum, **cookie/session-based** for the web SPA
  (`EnsureFrontendRequestsAreStateful`). Token auth for mobile is deferred to
  Phase 7.

**Login is USERNAME-based.** `users.username` is NOT NULL + unique;
`users.email` is nullable (unique when present). `LoginRequest` normalizes
`login`/`username`/`email` into one identifier and resolves it against
`username` OR `email` (via `filter_var` email detection), so username-only and
legacy email accounts both sign in. `User::booted()`/`generateUniqueUsername()`
auto-generates a username for any non-muted create that omits one; seeders set
explicit usernames because `DatabaseSeeder` uses `WithoutModelEvents`, which
mutes that hook.

Google sign-in is wired but strictly **link-only** — it never creates accounts.
Username + password remains the primary, always-available path.

## Hard Rules

1. **Do not create a duplicate migration.** Check `database/migrations/` first.
   If a migration for that table exists and hasn't shipped, EDIT it.
2. **Do not add a second middleware layer or restructure app architecture**
   without proposing it first and waiting for approval.
3. **Stay on the finalized schema** (InternTrack_Database_Schema_v2, 20 tables).
   Do not rename tables/columns or add tables without asking. Settled structure:
   - `weekly_activity_logs` splits into a header table +
     `weekly_activity_log_entries` (mirroring the physical SIPP form) — same
     pattern for `weekly_logs`/`weekly_log_entries` and `journal_entries`
   - Separate `programs`, `departments`, `student_profiles`,
     `journal_templates`, `system_settings` tables exist
   - `programs` has a composite unique on `(department_id, code)` — departments
     are independent top-level units (CABM-B and CABM-H are two separate
     departments, not sub-units of one "CABM"). Do not introduce a
     department→division→program hierarchy without confirming first.
4. **Out of scope — do not build:** geofence clock-in, rotating QR clock-in,
   photo capture on clock-in, exit interview report generation.
5. **Record every change in `PROJECT.md` as part of finishing the task.** Any new
   feature, schema change, notable bug fix, or behavior change gets a bullet
   added or a stale statement corrected — before reporting the task complete,
   not after being asked. A change not reflected here is invisible next session.
6. **Never `git commit`, `git push`, create a branch, or open a PR without the
   project owner's explicit permission for that specific action.** Approval to
   make a change is NOT approval to commit it. This applies to the `deploy`
   branch above all — `docker/entrypoint.sh` runs `migrate --force` on every
   boot, so an unrequested push reaches live data on the next wake.

## Domain Facts

- **3 departments, 7 programs:** CAST → BSIT · CABM-B → BSBA-FM, BSBA-MM,
  BSBA-OM, BSA · CABM-H → BSTM, BSHRM
- **Roles** (`users.role` enum, exactly four): `admin`, `coordinator`,
  `supervisor`, `student`. `supervisor` means *company* supervisor (see
  `CompanySupervisor`, linking a `supervisor`-role user to a `Company`). There
  is no separate adviser/instructor role.
- **User deactivation is soft** (`is_active = false`). There is no hard-delete
  route for users — a deactivated user's journal entries and weekly logs must
  stay intact. The one deliberate exception is
  `Coordinator/EnrollmentController::destroyAccount`, which hard-deletes only
  accounts carrying **zero** OJT history (see Intake & Enrollment below).
- **A completed student is already read-only** — not via `is_active`, but via
  enrollment status: `ResolvesStudentEnrollment::activeEnrollment()` (every
  student WRITE endpoint) requires `status = 'active'`, while
  `currentEnrollment()` (READ endpoints) accepts `active` OR `completed`.
- **Creating a `student`-role user auto-creates a `student_profiles` row** via
  `UserObserver` on the `created` event, with a placeholder
  `student_id_number` (`PENDING-XXXXXXXX`) until the registrar ID is filled in.
- **SIPP compliance documents:** OJT Annual Report; Summary Report on Student
  Exit Interview (the report itself is out of scope); Student Information Sheet
  in **two variants, both built** — the per-student individual sheet and the
  per-company GROUP sheet (reference:
  `docs/reference/Student Information Sheet (Group) (1) (3).pdf`).

## Core Data Model & Invariants

### Enrollment (`batch_students`)

`batch_students` is the **authoritative student-to-company/supervisor linkage**,
with a DB-level **`UNIQUE(batch_id, student_id)`** backstop — at most one row
per (batch, student) pair, ever. Its `status` (`active`/`completed`/`dropped`)
carries the lifecycle.

**All enrollment placement flows through
`App\Services\EnrollmentService::enrollOrReactivate()`** (used by
`EnrollmentController::store`, info-sheet Accept, and `BatchRosterController::add`).
It reuses ANY existing row for that exact pair regardless of status — refreshing
`company_id`/`supervisor_id`/`assigned_division` and setting it back to `active`
— and only creates a fresh row when none exists. That is what makes the unique
index safe.

- **Student WRITE endpoints** require an `active` row via
  `ResolvesStudentEnrollment::activeEnrollment()`, else 422 "You are not
  currently enrolled in an active OJT batch."
- **Journal/weekly-log READ endpoints** use `currentEnrollment()` (latest
  `active` OR `completed`), so a completed student keeps full read access.

### The OJT window is real-time, not estimate-bound

`ResolvesStudentEnrollment::ojtRange()`: start = the batch's `start_date`; end =
`batch_students.completed_at` once the coordinator marks the enrollment
completed, or **today** (rolling) while still active. The info sheet's
`ojt_info.ojt_start_date`/`ojt_end_date` are **informational only** — they render
on the form/PDF and gate nothing.

Completion is coordinator-driven from the batch roster modal
(`BatchRosterController::complete`/`reopen`). **`completed_at` is maintained ONLY
by a `BatchStudent::booted()` saving hook** — stamped on entry to `completed`,
cleared on exit. Never set it manually.

Downstream of completion: writes 422; the journal calendar freezes its upper
bound at `completed_at`; `WeeklyBundlingService` skips them (it only iterates
`status='active'`); `User::isInfoSheetGated()` treats active **or completed** as
having cleared intake.

### Coordinator scope — one department per coordinator

`coordinator_departments.coordinator_id` is **unique**. A department can have
multiple programs and multiple coordinators, but not the reverse.
`AssignCoordinatorRequest` rejects assigning a coordinator already attached
elsewhere; `Admin/UserController::store` takes a single `department_id`.

A coordinator's programs resolve via **`User::coordinatorProgramIds()`** — every
program in their assigned department, merged with programs of batches they
already coordinate (`batchesCoordinated()`, kept only as a backward-safety net).
**`users.program_id` is retained but is NOT the coordinator scoping source.**
Every coordinator page is scoped by this; out-of-scope access 403s.

NOTE: a coordinator's **department name does not reach the frontend at all** —
`/api/user` loads only `program.department`, and a coordinator's
`users.program_id` is null. This is why scope notices render a `'your
department'` fallback rather than a real name.

### Company Supervisor: login-bearing vs named-only

`company_supervisors` rows come in two kinds, sharing one table:

- **Login-bearing** (`user_id` set, `name` null) — the company's **one shared
  login** used to authenticate as `role=supervisor` and review weekly logs.
  `Company::loginSupervisor()` resolves it.
  `CoordinatorCompanyController::guardSingleLogin()` 422s any attach/create of a
  **second** login row for the same company; re-attaching the same user is
  idempotent. **A company has at most one login, ever.**
- **Named-only** (`user_id` null, `name` set, `position` nullable) — a record of
  a real person with no login. Unlimited per company. Also used for **Company
  Representatives** (purely informational contacts).

`batch_students` carries both: **`supervisor_id`** (NOT NULL, `users.id`) always
pins to the company's **login**; **`company_supervisor_id`** (nullable) records
the **named individual** the student typed on their info sheet, who may be a
different person.

**`detachSupervisor` is keyed by `company_supervisors.id`, not `user.id`** — a
named-only row has no user to bind to.

**Supervisor scoping is company-based, not user-based**:
`ScopesSupervisorWork::supervisedCompanyIds()` finds every company where the
authed user is the `company_supervisors.user_id`; `supervisedEnrollments()`
filters `BatchStudent::whereIn('company_id', ...)`. This is what lets a shared
company login see the company's full roster.

### The supervisor is tied to the company, never manually picked

`EnrollmentService::enrollOrReactivate()` takes **no `$supervisorId` parameter**.
Callers supply only a `$companyId`, and the service derives `supervisor_id` from
`Company::loginSupervisor()->user_id`, aborting 422 if the company has none.
Centralizing this in one service is what stops the rule drifting between the
manual Enroll form, the roster Add-Intern flow, and info-sheet Accept.
`EnrollmentController::update()` re-derives on a company change. There is **no
Supervisor select element in any form** — the resolved supervisor is read-only.

Consequences, all deliberate:

- **A company must have a login-bearing supervisor before ANY student can enroll
  there.** A company with only named-only rows 422s on every path. Add a login
  supervisor first.
- **A login swap self-heals**: `attachSupervisor`/`createSupervisor` call
  `syncActiveEnrollmentSupervisors()`, re-pointing every **active** row for that
  company at the new login. Deliberately untouched: `company_supervisor_id`, and
  **completed/dropped** rows (historical — they keep whoever supervised them).
- Remaining edge: detaching a login **without** re-attaching leaves active rows
  pointing at the former login, since `supervisor_id` is NOT NULL.
- `EnrollmentController::options()` deliberately does **not** filter
  `supervisors[]` by `is_active`, matching `Company::loginSupervisor()`, so the
  read-only display and the backend agree even for a deactivated login.
- **Seeders bypass this gate** (expected) — they write `batch_students` directly
  via Eloquent, so `migrate:fresh --seed` is unaffected. But every demo company
  referenced by an enrollment still needs a login supervisor for later UI edits.

## Intake & Enrollment

**Enrollment is student-driven, coordinator-gated intake, and the Student
Information Sheet is the gateway.** Account creation is always distinct from
enrollment.

1. **Coordinator creates the student login**
   (`EnrollmentController::createAccount`) with name, optional username,
   password, optional student ID, program (scoped to their department) and
   batch. The account is created **NOT-ENROLLED**; the intended placement is
   recorded as a **draft `student_information_sheets` row whose `batch_id` is
   the intended batch** — the single home for "intended batch before Accept".
   Username is **optional**; blank auto-generates, and the success toast echoes
   the final username back.
2. **The student is GATED** until their sheet is `approved`.
   `EnsureInfoSheetApproved` (alias `infosheet.approved`) wraps a gated student
   route subgroup; only `student/info-sheet` (show/store), its PDF, and
   `student/companies` stay ungated — every other student endpoint 403s.
   **`User::isInfoSheetGated()` is the single source of truth** (gated unless an
   approved sheet OR an active enrollment exists — the latter keeps legacy
   directly-enrolled students working), surfaced on `/api/user` as
   `student_gated` for the router guard and nav filtering.
3. **The student fills and submits the sheet.** `store()` does **not** require an
   active enrollment; it writes the scaffolded draft and re-derives the read-only
   Program/Coordinator from the intended batch (anti-spoof).
   - **Name of Company is the one constrained field** — a dropdown from
     `GET student/companies`, storing both `ojt_info.company_id` (which drives
     Accept) and `host_company` (the name).
   - **Year Level** is a constrained dropdown, values `1st-year` to `4th-year`.
   - **`personal_info.parent_guardian_name` is required to SUBMIT** but not to
     draft. `parent_guardian_contact` stays optional by explicit choice.
4. **The coordinator reviews** on Student Info Sheets — an Accept/Reject queue
   scoped by the sheet's **intended-batch program** (so not-yet-enrolled students
   appear). **Accept = enroll**: creates the active `batch_students` row for the
   sheet's batch + chosen company + that company's login supervisor, then marks
   the sheet `approved`, lifting the gate. Only a `submitted` sheet is
   acceptable (re-accept 422s, never double-enrolls). **Reject** sets `rejected`
   with a required reason shown to the student, who edits and resubmits. The UI
   labels `rejected` as **"Returned"**.
5. **Individual Info Sheet PDF** (`pdf.info-sheet` via the shared
   `BuildsInfoSheetPdf` trait) — MDC logo as a base64 data URI from
   `public/images/mdc-logo.png`, the two labeled sections, plus a blank
   **"Sketch of Internship Company Location"** box. Downloadable by the student,
   the coordinator (in-scope), and the admin (no scope check — that page is
   explicitly all-departments).

**An approved sheet is NOT permanently read-only.** A fresh gate submission
cannot replace it, but partial post-enrollment editing is allowed: **Program &
Year and the assigned Company stay locked** (re-derived server-side, ignoring
whatever the client sent, since they drove Accept), while personal info, company
address, signatory, supervisor, schedule and dates stay freely editable. Saving
an already-`approved` sheet **never** moves it back to `draft`/`submitted` or
clears `rejection_reason` — `submission_status` stays pinned to `approved`, so
routine edits cannot re-gate an enrolled student.

**Guarded permanent account delete** (`EnrollmentController::destroyAccount`) is
the one exception to soft-deactivation: it 422s any account carrying OJT history
(`journal_entries`/`weekly_logs`/`weekly_activity_logs`) and only erases truly
empty accounts. It can never destroy SIPP records.

**Graceful "enrollment inactive" state**: `User::isEnrollmentPaused()` (a student
past intake with no `active`/`completed` row) surfaces as `student_paused` on
`/api/user`. The router bounces them to a read-only `/student/paused` page and
`StudentLayout` collapses the nav. Distinct from `student_gated` (still in
intake). Re-enrolling clears it automatically.

### Batch roster: archive before delete

`batch_students.archived_at` is a plain nullable flag — deliberately **not**
Laravel `SoftDeletes` and with no global scope, so every existing
`BatchStudent::where(...)` query keeps returning archived rows and `status` is
untouched by archiving.

- `archive()` is allowed on a `dropped` or `completed` row (never `active`, never
  twice); `restore()` clears the flag and leaves `status` exactly as it was.
- **Permanent delete requires archiving first** — `destroy()` guards on
  `archived_at === null`.
- **`reactivate()`/`reopen()` also guard on `archived_at`**, forcing an explicit
  `restore()` first. Without this, calling either on an archived row produces an
  active-but-archived row that `destroy()` would then allow through and the purge
  would eventually hard-delete out from under an active enrollment.
- `archived_at` is deliberately **excluded from `#[Fillable(...)]`** (matching
  `completed_at`), so both actions set it by direct property assignment +
  `save()`, never `->update([...])`, which would silently no-op.
- A nightly **30-day auto-purge**
  (`BatchStudentPurgeService::purgeExpiredArchives()`) hard-deletes stale
  archives. No table has a foreign key to `batch_students.id` (journals and
  weekly logs key off `student_id`+`batch_id`), so the purge cannot orphan
  history. Return shape is `array{purged, protected, cutoff}`.
- **The purge protects a `completed` row that is a student's sole gate-clearing
  signal** when they have no approved info sheet — otherwise purging it would
  re-gate an already-graduated legacy student. Re-evaluated fresh each run, so
  the protection lifts automatically once no longer needed.
- **`HteReportController::buildRows()` renders any saved curated row whose source
  enrollment was purged** from its last-saved snapshot, so curation survives a
  purge instead of silently vanishing.

## Journals

### Daily journal entries

- **The length limit is character-based and fixed at 1500**
  (`journal_templates.char_limit`), validated with `mb_strlen` against the whole
  entry's combined content. **It is no longer coordinator-authored** —
  `ValidatesJournalTemplate::prepareForValidation()` force-merges the fixed value
  before validation, so a tampered or omitted value is silently ignored rather
  than 422ing. Per-SIPP-field cap is **300** characters.
- **A daily entry locks only once its week is BUNDLED, not on submit.**
  `store()` 422s only once `isBundledWeek()` finds a `WeeklyLog` for that date's
  Mon-Fri week. Until then a student can freely resave any date in range,
  submitted or not. There is no return-for-revision for individual daily
  entries; the correction surface is the weekly narrative once bundled.
- **This is a ONE-WAY lock** — even if the `WeeklyLog` is later returned by a
  supervisor, the underlying daily entries stay locked.
- `isEditableDate()` is a thin wrapper over `lockedReason()`, which returns
  `'not_active'` | `'range'` | `'bundled'` | `null`; `show()` exposes it as
  `locked_reason` so the UI can show the right banner.
- **`journal_entries.status` only ever holds `draft` or `submitted` in storage.**
  `missing`/`overdue` are **derived on read** (absence of a submitted entry on a
  working day), never queried against the column. Querying the column for them
  silently returns zero.

### Journal templates

- **Many-programs-per-template**: `journal_templates.program_id` was dropped for
  the `journal_template_program` pivot with **`UNIQUE(program_id)`** — a program
  belongs to **at most one** template, ever. Create/update take `program_ids[]`;
  a program already claimed by a different template 422s naming the conflict, so
  the unique index never surfaces as a 500. `index()` returns each in-scope
  program with a nullable `assigned_template_id` so the UI can grey out
  already-covered programs.
- A batch resolves its template via **`batches.journal_template_id`**.
- **A fixed "Daily Accomplishment" section is non-removable**:
  `key: 'daily_accomplishment'`, `required: true`, `sipp: false`. It is the
  guaranteed source Weekly Bundling compiles from across every department.
  Enforcement is in `ValidatesJournalTemplate::prepareForValidation()`, which
  strips any coordinator-submitted entry under that key and prepends the
  canonical definition **before** validation — so omitting or tampering with it
  never 422s, it is silently neutralized. This also unconditionally satisfies
  "at least one section must be required".
- **SIPP (Annex C) is a fixed trio** keyed `issues_concerns` / `solutions` /
  `recommendations`. A section may only be flagged `sipp=true` if its key is one
  of those three (server-guarded). The UI authors it as **one checkbox** that
  adds/removes the trio together — there is no per-section SIPP checkbox.
- The editor never shows the raw section `key`; it is auto-generated from the
  `label`.
- SEEDER CAVEAT: seeders create `JournalTemplate`/`JournalEntry` rows directly
  via Eloquent, bypassing this FormRequest-level enforcement. Seeders writing
  **submitted** entries key content by `daily_accomplishment` so Weekly Bundling
  has real text to compile against demo data.

### Weekly Bundling

`WeeklyBundlingService::bundleWeek()` auto-compiles a student's **Mon-Sun**
`journal_entries.content['daily_accomplishment']` (only from **submitted**
entries) into `"MONDAY\n<text>\n\nTUESDAY\n<text>"`-shaped
`weekly_logs.narrative` — no time range, ever.

- Days with no entry are **silently skipped, with no placeholder** — which is
  what makes weekend support free: a `SATURDAY` block appears only for a student
  who actually submitted one, so a Mon-Fri intern's narrative is byte-identical
  to before.
- It touches every student with an **active** row, and **never overwrites an
  already-submitted `WeeklyLog`**. An unsubmitted draft is freely recompiled on
  every run — there is no way to distinguish a student's manual edit from an
  auto-fill.
- **Runs every MONDAY at 00:00**, for the full Mon-Sun week that just ended.
  **It used to run Saturday and that was a real bug, not a preference** —
  stamping a `WeeklyLog` is a one-way edit lock on every daily entry in the week,
  so a Saturday run locked the week BEFORE a Saturday shift began, and an intern
  rostered that day could never write the entry.
- `mostRecentlyCompletedWeekStart()` is unconditionally
  `today()->startOfWeek(Monday)->subWeek()` — a week is complete only once its
  **Sunday** has passed.
- Laravel 13 has no `app/Console/Kernel.php`; scheduling lives in
  `routes/console.php` via `Schedule::command()`.

### Weekly logs and supervisor review

- **`submitted_at`, not `status`, distinguishes "still drafting" from
  "submitted"** — `weekly_logs.status` defaults to `'pending'` at the DB level
  even for a never-submitted draft.
- `submit` 422s if no narrative is saved yet, and 422s on a double-submit while
  `submitted_at` is set and status is `pending`/`approved`. It is **allowed again
  once a supervisor returns it** (`status = 'returned'`); `supervisor_comment` is
  left untouched by resubmission.
- `store()` (the student's autosave) mirrors the same lock: rejected once
  submitted and pending/approved, but editable while `returned`. That is what
  makes return-with-comment meaningful.
- Supervisor actions: `approve` and `returnLog` (which requires a
  `supervisor_comment`); both stamp `supervisor_id` + `reviewed_at`. Only
  **submitted** logs still `pending`/`returned` are reviewable — drafts and
  finalized logs 422.
- **The weekly narrative has its own fixed 5000-character limit**
  (`StoreWeeklyLogRequest::CHAR_LIMIT`), deliberately larger than the daily 1500
  since it is a compiled summary of a full week. The auto-bundling path writes
  via Eloquent and bypasses the FormRequest, so an auto-fill may exceed it; only
  a student's manual save is capped.
- **Coordinators get a read-only weekly-journal surface**
  (`CoordinatorWeeklyJournalController`), department-scoped, excluding
  never-submitted drafts. **No approve/return/edit for coordinators anywhere** —
  review verdicts belong to supervisors.
- DTR/QR/geofence clock-in is a separate pending feature, **not built here**.

### Journal calendar

`JournalCalendarController::statusFor()` resolves in this order: submitted →
draft → **then** the working-day check → `missing`/`draft`. The order matters:
an entry always wins whatever day it falls on, so a genuinely-submitted Saturday
shows as `submitted`, while an empty weekend returns `no_entry` and can **never**
be `missing`. Nobody is penalised for not working a weekend; you can only ever
add work, never excuse an absence.


### Weekly Activity Log and Time Log Summary

The official MDC paper form, reinstated as a student surface on 2026-08-18.
Reference: `docs/reference/Weekly-Activity-Log-and-Time-Log-Summary-Guide.pdf`.

**This is a UI restoration, not a new feature.** The backend
(`weekly_activity_logs` / `weekly_activity_entries`, `WeeklyActivityLogController`
with 9 routes, 5 Form Requests, and the `pdf.weekly-activity-log` blade) had been
deliberately kept when the old inline section was removed from
`StudentWeeklyJournalsPage.vue`; only the Vue markup had gone. Nothing was
migrated and no route was added.

- **Page** `web/src/pages/student/StudentWeeklyTimeLogPage.vue`, route
  `/student/weekly-time-log`, nav label **"Weekly and Time Log Summary"**
  (`clock` icon, added to `StudentLayout`'s icon set). It sits in the **gated**
  student route group, so it needs an approved info sheet like every other
  student page.
- **One sheet per Period Covered**, each holding N activity rows. The five table
  columns map 1:1 to the paper form: Inclusive Dates, Activities,
  Document/Records, Objective/s, and Supervisor's name/position/Signature.
- **The table IS the input surface — students type straight into the grid**, one
  bordered cell per form column, rather than filling a separate form below it. A
  blank row always sits at the bottom so the template is immediately typeable.
  This is a manual-entry template: **nothing is auto-extracted from daily
  journal entries.**
- **Everything auto-saves** (800ms debounce per row and for the Form Details
  pair), so there is **no Save button anywhere** — the only row action is
  **Delete**. A page-level pill reports Saving / All changes saved / error, and
  each row carries its own small status.
  - **A draft row is only POSTed once it has both dates AND activities.** Those
    three are `required` on `StoreWeeklyActivityEntryRequest`, so auto-saving an
    incomplete row would 422 on every keystroke — `isRowCreatable()` is the gate
    that prevents it. Once created, the row keeps its id and subsequent edits go
    out as PUTs.
  - **A flush that lands while a row is already saving reschedules instead of
    firing**, so a fast typist cannot race two POSTs and duplicate a row.
  - **Saving never re-fetches the sheet.** Reloading mid-typing would blow away
    focus and cursor position; rows are updated in place instead.
  - `onBeforeUnmount` flushes anything still inside its debounce window, so
    navigating away does not silently drop the last keystrokes.
  - **The "New Log Sheet" form is deliberately NOT auto-saved** — it creates a
    record rather than editing one, and auto-saving it would create sheets while
    the student is still typing dates.
- **The signature space is intentionally left blank on the PDF** for the
  supervisor to sign by hand — the app stores a name and position, never a
  signature image.
- **`Faculty Adviser` on the form maps to the batch coordinator.** This system
  has no adviser/instructor role (see Domain Facts), and the coordinator is the
  person that field means.
- **`Area Assigned` and `No. of hours` are student-entered**, not derived. There
  is no clock-in/DTR anywhere in this project (explicitly out of scope), so hours
  cannot be computed and must never be fabricated.
- The header block (student name, program and year, adviser, company,
  supervisor) is **read-only**, resolved from the active enrollment.

#### The PDF is a facsimile — four things are load-bearing

1. **`->setPaper('letter', 'portrait')` in the controller.** dompdf defaults to
   **A4** (595x842), which silently narrows every measured column. Pinned by
   `test_the_pdf_is_us_letter_and_fits_on_one_page`, which asserts the MediaBox
   is `612 x 792` — verified to genuinely fail (it reports A4) when the call is
   removed.
2. **The whole form fits on ONE page, and the margin is thin.** A blank form is
   one page at a `table.activity td` height of **70pt** and spills to two at
   72pt — bisected empirically, not estimated. Re-measure after changing any
   masthead, info-table or row spacing.
3. **Both tables open with a zero-height `.sizer` row carrying the column
   widths, under AUTO layout.** dompdf ignores `<colgroup>` and ignores a width
   on any cell carrying a `colspan` (the info table's Faculty Adviser and Name of
   Company rows both span), and `table-layout: fixed` distributes columns equally
   regardless. Those widths are **content-box** — dompdf adds cell padding on
   top, so each is written as (target - horizontal padding).
4. **The department and unit lines are literal constants**
   (`DEFAULT_DEPARTMENT_LINE` = "College of Accountancy, Business and
   Management", `DEFAULT_UNIT_LINE` = "Business Department"), matching the
   reference form verbatim. Deliberately **not** derived from
   `departments.name`, which is seeded to the short code ("CABM-B") and would
   print wrongly — the same call already made for the GROUP Student Information
   Sheet.

`formRows()` pads to **`MIN_FORM_ROWS = 5`** so a sparse log still prints like
the pre-printed paper form, while the table itself **grows** past it (matching
the GROUP sheet's "the roster grows" decision). Coverage:
`test_the_pdf_renders_every_label_from_the_paper_form` asserts all 15 printed
labels survive.
### Journal document format (V2 — plain typed documents)

`pdf/daily-journal-entry.blade.php` and `pdf/weekly-log.blade.php` are
**standalone minimal blades** — they must look like plain typed documents, not
application reports.

- **Daily** (Times New Roman 16px, black on white): line 1 = UPPERCASE student
  name left / program name right (a borderless two-cell table, for dompdf); line
  2 = the **weekday label** `Sunday (MM-DD-YYYY)` (exposed by `show()` as
  `day_label`); then the `daily_accomplishment` text as unlabeled justified
  paragraphs; then each other **filled** section as a paragraph beginning with a
  bold inline label. No doc title, no meta table, no Status, no empty-section
  placeholders.
- **Weekly** (Times New Roman 12px): a bold `My OJT Journal Week N (Company)`
  title, where N is the 1-based position among that student's WeeklyLogs by
  `week_start`, computed identically in all three controllers that share the
  blade (student, supervisor, coordinator). Then bold uppercase day headers each
  followed by a plain paragraph. No meta table, Status, comment box, or
  signature block.
- **No time-range line — shift times are not stored; never fabricate one.**
- Client-side, the same day-block parsing lives in exactly one place:
  `WeeklyJournalPaperView.vue`. `JournalPaperView.vue` mirrors the daily PDF 1:1
  and is always rendered read-only on the write page — the paper is a review
  surface, not an alternate editor.

## Reminders & Email

### Student-owned reminder preferences (the "alarm")

`student_profiles` carries `reminder_days` (comma-separated **ISO** day numbers,
1=Mon..7=Sun), `reminder_time` (`time`), and `reminder_enabled` (bool, default
true). All nullable/defaulted, so an untouched profile behaves exactly as before.

Resolution lives in `App\Support\ReminderSchedule` (`remindsOn()`/`hourFor()`),
deliberately a sibling of `App\Support\BatchWorkingDays`. **The split is the
whole point:** `BatchWorkingDays` answers "was work expected?" and drives
compliance; `ReminderSchedule` only answers "should we nudge?" and is the
student's own business.

**NOTHING but `SendMissingJournalEntryReminders` may ever read these columns.**
Bundling, the calendar, missing counts, coordinator dashboards and every report
must stay out — a student must never be able to redefine the yardstick their own
compliance is measured against.

Fallback chain: student's `reminder_days` → the batch's working-day pattern;
student's `reminder_time` → `batches.daily_reminder_time` → 21. `reminder_time`
is intentionally **uncast** (MySQL and SQLite return `time` columns in different
shapes) — callers parse it with Carbon.

Storage normalizes days sorted + deduped. **An empty `reminder_days` array stores
`null`, meaning "follow my batch", NOT "never remind me"** — switching reminders
off is what `reminder_enabled` is for, so a cell cannot be blanked into silence
by accident.

### The reminder command

`journal:send-missing-entry-reminders` is scheduled **HOURLY, not daily**,
because each student picks their own hour. The command decides whose hour it
currently is, and only the **hour** is honoured (minutes are ignored, not
rounded). `--ignore-time` treats everyone as due now, for manual/demo runs.

- **Dedupe is on (user, title, today)**, because `notifications` has no
  `entry_date`/`batch_id` column to key on. Without it an hourly schedule would
  nag a student once an hour all day. `self::TITLE` must therefore stay a **fixed
  constant** — the dedupe query keys on it, so it must never vary with the count.
- It writes `notifications.sent_at` **explicitly** rather than relying on the
  column's `useCurrent()` DB default: that default uses the *database* clock,
  which ignores Carbon test-time travel and would break the same-day dedupe under
  test.
- **One reminder per trigger covers the WHOLE WEEK's backlog, not just today.**
  Because the dedupe allows at most one contact per student per day, a message
  mentioning only today could never mention the rest. `missingDatesThisWeek()`
  derives every day from the later of (this week's Monday, the batch
  `start_date`) through today with no `submitted` entry.
- **The yardstick is `BatchWorkingDays`, deliberately NOT `ReminderSchedule`** —
  a student narrowing their own reminder days must not thereby shrink the list of
  entries they owe.
- **A student who submitted TODAY but left earlier days blank IS reminded.** A
  student is skipped only when `missingDates === []`.
- The derivation mirrors `StudentDashboardController::countMissingWorkingDays()`
  and `JournalCalendarController::statusFor()` so the email can never disagree
  with the number on the student's own dashboard.
- Days render `l, F j, Y` in mail and `M j` in the bell row, so a week spanning a
  month boundary reads correctly.
- Bullets are a literal `-` character inside separate `->line()` calls, **not**
  markdown dashes: each `->line()` renders as its own paragraph, so a markdown
  list marker would emit a one-item list per day.
- **`MissingJournalEntryReminder::inAppMessage()` is a public static shared by
  the notification and the command**, so the bell row and the email can never
  describe the same trigger differently.

### Email delivery is gated on a VERIFIED address, and is therefore dormant

Mail goes out **only** when `email !== null && email_verified_at !== null`; the
notification row's `type` reports what actually happened (`'email'` vs
`'in_app'`). Everyone else still gets the in-app bell row silently, which is the
normal case for a coordinator-created student.

`toMail()` sends **from** the admin's System Settings `system_email` when set and
valid, falling back to `MAIL_FROM_ADDRESS`.

**`users.email_verified_at` is set by exactly ONE thing — the Google verification
flow.** A `migrate:fresh --seed` yields users with emails and **zero** verified,
so on fresh demo data nobody receives reminder email until they click "Verify
with Google". That is the intended gate, not a bug.

**CAUTION: the seeders use real-looking Gmail addresses.** The
`email_verified_at` gate is the only thing stopping a demo run from mailing a
real stranger's inbox, so **never let a seeder set that column.**

`.env.example` keeps **`MAIL_MAILER=log` as the committed default** so a fresh
clone can never accidentally email real students. `APP_NAME` must stay
`InternTrack` — it is user-visible in the mail header, the sign-off, the footer,
and `MAIL_FROM_NAME`.

### Mail transport

**Resend is the intended provider but is BLOCKED on not owning a domain.**
Laravel 13's `resend` transport is already present in `config/mail.php`;
switching is `composer require resend/resend-php` + `MAIL_MAILER=resend` +
`RESEND_API_KEY`, with **zero code change**. The blocker: Resend refuses to send
until a domain is verified via SPF and DKIM DNS records, and its shared
`onboarding@resend.dev` sender only delivers to the account owner.

Until a domain exists, the documented **Gmail app-password SMTP** recipe in
`.env.example` is the working path. **Gmail rewrites the From address to the
authenticated account**, so the System Settings `system_email` silently has no
effect under that transport (it works again under Resend) — use a dedicated
address, not a personal one.

**No queue worker is needed** — nothing implements `ShouldQueue`; the
notification sends inline. Deployments set `QUEUE_CONNECTION=sync`.

## Google OAuth — email verification + link-only sign-in

`laravel/socialite`, via `App\Http\Controllers\Auth\GoogleController`. Two
intents through **one** controller and **one** callback (so there is only ONE
redirect URI to register in Google Cloud).

- **`intent=verify`** — a signed-in user of any role proves they own a Gmail
  address. **Google SUPPLIES the address**; it is not confirming one the user
  typed, so the flow writes both `users.email` and `users.email_verified_at` in
  one step and a typo is impossible. Rejected if Google reports the address
  unverified, or if it already belongs to a different account.
- **`intent=login`** — signs in an account matched on `LOWER(email)` **AND**
  `email_verified_at IS NOT NULL`. **Both halves are the guard.** Because this
  controller is the only thing that ever sets that column, non-null means Google
  established the binding — so an address merely typed by an admin or student can
  never be signed into. **It NEVER creates an account and never assigns a role**;
  an unrecognised address gets `not_linked`, a deactivated one gets
  `deactivated`.

**Why it is safe without Socialite's session state:** the flow uses
`->stateless()` with an **encrypted state** (`Crypt::encryptString` of
`{intent, user_id, nonce, expires_at}`, 10-minute TTL) plus the same `nonce` in a
short-lived httpOnly `SameSite=Lax` cookie, compared with `hash_equals` on
callback. The cookie double-submit replaces the CSRF protection `stateless()`
gives up — including *login* CSRF, where there is no user yet to bind state to —
and, unlike a session, cannot be lost on the round trip once the SPA and API are
on different domains. `SameSite=Lax` is deliberate: it still rides the top-level
GET redirect back from `accounts.google.com`.

**Both flows send `prompt=select_account`, and that is a security control, not a
preference.** Without it Google *silently* auto-selects when exactly one account
is signed into the browser — so on a shared machine (computer lab, borrowed
laptop) a student clicking "Sign in with Google" would be signed straight in as
whoever last used the browser, with no password and no prompt. The verify flow
needs it equally: silent selection there binds someone else's Gmail onto your
account, and since a verified address authorises Google sign-in, that grants them
a permanent way in. Deliberately Google's own chooser rather than a confirmation
screen of ours — only Google can truthfully list the available accounts.

**Routes are WEB routes** (`routes/web.php`), not `api/*`, because all three are
top-level browser navigations: `GET auth/google/verify` (`auth`),
`GET auth/google/login`, `GET auth/google/callback` (no auth — the encrypted
state authenticates it). All three are `throttle:10,1`, since the `api` group's
limiter does not cover web routes.

**Two redirect-target rules, both learned the hard way — do not "simplify"
either back:**

1. **`auth/google/login` must NOT carry the `guest` middleware.** That middleware
   redirects an already-authenticated user to `/`, which on the API origin is the
   Laravel root route — a dead end on a different host from the SPA.
   `redirectToLogin()` handles the already-signed-in case itself.
2. **Every success redirect goes to `/{role}/dashboard`, NEVER to `/`.** The
   SPA's root route is an unconditional redirect to `/login`, and the router
   guard only calls `fetchUser()` for routes marked `requiresAuth` — so a browser
   landing on `/` with a perfectly valid session never asks the server who it is
   and gets bounced to the login page. The symptom is a successful sign-in that
   *looks* like it failed. `dashboardPathFor()` mirrors `roleRedirect()`.

**Editing an email revokes verification, and therefore Google sign-in** —
`ProfileController::update()` and `Admin\UserController::update()` both null
`email_verified_at` whenever `email` actually changes (via `forceFill`, since it
is deliberately not mass-assignable). This is also the un-link mechanism; there
is no separate "unlink Google" action.

**Deliberately NOT done: the `MustVerifyEmail` interface is not added to
`User`.** It is unnecessary (`markEmailAsVerified()` already exists via the
trait) and would activate the framework's verification listener. The `verified`
middleware alias stays applied to **zero** routes: **verification gates email
delivery and Google sign-in, never app access** — coordinator-created students
have no email at all, and a `verified` gate would lock out exactly the population
intake produces.

**With the credentials blank, `startFlow()` short-circuits to `not_configured`**
and the SPA says so. This guard is load-bearing, not defensive padding: without
it Socialite still builds a valid-looking redirect carrying an **empty
`client_id`**, and Google answers with its own "Access blocked" page, stranding
the user outside InternTrack with no route back. Scopes are `email` + `profile`,
both non-sensitive, so publishing needs no Google app review — but while the
consent screen is in **Testing**, each tester's Gmail must be added under Test
users.

Frontend: `web/src/lib/googleAuth.ts` holds the two entry-point URLs, the error-
code-to-copy map, and `consumeQueryParam()` (reads a one-shot return param and
strips it via `history.replaceState` so a refresh cannot replay the banner).
`web/vite.config.js` proxies `/auth/google` so dev stays same-origin.

## Reports

All three coordinator report editors share one shape: candidate rows are derived
from live data, the coordinator curates them, and **curation persists as an
override layer in a JSON column — source rows are never mutated.** Each stores
per-row overrides keyed by source id, `manual_rows[]` for missing data,
`deleted_ids[]` tombstones, and include/exclude. Only *included* rows export.

- **Annual SIPP Report** (`AnnualSippReportController`) — **per-program**, one
  tab per program in scope. Rows are sourced one-per-daily-journal-entry from
  students' `sipp:true` fields, joined to `batches` by `program_id` +
  `academic_year`. Persists in `sipp_annual_reports.report_data`, keyed by
  coordinator + program + AY. Annex **"C"**.
- **HTE & Student Interns List** (`HteReportController`) — a single **combined
  list per academic year** covering all programs in scope (not per-program tabs).
  Rows are one-per-enrollment from `batch_students`, mapped to 5 official
  columns: host establishment, student name ("Last, First M."), program (code +
  year level, e.g. `BSIT-4`), gender, and duration. Persists in
  `hte_reports.report_data`. Annex **"D"**.
  - The PDF **merges Host Establishment cells with `rowspan`** across consecutive
    same-company rows (`withHostEstablishmentSpans()`). The on-screen editable
    table is deliberately left one row per input — merging free-text-editable
    cells is not meaningful the way it is on a read-only printed table.
- **GROUP Student Information Sheet** (`GroupInfoSheetController`) — the
  per-company companion to the individual sheet, **filtered by COMPANY**. Persists
  in `group_info_sheets`, keyed `UNIQUE(coordinator_id, company_id, academic_year)`.

Both official report PDFs use a clean/white table header (no gray fill) and a
"(Name and Signature)" caption under each signatory block.

### GROUP Info Sheet — settled decisions

- **Band 1 (the roster) is REUSED student data**; **Band 2 (the company block) is
  typed by the COORDINATOR, never pulled from any one student's sheet** — a dozen
  students at one company each type their own address/signatory/supervisor and
  those disagree, so the coordinator's copy is the single source of truth.
- **An override only counts when it is NON-EMPTY** (`trim(...) !== ''`), unlike
  the HTE shape. An empty string is not null, so the HTE pattern would let a
  single Save taken while a student had not yet filled a field freeze that blank
  permanently. Here a blank saved cell means "no edit" and the student's own
  sheet keeps flowing through. Trade-off: a cell **cannot be blanked** — use
  include/exclude or delete to drop a row.
- **The roster GROWS** — no 12-row cap and no per-page repagination. The
  reference form's 12 pre-printed rows are a paper artifact, not a limit.
- **Scope**: a company with **zero** in-scope interns for that AY **403s** rather
  than rendering an empty sheet; the company picker only offers companies that
  actually host in-scope interns.
- **`MI` is the middle name's first letter, capitalized, no trailing period**
  (`mb_*` throughout so a non-ASCII initial survives; a `\p{L}` match skips
  leading punctuation, so `" de la Cruz"` yields `D`, not a space). Sourced from
  the student's own sheet first, falling back to `student_profiles`.
- **`Program & Year` renders as short code + prettified year (`BSIT 4th Year`)**
  — the group table's column is far too narrow for the full program name the
  individual sheet prints. The individual sheet's own rendering is unchanged.
- **The roster draws from `active` + `completed` enrollments**, excluding
  `dropped` and any row with `archived_at` set — a graduated intern was still
  genuinely hosted there, a dropped one was not.
- **NO logo and no annex letter.** The reference PDF contains zero images, and
  that absence is exactly what lets a full 12-intern roster, the company block
  and the sketch box share one page. The individual sheet keeps its logo.
- The department header line is coordinator-editable, defaulting to the literal
  `College of Accountancy, Business and Management` (the reference's own
  wording). It is **not** derived from `departments.name`, which is seeded to the
  same short string as the code (`CABM-B`) and would print wrongly.

### GROUP Info Sheet PDF — measured facsimile

`resources/views/pdf/info-sheet-group.blade.php` is pixel-matched to the client
reference. **The numbers below were extracted from it — do not "tidy" them.**

- **Page: US Letter 612x792pt**, set via `->setPaper('letter', 'portrait')`.
  dompdf defaults to **A4**, which silently shifts every column. This is the
  single most important line in the trait.
- **Type: Arial (dompdf maps to Helvetica, metrically identical).** Every heading
  and field label is **bold**; only row numbers and the sketch caption are
  regular. Sizes: header block 10pt, labels 9pt, the signatory label 8pt, row
  numbers 10pt, footer 11pt, roster data 8pt.
- **Colour**: section bars are **`#1F3864`** with white 10pt text. The sketch box
  is stroked **1pt navy**, not a black hairline — the one element stroked rather
  than filled. Everything else is pure black on white.
- **Only the roster is a bordered grid** (0.4pt black). The company block is a
  **borderless fill-in form** with a rule under each *answer* cell only, and no
  rule under either label column.
- **Row numbers are LEFT-aligned** — "1" and "10" both begin at the same x on the
  reference, which only holds for left alignment.
- **Four dompdf workarounds, each load-bearing:** (1) dompdf ignores `<colgroup>`
  and any width on a cell carrying `rowspan`/`colspan`, and `table-layout: fixed`
  distributes columns equally regardless — so both tables open with an invisible
  zero-height sizer row carrying the widths, under **auto** layout. (2) Those
  widths are **content-box**: dompdf adds padding on top, so each is written as
  *target minus horizontal padding*. (3) Section bars are `div`s **outside** their
  tables; as a `colspan` first row they defeated column sizing entirely. (4)
  Roster data cells are `white-space: nowrap` — a contact number is fractionally
  wider than its column, and the resulting wrap pushed a 12-intern sheet onto a
  second page.
- **Footer "Page N of M" is stamped via `$dompdf->getCanvas()->page_text()`, not
  CSS** — `counter(pages)` renders as `0` because dompdf does not know the total
  until layout completes. The trait calls `render()` before stamping, and
  `download()` reuses that render.
- **Capacity**: a full 12-row roster + company block + sketch box fits on **one
  page**. Re-check after any spacing change.

### PDF blades are standalone — do not port them onto `pdf/layout.blade.php`

Every official PDF in the project (daily journal, weekly log, Annual SIPP, HTE,
both info sheets) is standalone. `pdf/layout.blade.php` still exists but is
extended by **no** view; its base contradicts the measured forms on every axis
(font, colour, margins, and forced title elements).

## Role Surfaces

### Admin

Creates accounts (`Admin/UserController`) and global structure (departments,
programs, companies). `Admin/UserController::index()` **unconditionally excludes
`role = 'admin'`** regardless of filter — the page manages day-to-day accounts,
not the admin account itself. Create Coordinator collects First/Middle/Family
name and takes a single `department_id`.

Departments carry an optional `dean_name`. The audit log deliberately **does not
record `Logged In`/`Logged Out`** (routine session noise drowned out real
events); it does record batch and roster transitions, info-sheet accept/reject,
enrollment, weekly-journal approve/return, and journal submission via
`SystemLog::record()`.

### Coordinator

All pages are department-scoped via `User::coordinatorProgramIds()`; out-of-scope
403s.

- **Dashboard** — active-intern count, journals submitted vs missing this week,
  active batches, and a "students behind" list.
- **Journal Activities** — read-only monitoring, default today, filterable by
  `from`/`to` date range + company + program + status. A `show` endpoint returns
  one student's full entry for a day (every section label + the text), keyed the
  same way the student write page keys `journal_entries.content`.
- **Partner Companies** — scoped CRUD where scope = companies used by the
  coordinator's students **plus companies not yet linked to any enrollment** (no
  creator column exists, so unlinked implies visible, keeping freshly-created
  companies in view). Includes the representatives and supervisor-login panels.
- **Student Info Sheets** — the Accept/Reject queue; defaults to **All** statuses.
- **Users page** (`/coordinator/users`) — a secondary nav with an **Interns** tab
  (every in-scope student regardless of enrollment, each badged ENROLLED /
  NOT ENROLLED) and a **Supervisors** tab. With **no `created_by` column** on
  `users`, "supervisors the coordinator created" is realized as supervisors
  attached to any company in the coordinator's company-scope. Header actions are
  tab-contextual. "Create Supervisor" **requires a company first** — a supervisor
  is always a Company Supervisor.
- **Batch roster management** is separate from the enroll flow, scoped by batch
  program. Adding a student who is already active in another batch **MOVES** them
  (old row dropped, new active row, behind a wrong-batch-guard confirm).

Coordinators can create **student or supervisor** accounts only — never
coordinator/admin.

### Supervisor

Gated by a `role:supervisor` route group, scoped by company (see
`ScopesSupervisorWork`). The core action is reviewing the weekly narrative.

### Student

Dashboard, journal calendar, write daily journal, my journals, weekly journals,
**Weekly and Time Log Summary**, info sheet. The Student Dashboard is real, not
mock data — it returns submitted
counts, weekly logs approved/pending, this-week missing working days, two
completion-progress percentages, the student's own last-5 `SystemLog` rows, and
internship details.

Two facts about the dashboard week data that are easy to get wrong:

- **`week.end` is TODAY, not Sunday**, so the week strip spans Monday-to-today
  and grows from 1 to 7 segments across the week.
- **`missing_this_week` counts missing WORKING days** (it honours
  `working_days_per_week`) while the calendar-day count does not, so on a Mon-Fri
  batch viewed on a Saturday the two denominators genuinely differ.
- `progress.weekly_reports_approved_percent` is an approval rate over *weeks
  elapsed*, **not** the approved/pending split — do not fold it into a donut of
  those two counts; it would misreport.

KNOWN ISSUE: `Coordinator/CoordinatorDashboardController`'s
`journals_missing_this_week`/`students_behind` stats query
`JournalEntry::whereIn('status', ['missing','overdue'])` directly against the DB
column — which only ever holds `draft`/`submitted`, so **those two numbers are
silently always zero**. Fix by deriving them the way
`StudentDashboardController::countMissingWorkingDays()` does. Not yet done.

### Profile — a popover, not a page

There is **no `/{role}/profile` route**. The header avatar opens
`ProfileMenuPopover.vue`, which drills the same panel into Edit Profile / Change
Password / Activity Log / Log Out (a local `view` ref, not a route). Backed by
`ProfileController` — deliberately **not** role-namespaced, since the behavior is
identical for all four roles — exposing `PUT /api/profile`,
`PUT /api/profile/password`, `POST`/`DELETE /api/profile/photo`, and
`GET /api/profile/activity` (scoped to `system_logs.user_id = auth()->id()`).

- **Log Out is confirm-first**, default (blue) tone, not `danger` — nothing is
  destroyed, but a mis-tap on the avatar menu should not sign someone out.
- **Forced password change is popover-owned, not router-owned**: the popover
  watches `must_change_password` and force-opens into the password view with no
  back arrow, no outside-click dismissal, and a dimming backdrop, auto-closing
  once the flag clears. There is no page to redirect to.
- **The outside-click handler must use `event.composedPath()`, not
  `rootRef.contains(event.target)`.** Selecting a menu item unmounts the clicked
  button before the click finishes bubbling, so `event.target` is already
  detached and `contains()` always reads false — the popover slammed shut on
  every selection. It also returns early when the path contains a
  `role="dialog"`, since `ConfirmHost` teleports to `<body>` and its Cancel would
  otherwise read as an outside click.

### Notifications

`GET /api/notifications` (paginated + `unread_count`), `POST .../read-all`,
`POST .../{notification}/read` (403 unless owned), `DELETE /api/notifications`
(clear all, own rows only). `NotificationBell.vue` polls the unread count every
60s and shows the numeric count (capped display `9+`).

**`notifications.type` is a strict DB enum: `email` / `push` / `in_app`.** An
in-app business event uses `'in_app'`.

Business triggers so far: the missing-journal reminder, and **Info Sheet
submission**, which notifies the submitting student's batch's **coordinator**
(`batches.coordinator_id` — the single unambiguous owner, not every coordinator
in the department) only on a transition **into** `submitted`. It deliberately
does not fire on a draft autosave, a resave of an already-submitted sheet, or an
edit to an approved sheet.

## Architecture

### Routing & middleware

`routes/api.php` defines `/api/user` (`auth:sanctum`) plus role-gated groups.
Role gating is `App\Http\Middleware\EnsureRole`, aliased as `role` in
`bootstrap/app.php` (`role:admin`, or `role:admin,coordinator` for multi-role).
**It also blocks `is_active = false` accounts with a 403 before checking role.**

`Route::pattern()` constrains date-shaped route params (`date`, `weekStart`) to
`\d{4}-\d{2}-\d{2}` at the top of `routes/api.php`, so a malformed segment 404s
at the router instead of reaching an unguarded `Carbon::parse()` and throwing.

**ROUTE ORDERING HAZARD:** a literal segment must be registered **before** a
wildcard that would swallow it — `info-sheets/pending-count` before
`info-sheets/{student}`, and `group-info-sheets/{company}/{academicYear}/pdf`
before `{academicYear}`.

**`POST /register` was REMOVED** (and `Auth\RegisteredUserController` deleted).
Breeze's self-service registration created an **active `role: student` account
for any anonymous caller, unthrottled** — on a public URL, a stranger
self-enrolls into a coordinator's intake queue. Side effect: the `Registered`
event no longer fires anywhere.

**`/login` is split from the SPA's own page route.** The SPA posts credentials to
**`/auth/login`** (and `/auth/logout`), which both the Vercel rewrite and the
Vite dev proxy map back to the API's real `/login`/`/logout` — **no backend route
changed**. Necessary because `/login` is simultaneously Laravel's POST endpoint
and the SPA's router page path, and **Vercel rewrites match on path only, never
on HTTP method**, so proxying `/login` wholesale would send a page refresh (GET)
to Laravel, which only defines POST there, and answer **405**.

### Rate limiting

`RateLimiter::for('api', ...)` in `AppServiceProvider::boot()` — 120 req/min,
keyed by the authenticated user id falling back to the request IP. Per-user
rather than pure per-IP, so one abusive account on a shared office network cannot
throttle everyone behind that NAT. `throttleApi()` is appended **after** the
Sanctum prepend, so the session is resolved before the limiter reads the user.

`trustProxies(at: '*')` is set in `bootstrap/app.php`. Laravel trusts **no**
proxies by default, so behind Vercel it would ignore `X-Forwarded-For` and put
**every unauthenticated visitor in one rate-limit bucket**. Trade-off knowingly
accepted: a wildcard lets a caller spoof the header to dodge throttling, but
Vercel publishes no stable edge-IP list, and an accidental shared-bucket lockout
is the likelier harm.

### Controllers & requests

`app/Http/Controllers/{Admin,Auth,Coordinator,Student,Supervisor}/*`. Follow the
existing **Form Request + Controller** pattern (one Form Request per
create/update action) before inventing new patterns. There is no
`app/Http/Resources` directory; the one shared payload builder is
`App\Support\AuthUserPayload::build()`, called by **both** the `/api/user`
closure and `AuthenticatedSessionController::store` so the two can never drift.

### Web SPA structure

Pages in `web/src/pages/{admin,coordinator,student,supervisor}/`, role shells in
`web/src/layouts/*Layout.vue`. **Those four layouts are self-contained** — each
renders its own sidebar/header and shares only the smaller pieces
(`SidebarCollapseToggle`, `NotificationBell`, `ProfileMenuPopover`). There is no
shared `DashboardShell` wrapper.

Routes are central in `web/src/router/index.ts`, gated by
`meta: { requiresAuth, role }` and a global `beforeEach` calling the Pinia `auth`
store. API calls go through the shared Axios instance at `web/src/lib/axios.ts`.

Shared helpers live in `web/src/lib/` (not a `composables/` directory):
`toast.ts`, `apiError.ts`, `formDraft.ts`, `fieldLabels.ts`, `enrollment.ts`,
`googleAuth.ts`.

### Login critical path — one blocking round trip

`auth.login()` issues exactly **one** blocking request. `ensureCsrfCookie()`
memoises its in-flight promise at module scope and `LoginPage.vue` primes it
fire-and-forget on mount, so the cookie is fetched **while the user types**;
`login()` awaits the same promise, so correctness never depends on the prime
having run or succeeded. The POST response body carries the full user payload, so
there is **no follow-up `/api/user` fetch**. `LoginPage.vue` awaits
`router.push` so the button stays busy until the lazy chunks resolve.

If this file is ever re-merged or rebased, check those three script changes
explicitly — losing them silently reverts login to three blocking round trips,
and nothing fails loudly to signal it.

### Shared frontend infrastructure

- **`categorizeError(error, fallback?)`** (`lib/apiError.ts`) turns any Axios
  error into a `kind` / `message` / `fieldErrors` triple, where `kind` is
  `network` (no response at all, e.g. offline) / `validation` (422) / `auth`
  (401, 403) / `not_found` / `server` (5xx) / `unknown`. **Rollout is a pilot,
  not complete** — migrate remaining pages incrementally as they are touched.
- **`LoadStatus.vue`** replaces the copied three-state loading/error/content
  block and provides the app's Retry affordance.
- **`confirmAction()` is async** (a promise-based styled modal, **not**
  `window.confirm`) and **`promptAction()`** likewise; both are rendered by
  `ConfirmHost.vue`, mounted **once in `App.vue`**. `showToast()` instead needs
  `ToastHost.vue` mounted **per page**.
  - **Every call site passes an options object**, not a bare string: a `title`
    naming the outcome as a question, a `confirmLabel` matching the button the
    user clicked, and `tone: 'danger'` for anything that drops/archives/destroys.
- **Toast durations auto-scale**: `timeout` is optional; omitted, it derives from
  message length (3s base, +50ms per character past a 20-char grace window,
  capped at 8s; `error` toasts get a 4.5s floor since severity is its own
  signal). `MAX_VISIBLE_TOASTS = 4` evicts the oldest. Per-toast timer state
  lives in a module-level `Map`, deliberately kept off the reactive object.
- **`ValidationErrorList.vue` + `friendlyFieldLabel()`** render 422s as a proper
  alert with humanized labels ("End Date", not `end_date`). Also a partial
  rollout.

**UX RULE (app-wide, all four roles):** crucial/destructive actions
(delete/remove/deactivate/drop/archive) **confirm first**; successful saves
**toast after**.

### Form draft persistence (`lib/formDraft.ts`)

`useFormDraft(key, read, apply, options)` mirrors watched state into
**`sessionStorage`** (debounced 300ms, deep watch), restoring on mount. Keys are
namespaced `interntrack:draft:<key>`. Every storage access is `try/catch`-wrapped
— Safari private mode and a full quota both *throw*, and losing a draft must
never break a form. Malformed JSON is discarded rather than re-thrown.

- **`sessionStorage`, NOT `localStorage` — a privacy decision, not a technical
  one.** It survives refresh, back/forward and a same-tab crash, but is wiped
  when the tab closes. InternTrack runs on **shared MDC computer-lab machines**,
  where a half-typed info sheet (name, address, contact number, guardian details)
  must not remain readable to the next person. `localStorage` would keep it for
  days.
- **THE SECURITY RULE: never persist a credential through this helper.** What
  gets stored is exactly what `read()` returns, so **the allowlist IS the
  getter** and the exclusion is *structural*, not a denylist someone can forget
  to update. Web storage is plain text readable by any JS on the page, so
  persisting a credential turns any XSS into credential theft. Passwords have a
  correct home: the login form carries `autocomplete="username"` /
  `autocomplete="current-password"`, so the browser's own encrypted,
  OS-protected password manager handles refill. **Do not "helpfully" add a
  password to any `read()`.**
  - **`LoginPage.vue` is an explicit EXCEPTION** and does not use this helper at
    all — it persists username *and* password by direct `sessionStorage` write
    (keys `interntrack_login_username` / `interntrack_login_password`) at the
    project owner's instruction, clearing both on success and deliberately
    keeping them after a *failed* login. It was kept off `useFormDraft` precisely
    so the rule above still holds for the ~12 forms that do use it. Those writes
    are **synchronous per keystroke, not debounced**, so clear-on-success cannot
    be undone by a stale timer.
- **`autoRestore: false` is load-bearing on every server-populated form** — those
  pages fetch on mount, so an eager restore is silently overwritten by the
  response landing afterwards; they call `restore()` after the load resolves.
- **Drafts that must not cross contexts carry their own scope key** and are only
  re-applied on an exact match (the daily journal by date, Annual SIPP by program
  + academic year, HTE by year, Group sheet by company + year). Without this,
  switching tab/year/day spills one context's writing into another.
- **Create-modals only — edit-modals are deliberately excluded.** A modal is not
  a route, so a refresh closes it and the draft is re-applied on reopen.
  Restoring into an *edit* modal would paint a stale draft over a freshly-loaded
  record and present outdated values as current.
- **Report editors and System Settings genuinely clear on save, and that needs a
  `nextTick` before `clear()`** — applying the response mutates the watched refs
  and queues a write, so a synchronous clear is undone ~300ms later by the
  pending debounce.

### Caching — arrays only, never objects

Uses the already-configured **database** cache store (a `cache` table in the same
MySQL database), deliberately not Redis — no new infrastructure, matching the
zero-cost deploy stack. Cached with explicit invalidation at the known write
points (the TTL is a backstop, not the mechanism):
`User::coordinatorProgramIds()`, `Admin/ProgramController::index()`,
`Admin/DepartmentController::index()`, and `SystemSetting::cached()`.

Dashboard aggregates are deliberately **not** cached — they are per-user, change
frequently, and were not found to be expensive.

**RULE: never put an object in `Cache::remember()` — cache arrays/scalars and
rehydrate.** `config/cache.php` ships Laravel's default
`'serializable_classes' => false`, so `DatabaseStore::unserialize()` passes
`allowed_classes: false` and **any object** read back from a serializing store
(database, file, redis) returns as `__PHP_Incomplete_Class`. A cached
`Collection` or Eloquent model therefore **works on the first (cold) request and
500s on every request after it**. Deliberately NOT "fixed" by enabling
`serializable_classes` — that would disable a real security control app-wide to
patch four call sites.

This escaped the original pass because `phpunit.xml` pins `CACHE_STORE=array`,
and `ArrayStore` holds live objects in memory without ever serializing, so the
failing warm read never executed under test. The regression test forces the
**`file`** store to reproduce production behaviour.

### Dev proxy & CORS

`web/vite.config.js` proxies `/api`, `/sanctum`, `/auth/google`, `/auth/login`,
`/auth/logout`, `/forgot-password`, `/reset-password` to `VITE_BACKEND_URL`
(default `http://localhost:8000`), so the Vite dev server makes same-origin
requests. `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` in `.env` must match
wherever `web/` actually runs. CORS `allowed_origins` is pinned to
`FRONTEND_URL`, never `*`.

## Deployment

The operational runbook is **`docs/DEPLOYMENT.md`**; cron/email operator setup is
**`docs/CRON-AND-EMAIL-SETUP.txt`**. This section is the decision record.

Chosen stack, driven by a hard **zero-cost / no-credit-card** constraint:

| Piece | Host | Notes |
|---|---|---|
| SPA | **Vercel Hobby** | Root Directory `web`, config in `web/vercel.json` |
| API | **Render free web service, via Docker** | Spins down after 15 min idle; 30-60s cold start |
| DB | **Aiven always-free managed MySQL** | 1GB. Deliberately **not** Render's free Postgres, which expires 30 days after creation |
| Cron | **External scheduler pings `/api/cron/run` hourly** | Render's free tier has no cron |
| Avatars | Local `public` disk | Discarded on every redeploy; R2 is wired and is a pure env flip |

**Deploys track a dedicated `deploy` branch — pushing to `main` deploys
nothing.** `render.yaml` sets `branch: deploy`, and `web/vercel.json` carries
`git.deploymentEnabled: {main: false}`. Vercel's Production Branch must also be
set to `deploy` in its dashboard (there is no vercel.json field for it). Shipping
is deliberate: `git checkout deploy && git merge main && git push origin deploy`.

This is not tidiness: `docker/entrypoint.sh` runs `migrate --force` on **every**
boot, and the API sleeps and re-boots on idle, so with auto-deploy on main a
half-finished migration would reach live data on the next wake.

The **Aiven database is external**, so redeploys and sleep/wake cycles never touch
the data. The only thing lost on a redeploy is uploaded avatars.

### The SPA-to-API model is a same-origin rewrite proxy, not cross-domain CORS

`web/vercel.json` rewrites `/api/*`, `/sanctum/*`, `/auth/google/*`,
`/auth/login`, `/auth/logout`, `/forgot-password`, `/reset-password` to the API
host, plus a catch-all to `/index.html` for the SPA's `createWebHistory()` deep
links. The browser therefore only ever sees one origin, which means **CORS never
fires, `SameSite=None` is not needed, and login does not depend on third-party
cookies** (the fragile part of the cross-domain alternative — Safari and hardened
browsers block them).

Four load-bearing consequences:

- **`SESSION_DOMAIN` must be EMPTY** so the session cookie is host-only and
  attaches to the Vercel domain the browser believes it is talking to. Setting it
  to the API's domain breaks login.
- **`SANCTUM_STATEFUL_DOMAINS` must be the VERCEL host**, not the API's. Sanctum
  decides "is this the frontend?" from the request's Referer/Origin, which
  through the proxy is the Vercel domain. Wrong value means every authenticated
  request 401s while `/login` itself appears to succeed.
- **`GOOGLE_REDIRECT_URI` must point at the VERCEL origin**, and that exact
  string must be registered on the OAuth client. Send Google straight to the API
  and the session cookie lands on the API's domain while the SPA reads the Vercel
  domain, so sign-in silently appears to fail.
- **`VITE_BACKEND_URL` must be LEFT UNSET — the deployed SPA takes NO environment
  variables at all.** Unset is what keeps the Google entry-point links
  **relative**, so they ride the rewrite. Set to the API origin, the browser
  navigates straight to the API host and the same cookie-domain failure occurs.

`SESSION_SAME_SITE=lax` is correct here (not `none`), because the requests are
first-party. Lax still rides the top-level GET redirect back from Google.

**`config('app.timezone')` is `env('APP_TIMEZONE', 'UTC')` and deployments set
`Asia/Manila`.** Every user is UTC+8, and on a UTC server anything before 08:00
Manila still reads as **yesterday** — so the journal calendar offers the wrong
date, "this week" counts are off, and reminder hours fire 8 hours early. **The
default stays UTC on purpose** so the test suite's `Carbon::setTestNow` travel
behaves exactly as before.

### Scheduled work runs over HTTP, because the deployed app has no cron

`App\Http\Controllers\CronController` (`GET|POST /api/cron/run`,
`throttle:12,1`, unauthenticated but guarded by `CRON_SECRET`). A free external
scheduler pings this hourly. `routes/console.php` is **unchanged** — a host that
does have cron keeps working, and the two paths are safe to run side by side.

- **It deliberately does NOT call `schedule:run`, and that is the central design
  point.** `schedule:run` only fires a task whose cron expression matches the
  **current minute**, and an external pinger cannot promise the minute it lands
  on — cron-job.org jitters and a sleeping Render instance adds 30-60s of cold
  start. A ping at 10:03 would find an `hourly()` task not due and silently do
  nothing, every hour, forever. Each command is invoked **directly** instead.
- **Reminders run on EVERY ping with no marker** — safe because the command
  already self-gates twice (skip anyone whose hour differs; dedupe on user +
  title + today). That is also why it is *correct* at any minute.
- **The weekly-bundling marker is load-bearing, not an optimisation.** Bundling
  freely overwrites any unsubmitted draft, so an unguarded hourly ping would wipe
  a student's in-progress narrative once an hour. It may run **at most once per
  calendar week**. The purge marker is merely daily and avoids pointless work.
- Markers are **rows** in the existing `system_settings` table
  (`cron_last_purge_at`, `cron_last_bundling_at`) — no schema change. `Cache` was
  rejected because an eviction would silently re-run bundling and clobber drafts.
  A corrupt marker falls back to "due" rather than throwing.
- **A blank `CRON_SECRET` disables the endpoint (404), which is load-bearing** —
  without it `hash_equals('', '')` returns true and an unconfigured deployment
  would leave a public trigger that sends real email. Bad/missing keys 404 rather
  than 401/403, so probing cannot confirm the endpoint exists. Accepts
  `X-Cron-Key` **or** `?key=`, since several free cron services can only issue a
  plain GET with no custom headers.
- **The response body carries only each command's trailing summary line (counts),
  never per-student log lines** — cron providers retain response bodies in their
  execution history.
- Known limitation: mail sends inline, so a large cohort could exceed a cron
  provider's HTTP timeout. Fine at this scale; revisit if student numbers grow.

### Deploy artifacts & seeding

- `Dockerfile` (php:8.3-apache; GD built **with jpeg/webp/freetype** so avatar
  processing is not silently degraded to PNG; opcache tuned because a free tier
  pays a full cold start).
- `docker/entrypoint.sh` — binds Apache to `$PORT` (a container left on 80 fails
  Render's health check with no useful error), **hard-fails on a missing
  `APP_KEY`** and on `production` + `APP_DEBUG=true`, runs `migrate --force`,
  optional one-shot `SEED_ON_BOOT`, caches config/routes/views non-fatally.
- `.dockerignore` excludes `.env`, `vendor/`, `web/`, `tests/`, and any local
  `*.sqlite` — a committed sqlite file would otherwise become the production DB.
- `render.yaml` (free plan, **singapore** region as closest to Bohol,
  `healthCheckPath: /up`, secrets as `sync: false`).
- **`ProductionSeeder`** — `DepartmentProgramSeeder` + exactly one admin from
  `ADMIN_USERNAME`/`ADMIN_PASSWORD` (refuses without both; rejects passwords
  under 12 chars; never resets an already-changed admin password), plus the
  non-login `system` account with an unusable random password and
  `is_active = false`. **Never run bare `db:seed` on a deployment.**
- **`demo:set-password`** rotates every account except `system`, because **this
  repo is public and this file documents both the demo usernames and their shared
  password**. It clears `must_change_password` so a demo login is not interrupted
  mid-presentation, and rejects both the literal `password` and anything under 12
  chars (the literal check runs first, since a bare length error would hide the
  real reason).
- Avatar disk is configurable via `config('filesystems.avatars')` (`AVATAR_DISK`,
  default `public`); a dedicated **`r2`** disk is defined alongside `s3`. R2
  requires `region: auto`, path-style endpoints, and a **public** `R2_URL`, since
  the avatar URL is handed straight to an `img` tag.

## Avatars & Image Processing

`ProfileController::uploadPhoto()` runs every upload through
`App\Services\AvatarProcessingService`:

1. **`sniffType()` independently re-verifies the file is a genuine JPEG/PNG/WebP
   by reading its actual image header** (`getimagesizefromstring()`) — a real
   magic-byte check, not the extension/declared-MIME trust the `mimes` rule
   alone provides. Wired in via a `withValidator()` hook so a spoofed upload
   still 422s.
2. **`toAvatarImage()` center-crops to a square, resizes to exactly 250x250, and
   re-encodes via GD.** Re-encoding through a fresh `imagecreatetruecolor` canvas
   never carries EXIF forward, so **metadata stripping (including GPS) falls out
   of the resize for free** — no separate call needed.

The stored filename is always a fresh random `avatars/{random}.{ext}`; the
original filename and extension are fully discarded. Upload order is
**write-new-then-delete-old**, so a mid-request failure cannot leave a user with
no avatar.

**Encoding gracefully degrades to PNG when the runtime's GD lacks WebP**
(`toAvatarImage()` returns `{binary, extension}` and the caller builds the path
from it). This is a **defensive fallback, not a substitute for a proper GD
build** — a GD without JPEG support still cannot *decode* an uploaded `.jpg` at
all.

Client-side, `onPhotoSelected` does **not** auto-upload: it validates type/size
locally (specific messages before any network call), then opens
`AvatarCropperModal.vue` (plain canvas + pointer events, **no new npm
dependency**) for pan/zoom with a circular crop guide. Only on confirm does it
POST the cropped square blob.

## Gotchas

Hard-won, each from a real debugging session. Do not "simplify" any of these away.

### `date`-cast columns and plain equality under SQLite

MySQL's `DATE` truncates any time component on write; **SQLite does not**. So a
`date`-cast attribute written as `'2026-07-06'` can come back out of SQLite as
`'2026-07-06 00:00:00'`. A plain `where('week_start', $dateString)` — or
`updateOrCreate()`'s match array, which is the same thing internally — silently
fails to find the row it just created, and a second write inserts a duplicate
instead of updating.

**Always use `whereDate()` against a `date`-cast column.** The same flaw applies
to `whereBetween` on a range: the last day is dropped because
`"...-02 00:00:00"` sorts *after* a bare `"...-02"` bound. This bit
`WeeklyBundlingService`, `WeeklyLogController::store()`, and
`JournalCalendarController`'s month query before being fixed the same way.

### `??` does not null-safe a chained expression

`$log->submitted_at?->toIso8601String() ?? null` still throws when `$log` itself
is null — PHP's `??` only suppresses the warning for a *direct* property/array
access on its left operand, not for a longer chained expression. Null-safe the
**base**: `$log?->submitted_at?->...`.

### `#[Fillable(...)]` exclusion means `->update()` silently no-ops

Columns deliberately kept out of the fillable list (`completed_at`,
`archived_at`) must be set by **direct property assignment + `save()`**. Using
`->update(['archived_at' => ...])` passes Laravel's fillable filter and does
nothing, with no error.

### `APP_URL` must carry the port, or every uploaded avatar is a broken image

`config/filesystems.php`'s `public` disk builds its URL from `APP_URL`, and
`User::avatarUrl()` builds `avatar_url` from that. Laravel's stock
`APP_URL=http://localhost` emits port 80 — which on a Laragon/Apache machine is
not `artisan serve` on :8000. Set `APP_URL=http://localhost:8000`; if you run
`serve --port=NNNN`, `APP_URL` has to follow.

**Diagnostic rule: on a broken avatar, 404 means the wrong host/port in
`APP_URL`; 403 means the file genuinely is not on disk.** A missing file under
`/storage/...` returns **403, not 404**, because the `local` disk's
`serve => true` registers a route resolving against `storage/app/private` while
real avatars live on the `public` disk and are served off the symlink before
Laravel is reached. Orphan files are normal — `migrate:fresh --seed` nulls
`users.avatar_path` but never sweeps the directory.

### A Windows PHP build ships NO CA bundle, so every outbound HTTPS call fails

The official php.net/WinGet Windows builds leave both `curl.cainfo` and
`openssl.cafile` empty, and Guzzle then dies with `cURL error 60: unable to get
local issuer certificate`. **This is not OAuth-specific** — it breaks Google's
token exchange, SMTP over TLS, and any third-party API call identically. The
symptom is indistinguishable from bad credentials.

Fix: download `https://curl.se/ca/cacert.pem` and point **both** ini keys at it.
**A WinGet upgrade of PHP replaces `php.ini` and silently wipes both lines** —
re-apply them.

**Diagnostic rule: when any integration "just fails", test raw TLS reachability
first** (a Guzzle GET to the provider from `artisan tinker`) before suspecting
credentials or app logic.

### Local verification: the Vite port must be in `SANCTUM_STATEFUL_DOMAINS`

If 5173 is occupied Vite silently falls back to 5174, and the symptom is deeply
misleading: **`POST /auth/login` returns 200 with the user JSON while the very
next `GET /api/user` returns 401**, and the SPA shows "Invalid credentials" —
because Sanctum decides statefulness from the Referer host and never issues the
session cookie. Passing `SANCTUM_STATEFUL_DOMAINS` as an env var to
`artisan serve` does **not** override the `.env` value. What works without
touching `.env`: run Vite on 8000 (already listed) and
`php artisan serve --port=8001` with `VITE_BACKEND_URL=http://localhost:8001`.

### Tailwind v4 emits `oklch()`, not `rgb()`

A colour assertion written against `rgb(37, 99, 235)` fails on a perfectly blue
element — `getComputedStyle` returns e.g. `oklch(0.546 0.245 262.881)` for
`blue-600`. Also: **a mistyped Tailwind utility fails silently, not at build
time**, so check the built CSS when introducing new utilities.

### Measuring layout in a browser test

Asserting "everything is vertically centred" by iterating leaf nodes gives a
false failure — a stacked two-line block is itself centred while each line sits
off-centre. Measure a container's **direct flex children**, not its leaves.

**Audit overflow in BOTH directions, and test dropdowns in their OPEN state.** A
`scrollWidth > clientWidth` check cannot see content spilling **leftward**, and a
closed dropdown hides that entire class of bug.

### The shared Axios instance has NO `baseURL` — always call `/api/...`

`web/src/lib/axios.ts` sets only `withCredentials`/`withXSRFToken`. A relative
path like `api.get('student/weekly-logs')` therefore resolves against the
**current page URL**, so from `/student/weekly-time-log` it requests
`/student/student/weekly-logs`, which the dev server answers with the SPA
fallback (or a 404) instead of JSON. Every call site must use the leading
`/api/` form.

**Build, type-check and the backend test suite all pass with the wrong path** —
it is a runtime string, the tests hit controllers directly, and TypeScript
cannot know a URL is wrong. Only loading the page catches it, which is why a new
page must be opened in a browser before it is called done.

### Vue: outside-click handlers must use `composedPath()`

Selecting a menu item unmounts the clicked button before the click finishes
bubbling to `document`, so `event.target` is already detached and
`rootRef.contains(target)` always reads false. `event.composedPath()` is captured
at dispatch time and stays correct even after the DOM mutates mid-event.

### Windows/tooling notes

- There is **no type-check script in the repo** — `npm run build` is plain
  `vite build`, which does **not** type-check. A bare `npx vue-tsc` fails with
  `ERR_PACKAGE_PATH_NOT_EXPORTED`; use a pinned isolated toolchain
  (`vue-tsc@2.2.10` + `typescript@5.9.3`).
- `intl` is not installed and is **not used** anywhere. The procedural `sqlite3`
  extension is absent but irrelevant — Laravel's SQLite testing driver uses
  `pdo_sqlite`.
- `storage/app/public` must be linked into `public/storage` (`storage:link`).

## Frontend UI conventions

Applies to the Vue SPA in `web/`. These describe what the code already does —
follow them rather than inventing a parallel style.

- **Card chrome**: `rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70` on
  every card. Tinted stat cards swap the white fill for a `-50/50` wash of their
  accent (`bg-blue-50/50`, `bg-emerald-50/50`, `bg-amber-50/50`, `bg-rose-50/50`)
  with `ring-1 ring-slate-200/60`.
- **Typography scale**: numerals `text-3xl font-semibold tracking-tight` (not
  extrabold); labels `text-xs font-medium uppercase tracking-wide text-slate-400`;
  section headings `text-sm font-semibold text-slate-900` with no left accent bar.
- **SVG progress convention**: always give the arc `pathLength="100"`, then
  `stroke-dasharray="100"` with `stroke-dashoffset="100 - percent"`. Never
  hand-compute a circumference — `pathLength` re-bases the geometry so the radius
  stops mattering. Gradient ids must be unique per instance (`gauge-ojt`, not
  `gauge`), since duplicate ids break rendering. Clamp every percentage to 0-100
  before use, and render the neutral track alone when the denominator is 0 rather
  than faking a fill. A zero-length segment still paints a dot under
  `stroke-linecap="round"`, so omit the element entirely instead of drawing it at 0.
- **Dashboards render only fields the API already returns.** No invented counts,
  placeholder trends, or sample time-series. A number with no honest denominator
  gets no bar; a missing value renders an em-dash.
- **Tooltips**: `web/src/components/ui/TooltipWrap.vue` provides the visual
  (`label`, optional `placement`). The tooltip element is `aria-hidden`
  decoration — the accessible name comes from an `aria-label` on the control
  itself, carrying the same string. Never add a native `title` alongside it; the
  browser would render a second tooltip on top. Shown on both `group-hover` and
  `group-focus-within`, so keyboard focus reveals it exactly like hover.
- **Sidebar collapse toggle** is anchored to the sidebar's right edge
  (`absolute right-0 translate-x-1/2` on the `aside`, which is `fixed` and
  therefore already the positioning context), not free-floating. It sits outside
  the scrolling `nav` so it never scrolls with the nav list.
- **Primary action buttons use the blue token** (`bg-blue-600` /
  `hover:bg-blue-700`); green is reserved for secondary and status actions, and
  black or dark-neutral primary buttons are not used.
- **Modals use a three-part flex shell, never `sticky` on the footer.** The
  overlay is `fixed inset-0 z-50 flex items-center justify-center p-4` and carries
  **no** `overflow`; the panel is
  `flex max-h-[90vh] w-full max-w-* flex-col overflow-hidden rounded-xl bg-white shadow-xl`;
  inside it sit exactly three siblings — a `shrink-0` header with `border-b`, a
  `flex-1 overflow-y-auto px-6 py-5` body that is the **only** scrolling element,
  and a `shrink-0` footer with `border-t bg-white px-6 py-4`. No element between
  the panel and the body may have its own overflow or height. A `sticky bottom-0`
  footer was tried and does not work: when the overlay is the scroller, the panel
  grows past the viewport and the footer's containing block has no bottom edge on
  screen to pin against, so the bar drifts up over the content.
- **Render a date by slicing the string, never by parsing it.** Take the leading
  10 characters (`value.slice(0, 10)`). Almost every date the API returns is
  either a `date`-cast column Laravel serialises at midnight UTC
  (`"2026-05-29T00:00:00.000000Z"`) or a bare unmarked `"2026-07-29 21:37:00"` —
  and `new Date()` on either can land a day earlier once `APP_TIMEZONE` is
  `Asia/Manila`, which is what deployments set. Slicing cannot drift. Where the
  time still matters, keep the untouched original in a `TooltipWrap` beside the
  sliced value. A wall-clock time (`"21:00:00"`) is likewise formatted by
  splitting on `:` — it has no date, so there is no instant for a `Date` to
  represent. Only a genuine instant carrying a timezone marker may be parsed.
- **Derive a journal field's label from its key — never hardcode a map.**
  `journal_entries.content` is keyed by whatever the coordinator's template
  defines, so the keys differ per program and the API returns no label alongside
  them. Humanise the key instead (split on `_`, capitalise each word:
  `task_performed` becomes `Task Performed`), which stays correct for a template
  nobody anticipated; a hardcoded lookup silently falls back to raw snake_case the
  moment a coordinator adds a section. Render each as a label above its value, and
  skip fields whose value is blank rather than printing an empty label.
- **A stat card links to a filtered page only if that page actually reads the
  query param.** Several list pages hold their filter in a local `ref` and never
  consult `route.query`, so a `?status=...` link navigates without filtering and
  silently lies about where it is taking you. Check the destination page first.
- **A table's actions cell is one right-aligned row of buttons at a fixed column
  width.** `flex items-center justify-end gap-2 whitespace-nowrap`, buttons
  `px-3 py-1.5 text-sm`, the neutral action a bordered outline button and the
  destructive one red text-only. The width belongs in a `colgroup` enforced by
  **`table-fixed`** — without `table-fixed` a browser treats col widths as hints
  and a long name can still squeeze the cell into wrapping onto two lines.
- **Data tables scroll inside their own container.** The table card is
  `overflow-x-auto`, never `overflow-hidden` (which clips instead of scrolling),
  so the page itself never scrolls horizontally.
- **Layouts are mobile-responsive via one shared off-canvas drawer pattern** —
  all four use a `mobileOpen` ref, `-translate-x-full` / `translate-x-0` slide, a
  `bg-black/40 md:hidden` backdrop, an `md:hidden` hamburger, and close-on-nav-link
  / close-on-backdrop. **Copy the pattern, do not re-invent it.**

### Dashboard structure

All four dashboards follow one order. A role skips a section only when it has no
real data for it.

1. **Scope/status notices** — role-specific, one compact row each (`px-4 py-3`),
   longer explanation moved into a `TooltipWrap` on an info icon.
2. **Hero card** — `rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70`
   holding an `h-14 w-14` initials avatar on `bg-blue-50`, `Hello, {first name}`
   in `text-xl font-semibold tracking-tight`, a muted subtitle of the role's most
   relevant context, and the page's single blue CTA as a `rounded-full`
   `RouterLink` with a right-arrow. The CTA target is the role's primary sidebar
   route, **copied from the layout's `navItems` rather than retyped**. A meta grid
   is rendered **only** where the endpoint returns fields worth showing — today
   that is the student alone.
3. **Stat row** — `grid gap-4 sm:grid-cols-2 xl:grid-cols-4`, cards
   `flex h-full flex-col rounded-xl p-6 ring-1 ring-slate-200/60` with a `-50/40`
   tint, an `h-9 w-9` **white** icon tile carrying `ring-1 ring-slate-200/70` and a
   coloured glyph, the label beside the tile, the numeral
   `text-3xl font-semibold tracking-tight`, and a caption under. Accent order
   where the meaning matches: **blue** neutral, **emerald** good, **amber**
   waiting, **rose** needs attention. Only those four hues.
4. **Panels row** — under a `text-xs font-medium uppercase tracking-wide
   text-slate-400` section heading with `mb-3`; the grid is `items-stretch` and
   each panel `flex h-full flex-col` so a row is equal height. Every panel heading
   carries a one-line muted subtitle describing what it shows.
5. **Detail list** — activity feed, queue, or table.

- **Exactly one filled blue button per dashboard: the hero CTA.** Everything else
  is an outline button, a text link, or a card that happens to be a link. A second
  filled blue button means the page no longer has a primary action.
- **Amber means one thing: the single most actionable item.** At most one amber
  *notice* per page; any second notice drops to neutral slate (`bg-slate-50` +
  `ring-slate-200/70`), and the actionable notice sits above the informational
  one. Amber as a stat-card accent or a data-series colour is unaffected.
- Page rhythm is `space-y-6`.

## Conventions

- **Laravel**: RESTful API controllers, Form Requests for validation, Policies for
  authorization once introduced. Follow existing repo patterns before inventing
  new ones.
- **Vue**: pages in `src/pages/<role>/`, shared components in `src/components`,
  Pinia stores in `src/stores`, types in `src/types`. Inspect before creating.
- **Naming**: snake_case for DB columns, camelCase in TS/JS, StudlyCase for PHP
  classes.
- **Seeders must remain re-runnable** — `php artisan migrate:fresh --seed` must
  always work.

## Verification — Evidence Before Claims

Never say something is "done", "fixed", or "working" without running a check and
showing the output:

- **Backend**: `php artisan test` (or the specific test), plus
  `php artisan migrate:fresh --seed` when migrations changed.
- **Frontend**: `npm run build` (in `web/`) must succeed.
- **After fixing a bug**: reproduce it first if possible, then show the passing
  result.

## Common Commands

```bash
# API (run in repo root)
php artisan serve
php artisan migrate:fresh --seed
php artisan test
php artisan test --filter=TestName            # single test
php artisan test tests/Feature/Coordinator    # a whole role folder
composer install
./vendor/bin/pint                             # code style fixer (no composer script wired up)

# Backend + queue + logs + Vite together
composer run dev

# Manual triggers for the scheduled jobs
php artisan journal:run-weekly-bundling                    # optional --week-start=
php artisan journal:send-missing-entry-reminders --ignore-time
php artisan roster:purge-archived                          # optional --now=

# Web SPA (run inside web/)
npm install
npm run dev
npm run build      # no lint or test script configured; there is no frontend test runner yet

# Mobile (run inside mobile/)
npm install
npx expo start
```

`tests/Feature` splits into `Admin` / `Auth` / `Console` / `Coordinator` /
`Services` / `Student` / `Supervisor`.

### Seeded demo accounts

From `php artisan db:seed`, password `password`. **Log in by USERNAME** (email
also works for accounts that have one).

| Username | Role |
|---|---|
| `mdcadmin` | admin |
| `mdccore` | CAST/BSIT coordinator |
| `mdcbalbero` | CABM-B coordinator (Balbero) |
| `mdcstudent` | enrolled student |
| `mdcsupervisor` | supervisor |
| `system` | non-login automation account |

**Intake-flow demo** (`CabmbIntakeDemoSeeder`, CABM-B under Balbero, both
NOT-enrolled, re-armed each seed): `mdcintake` has a **draft** sheet (log in
gated, fill, choose company, submit), and `mdcintake2` has a **submitted** sheet
naming a `supervisor_name` deliberately distinct from that company's login
supervisor — so it sits in Balbero's Submitted queue ready to **Accept**, a live
demonstration of the login-vs-named-individual split.

A one-off `PreOralDefenseDemoSeeder` exists but is deliberately **not** registered
in `DatabaseSeeder`; run it directly with `--class=`. See
`docs/PRE-ORAL-DEFENSE-DEMO-GUIDE.txt`.

## Workflow Preferences (project owner)

- Propose a plan for any multi-file change before implementing; wait for approval.
- Prefer direct numbered steps with exact terminal commands over conceptual
  explanations.
- When a task touches the database, always cross-check the schema first.
- If instructions in this file conflict with what you find in the repo, **stop and
  ask instead of guessing.**

## Roadmap Context

Seven-phase roadmap. Phases 1-2 (scaffolding, auth, base schema, admin
identity/role management) are complete. Coordinator, student, and supervisor
modules are built out. **Mobile (Expo) integration is Phase 7 — do not wire
mobile auth or endpoints yet unless asked.**
