# InternTrack — Web (Vue 3 SPA)

The student/supervisor/coordinator/admin web portal for InternTrack. Talks to
the Laravel API in the repo root over HTTP, authenticated via Laravel
Sanctum (cookie/session mode).

## Stack
- Vue 3 (`<script setup>` SFCs)
- Vite
- Vue Router
- Pinia
- Axios
- Tailwind CSS v4

## Getting started

```bash
npm install
npm run dev
```

Dev server runs at `http://localhost:5173`. The four role dashboards are
reachable at `/student`, `/supervisor`, `/coordinator`, and `/admin` —
currently static shells with placeholder nav, no live API data yet.

Make sure the backend (`../` at the repo root) is running at
`http://localhost:8000`. During development, Vite proxies `/api`,
`/sanctum`, and auth routes to the backend so the browser can use same-origin
requests from `http://localhost:5173`.

If your backend runs somewhere else, add `VITE_BACKEND_URL=http://localhost:8001`
to `web/.env.local` before starting Vite.

## Build

```bash
npm run build
```
