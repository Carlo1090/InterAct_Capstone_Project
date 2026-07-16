<script setup lang="ts">
import LoadStatus from '@/components/LoadStatus.vue'
import JournalPaperView from '@/components/journal/JournalPaperView.vue'
</script>

# InternTrack

Internship Journal and Progress Monitoring System — Mater Dei College CAST
Department capstone project (Group 1).

## Structure

This is a monorepo with three independent apps:

- **Backend (repo root)** — Laravel 13 API, Sanctum auth (cookie/session for
  web, token-based for mobile), MySQL/SQLite.
- **`web/`** — Vue 3 SPA (student, supervisor, coordinator, admin portals).
- **`mobile/`** — React Native / Expo app (student portal only).

## Backend setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

Default seeded accounts (change these passwords before any real deployment) —
log in by **username**, not email:
- `system` — system/automation account, not for login
- `mdcadmin` / `password` — test admin login
- `mdcstudent` / `password` — test student login

See `.env.example` for the `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS`
settings — these need to match wherever `web/` is actually running.

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

- `CLAUDE.md` — the single source of truth for current architecture,
  conventions, and domain rules; kept up to date as the project changes
- `docs/PROJECT_HISTORY.md` — condensed historical log of earlier build phases
- `docs/` also holds the database schema doc, development roadmap, and SIPP
  report annex references
- Database baseline: 20-table schema, v2.0 (see `docs/InternTrack_Database_Schema_v2.docx`)
