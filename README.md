# InternTrack

Internship Journal and Progress Monitoring System — Mater Dei College CAST
Department capstone project (Group 1).

## Structure

This is a monorepo with three independent apps:

- **Backend (repo root)** — Laravel 13 API, Sanctum **cookie/session** auth for
  the web SPA, MySQL (SQLite for tests). Token-based auth for mobile is deferred
  to Phase 7.
- **`web/`** — Vue 3 SPA (student, supervisor, coordinator, admin portals).
- **`mobile/`** — React Native / Expo app. Currently the default Expo template
  scaffolding only; deferred to Phase 7.

## Backend setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

Default seeded accounts — log in by **username**, not email:
- `system` — system/automation account, not for login
- `mdcadmin` / `password` — test admin login
- `mdccore` / `password` — CAST/BSIT coordinator
- `mdcbalbero` / `password` — CABM-B coordinator
- `mdcstudent` / `password` — enrolled student
- `mdcsupervisor` / `password` — company supervisor

> **These are for LOCAL development only.** This repository is public, so both
> the usernames above and the shared `password` are public knowledge. Never expose a
> `db:seed` database on a public URL: use `php artisan db:seed
> --class=ProductionSeeder` for a real deployment, or
> `php artisan demo:set-password --password="..."` to rotate them on a demo one.
> See `docs/DEPLOYMENT.md`.

See `.env.example` for the `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS`
settings — these need to match wherever `web/` is actually running.

## Deploying

**`docs/DEPLOYMENT.md` is the step-by-step guide** — a zero-cost stack (Vercel +
Render free tier + Aiven free MySQL), with the environment variables, the
verification checklist, and a symptom-to-cause troubleshooting table.

Deploys track the **`deploy`** branch, not `main`. Pushing to `main` never
touches the live site; shipping is a deliberate merge:

```bash
git checkout deploy && git merge main && git push origin deploy
```

## Web setup

```bash
cd web
npm install
npm run dev
```

See `web/README.md` for details.

## Mobile setup

```bash
cd mobile
npm install
npx expo start
```

## Documentation

- **`PROJECT.md`** — the single source of truth for current architecture,
  conventions, domain rules, and gotchas; kept up to date as the project changes.
  (`CLAUDE.md` is a one-line stub that imports it, so Claude Code auto-loads it.)
- `docs/DEPLOYMENT.md` — how to deploy this project, start to finish
- `docs/CRON-AND-EMAIL-SETUP.txt` — operator setup for scheduled jobs and mail
- `docs/PRE-ORAL-DEFENSE-DEMO-GUIDE.txt` — demo walkthrough and credentials
- `docs/PROJECT_HISTORY.md` — historical log: earlier build phases, plus an
  archived copy of the full pre-condensation notes
- `docs/reference/` — client-supplied source documents (SIPP annexes, the group
  Student Information Sheet, the development roadmap)

Database baseline: the 20-table InternTrack_Database_Schema_v2.
