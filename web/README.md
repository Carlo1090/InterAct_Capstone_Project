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
`http://127.0.0.1:8000` and that its `.env` has
`SANCTUM_STATEFUL_DOMAINS=localhost:5173` and `FRONTEND_URL=http://localhost:5173`
so CORS and Sanctum's cookie auth work correctly together.

## Build

```bash
npm run build
```
