# InternTrack — Project History

Condensed from three separate phase-notes files (`PHASE_1_COMPLETION_NOTES.md`,
`PHASE_2_COMPLETION_NOTES.md`, `SCALABILITY_UPDATE_NOTES.md`) into one, since
their content had already been superseded or folded into `CLAUDE.md`.

**This file is historical context only — how the project got here, not how it
behaves today.** For current behavior, schema, and conventions, `CLAUDE.md` at
the repo root is the single source of truth and is kept up to date as the
project changes. Where anything below conflicts with `CLAUDE.md`, `CLAUDE.md`
wins.

## Phase 1 — Foundation

Brought the repo from scaffolding to a genuinely working state: filled in all
20 migration stubs with real columns/types/FKs from the finalized schema
(fixing a dependency-ordering bug where `batches` was created before
`journal_templates`, which it has a foreign key to), filled in the empty
Eloquent models (fillable attributes, casts, relationships), wrote the
`DepartmentSeeder`/`ProgramSeeder` (initially with CAST confirmed + CBA/COED/BSCS
as placeholders, corrected in the scalability pass below), fixed CORS/Sanctum
config to match the SPA's actual port, and did general repo cleanup (stray
root `package.json`, default boilerplate `README.md`).

## Phase 2 — Identity, Roles & Account Management

Added `EnsureUserHasRole` middleware (role gating + blocking deactivated
accounts), `UserObserver` to auto-create a `student_profiles` row whenever a
`student`-role user is created (regardless of which code path creates them —
this is still the mechanism today, see `CLAUDE.md`), the first round of Admin
controllers (users/departments/programs/batches — since evolved considerably,
see `CLAUDE.md` for current admin capabilities), and the first three Admin web
pages wired to real API calls instead of mockups.

## Scalability check — multi-department/program support

Confirmed the department → program schema/model design already scaled to the
real institution structure (CAST/CABM-B/CABM-H, 7 programs total) without any
redesign — it was already a genuine one-to-many with no hardcoded assumptions.
Added a composite unique constraint on `programs (department_id, code)`,
corrected the seeders from CBA/COED/BSCS placeholders to the real
CAST/CABM-B/CABM-H/BSIT/BSBA-FM/BSBA-MM/BSBA-OM/BSA/BSTM/BSHRM structure, and
grouped the web UI's program dropdowns by department. Confirmed CABM-B and
CABM-H are modeled as two independent departments, not sub-units of one
"CABM" — this decision is preserved as a hard rule in `CLAUDE.md` since it
affects future schema decisions.

## Everything since

The project has grown substantially past these three rounds — enrollment and
the Student Information Sheet gateway, daily/weekly journals and weekly
bundling, supervisor review, coordinator reporting (Annual SIPP, HTE), the
Company Supervisor login/named-individual split, and the shared Profile/
Change Password/Activity Log surface, among others. None of that is
recapped here — `CLAUDE.md` is kept current as each of those lands, so it's
the only place that needs to stay in sync going forward.

## Deployment groundwork — 2026-07-28

The point at which the project stopped being local-only. Recorded here because
it changed a few long-standing assumptions; the operational detail lives in
`docs/DEPLOYMENT.md` and the full decision record in `CLAUDE.md`.

A **zero-cost stack** was chosen for the capstone defense — SPA on Vercel, API
on Render's free tier via Docker, database on Aiven's always-free MySQL, no
credit card anywhere. Notably **not** Render's own free PostgreSQL, which expires
30 days after creation and would have forced a MySQL-to-Postgres switch shortly
before the defense.

Three security issues were fixed on the way, all of which only mattered once the
app became publicly reachable:

- **`POST /register` was removed.** Breeze's self-service registration route had
  shipped with the project since Phase 1 and created an *active* `role: student`
  account for any anonymous caller, unthrottled. Harmless on localhost; on a
  public URL it means a stranger self-enrolls into a coordinator's intake queue.
- **Demo credentials were made rotatable** (`demo:set-password`) and a
  `ProductionSeeder` was added. Because this repository is public and `CLAUDE.md`
  documents both the demo usernames and their shared `password`, a seeded demo
  deployment was trivially takeover-able as admin.
- **Ten dependency advisories were cleared** — six in dompdf (which renders every
  PDF in the app) and one class in Guzzle (which performs the Google token
  exchange).

Two architectural decisions worth remembering: the SPA reaches the API through
**Vercel rewrites** rather than cross-domain CORS, so the session cookie stays
first-party and login never depends on third-party cookies; and deploys track a
dedicated **`deploy` branch**, so pushing to `main` cannot move the live site.

One earlier belief was also corrected: the API host was documented as needing a
**queue worker**, which it does not — nothing in the codebase implements
`ShouldQueue`, and the reminder notification sends inline.
