# CLAUDE.md — InternTrack

## Project Overview
InternTrack is a capstone OJT/internship monitoring system for Mater Dei College (Bohol, Philippines), built by a 4-person team (Group 1). It digitizes SIPP internship processes: student profiles, attendance/clock-in, weekly activity journals, evaluations, and reporting.

This is a **monorepo** with three parts:
- `api/` — Laravel 13 REST API (PHP 8.3, MySQL)
- `web/` — Vue 3 SPA (Vite, Tailwind CSS v4, **JavaScript only — NO TypeScript**)
- `mobile/` — React Native app (Expo SDK 56) — deferred until Phase 7

> If the actual folder names differ, check the repo root first and use the real paths.

## Tech Stack (do not change without asking)
- Laravel 13, PHP 8.3, MySQL
- Auth: Laravel Breeze + Sanctum — **cookie/session-based** for the web SPA; token-based auth for mobile is DEFERRED to Phase 7
- Vue 3 (Composition API, `<script setup>`), Vue Router, Pinia
- Tailwind CSS v4 (no v3 syntax, no tailwind.config.js patterns that v4 removed)
- Axios for API calls with CSRF/sanctum cookie flow

## Hard Rules
1. **NEVER introduce TypeScript.** The web SPA is JavaScript. Do not create `.ts`/`.tsx` files, do not add TS dependencies or tsconfig.
2. **NEVER create a duplicate migration.** Before creating any migration, list `api/database/migrations/` and check whether a table/column change already exists. If a migration for that table exists and hasn't shipped, EDIT it instead of adding a new one.
3. **Do not add a second middleware layer or restructure app architecture** without explicitly proposing it first and waiting for approval.
4. **Stay on the finalized schema** (InternTrack_Database_Schema_v2.0, 20 tables). Do not rename tables/columns or add tables without asking. Key structural decisions already made:
   - `weekly_activity_logs` is split into a header table + `weekly_activity_log_entries` (mirrors the physical SIPP form)
   - Separate `programs`, `departments`, `student_profiles`, `journal_templates`, `system_settings` tables exist
5. **Out of scope — do not build:** geofence clock-in, rotating QR clock-in, photo capture on clock-in, exit interview report generation.
6. Work on a **feature branch**, never directly on `main`. Small, focused commits with clear messages.

## Domain Facts
- 3 departments, 7 programs:
  - CAST: BSIT
  - CABM-B: BSBA-FM, BSBA-MM, BSBA-OM, BSA
  - CABM-H: BSTM, BSHRM
- Roles include (at minimum): admin/coordinator, adviser/instructor, student intern, company supervisor. Check the `users`/roles tables for the exact list before assuming.
- SIPP compliance documents referenced by the system: OJT Annual Report, Summary Report on Student Exit Interview (report itself out of scope), Student Information Sheet.

## Roadmap Context
Seven-phase roadmap. Phases 1–2 are complete (project scaffolding, auth, base schema). Current work continues from Phase 3 onward. Mobile (Expo) integration is Phase 7 — do not wire mobile auth or endpoints for it yet unless asked.

## Conventions
- Laravel: RESTful API controllers under `app/Http/Controllers/Api`, Form Requests for validation, API Resources for responses, Policies for authorization. Follow existing patterns in the repo before inventing new ones.
- Vue: pages in `src/pages` (or existing equivalent), shared components in `src/components`, Pinia stores in `src/stores`. Match the existing folder structure — inspect before creating.
- Naming: snake_case for DB columns, camelCase in JS, StudlyCase for PHP classes.
- Seeders must remain re-runnable (`php artisan migrate:fresh --seed` should always work).

## Verification — Evidence Before Claims
Never say something is "done", "fixed", or "working" without running a check and showing the output:
- Backend changes: `php artisan test` (or the specific test), and `php artisan migrate:fresh --seed` when migrations changed
- Frontend changes: `npm run build` (in `web/`) must succeed; run `npm run lint` if configured
- After fixing a bug: reproduce it first if possible, then show the passing result

## Common Commands
```bash
# API (run inside api/)
php artisan serve
php artisan migrate:fresh --seed
php artisan test
composer install

# Web SPA (run inside web/)
npm install
npm run dev
npm run build
```

## Workflow Preferences (project owner)
- Propose a plan for any multi-file change before implementing; wait for approval.
- Prefer direct numbered steps with exact terminal commands over conceptual explanations.
- When a task touches the database, always cross-check the schema first.
- If instructions in this file conflict with what you find in the repo, stop and ask instead of guessing.
