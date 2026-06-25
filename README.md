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

Default seeded accounts (change these passwords before any real deployment):
- `system@interntrack.local` — system/automation account, not for login
- `admin@interntrack.local` / `password` — test admin login

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

- `docs/` — database schema, development roadmap, and setup guides
- Database baseline: 20-table schema, v2.0 (see `docs/InternTrack_Database_Schema_v2.docx`)
