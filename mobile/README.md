# InternTrack Mobile (`mobileee/`)

Student-facing mobile app for InternTrack, built with React Native + Expo Router
(SDK 54), matching `InternTrack-Mobile.html` and Phase 7 of
`InternTrack_Development_Roadmap.docx`.

## Run it

```bash
npm install
npx expo start
```

Then press `a` for Android emulator, `i` for iOS simulator, or scan the QR
code with Expo Go on your phone.

## Sign In vs Sign Up

**New OJT students:** tap "Create an Account" on the login screen. Sign up
with your name, student ID, email, and a password (6+ characters). You'll
land on a genuinely empty dashboard — no seeded numbers — and every daily
journal you write through the app actually updates your Dashboard stats,
Calendar, Journals list, and Weekly bundles in real time. This is stored
locally on your device (via `AsyncStorage`) until the real backend is wired
up; nothing here is faked or hardcoded to look busy.

**Returning/demo testing:** the app still recognizes one fixed test account
for quickly seeing what a populated account looks like (defined in
`src/hooks/useAuth.ts`):

```
email:    student@interntrack.test
password: interntrack123
```

There's no button for this anymore — type it manually on the login screen.
Any other email/password combination that isn't a registered account will
correctly fail with "Unable to sign in."

Both paths only ever activate when the real `/api/login` or `/api/register`
call can't reach a backend — a real network response always takes priority
over any local fallback (see `fetchWithFallback` in `src/services/api.ts`).

## Responsive layout

Login and Sign Up cap their card width (`maxWidth: 420`) and center on
screen, so they look intentional on tablets instead of stretching
edge-to-edge. `app.json` has `ios.supportsTablet: true`. The rest of the
screens use flexible/percentage-based layouts already, but if you want the
same tablet-safe max-width treatment on Dashboard/Journals/etc., wrap their
content the same way (see the `cardMaxWidth` pattern in `app/login.tsx`).

## Wiring the real backend

1. Set `EXPO_PUBLIC_API_URL` (e.g. in a `.env` file) to your Laravel API's
   base URL. On the Android emulator, `10.0.2.2` maps to your host machine's
   `localhost`; on a physical device, use your machine's LAN IP instead.
2. Open `src/services/endpoints.ts` — every route the app calls is listed
   there, including the new `register` endpoint. Anything marked
   `UNVERIFIED` needs a quick check against the actual controller before you
   trust the response shape.
3. `src/hooks/useAuth.ts` assumes both `/api/login` and `/api/register`
   responses have a `token` or `access_token` field — confirm which one
   Sanctum actually returns and adjust if needed.
4. Once the backend is live and reachable, `src/services/localData.ts` (the
   on-device journal storage for locally-registered accounts) stops being
   read from — every hook tries the real endpoint first, every time.

## What's implemented

- Full navigation shell: tab bar (Dashboard / Calendar / Journals / Weekly / More)
  plus modal/stack screens (Write, More sheet, Guide, Info Sheet, Reports, Drafts, Profile)
- Sign Up flow for new students with real, working local persistence — not a
  static empty placeholder
- Every screen styled from shared color/spacing tokens
  (`src/constants/colors.ts`, `src/constants/layout.ts`) — no inline hex codes
- Secure token storage via `expo-secure-store`, login/logout/register flow
  with route-level auth gating (`Redirect` in `app/(tabs)/_layout.tsx`)
- Dashboard/Calendar/Journals/Weekly refetch on tab focus (`useFocusEffect`),
  so writing a new entry and navigating back shows it immediately

## What's intentionally excluded

Per the roadmap's own notes:
- **Exit Interview Summary** — flagged as an open item; the v2.0 schema has
  no supporting table, so it isn't built here either.
- **Geofence / rotating QR clock-in** — confirmed out of scope by the team.

## Not yet wired (next steps)

- Push notifications: helper is stubbed — add `registerForPushAsync()` using
  `expo-notifications` and POST the token to `endpoints.registerDevice` once
  that route is confirmed
- Real PDF download on the Reports screen — currently posts params and
  no-ops on the file itself; wire `expo-file-system`/`expo-sharing` once the
  export endpoint's response format is confirmed
