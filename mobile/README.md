# InternTrack Mobile

Student-facing mobile app for InternTrack, built with React Native + Expo Router
(SDK 54). This is Phase 7 of the roadmap. As of 2026-08-18, it is wired to the
**real** Laravel backend for authentication and every student-facing feature —
there is no mock/demo/local-only data path anymore.

## Run it

```bash
npm install
npx expo start
```

Then press `a` for Android emulator, `i` for iOS simulator, or scan the QR
code with Expo Go on your phone.

## Signing in

There is no sign-up flow — accounts are staff-provisioned (created by a
coordinator), exactly like the web SPA. Log in with the same username/email
+ password a student already uses on the web portal. A wrong password
always shows a real error from the server; nothing silently substitutes
fake data.

**Only student accounts can sign in here.** Mobile is deliberately
student-only scope — a coordinator/supervisor/admin account is rejected at
login with "This app is for students only. Please use the web portal.",
even with correct credentials.

Auth is bearer-token based (`POST /api/mobile/login`, distinct from the web
SPA's session-cookie login), backed by Laravel Sanctum's personal access
tokens — see `App\Http\Controllers\Auth\MobileAuthController` on the
backend. The token is stored via `expo-secure-store` and attached as
`Authorization: Bearer <token>` on every request (`src/services/api.ts`).

The app replicates the same student state machine the web SPA's router
guard enforces, read from `GET /api/user` (`src/hooks/useCurrentUser.ts`):
- **Gated** (info sheet not yet approved by a coordinator) — only the
  Student Info Sheet is reachable.
- **Paused** (dropped from a batch) — a calm "enrollment inactive" screen
  (`app/paused.tsx`), with Info Sheet still reachable.
- **Must change password** — force-routes to `app/change-password.tsx`
  regardless of gate state, for a temporary password issued by an admin.

## Wiring the real backend

1. Set `EXPO_PUBLIC_API_URL` (e.g. in a `.env` file) to your Laravel API's
   base URL. On the Android emulator, `10.0.2.2` maps to your host machine's
   `localhost`; on a physical device, use your machine's LAN IP instead.
2. `src/services/endpoints.ts` lists every route the app calls — all
   verified directly against `routes/api.php` and the corresponding
   `App\Http\Controllers\Student\*` controllers, not guessed.
3. Run `php artisan migrate` once on the backend if you haven't already —
   the mobile login endpoint needs Sanctum's `personal_access_tokens` table.

## What's implemented

- Full navigation shell: tab bar (Dashboard / Calendar / Journals / Weekly / More)
  plus modal/stack screens (Write, More sheet, Guide, Info Sheet, Profile,
  Reminder Settings, Change Password, Paused)
- Every screen styled from shared color/spacing tokens
  (`src/constants/colors.ts`, `src/constants/layout.ts`) — no inline hex codes
- Secure token storage via `expo-secure-store`, login/logout with
  route-level auth + gate-state guarding (`app/(tabs)/_layout.tsx`)
- Dashboard/Calendar/Journals/Weekly refetch on tab focus (`useFocusEffect`)
- Write Daily Journal renders whatever sections the student's actual batch
  journal template defines (not a hardcoded field set) — required section(s)
  always shown, optional sections addable via chips, SIPP trio behind one
  checkbox, matching the real 1500-char total / 300-char-per-SIPP-field caps
- Weekly journal status is derived the same way the web SPA derives it: the
  database only ever stores `pending|approved|returned` (never `draft`) —
  `submitted_at` is what actually distinguishes "still drafting" from
  "submitted, awaiting review" (`deriveWeekState()` in `src/types/api.ts`)
- PDF download (daily entry, weekly log, info sheet) via
  `expo-file-system` + `expo-sharing`, since the download endpoints require
  a bearer-auth header a plain `Linking.openURL` can't carry
- A failed request always shows a real error with a Retry action
  (`src/components/ErrorState.tsx`) — no mock-data fallback exists anywhere
  in the app, on the principle that a silently-substituted fake value could
  show the wrong data (e.g. the wrong coordinator/supervisor) without the
  student ever knowing

## What's intentionally excluded

- **Google Sign-In** — the web SPA's flow is a full-page browser redirect
  onto a cookie session; porting it to a bearer-token native client needs
  real deep-linking + a new backend OAuth branch. Deferred as a separate task.
- **Weekly Activity Log (SIPP tabular form)** — the backend routes still
  exist but the *web* student portal already removed this UI (nobody ever
  reviewed it); mobile matches the web's current UI, not the full backend
  surface.
- **Exit Interview Summary** — no supporting table in the v2.0 schema.
- **Geofence / rotating QR clock-in** — confirmed out of scope by the team.
- Coordinator/supervisor/admin interfaces — mobile is student-only by design.
