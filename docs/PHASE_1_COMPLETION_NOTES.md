# InternTrack — Phase 1 Completion Notes

This document lists everything that was changed to bring the repo's Phase 1
work to a genuinely working state, based on a full audit of
`Carlo1090/InterAct_Capstone_Project` as it stood before these fixes.

**How to use this:** pull these files into your local copy of the repo
(overwriting the matching paths), then follow the "Verification steps" at
the bottom. Everything here was either written from the finalized
`InternTrack_Database_Schema_v2.docx` or verified by actually running it —
see the "What was and wasn't tested" note at the end.

---

## 1. Backend — Database Migrations (`database/migrations/`)

**Problem found:** all 20 migration files existed with correct names, but
every one was an empty Laravel stub (`$table->id(); $table->timestamps();`
only) — no actual columns from the schema.

**Fixed:** every migration now has the real columns, types, defaults, and
foreign keys from `InternTrack_Database_Schema_v2.docx`, including:
- Enum columns (`role`, `status` fields, etc.) matching the schema exactly
- JSON columns for `sections`, `content`, `report_data`, `personal_info`,
  `academic_info`, `ojt_info`, `emergency_contact`
- The `journal_entries` unique constraint on `(student_id, entry_date)`
- Nullable foreign keys where the schema specifies them (e.g.
  `batches.journal_template_id`, `weekly_activity_logs.weekly_log_id`)

**Also fixed — a real ordering bug:** the original timestamp prefixes had
`batches` created *before* `journal_templates`, but `batches` has a foreign
key to `journal_templates.id`. Running migrations in that order would have
failed. All 20 files were renamed with corrected timestamps
(`2026_06_25_0000XX_...`) so they run in true dependency order:

```
departments → programs → companies → journal_templates → users →
student_profiles → company_supervisors → batches → batch_students →
journal_entries → weekly_logs → weekly_log_entries → edit_history →
weekly_activity_logs → weekly_activity_entries → student_information_sheets →
sipp_annual_reports → notifications → system_settings → system_logs
```

Note the `users` table migration was also renamed/moved later in the
sequence (out of Laravel's usual `0001_01_01_000000` slot) — it now needs to
run after `programs`, since `users.program_id` is a foreign key to it. The
`cache` and `jobs` stub migrations were left untouched since nothing in our
schema depends on them.

**`users` table specifically** also gained the columns our schema requires
that Breeze's default didn't include: `program_id` (nullable FK),
`role` (enum: student/supervisor/coordinator/admin), and `is_active`.

---

## 2. Backend — Models (`app/Models/`)

**Problem found:** all 18 existing model files were empty classes
(`class Batch extends Model {}`), and 2 models were missing entirely
(`Notification`, `SippAnnualReport`).

**Fixed:** all 20 models now have:
- `#[Fillable]` attributes listing the correct mass-assignable columns
  (Laravel 13's attribute-based style, matching how `User.php` was already
  written)
- Proper `casts()` for dates, JSON columns, and booleans
- Every relationship from the schema's "Key Relationships" section
  (`belongsTo`, `hasMany`, `hasOne` as appropriate)
- Correct timestamp handling for tables that use a single timestamp column
  instead of Laravel's default `created_at`/`updated_at` pair (e.g.
  `EditHistory` uses `edited_at`, `SystemLog` uses `logged_at`)

**`User.php` specifically** also gained:
- The `HasApiTokens` trait from Sanctum (needed for mobile token auth in
  Phase 7 — without this trait, the model has no way to issue API tokens
  even though the `personal_access_tokens` table already exists)
- Relationships to every table that references a user (journal entries,
  weekly logs as student/supervisor, batches coordinated, company
  supervisor assignments, notifications, etc.)

---

## 3. Backend — Seeders (`database/seeders/`)

**Problem found:** `DepartmentSeeder` and `ProgramSeeder` both existed but
had empty `run()` methods. `DatabaseSeeder` only created a generic test user
and never called the other two seeders.

**Fixed:**
- `DepartmentSeeder` now seeds CAST, CBA, and COED as a starting point —
  **CAST is confirmed correct for this project; CBA and COED are
  placeholders and should be reviewed/corrected against the institution's
  actual department list before your defense.**
- `ProgramSeeder` now seeds BSIT and BSCS under CAST — **BSIT is confirmed
  correct; BSCS is a placeholder pending confirmation.**
- `DatabaseSeeder` now calls both seeders, and creates two accounts:
  - `system@interntrack.local` — a dedicated system/admin account for
    auto-triggered actions (weekly compilation, reminders), per the schema
    doc's implementation notes. **Not meant for interactive login.**
  - `admin@interntrack.local` / `password` — a test admin login for local
    development. **Change this password before any real deployment.**

---

## 4. Backend — Config & Environment

**Problem found:** `config/cors.php` defaulted to `http://localhost:3000`,
but the Vue app's `vite.config.js` is hardcoded to port `5173`. `.env.example`
had no `SANCTUM_STATEFUL_DOMAINS` or `FRONTEND_URL` entries at all.

**Fixed:**
- `config/cors.php` now defaults to `http://localhost:5173`
- `.env.example` now includes `FRONTEND_URL=http://localhost:5173` and
  `SANCTUM_STATEFUL_DOMAINS=localhost:5173`, with comments explaining what
  each is for
- `SESSION_DOMAIN` changed from `null` to `localhost` to match

**Confirmed already correct, no change needed:** `bootstrap/app.php` already
has Sanctum's `EnsureFrontendRequestsAreStateful` middleware properly
prepended to the API stack, and `routes/auth.php` already has the full set
of Breeze `api`-stack routes (register, login, logout, password reset,
email verification) wired to the right controllers. This part of Phase 1
was already done correctly.

---

## 5. Web (`web/`)

**Problem found:** `package.json` only had Vue itself installed, even
though `main.js` already imported Pinia and a router, `vite.config.js`
already imported `@tailwindcss/vite`, and `services/api.js` already
imported Axios. None of these packages were actually installed, so running
`npm run dev` would have failed immediately. The router also imported four
dashboard components (`StudentDashboard.vue`, etc.) that didn't exist yet.
`App.vue` still rendered the default Vite starter template instead of the
router.

**Fixed:**
- Ran the actual installs: `vue-router`, `pinia`, `axios`,
  `tailwindcss`, `@tailwindcss/vite` are now real dependencies in
  `package.json` (verified — see below)
- Created the four dashboard components the router already expected, each
  using a new shared `DashboardShell.vue` layout (sidebar nav + topbar +
  content area), with nav items matching your HTML mockup for each role
- Fixed `App.vue` to render `<RouterView />` instead of the leftover
  `HelloWorld` starter component
- Removed the now-unused `HelloWorld.vue` and its starter assets
  (`vite.svg`, `vue.svg`, `hero.png`)
- Cleaned up `style.css` (removed ~150 lines of commented-out starter CSS,
  kept the Tailwind import) and `main.js` (removed dead commented-out code)
- Updated the page `<title>` from generic "web" to "InternTrack"
- Replaced the generic Vite boilerplate `web/README.md` with real setup
  instructions

---

## 6. Repo-Level Cleanup

**Problem found:** a `package.json` sitting at the repo root contained a
confusing mix of mobile (Expo, React Native) and web (vue-router, pinia,
tailwind) dependencies — it didn't belong to either app and nothing in the
Laravel backend referenced it. Likely a leftover from running `npm install`
in the wrong directory at some point. The root `README.md` was still the
default Laravel framework boilerplate.

**Fixed:**
- Removed the stray root `package.json` (confirmed nothing depends on it —
  no root-level `vite.config.js` or lockfile existed)
- Replaced the root `README.md` with real project documentation: structure
  overview, setup commands for all three apps, and seeded test account info

---

## What was and wasn't actually tested

Being precise about this matters, so here's exactly what's verified versus
what still needs your machine:

**Actually run and confirmed working:**
- `web/`: ran the real `npm install` for the missing packages (0
  vulnerabilities), ran `npm run build` successfully (clean production
  build), and ran the dev server and confirmed `/student` returns HTTP 200
  with the correct page title.

**Not run — no PHP/Composer available in this environment, needs to happen
on your machine:**
- `composer install`
- `php artisan migrate` (this is the most important one — please run this
  first and confirm it completes with zero errors before building on top of
  it)
- `php artisan db:seed`
- Confirming the API boots via `php artisan serve`

The migration and model code was written directly against the schema
document's exact column list, types, and constraints, and the dependency
ordering was worked out by hand — but since I couldn't run `php artisan
migrate` myself in this environment, please treat that as the first thing
to verify, not an assumption to build on.

---

## Suggested next steps

1. Pull these files into your local repo, overwriting the matching paths.
2. Run `composer install`, copy `.env.example` to `.env`, run
   `php artisan key:generate`, then `php artisan migrate` — confirm zero
   errors.
3. Run `php artisan db:seed` and confirm the admin account works.
4. In `web/`, run `npm install` then `npm run dev`, and check all four
   dashboard routes render.
5. Review the placeholder department/program seed data (CBA, COED, BSCS)
   against your actual institution data.
6. Commit on a branch and open a PR rather than pushing straight to `main`.

Once migrate and the web dev server both come up clean on your machine,
Phase 1 is genuinely done and Phase 2 (Identity, Roles & Account Management)
is ready to start.
