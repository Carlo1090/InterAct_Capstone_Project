# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview
InternTrack is a capstone OJT/internship monitoring system for Mater Dei College (Bohol, Philippines), built by a 4-person team (Group 1). It digitizes SIPP internship processes: student profiles, attendance/clock-in, weekly activity journals, evaluations, and reporting.

This is a **monorepo** with three parts:
- **repo root** — Laravel 13 REST API (PHP 8.3, MySQL/SQLite). There is no `api/` subfolder — the Laravel app lives at the top level (`app/`, `routes/`, `database/`, etc.)
- `web/` — Vue 3 SPA (Vite, Tailwind CSS v4, **TypeScript** — `.ts` files, `tsconfig.json`, and the `typescript` package are already in use under `web/src`)
- `mobile/` — React Native / Expo app (Expo SDK 56, TypeScript, expo-router). Currently just the default Expo template scaffolding — deferred until Phase 7, do not wire real auth/endpoints into it yet unless asked

## Tech Stack (do not change without asking)
- Laravel 13, PHP 8.3, MySQL (SQLite for local/testing)
- Auth: Laravel Breeze + Sanctum — **cookie/session-based** for the web SPA (`EnsureFrontendRequestsAreStateful`); token-based auth for mobile is DEFERRED to Phase 7
- Vue 3 (Composition API, `<script setup>`, TypeScript), Vue Router, Pinia
- Tailwind CSS v4 (no v3 syntax, no `tailwind.config.js` patterns that v4 removed)
- Axios for API calls with CSRF/Sanctum cookie flow (`withCredentials`, `withXSRFToken`)

## Hard Rules
1. **Do not create a duplicate migration.** Before creating any migration, check `database/migrations/` for whether a table/column change already exists. If a migration for that table exists and hasn't shipped, EDIT it instead of adding a new one.
2. **Do not add a second middleware layer or restructure app architecture** without explicitly proposing it first and waiting for approval.
3. **Stay on the finalized schema** (InternTrack_Database_Schema_v2, 20 tables). Do not rename tables/columns or add tables without asking. Key structural decisions already made:
   - `weekly_activity_logs` is split into a header table + `weekly_activity_log_entries` (mirrors the physical SIPP form) — same pattern for the separate `weekly_logs`/`weekly_log_entries` and `journal_entries`
   - Separate `programs`, `departments`, `student_profiles`, `journal_templates`, `system_settings` tables exist
   - `programs` has a composite unique constraint on `(department_id, code)` — departments are independent top-level units (e.g. CABM-B and CABM-H are two separate departments, not sub-units of one "CABM"); don't introduce a department→division→program hierarchy without confirming first
4. **Out of scope — do not build:** geofence clock-in, rotating QR clock-in, photo capture on clock-in, exit interview report generation.
5. Work on a **feature branch**, never directly on `main`. Small, focused commits with clear messages.

## Domain Facts
- 3 departments, 7 programs:
  - CAST: BSIT
  - CABM-B: BSBA-FM, BSBA-MM, BSBA-OM, BSA
  - CABM-H: BSTM, BSHRM
- Roles (`users.role` enum, exactly these four): `admin`, `coordinator`, `supervisor`, `student`. `supervisor` means company supervisor (see `CompanySupervisor`, linking a `supervisor`-role user to a `Company`). There is no separate "adviser/instructor" role.
- User deactivation is **soft** (`is_active = false`) — there is no hard-delete route for users, since a deactivated student/supervisor's journal entries and weekly logs must stay intact.
- Creating a `student`-role user auto-creates a matching `student_profiles` row via `UserObserver` (on the `User` model's `created` event), with a placeholder `student_id_number` (`PENDING-XXXXXXXX`) until the real registrar ID is filled in.
- SIPP compliance documents referenced by the system: OJT Annual Report, Summary Report on Student Exit Interview (report itself out of scope), Student Information Sheet.

## Roles & Configuration
- Admin creates accounts (`Admin/UserController`) and global structure (departments, programs, companies). Creating a student user does **not** enroll them — see below.
- Coordinators own `batches` for their program(s) (`Coordinator/BatchController`) and enroll students into a batch (`Coordinator/EnrollmentController`), which creates the `batch_students` row. `batch_students` is the **authoritative student↔company/supervisor linkage**; every student-facing endpoint (info sheet, journal entries, weekly logs, weekly activity logs) requires an active (`status='active'`) `batch_students` row via `ResolvesStudentEnrollment::activeEnrollment()`, and 422s with "You are not currently enrolled in an active OJT batch." until one exists.
- Coordinators are assigned to DEPARTMENT(S), many-to-many, via the `coordinator_departments` pivot (`User::departmentsCoordinated()` / `Department::coordinators()`) — a coordinator can run multiple departments (e.g. a lead + assistants sharing one), and admins manage the assignment from the Admin Departments page (`Admin/DepartmentController::assignCoordinator`/`removeCoordinator`). A coordinator's programs are resolved via `User::coordinatorProgramIds()` — every program in their assigned department(s), merged with the programs of batches they already coordinate (`batchesCoordinated()`, kept only as a backward-safety net) — not `batchesCoordinated()` alone. `users.program_id` is retained (still used by account creation/`StoreUserRequest`) but is **no longer** the coordinator scoping source.
- Students self-scaffold their Student Information Sheet after enrollment (`Student/StudentInfoSheetController::show` returns a pre-filled empty scaffold from their profile + enrollment; `store` upserts it, requires an active enrollment).
- The **Annual SIPP Report** (`Coordinator/AnnualSippReportController`, page `web/src/pages/coordinator/CoordinatorAnnualSippPage.vue`) is **per-program** — a secondary nav shows one tab per program in `User::coordinatorProgramIds()` scope; an out-of-scope program 403s. Candidate rows are sourced one-per-daily-journal-entry from students' `sipp:true` journal fields (`issues_concerns`/`solutions`/`recommendations`), joined to `batches` by `program_id` + `academic_year`. The coordinator curates (edit any of the 3 cells, include/exclude, delete row) — curation, signatories, and the editable "DEGREE PROGRAM" heading persist in `sipp_annual_reports.report_data` (JSON), keyed by coordinator+program+AY (`program_id` added nullable, `batch_id` made nullable). Only included rows export via dompdf (`resources/views/pdf/annual-sipp-report.blade.php`, official-document layout).
- The **HTE & Student Interns List** (`Coordinator/HteReportController`, page `web/src/pages/coordinator/CoordinatorHtePage.vue`) is a **separate** coordinator report from the Annual SIPP Report. It is a single **combined list per academic year** (not per-program tabs) covering all programs in `User::coordinatorProgramIds()` scope. Candidate rows are derived one-per-enrollment from `batch_students` (joined to `batches` by `program_id` + `academic_year`), mapped to the 5 official columns: host establishment (`company.name`), student name ("Last, First M." when a clean split exists), program (code + year level, e.g. `BSIT-4`), gender (`student_profiles.sex`), and duration (batch `start_date`–`end_date`). The coordinator curates (edit any cell, include/exclude, delete, **add a manual row** for incomplete data) and edits editable signatories; curation persists as an override layer in the new `hte_reports.report_data` (JSON), keyed by coordinator + nullable `program_id` + AY, leaving source enrollments untouched. An optional `program_id` query filter is authorized against scope (403 out-of-scope), mirroring `AnnualSippReportController`. Only included rows export via dompdf (`resources/views/pdf/hte-report.blade.php`, official-document layout).
- The four other coordinator pages are now **real + department-scoped** (via `User::coordinatorProgramIds()`, out-of-scope 403s): **Dashboard** (`CoordinatorDashboardController`) — active-intern count, journals submitted vs missing this week (Mon–today), active batches, and a "students behind" list (in-scope active interns with ≥1 `missing`/`overdue` daily entry this week); **Journal Activities** (`CoordinatorJournalActivityController`) — read-only monitoring, default = today, filterable by `from`/`to` (Y-m-d) date range + `company_id`, per-student submitted/missing tally over a range (use `whereDate`, not raw `whereBetween`, so same-day matches hold under SQLite's datetime storage); **Partner Companies** (`CoordinatorCompanyController`) — scoped CRUD where scope = companies used by the coordinator's department students **plus companies not yet linked to any enrollment** (no creator column exists, so unlinked ⇒ visible, which keeps freshly-created companies in view), with a supervisors panel that attaches an existing `supervisor`-role user or creates+attaches a new one inline via `company_supervisors`; **Student Info Sheets** (`CoordinatorInfoSheetController`) — read-only view of in-scope students' latest sheets (info-sheet PDF export is deferred to the Student page cleanup, marked with `TODO(student-cleanup)`). `companies.description` (nullable text) was added for the companies form.
- Coordinators can create **student or supervisor login accounts** from the Interns page (`EnrollmentController::createAccount`, `CreateAccountRequest`): role is restricted to `student`|`supervisor` only (never coordinator/admin), password hashed, `is_active` true, and a student also gets a `StudentProfile` (a student's `program_id` must be in the coordinator's scope). This is **account creation only, distinct from enrollment** — a created student still must be enrolled into a batch (company + supervisor) via `EnrollmentController::store`; the Interns page keeps the two as separate controls.
- The daily-journal length limit is **character-based**: `journal_templates.char_limit` (renamed from `word_limit`, default **1500**) validates the whole entry's combined content with `mb_strlen` in `StoreJournalEntryRequest`; per-SIPP-field cap stays at **300** chars. Coordinators set it on the Journal Templates page ("Character Limit"), and the student write page counts characters (`X / char_limit`). The journals-list `word_count` display attribute is a separate, unrelated concern.
- **Journal templates are many-programs-per-template**: `journal_templates.program_id` was dropped in favor of the `journal_template_program` pivot with **`UNIQUE(program_id)`** (a program belongs to **at most one** template, ever — the pivot is the single source of truth). Relations: `JournalTemplate::programs()` / `Program::journalTemplates()` (both `belongsToMany`; a program's template is `journalTemplates()->first()`). Template create/update take **`program_ids[]`** (required, min 1, each in `coordinatorProgramIds()`); a program already claimed by a **different** template 422s (naming the conflict, so `UNIQUE(program_id)` never surfaces as a 500); `store/update` `sync()` the pivot. `index()` returns each in-scope program with a nullable **`assigned_template_id`** so the UI can grey out already-covered programs. A batch still resolves its template via **`batches.journal_template_id`** (unaffected by the drop); the two coordinator batch requests validate that FK against the pivot. **SIPP (Annex C) is a fixed trio** keyed `issues_concerns`/`solutions`/`recommendations` — a section may only be flagged `sipp=true` if its key is one of those three (server-guarded in `ValidatesJournalTemplate`; the frontend owns adding/removing the trio). `journal_templates.is_active` stays (soft on/off).
- The two official report PDFs carry annex labels top-right: the **Annual SIPP Report is Annex "C"** (`resources/views/pdf/annual-sipp-report.blade.php`) and the **HTE & Student Interns List is Annex "D"** (`resources/views/pdf/hte-report.blade.php`); both use a clean/white table header (no gray fill) and a "(Name and Signature)" caption under each signatory block.
- **Coordinator UX rule**: crucial/destructive actions (delete a report row, deactivate a template) prompt a **confirm dialog first**; successful saves show a **toast after**. Use the shared helpers in `web/src/lib/toast.ts` (`confirmAction`, `showToast`) with `web/src/components/ToastHost.vue` mounted on the page.
- The **Supervisor** pages (`app/Http/Controllers/Supervisor/*`, gated by a `role:supervisor` route group; pages in `web/src/pages/supervisor/*`) are **real + scoped by `batch_students.supervisor_id` = the authed supervisor** (the "my interns" scope, via the `ScopesSupervisorWork` concern; a supervisor touching a student/log outside that scope 403s). The core action is reviewing a student's **weekly narrative journal** (`weekly_logs`): `SupervisorJournalController@approve` (status `approved`) and `@returnLog` (status `returned`, requires a `supervisor_comment` via `ReturnWeeklyLogRequest`); both stamp `supervisor_id` + `reviewed_at`. Only logs that are **submitted** (`submitted_at` set) and still `pending`/`returned` are reviewable — drafts and already-finalized logs 422. The **student side already displays** the resulting `status` + `supervisor_comment` (do not rebuild it). Same confirm/toast rule: **Return** confirms first, **Approve** toasts after. DTR/QR/geofence clock-in is a **separate pending feature — not built here**.
- The coordinator **Users** page (`web/src/pages/coordinator/CoordinatorInternsPage.vue`, route `/coordinator/users`; old `/coordinator/interns` redirects here) has a **secondary nav** with two lists, both department-scoped via `User::coordinatorProgramIds()`: an **Interns** tab (`EnrollmentController::interns`, `GET users/interns`) listing **every** in-scope student *regardless of enrollment*, each with an **ENROLLED / NOT ENROLLED** badge + current placement (optional `program_id` filter 403s out of scope); and a **Supervisors** tab (`EnrollmentController::supervisors`, `GET users/supervisors`). With **no `created_by` column** on `users` (adding one would be a forbidden schema change), the "supervisors the coordinator created ∪ supervisors on their students' companies" union is realized as **supervisors attached (via `company_supervisors`) to any company in the coordinator's company-scope** — companies used by in-scope enrollments plus globally-unlinked companies, mirroring `CoordinatorCompanyController::scopedCompanyIds` — deduped by user with their in-scope companies **and the distinct in-scope batches whose students they supervise** (`batch_students.supervisor_id`), so the UI can filter supervisors by company and by batch. The Create Account + Enroll actions stay on this page (account creation ≠ enrollment). **Batch roster management** (`Coordinator/BatchRosterController`, "View Interns" on `CoordinatorBatchesPage.vue`) is separate from the enroll flow and scoped by **batch `program_id` in `coordinatorProgramIds()`** (out-of-scope batch/student 403): **add** a student (must be **same program** as the batch, else 422; if already active in another batch it **MOVES** them — old row → `dropped`, new active row, `moved:true`, behind a wrong-batch-guard confirm; already active in this batch 422); **remove** → marks the row `dropped` (keeps history); **delete** → only allowed once `dropped` (deleting an `active` row 422s "drop first"). Same confirm/toast rule (move/remove/delete confirm first; adds toast after). Broad **CABM-B demo data** (`CabmbUsersDemoSeeder`, idempotent) — BSA + BSBA-FM/MM/OM students (mix of enrolled/not-enrolled), companies, attached + created-on-unlinked-company supervisors, one active batch per program — is owned by a **dedicated CABM-B coordinator "Maria Antonnette Balbero"** (`mdcbalbero@gmail.com`, **CABM-B only**): her world is pure CABM-B (13 students = 9 enrolled/4 not, 7 supervisors) with **no BSIT bleed-through**. The CAST/BSIT demo coordinator (`mdccore@gmail.com`) is explicitly **detached** from CABM-B (CAST only again).

## Architecture Notes
- **Routing**: `routes/api.php` defines `/api/user` (auth:sanctum) plus an `admin/*` group gated by `auth:sanctum` + `role:admin`. Role gating middleware is `App\Http\Middleware\EnsureRole`, aliased as `role` in `bootstrap/app.php`, used as `->middleware('role:admin')` or `role:admin,coordinator` for multi-role routes. It also blocks `is_active = false` accounts with a 403 before checking role.
- **Controllers**: `app/Http/Controllers/Admin/*` for admin-only endpoints, `app/Http/Controllers/Auth/*` for Breeze/Sanctum auth flows. Follow the existing Form Request + Controller pattern (one Form Request per create/update action in `app/Http/Requests/{Admin,Auth}/`) before inventing new patterns.
- **Web SPA structure**: pages live in `web/src/pages/{admin,coordinator,student,supervisor}/`, role-specific shells in `web/src/layouts/*Layout.vue` wrapping the shared `web/src/components/layout/DashboardShell.vue`. Routes are defined centrally in `web/src/router/index.ts`, gated by `meta: { requiresAuth, role }` and a global `beforeEach` guard that calls the Pinia `auth` store (`web/src/stores/auth.ts`). API calls go through the shared Axios instance at `web/src/lib/axios.ts`.
- **Dev proxy**: `web/vite.config.js` proxies `/api`, `/sanctum`, `/login`, `/logout`, `/register`, `/forgot-password`, `/reset-password` to the Laravel backend (`VITE_BACKEND_URL`, default `http://localhost:8000`) so the Vite dev server at `:5173` can make same-origin requests.
- **CORS/Sanctum config**: `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` in `.env` must match wherever `web/` is actually running — check `.env.example` when debugging auth/CORS issues.

## Roadmap Context
Seven-phase roadmap. Phases 1–2 (project scaffolding, auth, base schema, admin identity/role management) are complete. Coordinator-facing pages/module work is in progress (see `web/src/pages/coordinator/*`). Mobile (Expo) integration is Phase 7 — do not wire mobile auth or endpoints for it yet unless asked.

## Conventions
- Laravel: RESTful API controllers, Form Requests for validation, Policies for authorization once introduced. Follow existing patterns in the repo before inventing new ones.
- Vue: pages in `src/pages/<role>/`, shared components in `src/components`, Pinia stores in `src/stores`, types in `src/types`. Match the existing folder structure — inspect before creating.
- Naming: snake_case for DB columns, camelCase in TS/JS, StudlyCase for PHP classes.
- Seeders must remain re-runnable (`php artisan migrate:fresh --seed` should always work).

## Verification — Evidence Before Claims
Never say something is "done", "fixed", or "working" without running a check and showing the output:
- Backend changes: `php artisan test` (or the specific test), and `php artisan migrate:fresh --seed` when migrations changed
- Frontend changes: `npm run build` (in `web/`) must succeed; run `npm run lint` if configured
- After fixing a bug: reproduce it first if possible, then show the passing result

## Common Commands
```bash
# API (run in repo root)
php artisan serve
php artisan migrate:fresh --seed
php artisan test
php artisan test --filter=TestName   # single test
composer install

# Or run backend + queue + logs + Vite together:
composer run dev

# Web SPA (run inside web/)
npm install
npm run dev
npm run build

# Mobile (run inside mobile/)
npm install
npx expo start
```

## Workflow Preferences (project owner)
- Propose a plan for any multi-file change before implementing; wait for approval.
- Prefer direct numbered steps with exact terminal commands over conceptual explanations.
- When a task touches the database, always cross-check the schema first.
- If instructions in this file conflict with what you find in the repo, stop and ask instead of guessing.
