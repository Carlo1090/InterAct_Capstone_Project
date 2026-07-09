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
- A coordinator's programs are resolved via `User::coordinatorProgramIds()` — the union of their assigned `program_id` and the programs of batches they already coordinate — not `batchesCoordinated()` alone, so a coordinator with an assigned program but zero batches yet isn't locked out of creating their first one.
- Students self-scaffold their Student Information Sheet after enrollment (`Student/StudentInfoSheetController::show` returns a pre-filled empty scaffold from their profile + enrollment; `store` upserts it, requires an active enrollment).

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
