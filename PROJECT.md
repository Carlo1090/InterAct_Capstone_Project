# PROJECT.md

Guidance for Claude Code (claude.ai/code) and any developer working in this
repository. **This is the single source of truth for current architecture,
conventions, and domain rules**, and is kept up to date as the project changes.

- Repository: https://github.com/Carlo1090/InterAct_Capstone_Project.git
- Historical detail — why a rule exists, which bug produced it, which designs
  were superseded — is archived in `docs/PROJECT_HISTORY.md`. Where that file
  disagrees with this one, **this one wins**.
- Deployment runbook: `docs/DEPLOYMENT.md`. Cron/email operator setup:
  `docs/CRON-AND-EMAIL-SETUP.txt`.
- **The capstone manuscript is NOT in this repository.** It is authored and
  built outside the repo, and nothing in the Laravel app renders it. Do not add
  paper/thesis generation back here — `resources/views/pdf/` and
  `app/Console/Commands/` are for InternTrack's own documents (info sheets,
  journals, the SIPP and HTE reports) only.

## Project Overview

InternTrack is a capstone OJT/internship monitoring system for Mater Dei College
(Bohol, Philippines), built by a 4-person team (Group 1). It digitizes SIPP
internship processes: student profiles, weekly activity journals, supervisor
review, and compliance reporting.

This is a **monorepo** with three parts:

- **repo root** — Laravel 13 REST API (PHP 8.3, MySQL/SQLite). There is no
  `api/` subfolder; the Laravel app lives at the top level (`app/`, `routes/`,
  `database/`).
- `web/` — Vue 3 SPA (Vite, Tailwind CSS v4, TypeScript).
- `mobile/` — React Native / Expo app (Expo SDK **54**, TypeScript, expo-router).
  **Phase 7 is under way and this is no longer scaffolding**: it is a real
  student-only client wired to the live API via bearer-token auth
  (`POST /api/mobile/login`), with offline caching, a queued-write outbox,
  on-device local reminder alarms, and a **QR scanner for the Daily Time
  Record**, and it ships as an installable Android APK built on EAS. Its tab
  bar is Dashboard · Calendar · **Scan** · Journals · Weekly; Info Sheet,
  **Weekly and Time Log Summary** and **Exit Interview** live in Profile, which
  also supports changing the profile photo. A launch splash warms every read
  cache in one pass (`src/services/preload.ts`) so offline coverage is not
  limited to screens the student happened to visit. **What each feature can do
  offline — read-only vs read-and-write — is declared in ONE place,
  `mobile/src/lib/offlineCapability.ts`**, and rendered by `OfflineNotice`;
  only the Daily Journal is read-write offline (see the DTR section for why a
  punch deliberately is not). All dates and times go through
  `mobile/src/lib/datetime.ts` — never `toISOString()` for "today", which is
  UTC and was silently a day behind for the first eight hours of every Manila
  day. **Signing out wipes what the device is holding for that student** —
  `clearDeviceSession()` in `mobile/src/hooks/useAuth.ts` clears the read caches
  (`clearAllCached`), the queued journal writes (`clearOutbox`), the on-device
  alarms and the shared user store, and `login()` re-runs it whenever the account
  that just signed in is not the one the cache describes (the session can also
  end by a token simply expiring). Before that, only the in-memory store was
  reset: the next student on the same handset was painted with the previous
  student's name, photo, dashboard and journals, and — worst — a journal queued
  offline by the previous student was flushed under the NEW student's bearer
  token, filing one student's writing against another's account.
  **The dashboard's Recent Activity and the full Activity Log are ONE
  renderer** (2026-09-08) — `mobile/src/lib/activityLog.ts` holds the
  action→plain-language map, the category icons/colours and `trimOwnName`, and
  `mobile/src/components/ActivityRow.tsx` draws the row for both. They read the
  same five `SystemLog` rows and used to describe them differently: the
  dashboard printed the raw audit `description`, own-name prefix and all
  ("Juan Dela Cruz logged in (mobile)"), against a bare coloured dot, while the
  Activity Log showed "Signed in" with an icon, a category colour and the name
  trimmed. `StudentDashboardController` now returns the raw `id`/`action`/
  `description`/`logged_at` alongside the existing `text`/`time`/`tone` —
  **additive, because `StudentDashboardPage.vue` reads those three** and the
  web dashboard is deliberately not part of that change. `ActivityRow`'s
  `variant` prop changes the container only (a standing card on its own screen,
  a divided list inside the dashboard card), never the information.
  **The palette is NAVY, GENTLED** (2026-09-09, project owner) — same family,
  lower intensity, every value in `mobile/src/constants/colors.ts` measured
  rather than picked: ground `#20304C` on white is **13.22:1** (was `#0A1628`
  at 18.13:1, a contrast wall), primary `#3A5A8F` is **6.91:1** at saturation
  0.42 (was `#1E4D9B` at 0.83, close to electric), canvas `#F4F7FB` carries a
  faint blue cast, and the muted label colour went `#94A3B8` → `#7C8AA3`,
  which was a real accessibility fix (2.39:1 → 3.25:1, since the old value
  sat under even the 3:1 large-text floor). **`blue300` `#A3C0E6` is
  light-on-dark ONLY** — 7.08:1 on the ground, 1.87:1 on white, so it must
  never become text on a pale surface. **Status colours are deliberately
  unchanged**: green/red/amber carry meaning (submitted, missing, needs
  action) rather than brand, and re-tinting them toward navy would weaken
  exactly the signals that must stay loud.
  **`Banner` has an `offline` variant and it is the loudest thing on its
  page.** Offline used to render as `neutral` — grey text on a grey wash in a
  grey border, the app's quietest treatment — so the one notice saying "this
  may be out of date" looked less important than the blue tips beside it. It
  now carries its meaning FOUR ways (left stripe, filled icon chip, bold
  title, colour), because colour alone fails a red-green colour-blind reader
  and a phone screen in daylight. `OfflineNotice` uses it for BOTH levels and
  differentiates by title — "Offline — your work is safe" where writes
  genuinely queue, "Offline — showing saved data" everywhere else.
  **The journal calendar is cache-first and pre-warms its neighbours.**
  `useJournalCalendar` hand-rolled its own fetch and read the cache **only
  inside the catch block**, so a saved month was used only when a request
  actually FAILED — on a working-but-slow connection (a sleeping free-tier
  API costs 30-60s to wake) every month change was a blocking round trip with
  nothing on screen. It now goes through `useCachedResource` (the sixth hook
  to hand-roll that pattern and the second to drift from it, which is the
  whole reason that helper exists) and background-warms the two ADJACENT
  months after each load — silently, and skipped entirely while offline
  rather than firing two doomed requests. `useCachedResource` also now CLEARS
  `data` when `cacheKey` changes: a different key is different content, not a
  refresh, and without it October's grid sat under the word November until
  the network answered.
  **The tab bar icons answer a tap** (`mobile/src/components/TabBarIcon.tsx`,
  2026-09-09) — an overshoot to 1.42 then a spring to a resting 1.12 with a
  2px lift. It keys off **`focused`, never an `onPress`**: the tab bar owns
  the press, so a second handler would fire on taps the navigator rejects (the
  already-active tab) and miss every focus change that did not come from a tap
  (a deep link, `router.replace`, hardware back). `useNativeDriver` throughout,
  and it honours `AccessibilityInfo.isReduceMotionEnabled()` — this fires on
  every navigation, which is exactly the repeated movement that setting exists
  to stop.
  **The login screen matches the project owner's mockup** (2026-09-09): a DARK
  navy ground (`#2a3f60 → blue900 → #16213a`, diagonal) instead of the old pale
  `blue300 → blue100` wash, which had left a white card floating on an
  almost-white page with no edge; two hairline rings in the card header echoing
  the logo; an elevated card (**both** `elevation` and `shadow*`, since iOS
  ignores the first and Android the second); and neutral `blue50` icon chips on
  tinted fields. **That last one fixed a real signal bug** — the password field
  carried a RED icon slab, and red is the app's error colour everywhere else,
  so a resting password field read as a field in an error state. The footer
  moved to `blue300`, the light-on-dark token, since `blue700` is invisible on
  the new ground.
  **JS-only changes now ship OVER THE AIR** (`expo-updates` ~29, 2026-09-10) —
  `eas update --branch preview` pushes a new bundle to installed apps, no
  reinstall. `runtimeVersion` uses the **`fingerprint` policy**, which is the
  safety interlock: it hashes the native project, so an update built against
  different native code is simply never offered to an older APK rather than
  being delivered and crashing. `eas.json` pins `channel` per profile
  (`preview`/`production`/`development`) — without it a build subscribes to no
  channel and can never receive an update at all.
  **A NATIVE change still needs a real build**: a new library with native
  modules, the icon, the splash, permissions, the package name, or the SDK.
  Build 16 is the first APK carrying the update client — **every APK before it
  can never be updated over the air**, which is why it was cut immediately
  after wiring this up. The splash and adaptive-icon backgrounds were corrected
  from the pre-redesign `#0a1628` to the palette's own `#20304C` in that same
  build, since native config is exactly what OTA cannot reach afterwards.
  **`checkAutomatically` defaults to `ALWAYS`, and `fallbackToCacheTimeout: 0`
  means a published update needs TWO app launches to actually appear** — the
  first checks and downloads in the background while still showing the old
  bundle (it never waits), the second applies what was downloaded. Fully
  closing and reopening once is not enough; there is no in-app "update ready"
  affordance, so this has to be explained to whoever is testing a fresh push.
  **`OfflineNotice` distinguishes a dead connection from a slow server**
  (`ApiError.isTimeout`, 2026-09-10 — found from a real report: "im currently
  on offline even thu i have internet connections"). Every screen's `isOffline`
  flag fires on ANY failed request, and the API sleeps on a free Render
  instance and can take up to the full 60s timeout to wake — so a student
  opening the app right as it woke up was told "Offline — check your internet
  connection" for a problem that was never theirs. `isOffline` itself is
  unchanged (still gates writes, still falls back to cache); `OfflineNotice`
  now takes an optional `error` prop and, when `error.isTimeout` is true,
  overrides the title to **"Reconnecting — this may take a moment"** and shows
  the error's own honest message instead of the per-feature offline note.
  Threaded through all 13 `<OfflineNotice>` call sites.
  **A network-failed READ is retried; nothing in the app used to retry
  anything** (`withRetry` in `mobile/src/services/api.ts`, 2026-09-11 — from a
  real report: "why when we do update to the application i always in up offline
  mode even thu i have internet"). A screen that failed once stayed `isOffline`
  until the student happened to switch tabs or pull to refresh, so **one unlucky
  moment stuck the whole app in offline mode indefinitely** on a perfectly good
  connection. That moment is the NORMAL launch rather than a rare one: the API
  sleeps on a free Render instance after ~15 minutes idle, installing or
  updating is precisely when it has been idle, `preloadAll()` then fires its
  whole 13-step warm-up SEQUENTIALLY at a server that is still waking, and
  `usePreload`'s `PRELOAD_TIMEOUT_MS` (12s) releases the splash long before the
  cold start finishes — so the student lands on screens whose requests are still
  failing. On an OTA update the new bundle is downloading over the same
  connection at the same time. Retry fires **only on `status === null`** (no
  response at all — offline, DNS, dropped connection, timeout); anything
  carrying a status is the server's considered answer and repeating it would
  only get the same answer more slowly. Two short delays (1.2s, 3.5s), because
  this is a wake-up allowance and a Wi-Fi/mobile-data handover cushion, not a
  general resilience layer.
  **`EXPO_PUBLIC_*` IS INLINED AT BUNDLE TIME, AND THE TWO SHIP PATHS READ
  DIFFERENT ENVIRONMENTS — THIS WAS THE REAL CAUSE OF "OFFLINE AFTER AN
  UPDATE"** (2026-09-11). `eas build` takes its environment from **eas.json's
  per-profile `env`**; `eas update` bundles on **whatever machine runs it** and
  reads **`mobile/.env`**. That file still held `http://192.168.254.133:8000` —
  a laptop LAN address from August — so **every over-the-air update silently
  repointed the installed app at a host the phone could never reach**. Every
  request then failed with no response, which is `status === null`, which is
  exactly the shape of a genuine offline failure: the app called itself
  offline and login reported "no internet connection" on a perfectly good
  network. Proven, not inferred — `grep` found the LAN IP inside the shipped
  Hermes bundle and no trace of the Render host. **This is also why the symptom
  always followed an update and never a fresh APK**, and why it looked like the
  update had not applied at all. The earlier `withRetry` work is still correct
  and still needed, but it was treating a symptom: retrying an unreachable
  address three times only fails three times.
  Two guards now, because either alone can be forgotten: **`mobile/.env` must
  match eas.json** (a LAN IP for local work goes in `.env.local`, which is
  gitignored and takes precedence), and **`API_BASE_URL`'s fallback is the live
  API rather than `http://10.0.2.2:8000`** — a localhost default turns a
  missing variable into a silent, undebuggable outage, where a production
  default degrades to the correct host.
  **DEPENDENCY AUDIT, and what "28 vulnerabilities" actually meant**
  (2026-09-11). `npm audit fix` (semver-safe only) cleared 3 — `js-yaml`,
  `nanoid`, `@xmldom/xmldom` — leaving 25. **Every remaining fix npm proposes
  is `expo@57` (SDK 54 → 57, three majors, every native module replaced and a
  new runtime fingerprint) or a DOWNGRADE of `expo-router` 6.0.24 → 5.1.11.**
  Neither is a security fix; both are breakage. So the question was which
  advisories can actually reach a student's phone, answered by exporting with
  `--source-maps` and reading the module list: **of 1210 modules in the shipped
  bundle, exactly one vulnerable package has code in it** — `decode-uri-
  component` (moderate, a self-DoS on malformed percent-encoded input) via
  `query-string` via `@react-navigation` via `expo-router`. Metro, PostCSS,
  `@expo/cli`'s config code, `image-size`, `xcode`, `uuid` and the
  `@expo/config` chain are **build tooling** — they run here and on the EAS
  builder, never inside the APK, and the `expo-constants`/`expo-linking`/
  `expo-notifications`/`expo-updates` entries are flagged only for depending on
  that chain. (The one `@expo/cli` file in the bundle is
  `build/metro-require/require.js`, the module-require shim, not the vulnerable
  CLI code.)
  **`decode-uri-component` is DELIBERATELY LEFT AT 0.2.2.** The patched line
  (0.4.1+) is **ESM-only** — `"type": "module"` with an `exports` map carrying
  no `require` condition — while `query-string@7.1.3` is CommonJS and does
  `require('decode-uri-component')`. Under Metro that require yields the module
  NAMESPACE, not the default export, so the call site becomes
  `decodeComponent is not a function` and every URL parse crashes: deep links
  and the DTR QR landing included. Trading a moderate self-DoS (the student's
  own phone hangs on a hostile URL, recoverable by force-closing; no server, no
  other user, no data) for a certain crash on every navigation is the wrong
  way round. Revisit when `query-string` ships an ESM-compatible major, or when
  the SDK upgrade happens on purpose. `npx expo install --check` reports every
  direct dependency already matching SDK 54, and `expo-doctor` passes 18/18.
  **EVERY CONFIRMATION IS THE APP'S OWN DIALOG, NOT `Alert.alert`**
  (`mobile/src/services/confirm.ts` + `mobile/src/components/ConfirmHost.tsx`,
  2026-09-11, project owner: "change the default look in every message… i mean
  the double verification"). Nineteen call sites across nine screens asked
  their question through the OS, so the one moment a student is required to
  stop and read was drawn by Android — Android's type, Android's blue, on a
  page that is otherwise entirely navy InternTrack. Worse than off-brand: a
  native Alert gives every question the SAME face, so "Submit this entry?" and
  "Delete this row?" were visually identical and the destructive one relied
  entirely on the student reading the word. The dialog now carries a tone
  (`default` · `danger` · `success` · `warn`) that moves the icon chip, the
  glyph and the confirm button's fill together, so the weight of an action is
  visible before a word is read; cancel is the OUTLINED button on the LEFT, so
  a destructive answer is never the one nearest a right thumb. Every message
  was rewritten to ONE short line ("This cannot be undone.", "You can still
  edit it until your week is compiled.") — the native dialogs had grown to
  three-sentence paragraphs nobody reads at the moment of deciding.
  **It is promise-based**, so a call site reads as a straight line
  (`if (!(await confirmAction({…}))) return;`) instead of the callback-in-an-
  array shape `Alert.alert` forces, which is what had pushed several of these
  into bespoke helper functions. The store is **module-level** for the same
  reason `toast.ts`'s is — a dialog has to be openable from a plain async
  function mid-save, not only from inside a component body — and `ConfirmHost`
  is mounted once at the root beside `ToastHost`, above the navigator, so it
  reaches modals like Write Journal too. **`alertAction` is the one-button
  form** (what `Alert.alert` with no button array was) and is deliberately NOT
  collapsed into `showError`: a toast slides away on its own, which is right
  for a receipt and wrong for "your clock-in did not record". Two rules that
  are easy to lose: the backdrop declines on a confirm but is **inert on a
  one-button acknowledgement**, so a message that must be read cannot be lost
  to a stray tap; and a dialog raised from inside a `try/finally` is called
  with `void`, never awaited, because the `finally` clears the screen's loading
  flag and awaiting leaves the button spinning behind the dialog. **Log Out
  gained a confirmation it never had** — the web app has confirmed it since the
  beginning, and on mobile it clears every cache the device holds for that
  student (`clearDeviceSession()`), so a mis-tap on the last button of Profile
  should not do it silently.
  **A WRITE is deliberately NOT retried**, and that is why `apiPost` takes an
  opt-in `{ retry: true }` rather than sharing GET's treatment: a POST whose
  response was lost may well have succeeded, so repeating it can file a second
  journal entry or a second punch — exactly the case the journal outbox exists
  to handle safely. **Login is the one write that opts in**: it is the first
  thing to touch the API after the app has sat unused, and a lost response there
  told the student to check a connection that was never the problem. A duplicate
  login only issues a second token.
  It has its own
  `mobile/CLAUDE.md` (importing `mobile/AGENTS.md`) requiring the versioned docs
  at `docs.expo.dev/versions/v54.0.0/` be checked before any mobile code.

## Tech Stack (do not change without asking)

- Laravel 13, PHP 8.3, MySQL (SQLite for local/testing)
- Vue 3 (Composition API, `<script setup>`, TypeScript), Vue Router, Pinia
- Tailwind CSS v4 (no v3 syntax, no `tailwind.config.js` patterns v4 removed)
- Axios with the CSRF/Sanctum cookie flow (`withCredentials`, `withXSRFToken`)
- Auth: Laravel Breeze + Sanctum, **cookie/session-based** for the web SPA
  (`EnsureFrontendRequestsAreStateful`). Token auth for mobile is deferred to
  Phase 7.

**Login is USERNAME-based.** `users.username` is NOT NULL + unique;
`users.email` is nullable (unique when present). `LoginRequest` normalizes
`login`/`username`/`email` into one identifier and resolves it against
`username` OR `email` (via `filter_var` email detection), so username-only and
legacy email accounts both sign in. `User::booted()`/`generateUniqueUsername()`
auto-generates a username for any non-muted create that omits one; seeders set
explicit usernames because `DatabaseSeeder` uses `WithoutModelEvents`, which
mutes that hook.

Google sign-in is wired but strictly **link-only** — it never creates accounts.
Username + password remains the primary, always-available path.

**Every account-creation surface treats email as optional (2026-09-10).**
`Admin\UserController::store()` (Create Coordinator) and
`Coordinator\CreateSupervisorRequest`/`CoordinatorCompanyController::createSupervisor()`
(Create Supervisor, both the standalone modal on Users → Supervisors and the
inline form on Partner Companies) now validate `email` as `nullable` rather
than `required`, and both accept an optional `username` field alongside it
(same `nullable|string|min:3|max:50|regex` shape as the student-creation
`CreateAccountRequest` already used). Leaving both blank still yields a
working account: `User::booted()`'s `creating` hook auto-generates a username
from the name when there is no email to derive one from. This closes the last
two account-creation paths that still forced an email — every other creation
flow (student accounts, bulk import aside, which needs an address to auto-mail
credentials to) already worked this way. Google verification remains the only
way `email_verified_at` gets set, so username+password stays how these
accounts sign in until someone chooses to link Google.

Consequences threaded through so a blank-email account stays identifiable to
the person who created it: the success toast on both creation forms echoes the
assigned username back (reading it from the create response — `User::store()`
returns the full model, `createSupervisor()`'s response's `supervisors[]`
carries a `user.username`); `EnrollmentController::supervisors()`,
`CoordinatorCompanyController::index()`/`companyPayload()`/`mapSupervisors()`
all select `username` alongside `email` now; and every list/panel that used to
print only `supervisor.email` falls back to `@username` when there is no
email (`CoordinatorInternsPage.vue`'s Supervisors tab, both table and mobile
card view; `CoordinatorCompaniesPage.vue`'s OJT Supervisor Login panel).

## Hard Rules

1. **Do not create a duplicate migration.** Check `database/migrations/` first.
   If a migration for that table exists and hasn't shipped, EDIT it.
2. **Do not add a second middleware layer or restructure app architecture**
   without proposing it first and waiting for approval.
3. **Stay on the finalized schema** (InternTrack_Database_Schema_v2, 20 tables).
   Do not rename tables/columns or add tables without asking. Settled structure:
   - `weekly_activity_logs` splits into a header table +
     `weekly_activity_log_entries` (mirroring the physical SIPP form) — same
     pattern for `weekly_logs`/`weekly_log_entries` and `journal_entries`
   - Separate `programs`, `departments`, `student_profiles`,
     `journal_templates`, `system_settings` tables exist
   - `programs` has a composite unique on `(department_id, code)` — departments
     are independent top-level units (CABM-B and CABM-H are two separate
     departments, not sub-units of one "CABM"). Do not introduce a
     department→division→program hierarchy without confirming first.
   - `batches.ojt_type` and a nullable `batch_students.supervisor_id` were
     added 2026-08-30 at the project owner's request (see OJT Type below).
     The enum is additive and DEFAULTS to `supervisor`, so every existing batch
     keeps its current mechanics; the nullable column is the only relaxation of
     an existing constraint in the schema.
   - `student_exit_interviews` was added 2026-08-30 at the project owner's
     request (see Exit Interview below) — one row per (student, batch), three
     JSON payload columns, `UNIQUE(student_id, batch_id)`. It is the only
     table outside the v2 schema; the JSON columns exist so adding or
     rewording a question on the paper form never means a migration.
4. **Out of scope — do not build:** photo capture on clock-in.
   **NARROWED 2026-08-30 (project owner):** this entry used to also list the
   **Summary Report on Student Exit Interview** as out of scope; the
   per-student **Internship Program Student Exit Interview Form** was carved
   in first — see Exit Interview below.
   **NARROWED 2026-09-10 (project owner):** the aggregate **Summary Report on
   Student Exit Interview** is now also **IN scope and BUILT** — every
   in-scope intern's answer to each question gathered together, reached as a
   tab on the coordinator's Student Exit Interviews page. See Exit Interview →
   Summary Report below. Photo capture on clock-in remains the only item left
   here.
   **RESCINDED 2026-08-20 (project owner):** geofence clock-in, QR clock-in and
   the in-app camera scanner were all previously listed here as out of scope.
   They are now **IN scope and BUILT** — see Daily Time Record below. Do not
   re-add them to this list. Rotating (TOTP) QR is still unbuilt, but the verify
   path is deliberately shaped for it.
5. **Record every change in `PROJECT.md` as part of finishing the task.** Any new
   feature, schema change, notable bug fix, or behavior change gets a bullet
   added or a stale statement corrected — before reporting the task complete,
   not after being asked. A change not reflected here is invisible next session.
6. **Never `git commit`, `git push`, create a branch, or open a PR without the
   project owner's explicit permission for that specific action.** Approval to
   make a change is NOT approval to commit it. This applies to the `deploy`
   branch above all — `docker/entrypoint.sh` runs `migrate --force` on every
   boot, so an unrequested push reaches live data on the next wake.

## Domain Facts

- **3 departments, 7 programs:** CAST → BSIT · CABM-B → BSBA-FM, BSBA-MM,
  BSBA-OM, BSA · CABM-H → BSTM, BSHRM.
  **These are the SEEDED STARTING POINT, not a fixed set** (corrected
  2026-09-08). The admin creates departments, and since 2026-09-08 creates
  **programs** under them as well — see Admin → Programs below. Treat the seven
  as what a fresh install ships with; a running install may hold any number.
- **`departments.code` is the identifier; `departments.name` is display only.**
  Every lookup in the project keys off the code
  (`Department::where('code', 'CABM-B')`), it is what fits a narrow column and
  what prints on the SIPP forms — **never rename one casually**. Nothing keys
  off `name`; it appears in audit-log lines, the department dropdowns and the
  Departments list, and `AdminDepartmentsPage` hides the Code field on edit for
  exactly this reason.
  **CORRECTED 2026-09-08 (project owner):** `DepartmentProgramSeeder` used to
  set `name` to the *same string as the code* — `'CAST' => ['name' => 'CAST']`
  — so the Admin → Departments tab showed "CABM-B / CABM-B" in two columns with
  nothing saying what either was for. Not merely cosmetic: it is the reason
  `BuildsWeeklyActivityLogPdf::DEFAULT_DEPARTMENT_LINE` and the GROUP info
  sheet's header are **hardcoded literals** — PROJECT.md already said in as many
  words that `departments.name` "is seeded to the short code (`CABM-B`) and
  would print wrongly". The seeded names are now:

  | code | name |
  |---|---|
  | `CAST` | College of Arts, Sciences and Technology |
  | `CABM-B` | Business Department – College of Accountancy, Business and Management |
  | `CABM-H` | Hospitality Department – College of Accountancy, Business and Management |

  **THE DISTINGUISHING WORD LEADS, and that is load-bearing, not styling.**
  Written college-first, CABM-B and CABM-H open with the same 48 characters and
  both truncate to an identical `"College of Accountancy…"` in the list — the
  two rows become indistinguishable at any normal column width, which is the
  very confusion this change removed. Caught on screen, not in theory. It also
  matches the model above: those two are **independent top-level departments**,
  not sub-units of one CABM, and the SIPP forms print college and unit as two
  separate lines.

  **STILL OPEN, deliberately:** the two hardcoded PDF constants were NOT rewired
  to read `departments.name` — those are measured facsimiles and changing what
  they print needs a re-measure against the reference, which was not part of
  this change. They are now *derivable*, which they were not before.
  **`programs.name` has the identical defect** (`'BSIT' => 'BSIT'`), so the
  individual info sheet prints `BSIT` where it should print the full program
  name. Left alone at the project owner's sequencing ("starting off with
  Departments").
- **Roles** (`users.role` enum, exactly four): `admin`, `coordinator`,
  `supervisor`, `student`. `supervisor` means *company* supervisor (see
  `CompanySupervisor`, linking a `supervisor`-role user to a `Company`). There
  is no separate adviser/instructor role.
- **User deactivation is soft** (`is_active = false`). There is no hard-delete
  route for users — a deactivated user's journal entries and weekly logs must
  stay intact. The one deliberate exception is
  `Coordinator/EnrollmentController::destroyAccount`, which hard-deletes only
  accounts carrying **zero** OJT history (see Intake & Enrollment below).
- **A completed student is already read-only** — not via `is_active`, but via
  enrollment status: `ResolvesStudentEnrollment::activeEnrollment()` (every
  student WRITE endpoint) requires `status = 'active'`, while
  `currentEnrollment()` (READ endpoints) accepts `active` OR `completed`.
- **Creating a `student`-role user auto-creates a `student_profiles` row** via
  `UserObserver` on the `created` event, with a placeholder
  `student_id_number` (`PENDING-XXXXXXXX`) until the registrar ID is filled in.
- **SIPP compliance documents:** OJT Annual Report; Student Information Sheet
  in **two variants, both built** — the per-student individual sheet and the
  per-company GROUP sheet (reference:
  `docs/reference/Student Information Sheet (Group) (1) (3).pdf`); and the
  **Internship Program Student Exit Interview Form**, built 2026-08-30
  (reference: `docs/reference/INTERNSHIP PROGRAM STUDENT EXIT INTERVIEW -
  BUSINESS.pdf`). The aggregate **Summary Report on Student Exit Interview**
  — every intern's answer to each question gathered together — was added
  2026-09-10; see Exit Interview → Summary Report below.

## Core Data Model & Invariants

### Enrollment (`batch_students`)

`batch_students` is the **authoritative student-to-company/supervisor linkage**,
with a DB-level **`UNIQUE(batch_id, student_id)`** backstop — at most one row
per (batch, student) pair, ever. Its `status` (`active`/`completed`/`dropped`)
carries the lifecycle.

**All enrollment placement flows through
`App\Services\EnrollmentService::enrollOrReactivate()`** (used by
`EnrollmentController::store`, info-sheet Accept, and `BatchRosterController::add`).
It reuses ANY existing row for that exact pair regardless of status — refreshing
`company_id`/`supervisor_id`/`assigned_division` and setting it back to `active`
— and only creates a fresh row when none exists. That is what makes the unique
index safe.

- **Student WRITE endpoints** require an `active` row via
  `ResolvesStudentEnrollment::activeEnrollment()`, else 422 "You are not
  currently enrolled in an active OJT batch."
- **Journal/weekly-log READ endpoints** use `currentEnrollment()` (latest
  `active` OR `completed`), so a completed student keeps full read access.

### The OJT window is real-time, not estimate-bound

`ResolvesStudentEnrollment::ojtRange()`: start = the batch's `start_date`; end =
`batch_students.completed_at` once the coordinator marks the enrollment
completed, or **today** (rolling) while still active. The info sheet's
`ojt_info.ojt_start_date`/`ojt_end_date` are **informational only** — they render
on the form/PDF and gate nothing.

Completion is coordinator-driven from the batch roster modal
(`BatchRosterController::complete`/`reopen`). **`completed_at` is maintained ONLY
by a `BatchStudent::booted()` saving hook** — stamped on entry to `completed`,
cleared on exit. Never set it manually.

Downstream of completion: writes 422; the journal calendar freezes its upper
bound at `completed_at`; `WeeklyBundlingService` skips them (it only iterates
`status='active'`); `User::isInfoSheetGated()` treats active **or completed** as
having cleared intake.

### Coordinator scope — one department per coordinator

`coordinator_departments.coordinator_id` is **unique**. A department can have
multiple programs and multiple coordinators, but not the reverse.
`AssignCoordinatorRequest` rejects assigning a coordinator already attached
elsewhere; `Admin/UserController::store` takes a single `department_id`.

A coordinator's programs resolve via **`User::coordinatorProgramIds()`** — every
program in their assigned department, merged with programs of batches they
already coordinate (`batchesCoordinated()`, kept only as a backward-safety net).
**`users.program_id` is retained but is NOT the coordinator scoping source.**
Every coordinator page is scoped by this; out-of-scope access 403s.

**CORRECTED 2026-09-10 (project owner, found live):** this note used to say a
coordinator's department never reaches the frontend at all, because
`/api/user` only loaded `program.department` and a coordinator's
`users.program_id` is null — true as far as it went, but it meant
`AuthUserPayload::build()` never loaded `departmentsCoordinated` either, so
`CoordinatorLayout.vue`'s header (`"Coordinator · {{ department }}"`) fell
through to a **hardcoded placeholder** (`'Business Administration'`) for
*every* coordinator, unconditionally — it only went unnoticed because that
string happens to sound plausible for a CABM-type department. `build()` now
loads `departmentsCoordinated:id,code,name` for coordinators (at most one row,
per the unique constraint above), and the header reads
`departments_coordinated[0].code` — the **code**, matching how the rest of the
app treats `departments.code` as the identifier, not `departments.name`.
**`CoordinatorDashboardPage.vue`'s scope notice ("This workspace is scoped
to…") had the IDENTICAL bug**, reading the same always-null
`program?.department?.name` — it read as a deliberate design choice ("a
'your department' fallback rather than a real name") only because its
fallback text was generic enough to pass as intentional copy rather than a
wrong name. Fixed the same way, same field.

### Company Supervisor: login-bearing vs named-only

`company_supervisors` rows come in two kinds, sharing one table:

- **Login-bearing** (`user_id` set, `name` null) — the company's **one shared
  login** used to authenticate as `role=supervisor` and review weekly logs.
  `Company::loginSupervisor()` resolves it.
  `CoordinatorCompanyController::guardSingleLogin()` 422s any attach/create of a
  **second** login row for the same company; re-attaching the same user is
  idempotent. **A company has at most one login, ever.**
- **Named-only** (`user_id` null, `name` set, `position` nullable) — a record of
  a real person with no login. Unlimited per company. Also used for **Company
  Representatives** (purely informational contacts).

`batch_students` carries both: **`supervisor_id`** (NOT NULL, `users.id`) always
pins to the company's **login**; **`company_supervisor_id`** (nullable) records
the **named individual** the student typed on their info sheet, who may be a
different person.

**`detachSupervisor` is keyed by `company_supervisors.id`, not `user.id`** — a
named-only row has no user to bind to.

**Supervisor scoping is company-based, not user-based**:
`ScopesSupervisorWork::supervisedCompanyIds()` finds every company where the
authed user is the `company_supervisors.user_id`; `supervisedEnrollments()`
filters `BatchStudent::whereIn('company_id', ...)`. This is what lets a shared
company login see the company's full roster.

### The supervisor is tied to the company, never manually picked

**Scoped 2026-08-30: everything in this subsection describes a
`supervisor`-type batch, which is the default and remains how every existing
cohort runs. A `coordinator`-type batch has no company supervisor at all — see
OJT Type below.**

`EnrollmentService::enrollOrReactivate()` takes **no `$supervisorId` parameter**.
Callers supply only a `$companyId`, and the service derives `supervisor_id` from
`Company::loginSupervisor()->user_id`, aborting 422 if the company has none.
Centralizing this in one service is what stops the rule drifting between the
manual Enroll form, the roster Add-Intern flow, and info-sheet Accept.
`EnrollmentController::update()` re-derives on a company change. There is **no
Supervisor select element in any form** — the resolved supervisor is read-only.

Consequences, all deliberate:

- **A company must have a login-bearing supervisor before ANY student can enroll
  there.** A company with only named-only rows 422s on every path. Add a login
  supervisor first.
- **A login swap self-heals**: `attachSupervisor`/`createSupervisor` call
  `syncActiveEnrollmentSupervisors()`, re-pointing every **active** row for that
  company at the new login. Deliberately untouched: `company_supervisor_id`, and
  **completed/dropped** rows (historical — they keep whoever supervised them).
- Remaining edge: detaching a login **without** re-attaching leaves active rows
  pointing at the former login, since `supervisor_id` is NOT NULL.
- **Seeders bypass this gate** (expected) — they write `batch_students` directly
  via Eloquent, so `migrate:fresh --seed` is unaffected. But every demo company
  referenced by an enrollment still needs a login supervisor for later UI edits.

**CORRECTED 2026-09-11 (project owner, found live): `EnrollmentController::options()`'s
`supervisors[]` used to be every supervisor-role user in the ENTIRE system,
unfiltered by scope or company.** A brand-new coordinator with zero companies
of their own saw every other department's supervisors in the "Attach Existing
Supervisor" dropdown on Partner Companies — the old doc line above ("does not
filter by is_active") was true but incomplete: it filtered by nothing at all.
Fixed by splitting what had been one overloaded list into two:

- **The read-only "which supervisor does this company resolve to" preview**
  (the Enroll form and the Add-Intern roster form) now reads
  `companies[].login_supervisor` — a field resolved per-company via
  `Company::loginSupervisor()`, added directly onto the (still deliberately
  unscoped) `companies[]` array. `enrollResolvedSupervisor`
  (`CoordinatorInternsPage.vue`) and `addResolvedSupervisor`
  (`CoordinatorBatchesPage.vue`) both read it this way now, instead of
  scanning a `supervisors[]` list by a `company_ids` field (removed — see
  below). This preserves the existing behavior that a company can be shared
  across departments and its resolved supervisor still shown/required to
  enroll there, which is why `companies[]` itself stays unscoped.
- **The "Attach Existing Supervisor" dropdown's `supervisors[]`** is now scoped
  by a new **`ScopesCoordinatorAccounts::attachableSupervisorIds()`**: a
  supervisor already on one of the coordinator's own in-scope companies, OR
  attached to **no** company at all yet (a freshly-created or just-detached
  "floating" account nobody has claimed). **Deliberately NOT just
  `scopedSupervisorIds()`** — that method is built entirely from existing
  `CompanySupervisor` rows, so it can never contain a floating supervisor,
  and using it alone would have made every freshly-created or freshly-detached
  supervisor permanently unattachable by anyone. Pinned by
  `test_the_attach_dropdown_excludes_a_supervisor_exclusive_to_another_department`
  (`tests/Feature/Coordinator/EnrollmentTest.php`), which is also the
  regression guard for the `company_ids` removal — the sibling test
  `test_a_shared_companys_login_supervisor_resolves_even_when_out_of_scope`
  pins that a shared company's preview still works after the split.
- **`CoordinatorCompanyController::attachSupervisor()` now independently
  enforces the same `attachableSupervisorIds()` check server-side (403)** —
  the dropdown only narrows what is *shown*; `AttachSupervisorRequest`'s own
  rule (`Rule::exists('users','id')->where('role','supervisor')`) only proves
  the id names *some* supervisor, not one this coordinator may touch, so a
  crafted request could otherwise attach an out-of-scope supervisor regardless
  of what the UI offered. Pinned by
  `test_attaching_a_supervisor_exclusive_to_another_department_is_refused`.

## OJT Type — supervisor-supported vs coordinator-centered

Built 2026-08-30 at the project owner's request. **`batches.ojt_type`** is an
enum (`supervisor` | `coordinator`) chosen during batch creation, deciding who
reviews that cohort's weekly journals.

- **`supervisor`** (the column DEFAULT) — the host company holds a login and its
  supervisor reviews the weekly journals and corrects the time records. This is
  exactly how every batch behaved before the choice existed, so **every existing
  row keeps its mechanics with no backfill** and nothing changes on deploy.
- **`coordinator`** — there is **no company supervisor at all**. Not a different
  one, none. The coordinator reviews the weekly journals themselves, and the
  supervisor fields that still print on the paper forms are informational text
  rather than a linked account.

**It lives on the BATCH, not on the coordinator** (unlike `users.dtr_enabled`).
A cohort is placed under one arrangement; a coordinator can genuinely run a
supervisor-supported programme and a field-placement one at the same time; and
every journal, weekly log and time record already carries its `batch_id`, so a
finished cohort keeps whichever rule it ran under.

### Two migrations, and the second is the whole structural cost

1. `2026_08_30_000002_add_ojt_type_to_batches_table` — additive, defaulted.
2. `2026_08_30_000003_make_supervisor_id_nullable_on_batch_students_table` —
   **`batch_students.supervisor_id` was NOT NULL + FK since the beginning.** That
   is what made "the supervisor is tied to the company" enforceable at the
   database level, and it is unchanged for supervisor-supported batches; the
   column is merely allowed to be empty where the question does not arise.

`weekly_logs.supervisor_id` needed **no** migration — it has always been a
nullable users FK, so a coordinator's id sits in it as naturally as a
supervisor's. On a coordinator-centered batch the column simply means *the
reviewer*, which is what it has always recorded.

### The branch lives in ONE place per concern

- **Enrollment**: `EnrollmentService::enrollOrReactivate()` reads the batch and
  either resolves the company login as before, or writes `supervisor_id` and
  `company_supervisor_id` as **null**. The branch is read off the BATCH, never
  off a parameter, so no caller can opt a placement out of the gate by
  forgetting to pass something. All three enrollment paths inherit it.
- **Supervisor scoping**: `ScopesSupervisorWork::supervisedEnrollments()` gained
  **`->whereNotNull('supervisor_id')`** — one clause, not an `ojt_type` join at
  each call site. A company can host both kinds of batch at once, and those
  enrollments have no supervisor at all, so "rows that pin a supervisor" is the
  same question as "rows this login has any role over". My Interns, the review
  queue, the notebook and the DTR review surface all resolve through that one
  method, so they cannot disagree. Pinned by
  `test_a_coordinator_centered_intern_never_appears_on_a_supervisors_roster` —
  before the clause, the intern **did** appear on a supervisor's own roster.
- **Daily Time Record**: `DtrService::runsForEnrollment()` is the single answer
  to "does the DTR run here?", and requires **both** the coordinator's
  `dtr_enabled` **and** a supervisor-supported batch. Only a supervisor can
  anchor a geofence at the workplace or vouch for a forgotten punch, and a
  coordinator-centered batch has none — so hours come from the typed Weekly and
  Time Log Summary, and an unclocked hour still stays unclocked.
  `AuthUserPayload`'s `student_dtr_enabled` mirrors it inside the existing
  `whereHas`, so the student's nav item never appears.

### Frozen once anyone is enrolled

`UpdateBatchRequest::withValidator()` refuses a CHANGE to `ojt_type` once
`batchStudents()->exists()`. This is a data rule, not a UI nicety: flipping a
live cohort would hand every journal already waiting on one reviewer to a
different one mid-placement, and would strand enrollments pinning a
`supervisor_id` the new mode says should not exist. **Re-stating the same value
is always allowed**, because the batches page PUTs the whole form back including
fields the coordinator never touched. `BatchController` returns
`interns_count` so the form can disable the control rather than offer a change
the server would refuse.

## Working Days — a real day-of-week range, not just a count (2026-09-11)

Built at the project owner's request, after live testing surfaced that the old
"Working Days / Week" field was just a plain number (1-7) with no notion of
*which* days — `App\Support\BatchWorkingDays::isWorkingDay()` only ever checked
1-5 → Mon-Fri, 6 → Mon-Sat, 7 → every day, always anchored to Monday. A batch
whose real week ran, say, Tuesday-Saturday had no way to say so, and values 1-4
were indistinguishable from 5 (a pre-existing ambiguity, deliberately preserved
rather than "fixed" as part of this change — see below).

- **`batches.working_days_start` / `working_days_end`** (tinyInteger, ISO
  weekday 1=Mon..7=Sun, both NOT NULL) hold the real range now, picked on the
  coordinator's Create/Edit Batch form via **`WeekdayRangePicker.vue`**
  (`components/coordinator/`) — seven circles (M T W T F S S), click one to
  start a selection, click another to complete the range. **The range WRAPS
  across the week when the end precedes the start** (e.g. clicking Sat then Tue
  sets Sat/Sun/Mon/Tue) — deliberately not normalized to "whichever direction is
  shorter", since an ordered two-click range is unambiguous and silently
  flipping it would sometimes produce a different set of days than the
  coordinator actually clicked.
- **`working_days_per_week` STAYS** — every existing consumer (the reminder
  command, the student dashboard's missing-count, the journal calendar, the
  reminder-preference defaults) still reads it — but it is now **derived
  automatically**, never typed directly. `App\Observers\BatchObserver`
  (`#[ObservedBy]` on `Batch`, the same mechanism `UserObserver` uses) keeps the
  two in step on every save: picking a range derives the count
  (`BatchWorkingDays::countFromRange()`, wrap-aware); posting only the legacy
  count (an older client, or a seeder) derives a Monday-anchored range
  (`BatchWorkingDays::rangeFromLegacyCount()`) using the **exact** mapping the
  old count-only logic assumed, so nothing that already existed changes
  behavior.
  **Seeders bypass this**, same as `UserObserver`'s username generation —
  `DatabaseSeeder` uses `WithoutModelEvents`, which mutes `BatchObserver` too,
  so the three seeders that create a `Batch` directly
  (`CabmbCoordinatorCenteredDemoSeeder`, `CabmbUsersDemoSeeder`,
  `StudentDemoEnrollmentSeeder`) now write `working_days_start`/`_end`
  explicitly alongside `working_days_per_week`.
- **`BatchWorkingDays::isWorkingDayInRange($date, $start, $end)`** is the real
  predicate now (wraparound-aware); the old `isWorkingDay($date, $count)` is
  kept byte-for-byte as-is for any caller that only ever has a count (none
  exist in `app/` today). The 5 real consumers —
  `SendMissingJournalEntryReminders`, `StudentDashboardController`,
  `JournalCalendarController`, `ReminderPreferenceController`,
  `ReminderSchedule::remindsOn()` — were switched to pass the range instead of
  the count; this is what makes an arbitrary start+end day *mean* something
  app-wide, not just look different on the form.
- **Migration backfill freezes the exact old mapping** for every batch that
  already existed (7→[1,7], 6→[1,6], else→[1,5]), so nothing already seeded or
  live changed behavior the moment the migration ran — verified via
  `php artisan migrate:fresh --seed` and a direct query of the seeded batches
  afterward.
- Both `StoreBatchRequest`/`UpdateBatchRequest` (coordinator) accept either
  shape — `working_days_start`+`working_days_end` (paired via
  `required_with` both ways) or the bare legacy `working_days_per_week` — so an
  older caller still works. `Admin\StoreBatchRequest` got the same treatment for
  parity, though it remains **dead code**: no route or test wires it, and the
  admin's own Batches page is read-only (view-only modal, no create/edit form).
- `AdminBatchesPage`'s read-only batch view now shows the range as
  `"Mon – Fri"` (or the wrapped equivalent, e.g. `"Sat – Tue"`) via the shared
  `web/src/lib/weekdays.ts` helpers (`formatDayRange`, `isDayInRange`,
  `WEEKDAY_NAMES`/`WEEKDAY_LETTERS`) — the same helpers the picker itself uses,
  so the two can never describe a range differently.

Coverage: `tests/Unit/Support/BatchWorkingDaysTest.php` pins the
behavior-preserving mapping, the wraparound math (`isWorkingDayInRange`, both
directions of `countFromRange`), and the legacy-count round trip. No existing
test needed a behavior change — the 25+ tests touching
`working_days_per_week` were re-run and stayed green as-is, since
`BatchObserver` derives it transparently from whichever field a test/request
already sends.

### The coordinator's review surface

`Coordinator/CoordinatorJournalReviewController` (`index`, `interns`,
`notebook`, `show`, `pdf`, `approve`, `returnLog`; routes
`coordinator/journal-review*`), page `CoordinatorJournalReviewPage.vue` at
`/coordinator/journal-review`, nav label **"Journal Review"**.

- **Deliberately NOT the same surface as `CoordinatorWeeklyJournalController`**,
  which stays exactly as it was: read-only monitoring across EVERY batch in
  scope. That page answers "how is my department doing?"; this one answers "what
  is waiting on me?" and is the only coordinator surface that writes a verdict.
  Keeping them apart is what stops a coordinator gaining approve/return over a
  supervisor-supported batch, where the verdict belongs to the company.
- **Scope is `coordinatorProgramIds()`**, matching every other coordinator page
  rather than narrowing to `batches.coordinator_id` — a department's
  coordinators already cover for each other everywhere else, and a cohort whose
  coordinator is away must not have its journals stuck.
- `authorizeReview()` keys off the **LOG'S OWN batch**, not the student: a
  student may have been in a supervisor-supported cohort previously, and those
  weeks were the company's to review and stay that way.
- The **`interns` index** exists because the queue lists one status at a time,
  so without it an intern with nothing currently pending would be unreachable.
- **Filters (added 2026-09-01): batch, company, and status, with status
  defaulting to `pending`.** A department can run several coordinator-centered
  cohorts at several host companies at once, and collecting one batch's journals
  should not mean reading past the others.
  - **Batch and company narrow BOTH tabs** — `index` and `interns` take the same
    two params — because the page carries ONE filter bar above the tab strip.
    Applying them to the queue alone would let switching tabs silently change
    what is being looked at. Status stays with the queue, since the intern index
    shows all three tallies at once.
  - **`pending` remains the default and is echoed back as `status`.** "What is
    waiting on me" is the reason to open this page; an unknown status still
    falls back to pending rather than 422ing.
  - **The pill counts come from the SAME filtered query the table does.**
    Counting unfiltered would let "Approved 7" open onto an empty table whenever
    a batch filter is set.
  - **The dropdown options come from the UNFILTERED enrollment set**, so
    selecting one never removes the others — a filter list that narrows to its
    own selection cannot be changed without clearing it first. They list only
    batches/companies that actually host a coordinator-centered intern in scope,
    never the whole department.
  - `batch_id`/`company_id` are validated as integers but deliberately **not**
    scope-checked (unlike `CoordinatorWeeklyJournalController`'s `program_id`,
    which 403s): they only ever NARROW a query that is already scoped, so an
    out-of-scope id yields an empty list rather than leaking anything, and a 403
    is a worse answer to a stale bookmark.
  - The queue is constrained by **student AND batch**, so a company filter
    cannot leak a week the same student wrote on a different cohort.
  - **The company renders UNDER the batch name, not in a seventh column** — the
    queue table is already six columns wide at its documented widths, and a
    filter whose effect is invisible in its own results is not worth having.
    `weekly_logs` has no company; it is resolved per (student, batch) pair from
    the enrollment, which is the same pair the log is keyed by.
- **ROUTE ORDERING**: `journal-review/interns` and
  `journal-review/interns/{student}` must stay ABOVE
  `journal-review/{weeklyLog}`, the same hazard as `info-sheets/pending-count`.

#### Two nav items exist only for the OJT type they belong to (2026-09-01)

**This reverses the original decision, at the project owner's request.**
Journal Review used to be shown to every coordinator, on the argument that
hiding it made the OJT-type feature undiscoverable. In practice, for the
majority who run only supervisor-supported cohorts, it was a permanent sidebar
entry whose only content was an explanation of why it was empty. The OJT Type
control on the Batches page is where the mode is genuinely discovered. The same
treatment was then extended to the coordinator's Daily Time Record.

**THE TWO CONDITIONS ARE OPPOSITES, and that is the whole subtlety here:**

| Nav item | Shown when the coordinator has | Because |
|---|---|---|
| **Journal Review** | a **coordinator-centered** batch | those journals are theirs to approve; a supervisor-supported cohort's verdict belongs to the company |
| **Daily Time Record** | a **supervisor-supported** batch | `DtrService::runsForEnrollment()` requires one — the whole scheme rests on a company supervisor being on site to anchor a geofence and vouch for a forgotten punch, so a coordinator-centered cohort has no intern who can clock in at all |

**The DTR half came from the client, not from the code.** The project owner
reported (2026-09-01) that their client runs coordinator-centered placements and
had told them the Daily Time Record is not supported for her — which is exactly
what `runsForEnrollment()` already says. The page was a permanently empty table
for that department, so the item goes.

Wiring both to one flag is the mistake this table exists to prevent: it would
hide the DTR from exactly the coordinators whose interns DO clock in (a
department running only supervisor-supported cohorts — the common case, and the
one `mdccore` demonstrates on seeded data with three real intern rows), and show
it to the one department where it applies to nobody. That literal-but-inverted
version was briefly built and reverted; do not reintroduce it.

- **`AuthUserPayload` gains `coordinator_has_centered_batch` and
  `coordinator_has_supervised_batch`**, resolved from **one** `distinct()`
  pluck of `ojt_type` over the already-cached `coordinatorProgramIds()` rather
  than two `exists()` round trips. They ride on the payload for the same reason
  `student_dtr_enabled` does: `CoordinatorLayout` filters the nav **before** any
  page loads, so without them the items would flash in and then vanish.
- **The DTR flag is deliberately NOT also gated on the coordinator's own
  `dtr_enabled`.** `/coordinator/dtr` is the ONE place that switch is set (the
  account-menu panel was deleted in 2026-08-20), so hiding the page whenever the
  DTR is off would make switching it off irreversible. The page's own "off"
  state — the consequence, the switch and the reason fields — is unchanged.
- **`router.beforeEach` carries both guards** and bounces
  `/coordinator/journal-review*` and `/coordinator/dtr*` to the dashboard.
  Hiding an item alone would leave a bookmark reaching a page with no way back
  to it, which is not what "the tab should not exist" means. This is navigation,
  not authorization — every endpoint still scopes itself, and
  `DtrMonitorController` (which already excludes coordinator-centered batches
  from its rows) is untouched.
- The layout keeps the route→flag map in **one** place
  (`CONDITIONAL_NAV_ITEMS`), so a third conditional item is a line rather than
  another bespoke `filter` clause.
- **`CoordinatorBatchesPage::save()` re-fetches the auth user** after a
  successful save (non-fatally, since the batch is already written). Creating a
  batch is the only way either flag can flip, and without the refresh a
  coordinator creating their first batch would be told it worked and then have
  no way to reach the matching surface until they signed in again.
- Pinned by `CoordinatorJournalReviewTest::test_the_auth_payload_reports_which_kinds_of_cohort_the_coordinator_runs`
  and `..._a_coordinator_with_only_centered_cohorts_loses_the_daily_time_record`
  — the second exists purely to fail if the two flags are ever collapsed into
  one.

### One review implementation, two reviewers

`App\Http\Controllers\Concerns\ReviewsWeeklyJournals` holds everything about
reviewing that does not depend on WHO reviews: `isReviewable`/`assertReviewable`,
the queue row shape, the week payload, the notebook payload, both verdict writes
and the PDF. `SupervisorJournalController` and
`CoordinatorJournalReviewController` supply **scope and nothing else**. Same
reasoning as `WeeklyBundlingService::compileFor()` and `EnrollmentService`: a
student's journal must not mean two different things depending on which of the
two people opened it.

#### A reviewed daily entry is ordered and labelled by its own template (2026-09-08)

`weeklyLogPayload()` carries **`template_sections`** — `{key, label, sipp}` for
each section of the journal template **the LOG'S OWN batch** ran under (not the
student's current enrolment, so a finished cohort keeps the form it was written
on). `web/src/lib/journalContent.ts`'s **`journalContentFields()`** is the one
client-side writer, used by the supervisor's review modal and the shared
per-intern notebook.

**This fixed a real reporting gap, not a cosmetic one.** The review surfaces
rendered `Object.entries(content)`, and `journal_entries.content` is a JSON
column — so fields appeared in whatever order the student's payload happened to
serialise in. In practice that put the fixed **`daily_accomplishment` LAST**,
after the SIPP trio, with the trio itself unordered, and labelled everything by
humanising the key (`Issues Concerns`) rather than using the coordinator's own
wording (`Issues and Concerns Encountered`). Since the weekly document above the
list is compiled from `daily_accomplishment` alone, the SIPP answers — the whole
basis of Annex C — were the least legible part of the only surface that shows
them. Verified on screen: both reviewers now read
`Daily Accomplishment → Issues and Concerns Encountered (SIPP) → Solutions
(SIPP) → Recommendations (SIPP) → Task Performed`.

- **SIPP fields carry a visible `(SIPP)` marker** and an amber label, so the
  Annex C trio reads as one block rather than three unrelated paragraphs.
- **A key the template does not mention is still rendered**, after the known
  ones, under its humanised key. Templates get edited, and an older entry can
  hold a section since renamed or removed — silently dropping a student's
  writing is the one outcome worth avoiding.
- **With no template on the payload it degrades to exactly the old behaviour**,
  so a legacy batch with `journal_template_id` null still renders every field.
  **That fallback was firing on demo data**: `CabmbCoordinatorCenteredDemoSeeder`
  created *BSBA-OM 2026 Field Placement* with **no template** while every other
  seeded batch had one — so the coordinator's Journal Review, the one review
  surface that cohort has, was the single place the fix did nothing. The seeder
  now assigns the CABM-B template (BSBA-OM is a CABM-B program, and the entries
  it seeds already use that template's keys).
- **`WeeklyJournalReviewModal.vue` is DEAD CODE** — nothing imports it;
  `SupervisorJournalsPage.vue` carries its own inline modal markup. It still
  holds a stale copy of the old `filledContent`/`fieldLabel` pair and was
  deliberately left untouched rather than half-updated. Delete it or revive it,
  but do not treat it as live.

**The per-intern notebook is shared at the FRONTEND too.**
`SupervisorInternJournalsPage.vue` is mounted on **two routes** —
`/supervisor/interns/:studentId/journals` and
`/coordinator/journal-review/interns/:studentId` — and derives its API prefix,
back-link and 403 wording from `route.path`, not from the signed-in user's role
(the route is what the guard already gated, so the two cannot disagree). Forking
it would be 679 lines of duplicate differing over six URLs.

## Intake & Enrollment

**Enrollment is student-driven, coordinator-gated intake, and the Student
Information Sheet is the gateway.** Account creation is always distinct from
enrollment.

1. **Coordinator creates the student login**
   (`EnrollmentController::createAccount`) with name, optional username,
   password, optional student ID, program (scoped to their department) and
   batch. The account is created **NOT-ENROLLED**; the intended placement is
   recorded as a **draft `student_information_sheets` row whose `batch_id` is
   the intended batch** — the single home for "intended batch before Accept".
   Username is **optional**; blank auto-generates, and the success toast echoes
   the final username back.
2. **The student is GATED** until their sheet is `approved`.
   `EnsureInfoSheetApproved` (alias `infosheet.approved`) wraps a gated student
   route subgroup; only `student/info-sheet` (show/store), its PDF, and
   `student/companies` stay ungated — every other student endpoint 403s.
   **`User::isInfoSheetGated()` is the single source of truth** (gated unless an
   approved sheet OR an active enrollment exists — the latter keeps legacy
   directly-enrolled students working), surfaced on `/api/user` as
   `student_gated` for the router guard and nav filtering.
3. **The student fills and submits the sheet.** `store()` does **not** require an
   active enrollment; it writes the scaffolded draft and re-derives the read-only
   Program/Coordinator from the intended batch (anti-spoof).
   - **Name of Company is the one constrained field** — a dropdown from
     `GET student/companies`, storing both `ojt_info.company_id` (which drives
     Accept) and `host_company` (the name).
   - **Year Level** is a constrained dropdown, values `1st-year` to `4th-year`.
     See the KNOWN ISSUE below — several seeders write `'4th Year'` into this
     field, which the rule rejects.
   - **`ojt_info.intern_duty_schedule` is FREE TEXT and nothing else**
     (`nullable|string|max:150`, one string). **A day/time dropdown builder was
     removed on 2026-09-08 at the project owner's request — do not reintroduce
     it.** It offered six fixed day ranges and half-hourly times behind a
     "Type it instead / Use dropdowns" toggle, and cost ~170 lines of
     `StudentInfoSheetPage.vue`: a composer, a parser that had to round-trip
     every partial the composer could emit, a suppression flag so prefilling
     could not write back over the value it had just read, a mode chosen on
     load, and a save button disabled whenever the picked range was impossible.
     Real placements are not on that grid — split shifts, rotating rosters,
     "Flexible, as arranged with supervisor" — so the honest answer often could
     not be selected and the toggle merely stood between the student and the
     text box they needed anyway. **The stored shape did not change**, so every
     value already saved still loads and prints as before; the input keeps
     `maxlength="150"` mirroring the Form Request, and the old format survives
     only as the placeholder. Verified end to end: a free-text schedule the
     builder could not express saves to the database and reloads intact, and an
     approved sheet stays `approved` through the edit.
   - **`personal_info.parent_guardian_name` is required to SUBMIT** but not to
     draft. `parent_guardian_contact` stays optional by explicit choice.

   **KNOWN ISSUE (found 2026-09-08, NOT yet fixed — pre-existing):** several
   seeders write **`'4th Year'`** into `student_information_sheets.academic_info
   .year_level`, but `StoreInfoSheetRequest` validates
   `in:1st-year,...,4th-year` and the SPA's `<option value>`s use the hyphenated
   form. The consequences are worse than cosmetic: the Year dropdown renders
   **blank** for those students (no option matches), and **every save of their
   sheet 422s** — `"The selected Year is invalid."` — on a field they never
   touched and cannot see a value in. Confirmed on `mdcstudent`, `mdcstudent2`
   and `mdcstudent3`; `mdcintake*`, `cabmb.*` and `mdcbalberostudent` hold the
   correct `'4th-year'` and save fine. Offenders: `StudentDemoUserSeeder`,
   `CabmbUsersDemoSeeder`, `CabmbWeeklyDemoSeeder`, `CoordinatorPagesDemoSeeder`,
   `GroupInfoSheetDemoSeeder`, and `StudentDemoEnrollmentSeeder` (which copies
   `student_profiles.year_level` straight into the sheet, propagating whatever
   the profile holds). **Note the two columns may legitimately differ** —
   `student_profiles.year_level` is not validated against that list — so the fix
   is to normalise on the way INTO `academic_info`, not to blanket-rename every
   occurrence. Fixing it requires a re-seed, so it was left for the project
   owner to schedule.
4. **The coordinator reviews** on Student Info Sheets — an Accept/Reject queue
   scoped by the sheet's **intended-batch program** (so not-yet-enrolled students
   appear). **Accept = enroll**: creates the active `batch_students` row for the
   sheet's batch + chosen company + that company's login supervisor, then marks
   the sheet `approved`, lifting the gate. Only a `submitted` sheet is
   acceptable (re-accept 422s, never double-enrolls). **Reject** sets `rejected`
   with a required reason shown to the student, who edits and resubmits. The UI
   labels `rejected` as **"Returned"**.
5. **Individual Info Sheet PDF** (`pdf.info-sheet` via the shared
   `BuildsInfoSheetPdf` trait) — MDC logo as a base64 data URI from
   `public/images/mdc-logo.png`, the two labeled sections, plus the
   **"Sketch of Internship Company Location"** box, which since 2026-08-28 is
   **90mm tall and filled with a map of the student's pinned company location**
   (see Company location map below). Downloadable by the student, the
   coordinator (in-scope), and the admin (no scope check — that page is
   explicitly all-departments); all three share the trait, so all three get the
   map.

### Company location map — the sketch box, filled in

Built 2026-08-28. The student drops a pin on a map; the server rasterises those
coordinates into the sketch box at download time. It replaces a blank square
nobody could fill in from inside the app.

**OpenStreetMap, NOT Google — and that is a constraint, not a preference.**
Google Maps Platform (the Static Maps API included) requires a **billing
account with a card on file** even to stay inside its free credit, and this
project's whole deployment story is the documented zero-cost / no-credit-card
constraint (see Deployment). OSM raster tiles need no key, no account and no
card. `config/staticmap.php` takes any `{z}/{x}/{y}` raster endpoint, so a
keyed or paid provider is one env var with no code change.

- **No migration, deliberately.** `student_information_sheets.ojt_info` is a
  JSON column, so the pin lives there as `location_lat` / `location_lng` /
  `location_zoom` / `location_label`. Hard Rule #3 is untouched.
- **`App\Services\StaticMapService`** stitches the tiles covering a viewport
  centred on the pin, draws the marker and burns in the attribution — sized
  off the canvas **WIDTH**, never its height, since both canvases it draws are
  the full width of what they sit in and vary only in height; on height the
  credit grew with the box and rendered as a watermark across the 90mm map.
  The credit itself is burned in because the tile licence requires it to stay
  with the image. **Every failure path returns null and the blade prints the
  blank box** — no tiles, a slow server, GD missing, a nonsense coordinate. It
  reaches the network from inside a PDF download, so a student must never be
  unable to fetch their own information sheet because somebody else's tile
  server was down. Pinned by
  `test_the_pdf_still_downloads_when_the_tile_server_is_down`.
- **A zero-tile render returns null rather than a grey rectangle.** An empty
  grey box reads as "this application is broken"; the blank box reads as "no
  location pinned", which is what the paper form has always meant.
- **Both the composed image AND the individual tiles are cached** on the `local`
  disk. Re-downloading an unchanged sheet touches the network zero times (cold
  ~4s, warm ~200ms, measured), and students placed at the same company pin
  within a few hundred metres of each other so the tiles are shared. The cache
  is ephemeral on Render by design — everything is re-derivable from the two
  numbers stored on the sheet.
- **The `User-Agent` in config is REQUIRED, not decorative.** OpenStreetMap's
  tile policy and Nominatim both block unidentified callers, and the symptom is
  a silently empty map rather than an error.
- **The box is 90mm tall (255.12pt), and FOUR numbers must move together** —
  `BuildsInfoSheetPdf::SKETCH_HEIGHT_PT`, the blade's two `90mm` rules,
  `StaticMapService::PREVIEW_HEIGHT`, and `CompanyLocationPicker.vue`'s
  `PRINT_ASPECT`/`PRINT_RATIO`. The image is rasterised to exactly that box at
  2x (~144dpi) and then stretched to fill it, so changing one alone distorts
  the map; the preview and the dialog's guide are drawn at the same ratio, so
  missing either makes them quietly stop being previews of the print.
  **It was 50mm until 2026-08-29.** At 90mm the whole sheet still fits on one
  page with about **42pt of slack** below the attribution line — re-measure
  after touching anything above the box, since it is now the tightest part of
  the document.
- **Nothing is verified against anything, and that is deliberate.** The DTR
  geofence is adversarial and is distance-checked server-side because a punch
  is a claim about attendance. This is *descriptive* — a map so a coordinator
  can find the place — so a radius check would add friction against a threat
  that does not exist here. The pin is also never required: an unpinned sheet
  submits normally.

**Frontend**: `components/infosheet/CompanyLocationPicker.vue`, the **LAST**
field of the info sheet's Internship Company Information section — below
Estimated Date to Finish Internship, not up under Company Address where it
first sat. It mirrors the printed sheet, where the sketch box is the final
element after every typed field, so the form and the PDF are read in the same
order; it also keeps the one control that opens a dialog and loads a map
library out of the middle of a run of plain text inputs.

**THE MAP LIVES BEHIND A "Set location" BUTTON, AND THAT IS THE OPTIMISATION —
do not inline it again.** Rendered inline (as it briefly was) it charged every
student who opened the info sheet a **~43kB gzip map library plus a dozen live
tile requests**, on a form where every other field is typed and most students
never touch the map. Measured in a real browser on the collapsed page:
`leafletLoaded: false, tileRequests: 0`. Leaflet, its stylesheet and every tile
are fetched on the **first** press of the button and never before, then cached
in the component so a second open costs nothing.

- **Collapsed, the section shows ONE cached PNG** from
  `student/location-preview` — 640x324, ~25kB, `Cache-Control: private,
  max-age=86400`. Because it is rendered at the printed box's own aspect it is
  a **true preview of the sheet**, not an approximation of one. The preview
  canvas size is fixed **server-side**: a caller-controlled width and height is
  a way to make the server fetch arbitrarily many tiles from a free service on
  demand. A pin whose tiles cannot be fetched **404s rather than returning a
  placeholder**, so the `<img>` fails and the form falls back to plain
  coordinates instead of presenting a grey rectangle as the location.
- **The dialog holds a DRAFT pin; nothing reaches the form until "Use this
  location".** Cancel therefore really cancels, which is what a dialog with a
  Cancel button has to mean. Inline, every drag wrote straight through.
- **`z-500` on the "Printed area" guide is load-bearing.** Leaflet gives its own
  panes `z-index: 400` in the *shared* stacking context (the map container is
  `position: relative` with `z-index: auto`), so an un-layered overlay is
  painted underneath the tiles and silently never appears — which is exactly
  what happened first time. 500 clears the panes and stays below Leaflet's
  controls at 800.
- **The guide's size is computed in JS (`sizeGuide()`), not left to CSS, and
  that is not a preference.** It has to fit the map on BOTH axes and still be
  exactly the printed ratio, and no width/`max-height` pair does that: once a
  max clamps one axis, `aspect-ratio` does **not** re-derive the other, so the
  guide silently stops matching the print. It never bit while the box was a
  3.56:1 strip; at the 90mm box's 1.98:1 it clamps on any short viewport. The
  existing `ResizeObserver` (already there for Leaflet's `invalidateSize`)
  drives it, so there is no second observer. Verified in a browser at two map
  heights: 720x468 → guide 662x335, and 720x260 → guide 411x208 — `ratio:
  1.978` in both, which is 504.57/255.12.
- The dialog uses the documented three-part flex shell (`shrink-0` header,
  `flex-1 overflow-y-auto` body, `shrink-0` footer).
- The stock Leaflet marker is replaced by a `divIcon` carrying **inline SVG**:
  Leaflet's own marker loads PNGs by a path relative to its stylesheet, which a
  bundler rewrites, and the classic symptom is a broken-image icon.
- **Dragging leads; "Use my location" follows.** The information sheet is the
  intake gateway, filled in BEFORE enrollment, so the student is almost always
  at home or on campus rather than at the company — defaulting to their current
  position would confidently pin the wrong building. The button reuses
  `currentPosition()` / `isPermissionDenied()` / `locationErrorMessage()` from
  `lib/dtr.ts` (the two-stage GPS→network fix and the in-app-browser WebView
  warning are already solved there); `locationErrorMessage` gained an optional
  `purpose` argument so its copy no longer says "clock in" on this page.
- **The tile URL and attribution come from the SERVER** (`student/location-options`),
  not hardcoded in the SPA, so the map the student pins on and the map that
  prints can never be two different maps.
- **It deliberately does NOT seed the pin from `company_geofences`.** Those are
  the coordinates a supervisor captured for QR clock-in, and handing every
  student the precise location of every company's fence would lower the cost of
  spoofing a punch — the one thing that scheme's honesty rests on. The picker
  opens on the college instead.
- The tile layer sets **`updateWhenIdle: true` and `keepBuffer: 1`** (Leaflet's
  default is 2) — both purely to be a good citizen of a free tile server we do
  not own.
- **Address search** (`student/location-search`, `throttle:20,1`) proxies
  Nominatim. Its policy caps callers near 1 req/sec and forbids
  autocomplete-as-you-type, so the UI searches only on an explicit submit, the
  route is throttled, and answers are cached for a day — but a **failed lookup
  is never cached**, and comes back as `unavailable: true` rather than "no
  results", because the two need different advice.

All three routes are in the **ungated** student group, for the same reason the
sheet itself is: a student filling in the gateway has not cleared it yet.

#### Server-side rendering cost

- **Tiles are fetched `FETCH_CONCURRENCY = 6` at a time via `Http::pool()`.**
  Six is not a "make it faster" number — it is exactly what a browser opens per
  host, so a map this page draws costs the tile server no more than the same
  map drawn in Leaflet would. Measured cold render of a 1009x283 viewport (the
  box's size before it grew to 90mm): **6.7s sequential → 1.5s pooled**; warm
  (composed cache hit) **27ms**. The 90mm box is 1009x510 and needs about half
  again as many tiles, still far under `MAX_TILES = 40`.
- **The canvas is quantised to a flat 256-colour palette before encoding.**
  Street-map tiles are flat artwork from a small palette, so at print size this
  is indistinguishable from truecolour while cutting the PNG to about a third
  (134kB → 43kB measured). Dithering is deliberately off: it costs most of the
  saving and speckles the tiles' own label text.
- **Do not expect `imagepng($canvas, null, 9)` to reach the PDF.** dompdf's
  `Cpdf::addImagePng()` loads the image into GD and **re-encodes it with
  `imagepng()` at the default compression** before embedding, so the
  compression level chosen here only affects the cached file and the preview
  endpoint. The palette still survives that round trip (a pinned sheet went
  262kB → 237kB), and the preview — served straight to the browser with no
  dompdf in the path — is where the saving lands in full.

`phpunit.xml` sets **`STATIC_MAP_ENABLED=false`** so no test can silently depend
on the network; the map tests switch it back on with `Http::fake()`. Coverage:
`tests/Unit/Services/StaticMapServiceTest.php` (the Web Mercator projection
cross-checked against the OSM wiki's own `log(tan + sec)` formula, the caching,
the User-Agent, and the graceful null) and
`tests/Feature/Student/InfoSheetLocationTest.php`.

**Bulk-importing students (Excel/CSV) is an alternative entry to step 1
above, not a different flow.** `Coordinator/BulkStudentImportController`
(`preview`/`confirm`, routes under `coordinator/accounts/bulk-import/*`)
creates the exact same shape of account + draft info sheet `createAccount`
does, just for many rows from one uploaded spreadsheet instead of one
coordinator-typed form. Columns: First Name, Middle Name (optional), Family
Name, Sex, Student ID Number, Email — Program and Batch are picked once on
the upload form, not per row (kept out of free-typed cells on purpose, same
reasoning as everywhere else foreign keys meet user input). Row
parsing/validation lives in `App\Services\StudentBulkImportService`, shared
by both `preview` and `confirm` so the two can never disagree — **`confirm`
re-uploads and re-parses the same file from scratch rather than trusting a
client-supplied "these rows are valid" list**, since another import could
have consumed an ID number between the two calls. Duplicate ID numbers/
emails **within the same file** are flagged (a DB-uniqueness check alone
can't catch that, since neither row exists yet). Capped at
`StudentBulkImportService::MAX_ROWS` (100) rows per file, rejected upfront —
there is no queue worker in this deployment, so the whole request runs
synchronously and needs a bound on worst-case duration.

Two things distinguish a bulk-imported account from a manually-created one:

- **`username` is always the Student ID Number**, and the temporary password
  is a genuinely random `Str::password(12)` — a deliberate security choice.
  The pre-existing manual Create Student Account form
  (`CoordinatorInternsPage.vue`'s `derivedPassword`) still suggests
  `{first 3 letters of first name}_{student ID number}`, which is
  predictable from public-ish information. Left as-is for now since changing
  it wasn't part of this feature's scope, but it's the same "first 3
  letters" pattern this feature was deliberately built to move away from —
  worth revisiting for consistency.
- **The student is emailed their credentials immediately**
  (`App\Notifications\NewAccountCredentials`: username, temp password, and a
  link to the SPA login page), which is a deliberate, scoped exception to
  the "only mail a Google-verified address" rule
  (`SendMissingJournalEntryReminders`'s `$canEmail` gate) — the coordinator's
  uploaded roster is trusted directly, since there is no verified address to
  wait for at creation time. Consequence: a bulk-imported student's
  `email_verified_at` stays null like any coordinator-entered email, so they
  will **not** receive missing-journal-entry reminder emails until they
  separately verify that address via Google — matches existing behavior
  everywhere else, not a bug.

Each row's outcome in the `confirm` response is one of `created_and_emailed`
/ `created_email_failed` / `skipped_invalid`. **ONE ROW IS ONE TRANSACTION,
and there is deliberately no transaction spanning the file** —
`createOne()` wraps the user + profile + draft-info-sheet writes in
`DB::transaction()` while the per-row `catch` in `confirm()` keeps a failure
from rolling back rows already created before it. Before that wrapper existed,
a throw after `User::create` (the profile update, `scaffoldIntendedSheet`)
left an account behind with **no draft info sheet**, i.e. no intended batch to
be Accepted into, while the response said "Could not be created — please retry
this row in a new upload" and the retry then failed validation with "already in
use". The advice could not be followed and the student was stranded.
**`$user->notify()` stays OUTSIDE the transaction** — a dead SMTP credential
must never roll back an otherwise complete account. The response also carries a
one-time credentials table (ID number, email, temp password) that the
frontend renders and offers as a client-side CSV download — never persisted
server-side, and never run through `useFormDraft`/sessionStorage, matching
the project's existing "never persist a credential" rule
(`lib/formDraft.ts`).

**THE ROSTER IS READ FROM ONE WORKSHEET, and `StudentBulkImport::collection()`
is what picks it.** `Reader::loadSpreadsheet()` does
`array_fill(0, getSheetCount(), $import)` for any import that is not
`WithMultipleSheets` — it hands EVERY worksheet to the same object, so
`collection()` fires once per sheet. Assigning `$this->rows` unconditionally
therefore let the **last** sheet overwrite the roster, and a workbook carrying
an "Instructions" tab — **or merely the empty trailing "Sheet2" that Excel and
LibreOffice add by default** — imported ZERO rows and reported it as a
successful preview of an empty file, with no error anywhere. The rule now is:
the first sheet carrying a `student_id_number` heading wins outright; failing
that, the first non-empty sheet is held provisionally and a later real roster
replaces it; a blank sheet never wins.

**Do NOT "simplify" this by implementing `WithMultipleSheets` and returning
`[0 => $this]`.** An import that returns itself from `sheets()` sends
`ColumnCollection::requiresStyleInformation()` into unbounded recursion
(it walks `sheets()` looking for `WithColumns`), which exhausts memory before a
single row is read. Verified, not theorised.

This escaped review for the same reason the cache-object bug did: **every test
in `BulkStudentImportTest` uploads CSV, and a CSV has no worksheets** for the
overwrite to happen across. `tests/Feature/Coordinator/BulkStudentImportFileFormatTest.php`
exists to cover the file as a whole and writes **real `.xlsx` files** via
PhpSpreadsheet — keep it, and keep at least one genuine `.xlsx` case in it.

Three other file-level failures now return a **422 naming the problem** rather
than a wall of identical row errors or a 500, all raised as
`App\Exceptions\BulkImportFileException` from the service and caught in the
controller:

- **An unreadable file** (truncated download, damaged or password-protected
  workbook) used to escape as a raw PhpSpreadsheet reader exception. The
  `mimes` rule catches the easy shapes first, but not the ones that matter —
  a protected `.xlsx` sniffs as a perfectly good `.xlsx` and throws on read.
- **A missing or renamed heading** ("E-mail" instead of "Email") used to
  surface as the SAME row-level error on every row, with nothing pointing at
  row 1 where the actual mistake is. Named once instead.
- **A file with no data rows** (headings only, or the roster left on a sheet
  we did not choose) says so, instead of rendering "0 ready to create".

Two row-level rules also changed: **Sex accepts `M`/`F`** alongside the full
words (a registrar export routinely abbreviates it, and rejecting it meant
hand-editing every row), and the **already-in-use email check is now
`LOWER(email)`**, matching the in-file duplicate check — a plain `where()` is
case-sensitive under SQLite, so an address differing only in case slipped past
validation and died on the unique index, surfacing as the generic "Could not be
created" rather than naming the clash.

`confirm()`'s `set_time_limit()` is **sized from the row count**
(`BASE_SECONDS + rows × SECONDS_PER_ROW`), not a flat 120s. A full 100-row file
sends 100 messages inline (`QUEUE_CONNECTION=sync`, no worker), which at Gmail's
pace passes two minutes — and PHP then killed the request MID-LOOP, leaving the
accounts created but returning no response, so **the one-time credentials table
was lost for every student in the file** and each needed reissuing one at a time
(then the per-row Resend; today the Credential Manager).
This governs PHP only; a reverse proxy keeps its own timeout.

**"A student never got their welcome email" now has THREE answers, and the
student can reach the first one themselves.** In order of who has to act:

1. **The student resets their own password** — "Forgot password?" on the login
   page. See Password Reset below. This is the only one that needs nobody else,
   and until it was built there was no such path at all.
2. **The coordinator issues a temporary password** from the **Credential
   Manager** in their profile popover (below).
3. **The admin relays a password by hand** — `issueTemporaryPassword`, on the
   System Settings student-search panel. Still present and untouched, but **it
   is no longer an escalation the coordinator depends on**: since 2026-09-08
   answer 2 always surfaces the password too, so a coordinator meeting the
   case that used to strand them (SMTP reports success, the mail never lands)
   can now read the password out themselves.

Worth knowing: a student created through the **manual** `createAccount` flow can
have `email = null`, and then answer 1 is impossible (there is no address to
reset against) — but answer 2 works, since the Credential Manager shows the
password on screen rather than only mailing it. Bulk-imported students always
have an address, since Email is a required column.

### Credential Manager — reissuing a password, moved off the Users page

Built 2026-09-08 at the project owner's request. `Coordinator\CredentialManagerController`
(`index`, `issue`; routes `coordinator/credentials*`), panel
`components/profile/panels/CredentialManagerPanel.vue`, reached from the
**profile popover** as **"Credential Manager"** — a coordinator-only entry,
gated on `auth.user.role === 'coordinator'` exactly as Reminder Settings is
gated on `student`.

**It REPLACES the per-row "Resend" button on Users → Interns**, which is gone
along with `EnrollmentController::resendCredentials` and its route. Two reasons,
and the second is why the move was worth making rather than just relocating a
button:

- **Reissuing a password is critical and irreversible** — the account's current
  password stops working the instant it fires — and it sat as the middle button
  of a row whose other two actions were "View" and "Delete". It is also not
  something done while browsing a roster; it is what a coordinator does for one
  named person who has said they cannot sign in, so a searched-for destination
  matches the moment it is used.
- **It now covers SUPERVISORS as well as interns.** A button on the Interns tab
  structurally could not, and a company supervisor login — shared by a whole
  company — is locked out exactly as easily. Both populations the coordinator
  provisions are now reachable from one surface.

**THE PASSWORD IS ALWAYS RETURNED, and that is the substantive behaviour
change.** Resend only mailed it, so the documented failure — SMTP reports
success but the mail never lands — left the coordinator told "Credentials
resent" with nothing to read out, and the one action that surfaced a password
was admin-only. `issue()` therefore returns `temporary_password` unconditionally
and reports `emailed` as **`true` / `false` / `null`**: sent, delivery failed,
or **no address on file** — three different facts needing three different things
said to the coordinator. An account with `email = null` is fully supported,
where Resend simply 422'd it.

- **Scope**: interns by PROGRAM (`coordinatorProgramIds()`), supervisors by
  COMPANY. Those two rules already existed as **two byte-identical private
  `scopedCompanyIds()` methods** in `EnrollmentController` and
  `CoordinatorCompanyController`, one of whose docblocks said it mirrored the
  other; a third copy is how they would finally drift, so they were extracted to
  **`App\Http\Controllers\Concerns\ScopesCoordinatorAccounts`** and all three
  controllers now use it. Same reasoning as `EnrollmentService` and
  `ReviewsWeeklyJournals`.
- **`authorizeManagedAccount()` 404s a coordinator or admin account** rather
  than 403ing it: a coordinator provisions students and supervisors only, so a
  peer's account is not merely out of scope, it is not a kind of account this
  surface manages at all.
- **`index` is capped at `MAX_ROWS = 40`** and reports `total` — this renders
  inside the popover, not on a page, so it is a search box rather than a roster;
  a department running to hundreds of accounts says how many are not shown
  instead of truncating silently. Search matches name / username / email /
  student ID, debounced 300ms; the role chips fire immediately.
- **The issued password is held in a plain `ref` and NEVER persisted** — no
  `useFormDraft`, no `sessionStorage` — matching the bulk import's one-time
  credentials table and `lib/formDraft.ts`'s standing rule. It takes over the
  panel until dismissed rather than appearing in a toast that scrolls away with
  the one thing the coordinator came for, and the copy says plainly that it
  cannot be retrieved afterwards.
- The confirm is `tone: 'danger'` and names the consequence, per the app-wide
  crucial-actions rule.

`Admin\UserController::resendCredentials` and `issueTemporaryPassword` are
**untouched** — the System Settings panel keeps both.

Coverage: `tests/Feature/Coordinator/CredentialManagerTest.php`, which pins the
two rules that were NOT true of Resend —
`test_a_coordinator_issues_a_temporary_password_to_a_supervisor` and
`test_an_account_with_no_email_still_gets_a_password_to_read_out` — plus both
scope 403s, the 404 on a peer coordinator, and the role/search filters. The two
old resend tests were removed from `BulkStudentImportTest` and their assertions
carried over here.

MOBILE NOTE (Phase 7): a bulk-imported or credentials-resent account has
`must_change_password = true` on first login, exactly like every other
coordinator-provisioned account. The mobile app's future auth flow needs to
force a password change the same way the web popover does (no dismissal, no
back navigation) rather than treating it as an edge case.

**An approved sheet is NOT permanently read-only.** A fresh gate submission
cannot replace it, but partial post-enrollment editing is allowed: **Program &
Year and the assigned Company stay locked** (re-derived server-side, ignoring
whatever the client sent, since they drove Accept), while personal info, company
address, signatory, supervisor, schedule and dates stay freely editable. Saving
an already-`approved` sheet **never** moves it back to `draft`/`submitted` or
clears `rejection_reason` — `submission_status` stays pinned to `approved`, so
routine edits cannot re-gate an enrolled student.

**Guarded permanent account delete** (`EnrollmentController::destroyAccount`) is
the one exception to soft-deactivation: it 422s any account carrying OJT history
(`journal_entries`/`weekly_logs`/`weekly_activity_logs`) and only erases truly
empty accounts. It can never destroy SIPP records.

### Permanently deleting a supervisor account (2026-09-11)

Built at the project owner's request — the Users → Supervisors tab had no row
actions at all (no View, no Delete) until now; only `detachSupervisor` existed
(Partner Companies), and that only removes a company's login attachment, not
the account. `EnrollmentController::showSupervisor`/`destroySupervisorAccount`
(routes `coordinator/users/supervisors/{supervisor}`, GET and DELETE) fill both
gaps, mirroring `showIntern`/`destroyAccount`'s shape but not their guard.

**Why a supervisor delete needed its own guard, not the student one reused:**
`batch_students.supervisor_id` is a **`cascadeOnDelete`** foreign key — it is
the authoritative student-to-company/supervisor linkage (see Enrollment above).
Deleting a supervisor who was ever pinned to an enrollment, active or
historical, would silently **delete those `batch_students` rows along with
them** — not merely the supervisor's own login. This is a much larger blast
radius than the student case (where `journal_entries`/`weekly_logs` are keyed
by `student_id`+`batch_id`, not by the row being deleted) and is the actual
reason this feature did not already exist: building it safely meant tracing
every FK a `users` row carries first.

`destroySupervisorAccount()` therefore blocks (422) on either:

- **Any `batch_students` row, of any status, ever pinned to them** — the
  cascade-risk case above.
- **Any `weekly_logs` row they reviewed** (`weekly_logs.supervisor_id` is only
  `nullOnDelete`, so the review itself survives, but deleting them would strip
  off WHO gave the verdict).

**Deliberately NOT gated on still being attached to a company.**
`company_supervisors.user_id` is `cascadeOnDelete` too, but carries no history
behind it — just "who is currently logged in as this company" — so losing that
pointer on delete is exactly what a manual detach already does on purpose.
Requiring a detach-first step would add friction with nothing to show for it,
and would have made the account briefly **unreachable**: the Supervisors list
(`supervisors()`) is built entirely from `company_supervisors` rows in scope,
so a supervisor detached from every company vanishes from it. Both
`showSupervisor` and `destroySupervisorAccount` are scoped by
**`attachableSupervisorIds()`** (already defined on `ScopesCoordinatorAccounts`
for the "Attach Existing Supervisor" dropdown), not `scopedSupervisorIds()`,
specifically so a detached "floating" supervisor stays viewable and deletable
rather than 403ing the very account the action exists to reach.

**Frontend**: the Supervisors tab table (and its mobile card list) gained an
Actions column identical in shape to the Interns tab's — a bordered outline
**View** button and a red outline **Delete** button. View opens
`SupervisorDetailModal.vue` (mirrors `InternDetailModal.vue`), showing the
supervisor's companies (with position) and, new information the row itself
doesn't show, the actual roster of interns currently or previously assigned to
them (`SupervisorDetail`'s `interns[]`). Delete reuses the exact same
`DangerCountdownModal` the intern delete already used — a 7-second hold before
"Delete permanently" unlocks, `tone: danger`, Cancel always active — rather
than a second confirmation pattern; a blocked (422) delete surfaces the guard's
reason as a normal error toast, same as every other guarded action in the app.

Coverage: `tests/Feature/Coordinator/CoordinatorUsersTest.php` — the two 422
guards (assigned-to-an-enrollment, reviewed-a-weekly-log), a clean delete that
also cascades the `company_supervisors` attachment away, both 403/404 scope
checks, and the floating-supervisor case (detached, no history) staying
reachable through both endpoints.

**Graceful "enrollment inactive" state**: `User::isEnrollmentPaused()` (a student
past intake with no `active`/`completed` row) surfaces as `student_paused` on
`/api/user`. The router bounces them to a read-only `/student/paused` page and
`StudentLayout` collapses the nav. Distinct from `student_gated` (still in
intake). Re-enrolling clears it automatically.

### Batch roster: archive before delete

`batch_students.archived_at` is a plain nullable flag — deliberately **not**
Laravel `SoftDeletes` and with no global scope, so every existing
`BatchStudent::where(...)` query keeps returning archived rows and `status` is
untouched by archiving.

- `archive()` is allowed on a `dropped` or `completed` row (never `active`, never
  twice); `restore()` clears the flag and leaves `status` exactly as it was.
- **Permanent delete requires archiving first** — `destroy()` guards on
  `archived_at === null`.
- **`reactivate()`/`reopen()` also guard on `archived_at`**, forcing an explicit
  `restore()` first. Without this, calling either on an archived row produces an
  active-but-archived row that `destroy()` would then allow through and the purge
  would eventually hard-delete out from under an active enrollment.
- `archived_at` is deliberately **excluded from `#[Fillable(...)]`** (matching
  `completed_at`), so both actions set it by direct property assignment +
  `save()`, never `->update([...])`, which would silently no-op.
- A nightly **30-day auto-purge**
  (`BatchStudentPurgeService::purgeExpiredArchives()`) hard-deletes stale
  archives. No table has a foreign key to `batch_students.id` (journals and
  weekly logs key off `student_id`+`batch_id`), so the purge cannot orphan
  history. Return shape is `array{purged, protected, cutoff}`.
- **The purge protects a `completed` row that is a student's sole gate-clearing
  signal** when they have no approved info sheet — otherwise purging it would
  re-gate an already-graduated legacy student. Re-evaluated fresh each run, so
  the protection lifts automatically once no longer needed.
- **`HteReportController::buildRows()` renders any saved curated row whose source
  enrollment was purged** from its last-saved snapshot, so curation survives a
  purge instead of silently vanishing.

## Journals

### Daily journal entries

- **The length limit is character-based and fixed at 1500**
  (`journal_templates.char_limit`), validated with `mb_strlen` against the whole
  entry's combined content. **It is no longer coordinator-authored** —
  `ValidatesJournalTemplate::prepareForValidation()` force-merges the fixed value
  before validation, so a tampered or omitted value is silently ignored rather
  than 422ing. Per-SIPP-field cap is **300** characters.
- **A daily entry locks only once its week has been SUBMITTED TO THE
  SUPERVISOR — not on submit of the entry, and NOT when the week is compiled.**
  `store()` 422s only once `isWeekUnderReview()` finds a `WeeklyLog` for that
  date's Mon-Sun week that is `submitted_at IS NOT NULL` **and** still
  `pending`/`approved`. Until then a student can freely resave any date inside
  their OJT range, submitted or not, however late.
  **CHANGED 2026-08-27 — the old rule was a real defect, not a preference.**
  The lock used to fire on the mere EXISTENCE of a `WeeklyLog`, and
  `WeeklyBundlingService` stamps one every Monday for **every active student**.
  So a student who filed Friday's entry on Monday morning — or who came back to
  catch up on a fortnight — found the week permanently frozen with no way to
  write it, and no route to unfreeze it. Compilation is now reversible (the
  student can recompile the week themselves, see Manual bundling below), so it
  no longer freezes anything; a supervisor's review is not reversible by the
  student, which is why the line sits there instead.
- **The lock lifts when a supervisor RETURNS the week** (`status = 'returned'`),
  matching the weekly narrative's own rule. That is what makes "fix the daily
  entry, recompile, resubmit" a real revision path rather than a rewrite by
  hand.
- `isEditableDate()` is a thin wrapper over `lockedReason()`, which returns
  `'not_active'` | `'range'` | `'week_submitted'` | `null`; `show()` exposes it
  as `locked_reason` so the UI can show the right banner. One message per token
  lives in `JournalEntryController::LOCK_MESSAGES`, shared by `show()`'s banner
  and `store()`'s rejection so the two can never explain the same lock
  differently. **The old `'bundled'` token is gone** — the frontend type and
  `StudentWriteJournalPage.vue`'s banner were updated with it.
- **`journal_entries.status` only ever holds `draft` or `submitted` in storage.**
  `missing`/`overdue` are **derived on read** (absence of a submitted entry on a
  working day), never queried against the column. Querying the column for them
  silently returns zero.

### Journal templates

- **Many-programs-per-template**: `journal_templates.program_id` was dropped for
  the `journal_template_program` pivot with **`UNIQUE(program_id)`** — a program
  belongs to **at most one** template, ever. Create/update take `program_ids[]`;
  a program already claimed by a different template 422s naming the conflict, so
  the unique index never surfaces as a 500. `index()` returns each in-scope
  program with a nullable `assigned_template_id` so the UI can grey out
  already-covered programs.
- A batch resolves its template via **`batches.journal_template_id`**.
- **A fixed "Daily Accomplishment" section is non-removable**:
  `key: 'daily_accomplishment'`, `required: true`, `sipp: false`. It is the
  guaranteed source Weekly Bundling compiles from across every department.
  Enforcement is in `ValidatesJournalTemplate::prepareForValidation()`, which
  strips any coordinator-submitted entry under that key and prepends the
  canonical definition **before** validation — so omitting or tampering with it
  never 422s, it is silently neutralized. This also unconditionally satisfies
  "at least one section must be required".
- **SIPP (Annex C) is a fixed trio** keyed `issues_concerns` / `solutions` /
  `recommendations`. A section may only be flagged `sipp=true` if its key is one
  of those three (server-guarded). The UI authors it as **one checkbox** that
  adds/removes the trio together — there is no per-section SIPP checkbox.
- The editor never shows the raw section `key`; it is auto-generated from the
  `label`.
- SEEDER CAVEAT: seeders create `JournalTemplate`/`JournalEntry` rows directly
  via Eloquent, bypassing this FormRequest-level enforcement. Seeders writing
  **submitted** entries key content by `daily_accomplishment` so Weekly Bundling
  has real text to compile against demo data.

### Weekly Bundling

`WeeklyBundlingService::bundleWeek()` auto-compiles a student's **Mon-Sun**
`journal_entries.content['daily_accomplishment']` (only from **submitted**
entries) into `"MONDAY\n<text>\n\nTUESDAY\n<text>"`-shaped
`weekly_logs.narrative` — no time range, ever.

- Days with no entry are **silently skipped, with no placeholder** — which is
  what makes weekend support free: a `SATURDAY` block appears only for a student
  who actually submitted one, so a Mon-Fri intern's narrative is byte-identical
  to before.
- It touches every student with an **active** row, and **never overwrites an
  already-submitted `WeeklyLog`**. An unsubmitted draft is freely recompiled on
  every run — there is no way to distinguish a student's manual edit from an
  auto-fill.
- **Runs every MONDAY at 00:00**, for the full Mon-Sun week that just ended.
  It used to run Saturday and that was a real bug, not a preference — back when
  stamping a `WeeklyLog` was a one-way edit lock on every daily entry in the
  week, a Saturday run locked the week BEFORE a Saturday shift began. (That lock
  is gone as of 2026-08-27 — see Daily journal entries above — but the Monday
  schedule is still correct on its own terms: a week is not compilable until it
  has ended.)
- `mostRecentlyCompletedWeekStart()` is unconditionally
  `today()->startOfWeek(Monday)->subWeek()` — a week is complete only once its
  **Sunday** has passed.
- Laravel 13 has no `app/Console/Kernel.php`; scheduling lives in
  `routes/console.php` via `Schedule::command()`.

#### Manual bundling — the student can compile their own week

Added 2026-08-27. `POST student/weekly-logs/{weekStart}/bundle`
(`WeeklyLogController::bundle`), surfaced as **"Compile from Daily Entries"** on
each editable week of `StudentWeeklyJournalsPage.vue`.

Bundling used to be something that only happened TO a student, once, overnight.
Anything written after that run — a Friday entry filed on Monday, a fortnight of
catching up — could never reach the narrative, and there was no way to ask for
it. This is the same compiler, triggered by the person whose work it is.

- **ONE WRITER for both paths.** `bundleWeek()` (the schedule) and
  `bundleForStudent()` (the button) both go through the private
  `compileFor()`, the `EnrollmentService` pattern again — a student pressing the
  button and the job running overnight must never produce two different
  narratives from the same daily entries.
- **The two callers differ on exactly ONE point, via `$recompileReturned`.** A
  `returned` log is back in the student's hands, so their own explicit
  recompile picks up whatever they have since corrected; the **scheduled job
  leaves it alone**, because it runs unattended and would otherwise silently
  replace a revision the student typed after a return. Pinned by
  `test_the_scheduled_job_leaves_a_returned_log_alone`.
- Guards, in order: active enrollment → the week has actually started (the
  CURRENT week is allowed on purpose — compile what you have so far) → the week
  is not entirely before the batch start → at least one **submitted** daily
  entry exists in it (else 422 naming that, rather than writing an empty
  narrative) → the log is not already with the supervisor.
- It **overwrites** the narrative box, which is what it is for — so the button
  confirms first, and the confirm switches to `tone: 'danger'` when there is
  already text to lose.
- `show()` returns **`submitted_entries_count`** so the page can say what the
  button will draw from and disable it at zero, rather than offering a button
  that 422s.

### Weekly logs and supervisor review

- **`submitted_at`, not `status`, distinguishes "still drafting" from
  "submitted"** — `weekly_logs.status` defaults to `'pending'` at the DB level
  even for a never-submitted draft.
- `submit` 422s if no narrative is saved yet, and 422s on a double-submit while
  `submitted_at` is set and status is `pending`/`approved`. It is **allowed again
  once a supervisor returns it** (`status = 'returned'`); `supervisor_comment` is
  left untouched by resubmission.
- `store()` (the student's autosave) mirrors the same lock: rejected once
  submitted and pending/approved, but editable while `returned`. That is what
  makes return-with-comment meaningful.
- Supervisor actions: `approve` and `returnLog` (which requires a
  `supervisor_comment`); both stamp `supervisor_id` + `reviewed_at`. Only
  **submitted** logs still `pending`/`returned` are reviewable — drafts and
  finalized logs 422.
- **The weekly narrative has its own fixed 5000-character limit**
  (`StoreWeeklyLogRequest::CHAR_LIMIT`), deliberately larger than the daily 1500
  since it is a compiled summary of a full week. The auto-bundling path writes
  via Eloquent and bypasses the FormRequest, so an auto-fill may exceed it; only
  a student's manual save is capped.
- **Coordinators get a read-only weekly-journal surface**
  (`CoordinatorWeeklyJournalController`), department-scoped, excluding
  never-submitted drafts. **No approve/return/edit for coordinators anywhere** —
  review verdicts belong to supervisors.
- DTR/QR/geofence clock-in is a **separate feature that IS now built** — see
  Daily Time Record below. It is unrelated to weekly-log review: a supervisor
  approves narratives here and corrects time records there.


#### The per-intern journal notebook (added 2026-08-28)

`SupervisorJournalController::notebook` (`GET supervisor/interns/{student}/journals`),
page `SupervisorInternJournalsPage.vue` at
`/supervisor/interns/:studentId/journals`. Reached from the **"Journals"**
action on each row of My Interns, and from an **"Open full notebook"** link in
the review modal's header.

**This replaced a button that lied.** The row action used to read "Review
Journals" and did `router.push('/supervisor/journals')` — the cross-intern
queue, unfiltered, defaulting to Pending. Clicking it on a specific intern
neither scoped to that intern nor said it had not, so a supervisor wanting to
read one student's work had no surface that showed it, and the only way to see
an approved week again was to switch the queue's tab and hunt for the name.

The notebook is the opposite cut of the same data: **one intern, every week
they have handed in, oldest first**, with the week's document, its daily
entries, and — because the supervisor is already looking at it — the same
Approve / Return actions the queue offers. It calls the **existing**
`journals/{weeklyLog}` show/approve/return/pdf endpoints; nothing about
reviewing changed.

- **Never-submitted drafts are excluded**, matching the queue and
  `CoordinatorWeeklyJournalController`. `WeeklyBundlingService` stamps a draft
  every Monday for every active student, so including them would show a
  supervisor work the intern has not handed in.
- **`week_number` is counted over ALL of that student's logs, drafts included**,
  so it is the same number `pdf.weekly-log` prints. A gap in the visible list
  (Week 1, then Week 3) is therefore honest rather than a bug: Week 2 exists and
  has not been submitted. `totals.drafts_hidden` says so in words under the
  index.
- The week detail is **lazy-loaded per week and cached client-side**, so paging
  back and forth never re-requests a week already read. A verdict busts that
  week's cache entry and reloads the list, since the tallies move with it.
- **The tally pills double as the index filter** (click "3 pending" to narrow,
  click again to clear) and are `disabled` at zero — a filter that can only
  produce an empty list is not worth offering.
- **Daily entries are EXPANDED by default, matching the review modal.**
  **REVERSED 2026-09-08 (project owner) — they used to be collapsed here**, on
  the argument that this page pages through a whole placement and five expanded
  entries per week buries the next week's document under a screen and a half of
  scroll. That cost is real, but the consequence was worse: the weekly document
  above is compiled from `daily_accomplishment` ALONE, so with the section shut
  a reviewer saw only that one field and had **no sign the student's SIPP
  answers existed at all**. The toggle stays and the choice still persists
  across weeks, so the compact view is one click away for anyone who wants it.
- Below `lg` the week index becomes a horizontally scrolling chip row (its own
  container's scroll, so the page never scrolls sideways — verified at 390px,
  `documentElement.scrollWidth === clientWidth`).

Coverage: `tests/Feature/Supervisor/SupervisorInternNotebookTest.php` — the
draft exclusion and the PDF-matching week numbering above all, plus the
own-interns-only 403, a 404 on a non-student account, and the empty notebook.

### Journal calendar

`JournalCalendarController::statusFor()` resolves in this order: submitted →
draft → **then** the working-day check → `missing`/`draft`. The order matters:
an entry always wins whatever day it falls on, so a genuinely-submitted Saturday
shows as `submitted`, while an empty weekend returns `no_entry` and can **never**
be `missing`. Nobody is penalised for not working a weekend; you can only ever
add work, never excuse an absence.


### Weekly Activity Log and Time Log Summary

The official MDC paper form, reinstated as a student surface on 2026-08-18.
Reference: `docs/reference/Weekly-Activity-Log-and-Time-Log-Summary-Guide.pdf`.

**This is a UI restoration, not a new feature.** The backend
(`weekly_activity_logs` / `weekly_activity_entries`, `WeeklyActivityLogController`
with 9 routes, 5 Form Requests, and the `pdf.weekly-activity-log` blade) had been
deliberately kept when the old inline section was removed from
`StudentWeeklyJournalsPage.vue`; only the Vue markup had gone. Nothing was
migrated and no route was added.

- **Page** `web/src/pages/student/StudentWeeklyTimeLogPage.vue`, route
  `/student/weekly-time-log`, nav label **"Weekly and Time Log Summary"**
  (`clock` icon, added to `StudentLayout`'s icon set). It sits in the **gated**
  student route group, so it needs an approved info sheet like every other
  student page.
- **One sheet per Period Covered**, each holding N activity rows. The five table
  columns map 1:1 to the paper form: Inclusive Dates, Activities,
  Document/Records, Objective/s, and Supervisor's name/position/Signature.
- **The table IS the input surface — students type straight into the grid**, one
  bordered cell per form column, rather than filling a separate form below it. A
  blank row always sits at the bottom so the template is immediately typeable.
  This is a manual-entry template: **nothing is auto-extracted from daily
  journal entries.**
- **Everything auto-saves** (800ms debounce per row and for the Form Details
  pair), so there is **no Save button anywhere** — the only row action is
  **Delete**. A page-level pill reports Saving / All changes saved / error, and
  each row carries its own small status.
  - **A draft row is POSTed as soon as ANY cell has something in it**, and
    `isRowCreatable()` is now simply "not untouched".
    **CHANGED 2026-08-27 — this was a real data-loss bug.** A row could only be
    created once BOTH dates AND the Activities text were present, because those
    three columns were NOT NULL, so a half-filled row lived only in the browser
    tab and was thrown away on logout with nothing on screen admitting it would
    be. `2026_08_27_000001_make_weekly_activity_entry_fields_nullable` relaxes
    `inclusive_date_start` / `inclusive_date_end` / `activities`, and the guard
    **moved up a layer rather than disappearing**:
    `StoreWeeklyActivityEntryRequest` has a `withValidator` "at least one of
    `CONTENT_FIELDS` is non-empty" rule, so the blank template row at the bottom
    of the grid still never becomes a database row on its own.
    Once created, the row keeps its id and subsequent edits go out as PUTs.
  - **`after_or_equal:inclusive_date_start` is applied only when a start date
    was actually sent.** With the field nullable, the rule's fallback — comparing
    against `Carbon::parse(null)`, i.e. **now** — would silently reject every
    past end date on a row whose start cell has not been typed yet. Both entry
    requests build that rule conditionally; pinned by
    `test_an_end_date_before_its_start_date_is_refused_but_a_lone_past_end_date_is_not`.
  - **Empty cells are sent as `null`, never `''`.** A bare `''` fails the `date`
    rule and would reject the whole row over a cell the student has not reached.
  - **A flush that lands while a row is already saving reschedules instead of
    firing**, so a fast typist cannot race two POSTs and duplicate a row.
  - **Saving never re-fetches the sheet.** Reloading mid-typing would blow away
    focus and cursor position; rows are updated in place instead.
  - `onBeforeUnmount` flushes anything still inside its debounce window, so
    navigating away does not silently drop the last keystrokes. Two more nets
    cover the same 800ms window: **`visibilitychange` → hidden** (the point a
    browser is free to freeze or discard the page, and the only one that is
    reliable on mobile) also flushes, and **`beforeunload`** flushes and then
    warns — but only when something is genuinely unconfirmed, since a prompt on
    every navigation is noise students learn to click through.
  - **The "New Log Sheet" form is deliberately NOT auto-saved** — it creates a
    record rather than editing one, and auto-saving it would create sheets while
    the student is still typing dates.
- **The signature space is intentionally left blank on the PDF** for the
  supervisor to sign by hand — the app stores a name and position, never a
  signature image.
- **`Faculty Adviser` on the form maps to the batch coordinator.** This system
  has no adviser/instructor role (see Domain Facts), and the coordinator is the
  person that field means.
- **`Area Assigned` is student-entered**, not derived — there is nothing to
  derive it from.
- **`No. of hours` is student-entered but PREFILLED from the DTR** where the
  batch's coordinator enabled it: `WeeklyActivityLogController::store()` fills
  `no_of_hours` from `DtrService::minutesInWeek()` **only when the student left
  the field empty**, and `show()` returns an advisory `dtr_hours` alongside it.
  A typed value is **never** overwritten — this is a paper facsimile a
  supervisor signs by hand, so a wrong DTR total (a forgotten clock-out, a
  session still awaiting adjustment) must stay correctable before printing.
  Where DTR is off, both are null and the field behaves exactly as before.
  **Hours are still never fabricated** — an unclocked hour stays unclocked.
- The header block (student name, program and year, adviser, company,
  supervisor) is **read-only**, and is resolved from the LOG's own
  `(student_id, batch_id)` pair — not from "whatever the student is enrolled in
  right now" — so a sheet still prints its real company and coordinator after
  the enrollment is marked completed, which is exactly when a coordinator is
  collecting these for the SIPP file.

#### The coordinator's read-only list (added 2026-08-27)

`Coordinator/CoordinatorWeeklyActivityLogController` (`index`/`show`/`pdf`,
routes `coordinator/weekly-activity-logs*`), page
`CoordinatorWeeklyTimeLogsPage.vue` at `/coordinator/weekly-time-logs`, nav
label **"Weekly and Time Log Summary"** (`clock` icon, the same glyph
`StudentLayout` uses for the student's own copy).

- Shaped like the **Student Info Sheets queue** — scope by the batch's program,
  filter, open one, download the official PDF — minus Accept/Reject, which has
  no meaning here: **this form has no approval step in the app at all.** The
  paper copy is signed by hand by the company supervisor. Same read-only
  posture as `CoordinatorWeeklyJournalController`.
- Unlike the weekly-journal queue it does **NOT** filter on a submitted state.
  There is no submit step on this form, so excluding anything unsubmitted would
  hide every sheet in the system.
- **The PDF is the identical measured facsimile the student downloads** — both
  controllers use the shared `Concerns\BuildsWeeklyActivityLogPdf` trait (the
  same call already made for the individual info sheet via
  `BuildsInfoSheetPdf`), so the coordinator's filed copy and the student's
  printed copy cannot drift. Only the filename differs (it carries the student
  name). `CoordinatorWeeklyActivityLogTest` asserts the coordinator's download
  is still US Letter, which is the trait's own load-bearing `setPaper()` call.
- Filters: `program_id` (403 out of scope), `search` (student name), and
  `from`/`to` matched against the period **overlapping** the range
  (`week_end >= from`, `week_start <= to`), not against `week_start` alone — a
  sheet whose period straddles the filter boundary is still the sheet the
  coordinator is looking for.

#### The PDF is a facsimile — and as of 2026-08-28 it is a MEASURED one

**Every geometric number in `pdf/weekly-activity-log.blade.php` was extracted
from the reference PDF's own vector content stream** — its stroked table rules
and its text baselines — not estimated from a photo. The reference, on a
612x792 US Letter page, in points:

- content column **x 56.8 -> 559.8** (503.0pt); **both** tables are that width
- info table rules at x **56.8 | 177.0 | 357.7 | 453.5 | 559.8**, rows at y
  **646.5 632.7 618.7 604.7 590.8 577.1** (a ~13.9pt pitch)
- activity table rules at x **56.8 | 120.25 | 226.75 | 333.0 | 453.5 | 559.8**;
  header row **40.75pt**, then five rows of **94.5 94.5 94.5 81.25 81.0**
  landing the last rule on **y=67.5**
- type is **Calibri Bold 11pt throughout** — masthead, title, every field label
  and every column heading; rules are **0.5pt** black

Rebuilt against those numbers, the blank form overlays the reference with
**7.5% of ink pixels differing at a ±2px tolerance** (it was **84.7%** before),
and the residue is anti-aliasing on 0.75px hairlines. Everything below is what
made that possible; do not "tidy" any of it.

1. **`->setPaper('letter', 'portrait')` in `BuildsWeeklyActivityLogPdf`.** dompdf defaults to
   **A4** (595x842), which silently narrows every measured column. Pinned by
   `test_the_pdf_is_us_letter_and_fits_on_one_page`, which asserts the MediaBox
   is `612 x 792` — verified to genuinely fail (it reports A4) when the call is
   removed.
2. **EVERY `line-height` IS WRITTEN PRE-DIVIDED BY 1.3428, and that is not a
   typo.** dompdf does not use `line-height` as the line box height:
   `FrameDecorator\Text::get_margin_height()` returns
   `(line_height / font_size) * fontHeight`, and `fontHeight` is
   `(winAscent + winDescent) / unitsPerEm * FONT_HEIGHT_RATIO` — for Carlito
   `(1950 + 550) / 2048 * 1.1 = 1.3428em`. So a plain `line-height: 14.5pt`
   renders a **19.47pt** line, and every heading, row and table comes out a
   third too tall. A unitless value is **not** a way out: it is multiplied by
   font-size first and then hits the same factor. `height`, by contrast, is
   content-box and is **not** rescaled, so a row's printed pitch is
   `height + vertical padding + the 0.5pt rule`.
3. **The whole blank form fits on ONE page**, with the last rule landing on the
   reference's own y=67.5 and 13.5pt of slack to the bottom margin. Re-measure
   after changing any masthead, info-table or row spacing. A log with more than
   five entries still flows to a second page, which is the documented
   "the table grows" behaviour.
4. **The column widths ride on the first row of each table that has no colspan
   in it** — the info table's first data row and the activity table's header
   row. dompdf ignores `<colgroup>`, ignores a width on any cell carrying a
   `colspan` (the info table's Faculty Adviser and Name of Company rows both
   span), and `table-layout: fixed` distributes columns equally regardless.
   Those widths are **content-box** — dompdf adds cell padding on top, so each
   is written as (target - 10pt of horizontal padding).
   **There is deliberately NO `.sizer` row any more, and it must not come
   back.** It was the visible defect that prompted this rebuild: a `.sizer td`
   rule (specificity 0-1-1) *loses* to `table.info td` / `table.activity td`
   (0-1-2), so both "zero-height" rows inherited a real row's border, padding
   and height and **printed as an empty leading row** in each table — a 13.9pt
   ghost above "Name of Student Intern" and a 70pt one above the column
   headings.
5. **The masthead and title carry a 9.4pt `padding-left`.** The reference
   centres its heading block on x=313.0, which is 4.7pt right of the content
   column's own centre (308.3); the indent moves the centre by half of it.
6. **Column headings are `vertical-align: top`, not middle** — on the reference
   every heading's first line shares one baseline with "Inclusive".
7. **The department and unit lines are literal constants** (on the trait)
   (`DEFAULT_DEPARTMENT_LINE` = "College of Accountancy, Business and
   Management", `DEFAULT_UNIT_LINE` = "Business Department"), matching the
   reference form verbatim. Deliberately **not** derived from
   `departments.name`, which is seeded to the short code ("CABM-B") and would
   print wrongly — the same call already made for the GROUP Student Information
   Sheet.

#### The type is Carlito, and the font files ship in the repo

The reference is set in **Calibri**, which cannot be redistributed and is not
present in the Linux Docker image. **Carlito** (`resources/fonts/Carlito-Regular.ttf`,
`Carlito-Bold.ttf`, plus `OFL.txt`) is metric-compatible with Calibri and is SIL
OFL licensed, so it can. Measured against the reference: "Name of Student
Intern" sets 108.11pt wide in Carlito against Calibri's 107.98pt.

- The faces are registered in PHP by `registerWeeklyActivityLogFonts()`,
  **not** through `@font-face`. A CSS `url()` pointing at a local `.ttf` goes
  through dompdf's URL resolver, which does not survive a Windows drive-letter
  path. Registration is **best-effort** — the blade's stack falls back to
  Helvetica, so a missing font file degrades the type rather than 500-ing the
  download.
- dompdf caches the parsed metrics into **`storage/fonts/`** on first render.
  That directory is committed (via its own `.gitignore`) and the Dockerfile
  already `chown`s `storage`, so nothing extra is needed to deploy.
- **Font subsetting is switched ON for this document only.**
  `laravel-dompdf`'s shipped config sets `enable_font_subsetting => false`,
  which is harmless while every PDF uses a base-14 font but embeds the whole
  682KB face the moment one does not — the download went from ~10KB to
  **~600KB**. `setIsFontSubsettingEnabled(true)` on the instance takes the
  blank form to **~12KB** and a filled one to ~24KB, and changes nothing that
  renders. It is set per-instance, so no other PDF in the project is affected.

`formRows()` pads to **`MIN_FORM_ROWS = 5`** so a sparse log still prints like
the pre-printed paper form, while the table itself **grows** past it (matching
the GROUP sheet's "the roster grows" decision). Coverage:
`test_the_pdf_renders_every_label_from_the_paper_form` asserts all 15 printed
labels survive.
### Journal document format (V2 — plain typed documents)

`pdf/daily-journal-entry.blade.php` and `pdf/weekly-log.blade.php` are
**standalone minimal blades** — they must look like plain typed documents, not
application reports.

- **Daily** (Times New Roman 16px, black on white): line 1 = UPPERCASE student
  name left / program name right (a borderless two-cell table, for dompdf); line
  2 = the **weekday label** `Sunday (MM-DD-YYYY)` (exposed by `show()` as
  `day_label`); then the `daily_accomplishment` text as unlabeled justified
  paragraphs; then each other **filled** section as a paragraph beginning with a
  bold inline label. No doc title, no meta table, no Status, no empty-section
  placeholders.
- **Weekly** (Times New Roman 12px): a bold `My OJT Journal Week N (Company)`
  title, where N is the 1-based position among that student's WeeklyLogs by
  `week_start`, computed identically in all three controllers that share the
  blade (student, supervisor, coordinator). Then bold uppercase day headers each
  followed by a plain paragraph. No meta table, Status, comment box, or
  signature block.
- **No time-range line — shift times are not stored; never fabricate one.**
- Client-side, the same day-block parsing lives in exactly one place:
  `WeeklyJournalPaperView.vue`. `JournalPaperView.vue` mirrors the daily PDF 1:1
  and is always rendered read-only on the write page — the paper is a review
  surface, not an alternate editor.

## Exit Interview

Built 2026-08-30 at the project owner's request. Narrows Hard Rule #4: the
per-student **form** is in scope and built. The aggregate **Summary Report on
Student Exit Interview** followed on 2026-09-10, narrowing Hard Rule #4 a
second time — see Summary Report below.

The student fills in the official CABM "Internship Program Student Exit
Interview Form" at the close of their placement; their coordinator reads every
answer, records the compliance verification the form reserves for them, and
downloads a measured facsimile to file. Reference:
`docs/reference/INTERNSHIP PROGRAM STUDENT EXIT INTERVIEW - BUSINESS.pdf`.

- **Student**: `StudentExitInterviewController` (`show`/`store`/`pdf`, routes
  `student/exit-interview*` in the **gated** group), page
  `StudentExitInterviewPage.vue` at `/student/exit-interview`, nav label
  **"Exit Interview"** (`exit` icon), placed last in the student nav —
  after Student Info Sheet, because that is the order a student meets them:
  intake first, exit last.
- **Coordinator**: `CoordinatorExitInterviewController`
  (`index`/`show`/`update`/`pdf`/`summary`, routes `coordinator/exit-interviews*`),
  page `CoordinatorExitInterviewsPage.vue` at `/coordinator/exit-interviews`, nav
  label **"Student Exit Interviews"**. The page carries two tabs — **By
  Student** (the queue below) and **Summary Report** (see below) — rather than
  a second nav item; see Summary Report for why.

### Schema — one additive table

`student_exit_interviews`: `student_id`, `batch_id`, three JSON payloads
(`student_info`, `responses`, `coordinator_section`), `submission_status`
(`draft`/`submitted`/`reviewed`), `submitted_at`, `reviewed_at`, `reviewed_by`,
and **`UNIQUE(student_id, batch_id)`** — the form is filled once per placement,
backstopped in the database the way `batch_students` backstops its own pair
rather than by the controller alone.

The JSON columns follow `student_information_sheets`' precedent for the same
reason: **the question set belongs to the paper form, not to the schema**, so
rewording or adding a question is not a migration. `responses` is keyed
`q1`..`q14` plus `q2_choice` / `q7_choice` / `q10_choice` / `q11_choice` for the
four printed ☐ Yes ☐ No pairs. **`StudentExitInterview::QUESTION_KEYS` and
`CHOICE_KEYS` are the single definition** shared by the Form Request, the API
payload and the PDF, so the three can never disagree about what question 7 is.

### The write rule is deliberately NOT the project-wide one

Every other student WRITE endpoint requires `activeEnrollment()`, so a
`completed` student is read-only. **This form uses `currentEnrollment()`
(active OR completed)** — and it is the only student surface that does.

An exit interview is *by definition* filed at or after the end of a placement.
Applying the usual rule would make it unwritable at exactly the moment it falls
due, which is the whole reason it exists. A `dropped` student still gets
nothing, as everywhere else. Pinned by
`test_a_completed_student_can_still_file_their_exit_interview` and
`test_a_dropped_student_has_no_exit_interview_to_file` — do not "restore" the
usual rule here.

### What the student types, and what is derived

Section A is mostly re-derived, so the student types only what the system cannot
know: **Department/Position Assigned** (prefilled from
`batch_students.assigned_division`, still editable — the form asks for something
more specific than a division), **Total Hours Completed**, and **Date of
Interview**. Name, program, company, training period and coordinator come from
the enrollment and are read-only.

**Total Hours prefills from the DTR and is never overwritten** — the same rule
as the Weekly Activity Log's "No. of hours". This is a paper facsimile somebody
signs by hand, so a wrong DTR total (a forgotten clock-out, a session awaiting
adjustment) must stay correctable before printing. With DTR off and nothing
typed, the blank prints blank; **hours are never fabricated**.

### Draft, submit, lock — and the coordinator's own block

- A **draft** validates nothing; a **submit** requires all fourteen answers and
  all four Yes/No choices. Half an interview handed to a coordinator is worse
  than none, but a student must be able to stop typing and come back.
- **Submitting locks the student's half permanently** — there is no unsubmit.
  It notifies the batch's own coordinator (`batches.coordinator_id`, the single
  unambiguous owner — the same call the info sheet's submission makes) and
  writes a `SystemLog` row.
- The coordinator then fills **"SECTION FOR OJT/INTERNSHIP COORDINATOR"**
  (compliance verification, the pending-requirements detail, remarks), which
  stamps `reviewed`. `update()` never touches `responses` and 422s a `draft`
  interview — there is nothing to verify until it is handed in.
- **There is deliberately NO accept/reject**, unlike the Student Info Sheets
  queue. An exit interview is feedback, not an application: it gates nothing,
  and a coordinator disagreeing with an answer is not grounds to bounce it back.
  Drafts ARE listed (unlike the weekly-journal queue), because the list is also
  how a coordinator sees who has not started.

### Answer length is a WIDTH check, not a character count

Each question gets five printed ruled lines. The
renderer wraps onto exactly those and **silently drops the overflow, because a
PDF cannot refuse** — so the refusal has to happen at validation time, and
`ExitInterviewFormLayout::fits()` measures the rendered width rather than
counting characters.

That distinction is load-bearing, not fussiness: measured in Helvetica at
9.5pt, ordinary prose sets ~0.45em per character (~109 to a 467.75pt rule)
while an ALL-CAPS answer sets ~0.60em (~82). **No single character count is
both generous to the first and safe for the second** — a cap tight enough for
capitals would refuse a perfectly ordinary answer, and one generous enough for
prose would cut a shouted one off mid-sentence with nothing on screen admitting
it. The character caps that remain are `CHARS_PER_LINE` × the field's OWN rule
count — 500 for a question, 300 for the coordinator's Remarks, 200 for the
pending-requirements detail — and only size the textarea and bound the
payload; `HARD_CHAR_CAP` is the cheap gate that stops a megabyte reaching the
measurer. The coordinator's two fields go through the identical check, so a
remark cannot be truncated off the foot of the form either.

### The PDF: measured from the reference, regularised against it

`resources/views/pdf/exit-interview.blade.php` via the shared
`Concerns\BuildsExitInterviewPdf` trait — one renderer, so the student's
printed copy and the coordinator's filed copy cannot drift.

**THE BLADE HOLDS NO GEOMETRY.** Every position is computed by
`App\Support\ExitInterviewFormLayout::document()` from one horizontal grid and
one vertical rhythm; the blade is three loops over the result. That is
deliberate and is the whole point of the 2026-08-30 rebuild: the first cut
hand-placed some sixty coordinates in the blade, which is exactly the shape
that lets spacing drift, one edit at a time, into what the reference itself
became.

**What was measured off the reference and KEPT:** the page box, the ruled
column (x 72 → 539.75), the 13.2pt rule pitch, the type and its sizes, the
two-column Section A, the section order, and every word of every question and
label.

**What was NOT kept is its spacing**, which is a Word artifact. The reference,
measured off its own content stream:

- pitches its answer rules at **13.15 / 13.20 / 13.25 / 13.40 / 13.45 /
  13.65pt** in different places
- varies the gap from a question to its first answer rule across **14.84 –
  15.58pt**
- puts the four ☐ Yes ☐ No pairs at **four different x positions** (378.12,
  450.12, 324.00, 466.80), each simply trailing however long its question
  happened to be
- orphans **"Please explain:"** onto a line of its own **18pt to the LEFT of
  the question it belongs to**, because Word wrapped it off the end
- indents **page 1's whole body 18pt less than page 2's**, so one document has
  two left margins
- gives **question 7 four answer lines** where every other question gets five,
  and **splits them across the page break** — three at the foot of page 1, one
  at the top of page 2, so a single answer runs across two sheets
- spends **less** vertical air on a section break (10.5pt) than between two
  questions of the same section (11.4pt), so its sections do not announce
  themselves

The rebuilt form fixes each of those. Load-bearing details:

1. **The page is 612 x 936pt — Philippine "long bond" (8.5" x 13", Folio/F4),
   NOT Letter and emphatically not dompdf's A4 default.** `setPaper([0, 0, 612,
   936], 'portrait')` is the single most important line in the trait. Asserted
   on both download paths.
2. **One horizontal grid, both pages**: section headings at **72** (flush with
   the answer rules, so the document has a single strong left edge), question
   numbers hanging at **90**, question text and every wrapped line at **108**,
   answers back at **72**.
3. **One vertical rhythm**, stated against `LINE = 13.2` (the reference's own
   rule pitch). The resulting gaps are few and each means something: **26.4pt**
   between two questions of the same section, **43.6pt** across a section
   break, plus **one LINE** where a question genuinely needs a second line. Every
   variation encodes structure; none is an accident.
4. **Every question gets five answer lines, on one page.** Question 7 no longer
   straddles the break.
5. **The ☐ Yes ☐ No pairs share one column on both pages.** A choice question's
   text wraps short of that column, so the pair is in the same place every
   time rather than trailing the text. `CHOICE_GUTTER` is sized so all four fit
   beside their pair on one line — **question 11 is the long one and clears it
   by about 3.6pt**, and `test_only_the_one_over_long_question_wraps` guards
   the widow that appears if it stops fitting.
6. **"Please explain:" is printed ON the first answer rule**, with the answer
   inset past it — the same treatment the coordinator's "If pending, specify:"
   already had. One mechanism for all five labelled fields.
7. **Answers are wrapped in PHP, not by dompdf**, against the very
   `Helvetica.afm.json` dompdf will use, so the wrap and the render cannot
   disagree. It also sidesteps dompdf's line-height quirk entirely (its line
   box is `(line_height / font_size) * fontHeight`, not `line_height`), since
   no block holds more than one line.
8. **dompdf positions a block by its TOP, so each computed BASELINE is
   converted with the face's own ratio** — `BASELINE_RATIO`: Helvetica (regular
   and bold) 0.81400, Times 0.79200, Times-Bold 0.78922, ZapfDingbats 0.84740.
   These were **probed against dompdf itself**, not derived; linear in size to
   within 0.05pt across 9.4-19pt.
9. **The type is substituted and the size scaled to match.** The reference sets
   its body in **Tahoma at 10.45pt horizontally CONDENSED to ~91%** (every glyph
   carries its own `Tm` with an `a` scale of 0.043-0.05 against a fixed `d` of
   0.05). dompdf cannot condense a face and Tahoma is proprietary, so the
   substitute is **Helvetica at 9.5pt** — base-14, so nothing embeds. Measured
   at 10.45pt the preamble sets 553.71pt in real Tahoma against **550.59pt in
   Helvetica (0.6% out)**, 628.24pt in DejaVu Sans (13% too wide) and 509.49pt
   in Carlito (8% too narrow); the drop to 9.5pt is what makes UNCONDENSED
   Helvetica occupy the width the condensed original does. Times **is**
   metric-compatible with Times New Roman, so the masthead keeps the
   reference's own sizes — including "Mater Dei College" in **#205E99**, the
   one coloured element and the reference's own `rg 0.125 0.369 0.6`.
10. **A ticked box carries a CHECK MARK from ZapfDingbats** (glyph `a19`,
    character `'3'`), centred in the box from the glyph's own AFM metrics.
    ZapfDingbats is base-14 like Helvetica and Times, so it embeds nothing —
    **Helvetica has no check glyph at all**, which is why an X stood in for one
    before. The ☐ itself is a stroked div: the reference's Segoe UI Symbol is
    proprietary and would render as a blank or a tofu square.
11. **Section A pairs Total Hours with Date of Interview and gives the
    coordinator a full-width row of its own.** The reference pairs Date of
    Interview with the coordinator, which leaves the coordinator's blank far
    too short for a full name with post-nominals — the longest value on the
    form. Regrouping costs no height and lets **every** right-hand cell start
    at the same x.
12. **Nothing may reach the folio.** `document()['bottom']` reports each page's
    lowest ink and a test asserts 20pt of clearance — an answer printing across
    the page number is what an unchecked overrun produces. Currently page 1
    ends at 868.85 and page 2 at 871.37, against a folio baseline of 906.53
    (the reference's own page-2 bottom was 882.05, so the envelope is
    unchanged).

The blank form and a fully filled one both come out at **exactly two pages**,
asserted on both download paths, and **no font is embedded** — a filled
download is about 6KB.

### Coverage

`tests/Unit/Support/ExitInterviewFormLayoutTest.php` pins the REGULARITY, and
each of its cases names an irregularity the reference actually has — a single
rule pitch, one answer box size on one page per question, one column for every
☐ Yes ☐ No pair, one left grid across both pages, labelled answers inset clear
of their printed label, no page running into the folio, and only the one
genuinely over-long question wrapping. It also pins that `fits()` measures
width rather than length — the same text passes as prose and is refused in
capitals.

`tests/Feature/Student/ExitInterviewTest.php` (draft vs submit validation, the
lock, the completed/dropped split, the page size) and
`tests/Feature/Coordinator/CoordinatorExitInterviewTest.php` (program scoping,
the 403s, the coordinator block never touching `responses`, and the draft that
cannot be signed off) cover the endpoints.

### Summary Report — every intern's answer gathered per question

Built 2026-09-10 at the project owner's request, narrowing Hard Rule #4 a
second time. This is the aggregate **Summary Report on Student Exit
Interview** that Hard Rule #4 used to keep out of scope: instead of one row
per student, every in-scope intern's answer to question 1 is gathered
together, then question 2, and so on — `GET coordinator/exit-interviews/summary`
via `CoordinatorExitInterviewController::summary()`.

**Reached as a "Summary Report" tab on the same `CoordinatorExitInterviewsPage.vue`,
not a second nav item or route.** The project owner asked for it inside the
existing Student Exit Interviews page rather than as its own sidebar entry.

- **Scope is `coordinatorProgramIds()`**, exactly like the by-student queue,
  with the identical optional `program_id` narrowing (403 out of scope). The
  view is **combined across every in-scope program by default** — not
  per-program tabs like the Annual SIPP report editor — since the client's ask
  was framed as "one page gathering all the answers," with the program filter
  available to narrow it.
- **Filtered by `academic_year`** (via `batches.academic_year`, the same
  column the Annual SIPP/HTE reports key on), defaulting to the most recent
  year that has any batch in scope.
- **DRAFTS ARE EXCLUDED — only `submitted` and `reviewed` interviews feed
  it.** A draft can be blank or half-typed, and only a submitted interview is
  guaranteed to carry all fourteen answers (`StoreExitInterviewRequest`
  enforces that on submit); including drafts would let an in-progress form
  skew a tally into looking like a completed one. This is the opposite of the
  by-student queue, which deliberately DOES include drafts (see above) — the
  two answer different questions ("who has started?" vs. "what did people who
  finished actually say?").
- **Deliberately NOT curated, unlike the Annual SIPP / HTE / Group Info Sheet
  report editors.** Those exist so a coordinator can correct messy real-world
  source data (a mistyped company name, a missing hire date) before filing an
  official annex. There is nothing to correct here — every answer is text the
  student already submitted themselves — so this is a **live read, recomputed
  on every request, with nothing persisted.** No `manual_rows`, no
  `deleted_ids`, no override JSON column.
- **No PDF export.** Unlike the per-student form, there is no reference
  document for this aggregate — `docs/reference/` has no facsimile to measure
  it against — so a PDF here would be an unmatched, made-up layout rather than
  a measured one. Easy to add later against the same query if ever needed.
- The 14-question catalog (number, section heading, text, and which four carry
  a ☐ Yes ☐ No pair) is read straight off **`ExitInterviewFormLayout::SECTIONS`**
  rather than re-declared a third time — it already backs the PDF and cannot
  drift from the actual form wording.
- **Choice questions (q2/q7/q10/q11) carry a `{yes, no, unanswered}` tally**
  alongside the same per-student answer list; a student who picked Yes/No but
  left the explanation blank still appears (with `text: ''`), since the choice
  itself is an answer.
- **A blank text answer is skipped from that question's list entirely**,
  matching the app-wide "skip fields whose value is blank" convention — it is
  not rendered as an empty row.

Coverage: `tests/Feature/Coordinator/ExitInterviewSummaryTest.php` — gathering
answers under their own question, draft exclusion (and that a `reviewed`
interview still counts), blank-answer skipping, choice tallies including
`unanswered`, program scope (403 + filtering), academic-year default/filter,
and the empty-but-valid shape when nobody has submitted yet.

### Demo data

`CabmbExitInterviewDemoSeeder` gives the three `mdcbalintern*` logins one form
each, **one in every state** — `mdcbalintern1` reviewed (with the coordinator's
block filled in and "With pending requirements" ticked, so the printed PDF
shows a ticked box), `mdcbalintern2` submitted (the row that needs the
coordinator to act), `mdcbalintern3` draft (visible to the coordinator but
refused for sign-off). Three states, not three copies: the Status filter and the
sign-off rule are only demonstrable side by side. Answers are written in three
distinct voices, and every one fits its printed rules.

Its `interviewedOn()` both anchors to **Asia/Manila** (never `->setTime()`, for
the reason `CabmbSupervisorDtrDemoSeeder` documents) **and clamps the date to
the past** — the batch runs on beyond today, so a naive "start + 10 weeks"
printed a FUTURE interview date onto a form a coordinator signs by hand. That
was a real defect caught on screen, not a precaution.

## Daily Time Record (QR + geofence)

Built 2026-08-20. Reverses the earlier "out of scope" wording in Hard Rule #4.
A company supervisor generates a **static QR code** anchored to coordinates
captured from their own device at the workplace; the student scans it with
their phone's camera, the browser reports coordinates, and the server records a
clock-in/clock-out. **How the supervisor SHOWS that code is up to them** —
from their own `/supervisor/dtr` page on screen, or from a downloaded SVG/PNG
copy. Printing is one option among several, not an expectation, and no
user-facing copy may tell a supervisor to print and post it (corrected
2026-08-20 by the project owner). Banked hours become a real OJT progress metric
against `batches.required_hours` — which until now was stored and read by
**nothing**.

### Be honest about what this proves

- **A static QR authenticates nothing.** Its payload is fixed, so the first
  student to scan it can screenshot it and share it. The QR identifies a
  **site**; the geofence carries the entire anti-fraud load.
- **Browser geolocation is client-supplied and spoofable** (DevTools has a
  location override; Android mock-location apps need no root). Only a native
  app could report `mocked: true`, and `mobile/` is still an empty template.
  The defensible claim is *"raises the cost of cheating and creates a reviewable
  audit trail"*, **not** *"prevents buddy-punching"*. That is exactly why every
  punch stores lat/lng/accuracy/distance and why the supervisor's review and
  adjustment surface exists.
- **Rotating (TOTP) QR does not "cost a fortune"** — paid *dynamic QR* is a
  commercial redirect/analytics product and is unrelated. A rotating code is
  `hash_hmac` over a 30-second window, the same construction as Google
  Authenticator, and is free. Its only real cost is physical: it must live on a
  **screen**, so it cannot be printed and taped to a wall. Hence static-now,
  rotation-ready.
  **That cost is smaller than it first looked.** The project owner confirmed
  (2026-08-20) that supervisors are free to display the code straight from
  their own `/supervisor/dtr` page rather than printing it — and a code already
  being shown on a screen is exactly the condition rotation needs. Rotation is
  still unbuilt, but the objection to it is now a preference about how each
  company chooses to display its code, not a physical blocker.

### Schema (4 migrations, all additive)

- **`users.dtr_enabled`** (bool, default **false** = opt-in). The coordinator's
  preference, set by the admin at account setup and changeable by the
  coordinator afterwards. Chosen over the `coordinator_departments` pivot
  deliberately: that pivot has **no model** and is reached only through
  `belongsToMany`, so a payload column would need `withPivot()` at every read.
  Resolution is one hop —
  `batch_students.batch_id → batches.coordinator_id → users.dtr_enabled` — and
  `batches.coordinator_id` is NOT NULL and singular, so a department with
  several coordinators is never ambiguous.
- **`users.dtr_disabled_reason`** (string(40), nullable) + **`dtr_disabled_note`**
  (string(255), nullable) — WHY a programme opted out, so an admin auditing a
  department, or the next coordinator to inherit it, need not guess whether the
  switch is off deliberately or by neglect. The reason is a slug from the fixed
  **`User::DTR_DISABLED_REASONS`** vocabulary (not free text, so departments can
  be compared) and the note carries the specifics a fixed list cannot.
  **Both are cleared by `DtrPreferenceController` whenever the DTR is switched
  back ON** — the reason justifies an *off* state, and left behind it would
  assert a justification for a state the programme is no longer in. The
  controller ignores whatever the client sent on an enable rather than trusting
  it. Both are optional: a coordinator is never blocked from switching the DTR
  off for not having explained themselves.
  **Deliberately NOT a third state on `dtr_enabled`.** A "never answered" state
  would make that column nullable and put a null check on every read of the
  one-hop resolution path above, all to power a first-run prompt — and there is
  nothing to prompt for, since `Admin/UserController::store` already sets the
  value definitively on the Create Coordinator form.
- **`company_geofences`** — `company_id`, `created_by`, `label`, `token`
  (unique, 32 chars, the static QR payload), `rotation_secret` (nullable, the
  forward door), `latitude`/`longitude` `decimal(10,7)`, `radius_meters`
  (default **150**), `captured_accuracy`, `is_active`. A table rather than
  columns on `companies` because a company can host at two sites, the
  coordinates belong to the QR rather than the company record, and `companies`
  has `$timestamps = false` plus a `#[Fillable]` attribute best left alone.
  `token`/`rotation_secret` are deliberately **not fillable** — generated in a
  `creating` hook, never accepted from a request.
- **`dtr_sessions`** — see the two invariants below.

**`dtr_sessions.open_session_key` is a nullable UNIQUE column holding
`student_id` while a session is open and NULL once it closes.** This is what
makes "at most one open session per student" a *database* guarantee. The service
checks it too and produces the friendly message, but a check-then-write cannot
survive two concurrent requests — a double-tap or two tabs both read "no open
session" before either writes. Verified: without this index the database
accepted two open sessions for one student, leaving them unable to clock out of
either. "At most one open per student" is not expressible as a plain composite
unique index, and partial/filtered indexes are not portable across MySQL and
SQLite, hence the nullable-key trick (both treat NULLs as distinct). A
`DtrSession::booted()` saving hook keeps the key in lockstep with `status` so no
caller can forget it. `DtrService` catches the resulting
`UniqueConstraintViolationException` and returns the winning session as a
success — the student's intent was "I am here now", and that is what got
recorded.

**CRITICAL — `dtr_sessions` has NO foreign key to `batch_students.id`, and must
never gain one.** `BatchStudentPurgeService` hard-deletes archived enrollment
rows after 30 days, and no table references that id precisely so the purge
cannot orphan history. A cascading FK here would let the nightly purge silently
erase a student's entire attendance record. Keyed off `student_id` + `batch_id`,
exactly like `journal_entries` and `weekly_logs`. Pinned by
`tests/Feature/Services/DtrPurgeSafetyTest.php`. `geofence_id` is
`nullOnDelete` for the same reason at the site level.

`minutes_worked` is **stored, not derived** from the timestamps, so a
supervisor's correction (deducting an unlogged break, closing a forgotten
punch) is durable and the progress figure cannot drift from what was signed off.

`work_date` is derived server-side from `now()` in `config('app.timezone')`.
Deployments set `Asia/Manila`; on a UTC box a 07:00 Manila punch would land on
**yesterday**.

### A punch is a TOGGLE, and there is deliberately no standalone clock-out

`DtrService::punch()` is the single chokepoint (the `EnrollmentService` pattern).
Scanning on arrival opens a session; scanning **the same code** on departure
closes it. A location-free "Clock Out" button could be pressed from anywhere,
which would undo the geofence at exactly the moment it matters. A student who
genuinely cannot scan out asks their supervisor to adjust, which leaves a
required reason on the record.

Rules enforced there, in order: active enrollment → coordinator has DTR on →
token resolves to an **active** fence → the fence's company **matches the
student's enrolled company** → rotating code (no-op today) → distance ≤ radius →
**stale-session guard** → one-open-session guard. An open session at a
**different** site 422s rather than being silently closed — closing it would
invent a clock-out time and place nobody observed. The one-open-session rule
lives in the service because it is not expressible as a portable partial unique
index.

**THE ONE CARVE-OUT IN THE TOGGLE: a stale session is not clocked out of, it is
closed out of the way.** If the open session's `time_in` is older than
`DtrService::STALE_SESSION_MINUTES`, `punch()` auto-closes it and the scan opens
a NEW session, returning `action = 'clocked_in'` with `auto_closed_previous`.
Without this the toggle cascades: a student who forgot to scan out scans the
next morning expecting to time in, is silently timed OUT of yesterday instead,
walks away believing they are clocked in, works the whole day uncounted, and
their evening scan opens *another* overnight session — a day at a time, forever.
The guard lives in `punch()` **as well as** in the nightly command deliberately:
the API sleeps on an idle free tier and the external cron jitters, so the
morning scan cannot depend on the job having run.

- Timestamps are **server receipt time**, never the client clock — a device
  clock is as manipulable as its GPS.
- **`STALE_SESSION_MINUTES` is 720 (12h), and 12 is not 8 by accident.** Closing
  at exactly one standard shift would punish a genuinely long day: an intern
  working 8h30m would find their session already closed and their scan-out would
  read as a fresh clock-in. 12h clears a shift plus lunch plus grace, still
  closes a morning clock-in the same evening, and leaves a real shift crossing
  midnight (in 22:00, out 02:00) alone — which a "it is a new day" rule would
  not. Pinned by `test_a_shift_across_midnight_...` and
  `test_a_long_but_plausible_shift_still_clocks_out_normally`.
  It replaced `MAX_PLAUSIBLE_SESSION_MINUTES` (16h); one number, one meaning.
- `minutesCompleted()` counts **`closed` only** (`DtrSession::COUNTED_STATUSES`).
  `open`, `flagged` and `void` never count.
- Required hours resolve as
  `student_profiles.total_hours_required ?? batches.required_hours` — both
  columns already existed; the per-student one becomes the transferee override
  it was always shaped for.
- **The read/write enrollment split applies here exactly as elsewhere.**
  `DtrService::enrollmentFor()` is **active only** and gates punching;
  `readEnrollmentFor()` accepts **active OR completed** and backs the DTR page
  and `appliesTo()`. Getting this wrong was a real inconsistency: the dashboard
  resolves through `currentEnrollment()` and so already showed a completed
  student their hours, while the page explaining that number 422'd them out.
- **`accuracy` is CLAMPED in `PunchDtrRequest::prepareForValidation()`, never
  rejected.** `coords.accuracy` is unbounded in the spec and a phone with no GPS
  lock indoors can report a six-figure radius. A bare `max:` rule would 422 the
  whole punch — refusing a student standing exactly where they should be, over a
  diagnostic field that gates nothing. The distance check is the gate.
- **A supervisor's `adjust` or `void` on an open session releases the student.**
  Since the one-open-session rule is now a DB constraint, a stuck session would
  otherwise end that student's DTR for the rest of the placement. Both paths go
  through the saving hook, and both are covered by tests.

### The forgotten clock-out — auto time-out

Built 2026-08-21. `dtr:auto-close-sessions` (hourly) closes every session open
past `STALE_SESSION_MINUTES` and notifies the student. `DtrService::punch()`
carries the identical guard (above), so the two must never diverge — both go
through the single writer **`DtrService::autoCloseStaleSession()`**, the
`EnrollmentService` pattern again, and both use the one wording from
`DtrService::staleReason()` so a student cannot be told two stories about the
same event.

What an auto-closed session looks like, and why each part:

- **`status = 'flagged'`.** `COUNTED_STATUSES` is `closed` only, so it banks
  exactly zero until a supervisor confirms it. This is what makes the assumed
  figure below safe to write at all.
- **`minutes_worked = DEFAULT_SHIFT_MINUTES` (480 / 8h).** Not a claim about the
  day — the **supervisor's starting number** in the Adjust action, which
  `SupervisorDtrPage.vue` prefills via `promptAction`'s `initialValue` so
  confirming is one tap. **If this ever becomes a counted status, an intern who
  never clocked out banks a full day for turning up once.**
- **`time_out` stays NULL.** Nobody observed the student leaving. Stamping an
  assumed departure would print a time they never gave into the "Out" column of
  their own record; a null reads correctly as "never scanned out", which is what
  happened. Same reasoning already written into `DtrReviewController::adjust`.
- **`adjusted_by` stays NULL**, so a system close is distinguishable from a
  human correction.
- The student gets an **`in_app`** notification. The supervisor is deliberately
  not notified — their Needs Attention filter already lists `flagged`.
- A session is closed **even if the coordinator has since switched DTR off**.
  Leaving a student permanently unable to clock in, should it come back on, is
  the worse outcome, and the minutes still do not count.

**Scheduled on BOTH paths, and the cron one takes no marker.** `routes/console.php`
runs it `hourly()`; `CronController` invokes it on **every ping**, like
reminders and unlike purge/bundling. It self-gates on how long each session has
been open, so it is correct at any ping minute, and a marker would actively
*delay* closing a session that crossed the threshold just after the last run.
Re-running is free: closing a session moves it out of the `status = 'open'` set
it selects, so a second run finds nothing and cannot re-notify.

### The rotation-ready contract

The QR encodes `{FRONTEND_URL}/student/dtr/scan?s=<token>` — pointing at the
**SPA**, not the API, because the student opens it with their phone's own
camera app. `POST /api/student/dtr/punch` accepts an optional `code` that is
**validated and ignored** while every `rotation_secret` is null. Populating one
switches that site to requiring a TOTP code, with no change to any caller and
no change to what the student does.

### Two ways to scan, both supported

**1. The in-app camera scanner** (`components/dtr/QrScannerModal.vue`) is the
primary path — the student taps **Scan to Time In / Scan to Time Out** on
`/student/dtr` and never leaves the app. Decoding falls back in this order:

- **`BarcodeDetector`** where the browser has it (Chrome, Edge, Android Chrome)
  — hardware-accelerated and free.
- **`jsqr`** everywhere else. This branch is what makes iPhones work at all:
  Safari has no `BarcodeDetector`, so without it every iOS student would be
  locked out of the scanner. It is **dynamically imported**, so browsers with
  the native detector never download it — that keeps the DTR page chunk at
  ~13kB instead of ~142kB, with jsQR in its own lazily-fetched chunk.

Both paths feed the same `extractSiteToken()` in `web/src/lib/dtr.ts`, so they
cannot disagree about what counts as one of our codes. A QR that is not ours is
ignored and scanning continues, rather than firing a doomed request at the first
random code that wanders into frame.

**TWO GATES stand between a decode and a punch, and both exist because a punch
is a TOGGLE** — an accidental read of a code lying on a desk does not merely do
nothing, it clocks the student OUT.

1. **The framing/stability gate.** A decode is accepted only when the code's
   corner points fall inside a centred square of `min(videoWidth, videoHeight) *
   0.75`, the same token reads on 3 consecutive frames, and at least 600ms has
   passed since the camera opened. QR error correction is deliberately good
   enough to decode a code half out of frame, at an angle, from a glance — a
   virtue everywhere except here, where it makes a punch feel like it fired by
   itself.
   - **The guide rect is computed the way `object-cover` crops**, from
     `min(vw, vh)` centred — NOT scaled from width. The video is 4:3 or 16:9
     inside a square box, so a width-based rect lands in the wrong place on
     every phone whose camera is not square.
   - **Missing corner points are ACCEPTED, not rejected.** Some
     `BarcodeDetector` implementations omit `cornerPoints`; refusing there would
     lock those browsers out of the scanner entirely. The stability gate and the
     confirmation below still apply.
   - The warm-up is load-bearing: without it a code already in frame when the
     modal opens is read on the very first frame and the student never sees the
     scanner at all.
2. **The confirmation card** — and this is the gate that actually matters, since
   with it, decoding fast is harmless. On lock the scanner stops the camera and
   calls **`resolveSite()`** (the read-only `GET student/dtr/scan` preview), then
   shows the site, the direction, and the account before anything is written.
   Only `Confirm` punches. This brings the in-app scanner in line with the QR
   landing page, which has always confirmed first, and with the project's rule
   that crucial actions confirm before they happen.

The modal therefore emits **`confirmed`**, not `decoded` — a decode is no longer
a decision.

**2. The phone's own camera app** still works and is the fallback whenever
camera permission in-app is refused: the QR encodes a URL, so the native camera
opens `/student/dtr/scan?s=<token>` directly. Keep this path — it is the only
one that works when the student has denied camera access to the site.

`web/src/lib/dtr.ts` is the shared client contract for both surfaces
(`extractSiteToken`, `resolveSite`, `currentPosition`, `isPermissionDenied`,
`locationErrorMessage`, `punchAtSite`). Both the scanner and the landing page go
through it for exactly the reason `DtrService` exists on the backend.

**The scanner must release its camera tracks on close/unmount.** A missed track
leaves the phone's camera indicator lit after the modal is gone, which reads to
the student as the app watching them. `stopCamera()` also sets a `finished`
flag: without it a lucky second decode from an in-flight frame fires a duplicate
punch.

**Duck-type `code === 1` for PERMISSION_DENIED — never
`err instanceof GeolocationPositionError`.** That global is not reliably defined
across browsers, and referencing an undefined identifier *from inside a catch
block* throws a ReferenceError that swallows the real error and leaves the
button dead with no message at all.

**`currentPosition()` reads the position in TWO stages.** Stage 1 is GPS
(`enableHighAccuracy: true, maximumAge: 0`). Stage 2, on failure, is a coarse
network fix (`enableHighAccuracy: false, maximumAge: 60000`). Interns work
inside concrete buildings where a GPS lock frequently never arrives and stage 1
just times out; refusing the punch there would block a student standing exactly
where they should be, which is the worse failure. This does **not** weaken the
geofence — the distance check is still the gate, a coarse fix that lands outside
the radius is rejected as before, and the reported accuracy is stored on the
punch for the supervisor to judge. A PERMISSION_DENIED is re-thrown immediately
rather than retried, since the answer will not change on a second ask.

**GOTCHA — an in-app browser can open the camera and still never return a
position.** Messenger/Facebook/Instagram WebViews need the *host app* to hold
Android location permission, which most users have never granted. The symptom
is a successful QR decode followed by a location failure, which reads as an app
bug. `locationErrorMessage()` therefore names both real causes (device Location
off, in-app browser) and tells the student to reopen in a real browser.

### Routes and surfaces

- **Student** (inside the gated `infosheet.approved` group): `GET student/dtr`,
  `GET student/dtr/scan`, `POST student/dtr/punch`. Pages
  `StudentDtrPage.vue` (`/student/dtr`, read-only) and `StudentDtrScanPage.vue`
  (`/student/dtr/scan`, the QR landing page). The nav item is hidden unless
  `student_dtr_enabled`.
- **Supervisor**: `DtrGeofenceController` (index/store/update/destroy/qr) and
  `DtrReviewController` (index/adjust/void), one page at `/supervisor/dtr`.
  `destroy` **deactivates, never deletes**. `update` deliberately cannot move
  the coordinates — re-anchoring means creating a new site, so a fence can never
  be quietly relocated without fresh capture evidence. The **radius IS editable
  in place**, from a dropdown on each site card: resizing is safe from anywhere,
  and the alternative (retire-and-recreate) issues a new `token` and therefore
  silently invalidates every QR code already printed.
- **Coordinator**: `DtrMonitorController` (`index`, `sites`) — **read-only, no
  adjust/void**, same posture as `CoordinatorWeeklyJournalController`.
  Its figures come from **one grouped query** (`sessionTallies()`), not from
  `DtrService`'s per-student helpers — those cost three queries per intern, i.e.
  ~300 round trips for a 100-intern department on an instance that cold-starts.
  It also eager-loads **`student.studentProfile`**, because `requiredHours()`
  reads `total_hours_required` and would otherwise lazy-load one profile per
  intern — a second N+1 hiding behind the first, which only surfaced because
  `DtrMonitorTest` asserts a query-count ceiling. Keep that test: it is the
  only thing that notices either regression coming back.
  `sites` exists because a supervisor who generated their QR at home anchored
  the fence to their house and nothing automated can detect that; it lists the
  coordinates plus a maps link against the company's registered address.
  `DtrPreferenceController` (`show`/`update`) is the coordinator's own switch.
  **It is surfaced on `/coordinator/dtr` itself, and nowhere else** — it used to
  live in the account-menu popover as `DtrSettingsPanel.vue`, which meant a
  coordinator looking at an empty DTR page had no way to find out from the page
  why it was empty. That panel is **deleted** and the popover's `dtr` view is
  gone with it; do not reintroduce a second surface, since the two would then
  have to be kept in step.
  `CoordinatorDtrPage.vue` therefore has two whole states: **off** renders the
  consequence (what interns and supervisors lose, where hours come from
  instead) plus the switch and the reason fields, in place of the empty table it
  used to show; **on** collapses to a one-line status strip above the existing
  read-only tabs. The switch and both reason fields go through **one** writer
  (`savePreference`), which always PUTs all three values, so the toggle and the
  reason can never disagree about the stored preference.
  `show`/`update` also return **`updated_at`**, read back from the
  `system_logs` row the controller writes rather than from `users.updated_at` —
  that column moves on any profile edit and would date the preference to an
  unrelated change. `SystemLog`'s `$timestamps = false` switches off the date
  casting Laravel would otherwise give `CREATED_AT`, so `logged_at` comes back a
  plain **string** and must be `Carbon::parse`d; calling `->toIso8601String()`
  on it directly is a 500.

Turning DTR **off is non-destructive** — existing sessions are kept and simply
stop counting, and turning it back on restores the figures intact.


#### A site's whole lifecycle: retire → restore → delete (added 2026-08-28)

`destroy` has always RETIRED rather than erased, for the reason above. What was
missing was everywhere that led. A retired site stayed in the same grid at 60%
opacity with **every action stripped off** (they were all inside
`v-if="site.is_active"`), so it became a permanently inert card: no way to undo
the retire, no way to remove it, and the list only ever grew. "Where does a
retired site go, and how do I delete one?" had no answer in the GUI at all.

Three additions close it:

- **`restore`** (`POST supervisor/dtr/geofences/{geofence}/restore`) — undoes a
  retire. **The token is untouched**, so QR codes already handed out start
  working again; that is the whole point, since re-creating the site instead
  issues a NEW token and silently kills every printed copy. Pinned by
  `test_a_retired_site_can_be_restored_and_keeps_its_token`.
- **`forceDestroy`** (`DELETE supervisor/dtr/geofences/{geofence}/permanent`) —
  the real delete, for a site created by mistake (a typo'd label, a fence
  anchored at home). **Two guards, both load-bearing:**
  1. **Retired first**, the same shape as the batch roster's
     archive-before-delete rule. A live site is one interns may be standing in
     front of right now.
  2. **Zero time records.** `dtr_sessions.geofence_id` is `nullOnDelete`, so
     deleting a used site does NOT remove the punches — it silently strips the
     location off every one of them, which is precisely the audit trail the
     geofence exists to produce. Refused with a 422 that counts them.
- **`sessions_count`** on every row of `index()` (via `withCount('sessions')`,
  one subquery, not a count per card). The SPA offers Delete **only** at zero,
  so a supervisor never meets a button that answers 422 — a used site shows the
  reason in the button's place instead.

GUI: retired sites moved out of the active grid into their own collapsed
**"Retired sites (N)"** section beneath it, each row carrying **Restore** and,
where allowed, **Delete permanently**. Retiring opens that section
automatically, so the card does not appear to vanish.

##### The confirmation ladder — three different weights, on purpose

This page now holds actions of genuinely different severity, and they are
deliberately NOT confirmed identically. The app-wide rule ("crucial actions
confirm first, `tone: 'danger'` for anything destructive") is the floor, not
the ceiling.

1. **Resize a radius — no confirmation.** It is instantly reversible from the
   same dropdown, and the change is visible in the control itself.
2. **Retire — `confirmAction`, danger tone.** Destructive in effect (the QR
   stops working that second) but fully recoverable. The copy now **says** it is
   recoverable and names where the site goes; the old wording read like a
   delete, which made a reversible action feel final and pushed supervisors
   toward creating duplicate sites instead.
3. **Restore — `confirmAction`, DEFAULT tone.** It is confirmed because it
   re-arms a QR code that may be posted somewhere, but it is not destructive, so
   it does not get the red treatment. Reaching for `danger` here would dilute it
   where it matters.
4. **Delete permanently — `promptAction` type-to-confirm, danger tone.** The
   only irreversible action on the page, and the only one where a single "are
   you sure?" is not proportionate: every other destructive control here is
   recoverable, so a supervisor's reflex on a red button in a familiar position
   is "yes". Typing the site's own name back makes the gesture **specific to
   that site** — the failure mode being defended against is deleting the wrong
   row, not deleting on purpose. Match is **case- and whitespace-insensitive**:
   the point is proving they read WHICH site, not testing their typing.

`promptAction` gained three additive options for this — `tone`, `validate`
(return a message to reject and keep the dialog OPEN with it inline) and
`multiline` (a short exact value gets a single-line `<input>`; a reason or
comment keeps the 3-row textarea the dialog was built for). Existing callers
pass none of them and are unchanged. **A failed validate must not close the
dialog** — a mistyped confirmation is a slip to correct, not a reason to make
someone start the action over.
### QR generation

`endroid/qr-code` **^6.0** (nothing QR-related existed before, not even
transitively). **Pinned to `^6.0`, not `^6.1`, and that is load-bearing: every
6.1.x release requires PHP `^8.4`, while `Dockerfile` runs `php:8.3-apache`.**
A `^6.1` lock built fine locally and then failed the Render Docker build
outright at `composer install` ("Your lock file does not contain a compatible
set of packages"). `composer.json` now also carries
`config.platform.php = 8.3.33` so local resolution matches the deployment
target and this class of mismatch cannot recur silently — **do not remove that
pin, and do not bump this package to 6.1 without first moving the Dockerfile to
PHP 8.4.**
Output defaults to **SVG**, which needs no GD and prints sharp at
any size — it stays sharp both on a supervisor's screen and on paper, whichever
they choose; `?format=png` is available. `ErrorCorrectionLevel::High` rather
than the library default `Low`, because a code that *is* printed picks up
scuffs, glare and torn corners.

### Progress and the dashboard

`StudentDashboardController` gains `progress.hours` —
`{minutes_completed, hours_completed, hours_required, hours_percent}`, or
**`null`** when the coordinator has DTR off. Null rather than zero is
load-bearing: the SPA renders the hours gauge only when it is non-null, and
falls back to the existing `ojt_duration_percent`. A zeroed gauge would read as
"you have done nothing" to a student with no way to clock in.
`weekly_reports_approved_percent` and `ojt_duration_percent` are **unchanged**.

Worth knowing when comparing the two: `ojt_duration_percent` is elapsed calendar
time and ticks up whether or not the intern ever turns up. `hours_percent` only
moves when they do.

### Whose record is this? — the account-identity guards

The QR opens in whichever browser the phone treats as default, on a handset that
may be shared, borrowed, or still signed in from last time. **A scan looks
identical whichever account is signed in**, so the punch can land on the wrong
student's record silently and undetectably. Four guards, all cheap:

- **`GET student/dtr/scan` returns a `student` block** (`name`, `username`,
  `student_id_number`). Free — `DtrService::enrollmentQuery()` already
  eager-loads `student.studentProfile`.
- **Both scan surfaces name the account BEFORE the button.** The landing page
  renders a "Recording as …" row with a **Not you?** control that signs out and
  returns to the same scan URL via `?redirect=`; the scanner's confirm card
  carries the same line.
- **The punch response echoes `student_name`**, shown on the success card, so a
  student who taps straight through still gets a receipt naming the account.
- Deliberately **not** done: re-entering a password per punch. Naming the
  account plus a one-tap switch is the control that actually gets used.

### Redirect-after-login is part of this feature, not incidental

`router.beforeEach` bounces an unauthenticated visitor to
`/login?redirect=<fullPath>`, and `LoginPage.vue` honours it (same-origin
relative paths only, so this cannot become an open redirect). Without it the QR
flow dead-ends: a printed code opens in whichever browser is default, very often
one with no session, and the student would log in to find the site token gone.

**THREE redirects must preserve the target, not just that one.** Each was a way
to silently eat the site token:

- **Role mismatch** (`to.meta.role !== user.role`) used to be a bare
  `return '/login'`. A phone signed in as a supervisor or coordinator opening a
  clock-in QR landed on a plain login form; signing in as the student then went
  to the dashboard with the scan lost and nothing explaining why. It now carries
  `{ redirect: to.fullPath, wrong_role: '1' }`, and `LoginPage.vue` shows an
  **amber notice, not an error** — nothing failed, and `errorMessage` is watched
  to shake the card. Read through `consumeQueryParam()` so a refresh cannot
  replay it. **No automatic sign-out**: replacing someone's session uninvited is
  not ours to do, and signing in through the form replaces it anyway.
- **Gated** (`student_gated`) and **paused** (`student_paused`) bounces from
  `/student/dtr/scan` carry `?from=scan`, and the destination says the clock-in
  was not recorded and why. The token genuinely cannot be honoured — the student
  cannot un-gate themselves — so an explanation *is* the whole fix; without it a
  scanned QR just becomes an unrelated page.

No guard loop is possible: `/login` carries no `requiresAuth`.

### Operational notes

- **Geolocation AND `getUserMedia` require a secure context.** `localhost` is
  exempt so normal dev works, but **testing from a real phone over
  `http://192.168.x.x` fails** — the page loads and the camera then refuses,
  because `navigator.mediaDevices` is undefined on an insecure origin.

  The verified recipe (used to test this feature on a real phone, 2026-08-20):
  1. `web/vite.config.js` already carries `host: true`, `strictPort: true` and
     `allowedHosts: true` for exactly this. Vite otherwise binds to localhost
     only and rejects an unrecognised Host header.
  2. `cloudflared tunnel --url http://localhost:5173` (install once with
     `winget install Cloudflare.cloudflared`). Tunnel **Vite, not Laravel** —
     Vite proxies `/api` onward, so the phone stays same-origin, which mirrors
     the deployed Vercel rewrite model.
  3. Put the printed `*.trycloudflare.com` host into **both** `FRONTEND_URL` and
     `SANCTUM_STATEFUL_DOMAINS`, then **restart `php artisan serve`** — it reads
     `.env` at boot, and skipping this produces the classic symptom: a 200 login
     followed by 401 on every request after it. The subdomain changes on every
     tunnel run.
  4. Open the tunnel URL in a **real browser** on the phone, never a chat app's
     in-app browser (see the WebView gotcha above).
  5. **Put `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` back to `localhost`
     afterwards** — a dead tunnel host left in `.env` breaks ordinary local dev.

  A LAN-IP alternative exists for **Android only**
  (`chrome://flags/#unsafely-treat-insecure-origin-as-secure`), and additionally
  needs an inbound firewall rule for 5173 on the *matching* profile — a hotspot
  network is categorised **Public**, so a `-Profile Private` rule silently does
  nothing. It does not work on iOS at all.
- **GPS indoors is poor.** Default radius 150m, and a capture worse than
  `CompanyGeofence::POOR_ACCURACY_METRES` (100m) is flagged back to the
  supervisor and to the coordinator. Warned, not blocked — a hard block would
  strand a company whose building simply has bad GPS, and a fence too tight
  rejects interns who are genuinely present, which is the worse failure.
- Demo: **both** demo coordinators (`mdccore`, `mdcbalbero`) seed with
  `dtr_enabled = true` so every role is testable end to end; the opt-out is
  shown by switching it off on the coordinator's own Daily Time Record page.
  **CHANGED 2026-08-28 — geofences ARE now seeded, for `mdcbalsup` only**
  (`CabmbSupervisorDtrDemoSeeder`, see Seeded demo accounts below). The old
  rule said none ever were, because a seeded fence is anchored to coordinates
  nobody is standing at and so cannot be used to actually clock in. **That
  reasoning is unchanged and still true — the seeded sites are for looking at,
  not for scanning, and testing a real punch still means creating your own site
  from your own location.** It simply was not the whole story: the site LIST is
  a real surface with real actions on it (resize, retire, restore, delete), and
  at zero seeded sites none of them could be seen at all.

## Reminders & Email

### Student-owned reminder preferences (the "alarm")

`student_profiles` carries `reminder_days` (comma-separated **ISO** day numbers,
1=Mon..7=Sun), `reminder_time` (`time`), and `reminder_enabled` (bool, default
true). All nullable/defaulted, so an untouched profile behaves exactly as before.

Resolution lives in `App\Support\ReminderSchedule` (`remindsOn()`/`hourFor()`),
deliberately a sibling of `App\Support\BatchWorkingDays`. **The split is the
whole point:** `BatchWorkingDays` answers "was work expected?" and drives
compliance; `ReminderSchedule` only answers "should we nudge?" and is the
student's own business.

**NOTHING but `SendMissingJournalEntryReminders` may ever read these columns.**
Bundling, the calendar, missing counts, coordinator dashboards and every report
must stay out — a student must never be able to redefine the yardstick their own
compliance is measured against.

Fallback chain: student's `reminder_days` → the batch's working-day pattern;
student's `reminder_time` → `batches.daily_reminder_time` → 21. `reminder_time`
is intentionally **uncast** (MySQL and SQLite return `time` columns in different
shapes) — callers parse it with Carbon.

Storage normalizes days sorted + deduped. **An empty `reminder_days` array stores
`null`, meaning "follow my batch", NOT "never remind me"** — switching reminders
off is what `reminder_enabled` is for, so a cell cannot be blanked into silence
by accident.

### The reminder command

`journal:send-missing-entry-reminders` is scheduled **HOURLY, not daily**,
because each student picks their own hour. The command decides whose hour it
currently is, and only the **hour** is honoured (minutes are ignored, not
rounded). `--ignore-time` treats everyone as due now, for manual/demo runs.

- **Dedupe is on (user, title, today)**, because `notifications` has no
  `entry_date`/`batch_id` column to key on. Without it an hourly schedule would
  nag a student once an hour all day. `self::TITLE` must therefore stay a **fixed
  constant** — the dedupe query keys on it, so it must never vary with the count.
- It writes `notifications.sent_at` **explicitly** rather than relying on the
  column's `useCurrent()` DB default: that default uses the *database* clock,
  which ignores Carbon test-time travel and would break the same-day dedupe under
  test.
- **One reminder per trigger covers the WHOLE WEEK's backlog, not just today.**
  Because the dedupe allows at most one contact per student per day, a message
  mentioning only today could never mention the rest. `missingDatesThisWeek()`
  derives every day from the later of (this week's Monday, the batch
  `start_date`) through today with no `submitted` entry.
- **The yardstick is `BatchWorkingDays`, deliberately NOT `ReminderSchedule`** —
  a student narrowing their own reminder days must not thereby shrink the list of
  entries they owe.
- **A student who submitted TODAY but left earlier days blank IS reminded.** A
  student is skipped only when `missingDates === []`.
- The derivation mirrors `StudentDashboardController::countMissingWorkingDays()`
  and `JournalCalendarController::statusFor()` so the email can never disagree
  with the number on the student's own dashboard.
- Days render `l, F j, Y` in mail and `M j` in the bell row, so a week spanning a
  month boundary reads correctly.
- Bullets are a literal `-` character inside separate `->line()` calls, **not**
  markdown dashes: each `->line()` renders as its own paragraph, so a markdown
  list marker would emit a one-item list per day.
- **`MissingJournalEntryReminder::inAppMessage()` is a public static shared by
  the notification and the command**, so the bell row and the email can never
  describe the same trigger differently.

### Email delivery is gated on a VERIFIED address, and is therefore dormant

Mail goes out **only** when `email !== null && email_verified_at !== null`; the
notification row's `type` reports what actually happened (`'email'` vs
`'in_app'`). Everyone else still gets the in-app bell row silently, which is the
normal case for a coordinator-created student.

This gate is specific to `MissingJournalEntryReminder` — `NewAccountCredentials`
(bulk import / the Credential Manager, see Intake & Enrollment above) is a
deliberate, narrower exception that mails an unverified coordinator-supplied
address directly, since account-creation time has no verified address to wait
for. Do not read this section as a blanket rule for every notification.

`toMail()` sends **from** the admin's System Settings `system_email` when set and
valid, falling back to `MAIL_FROM_ADDRESS` — both `MissingJournalEntryReminder`
and `NewAccountCredentials` resolve this identically via the shared
`App\Support\SystemMailFrom::resolve()` helper, so the two notifications can
never disagree about the sending address.

**`users.email_verified_at` is set by exactly ONE thing — the Google verification
flow.** A `migrate:fresh --seed` yields users with emails and **zero** verified,
so on fresh demo data nobody receives reminder email until they click "Verify
with Google". That is the intended gate, not a bug.

**CAUTION: the seeders use real-looking Gmail addresses.** The
`email_verified_at` gate is the only thing stopping a demo run from mailing a
real stranger's inbox, so **never let a seeder set that column.**

`.env.example` keeps **`MAIL_MAILER=log` as the committed default** so a fresh
clone can never accidentally email real students. `APP_NAME` must stay
`InternTrack` — it is user-visible in the mail header, the sign-off, the footer,
and `MAIL_FROM_NAME`.

### Mail transport

**Resend is the intended provider but is BLOCKED on not owning a domain.**
Laravel 13's `resend` transport is already present in `config/mail.php`;
switching is `composer require resend/resend-php` + `MAIL_MAILER=resend` +
`RESEND_API_KEY`, with **zero code change**. The blocker: Resend refuses to send
until a domain is verified via SPF and DKIM DNS records, and its shared
`onboarding@resend.dev` sender only delivers to the account owner.

Until a domain exists, the documented **Gmail app-password SMTP** recipe in
`.env.example` is the working path. **Gmail rewrites the From address to the
authenticated account**, so the System Settings `system_email` silently has no
effect under that transport (it works again under Resend) — use a dedicated
address, not a personal one.

**No queue worker is needed** — nothing implements `ShouldQueue`; the
notification sends inline. Deployments set `QUEUE_CONNECTION=sync`.

#### `php artisan mail:test <address>` — verify BEFORE importing a roster

`App\Console\Commands\TestMailConfiguration` sends exactly one message and
interprets the failure. It exists because the alternative way to discover dead
SMTP credentials is to bulk-import 40 students, have all 40 come back
`created_email_failed`, and then Resend each one individually.

- It deliberately sends the **real `NewAccountCredentials` notification**, not a
  throwaway string, so the test exercises the actual template, resolved from
  address and login link a student receives.
- It prints the resolved transport, host, SMTP user, from address (flagging
  whether it came from System Settings or `MAIL_FROM_ADDRESS`) and login link
  before sending — most misconfigurations are visible in that header alone.
- It maps the common failures to the actual fix rather than echoing Symfony's
  authenticator wall: SMTP **535 / BadCredentials** → regenerate the Google App
  Password; connection refused → host/port/firewall; **cURL error 60** → the
  Windows missing-CA-bundle gotcha; a scheme rejection → `MAIL_SCHEME` must be
  `null`, not `tls`, on port 587. `--raw` shows the unabridged exception.

**GOTCHA — a Google App Password silently dies.** SMTP 535 with a
*correctly-shaped* password (16 lowercase chars, unquoted, no spaces) does not
mean it was typed wrong: Google invalidates every app password when 2-Step
Verification is switched off, when the account password changes, or when the app
password is revoked. Regenerate at `myaccount.google.com/apppasswords`. The
symptom is indistinguishable from a typo, which is why `mail:test` names this
cause explicitly.

**Gmail SMTP can send to ANY recipient** — there is no allowlist and no
"only my own address works" restriction (that limitation belongs to Resend's
shared `onboarding@resend.dev` sender, which genuinely only delivers to the
account owner). If mail reaches you but not students, the cause is spam
filtering or dead credentials, not the recipient address. Free Gmail caps at
~500 recipients/day, which bounds a single bulk import. To prove
arbitrary-recipient delivery without mailing a third party, send to a
**plus-addressed** variant of your own inbox (`you+test@gmail.com`) — a
different recipient string that still lands in your own mail.

`NewAccountCredentials::toMail()` falls back to `'there'` when the notifiable
has no `name`, since `mail:test` routes it to a bare address
(`AnonymousNotifiable`) rather than a `User`.

## Password Reset — the student's own way back in

Built 2026-08-21. **Every endpoint below already existed and worked; what did
not exist was any way to reach them.** The SPA had no forgot-password page, no
link on the login form, and no route matching the URL the reset email carries —
so a student who never received their credentials had *no* self-service option
and had to find their coordinator. `password_reset_tokens` has been in
`create_users_table` since the beginning.

- **Pages**: `ForgotPasswordPage.vue` at `/forgot-password` and
  `ResetPasswordPage.vue` at `/password-reset/:token`, both public (marking
  them `requiresAuth` would bounce a locked-out user to `/login`, the one place
  they cannot get past). They share `components/auth/AuthCardShell.vue` — a
  still version of LoginPage's frosted card, deliberately without its
  pointer-tilt, entrance stagger and sheen.
- **The token path is `/password-reset/:token`, NOT `/reset-password`.** It has
  to match the URL `AppServiceProvider::boot()`'s `ResetPassword::createUrlUsing`
  builds (`{FRONTEND_URL}/password-reset/{token}?email=...`), and it
  deliberately differs from the API's own POST path so the deployed rewrite
  cannot swallow the page load. **Both halves of that URL are load-bearing** —
  `password_reset_tokens` is keyed by email, so the token alone identifies
  nothing.
- **The POSTs go to `/auth/forgot-password` and `/auth/reset-password`**, which
  the Vercel rewrite and the Vite dev proxy map back to the API's real
  `/forgot-password` and `/reset-password`. Exactly the `/auth/login`
  indirection and for exactly the same reason: `/forgot-password` is now also
  the SPA's own page route, and **rewrites match on path, never on method**, so
  proxying it wholesale would send a page load (GET) to Laravel, which only
  defines POST there.

### The exception handler had to change first, and it fixed a login bug too

`bootstrap/app.php`'s `shouldRenderJsonWhen()` **fully REPLACES** Laravel's
default `expectsJson()` check, and listed only `api/*`. The SPA's auth
endpoints are **web** routes, so every failure on them came back as a **302 HTML
redirect even for an XHR asking for JSON**. Success returned clean JSON; only
the unhappy path broke, which is why it went unnoticed.

It now also renders JSON for `login`, `logout`, `forgot-password` and
`reset-password` when the request asks for it. Listed **explicitly rather than
by prefix**: `auth/google/*` must keep rendering redirects, because those three
routes genuinely ARE top-level browser navigations.

Consequences, both real:

- Without it a reset form had no way to show "we can't find a user with that
  email address" or that a token had expired.
- **`LoginPage.vue` was a blanket `catch { 'Invalid credentials.' }`** — not
  merely lazy, since there was no message to read. Both halves are fixed, and
  the difference matters most for a **deactivated account**: `LoginRequest`
  rejects it with its own reason, but the student was told their password was
  wrong, and so went and asked for a credentials resend that could not possibly
  help them.

`/forgot-password` and `/reset-password` are **`throttle:6,1`**. The `api`
group's limiter does not cover web routes, and the password broker's own
throttle (`config/auth.php`, 60s) only rate-limits repeats of the SAME address —
it does nothing about a caller walking a list, which both mails real students
and reports back whether each address is on file.

Coverage: `tests/Feature/Auth/PasswordResetTest.php`, which pins the emailed
URL's shape, the reset round trip, token replay, the throttle, and above all
that these routes answer in **JSON** rather than redirecting.

## Google OAuth — email verification + link-only sign-in

`laravel/socialite`, via `App\Http\Controllers\Auth\GoogleController`. Two
intents through **one** controller and **one** callback (so there is only ONE
redirect URI to register in Google Cloud).

- **`intent=verify`** — a signed-in user of any role proves they own a Gmail
  address. **Google SUPPLIES the address**; it is not confirming one the user
  typed, so the flow writes both `users.email` and `users.email_verified_at` in
  one step and a typo is impossible. Rejected if Google reports the address
  unverified, or if it already belongs to a different account.
- **`intent=login`** — signs in an account matched on `LOWER(email)` **AND**
  `email_verified_at IS NOT NULL`. **Both halves are the guard.** Because this
  controller is the only thing that ever sets that column, non-null means Google
  established the binding — so an address merely typed by an admin or student can
  never be signed into. **It NEVER creates an account and never assigns a role**;
  an unrecognised address gets `not_linked`, a deactivated one gets
  `deactivated`.

**Why it is safe without Socialite's session state:** the flow uses
`->stateless()` with an **encrypted state** (`Crypt::encryptString` of
`{intent, user_id, nonce, expires_at}`, 10-minute TTL) plus the same `nonce` in a
short-lived httpOnly `SameSite=Lax` cookie, compared with `hash_equals` on
callback. The cookie double-submit replaces the CSRF protection `stateless()`
gives up — including *login* CSRF, where there is no user yet to bind state to —
and, unlike a session, cannot be lost on the round trip once the SPA and API are
on different domains. `SameSite=Lax` is deliberate: it still rides the top-level
GET redirect back from `accounts.google.com`.

**Both flows send `prompt=select_account`, and that is a security control, not a
preference.** Without it Google *silently* auto-selects when exactly one account
is signed into the browser — so on a shared machine (computer lab, borrowed
laptop) a student clicking "Sign in with Google" would be signed straight in as
whoever last used the browser, with no password and no prompt. The verify flow
needs it equally: silent selection there binds someone else's Gmail onto your
account, and since a verified address authorises Google sign-in, that grants them
a permanent way in. Deliberately Google's own chooser rather than a confirmation
screen of ours — only Google can truthfully list the available accounts.

**Routes are WEB routes** (`routes/web.php`), not `api/*`, because all three are
top-level browser navigations: `GET auth/google/verify` (`auth`),
`GET auth/google/login`, `GET auth/google/callback` (no auth — the encrypted
state authenticates it). All three are `throttle:10,1`, since the `api` group's
limiter does not cover web routes.

**Two redirect-target rules, both learned the hard way — do not "simplify"
either back:**

1. **`auth/google/login` must NOT carry the `guest` middleware.** That middleware
   redirects an already-authenticated user to `/`, which on the API origin is the
   Laravel root route — a dead end on a different host from the SPA.
   `redirectToLogin()` handles the already-signed-in case itself.
   **Fixed 2026-08-23: the plain username/password form had the identical bug**
   and was the actual root cause of a reported "login just sits there, nothing
   happens" report — `POST /login` (`routes/auth.php`) carried `guest` too, so a
   browser with a still-valid session (SESSION_LIFETIME is 120 minutes, and the
   SPA never notices — see the Gotchas entry below) silently got the SAME `/`
   JSON body back instead of `{user: ...}`, `auth.ts` set `this.user = undefined`,
   `roleRedirect(null)` resolved to `/login`, and the router pushed to the page
   it was already on — no error, no navigation, which reads exactly like a stuck
   button. `guest` is now removed from `/login`, `/forgot-password` and
   `/reset-password` for the same reason; none of the three controllers assume a
   guest, so nothing else changed. This is also why the "log in with Google
   signs me in directly, no Google screen" half of that same report was **not**
   a bug — it is `redirectToLogin()`'s documented short-circuit above, correctly
   firing because the browser genuinely still had a valid session.
2. **Every success redirect goes to `/{role}/dashboard`, NEVER to `/`.** The
   SPA's root route is an unconditional redirect to `/login`, and the router
   guard only calls `fetchUser()` for routes marked `requiresAuth` — so a browser
   landing on `/` with a perfectly valid session never asks the server who it is
   and gets bounced to the login page. The symptom is a successful sign-in that
   *looks* like it failed. `dashboardPathFor()` mirrors `roleRedirect()`.

**Editing an email revokes verification, and therefore Google sign-in** —
`ProfileController::update()` and `Admin\UserController::update()` both null
`email_verified_at` whenever `email` actually changes (via `forceFill`, since it
is deliberately not mass-assignable). This is also the un-link mechanism; there
is no separate "unlink Google" action.

**Deliberately NOT done: the `MustVerifyEmail` interface is not added to
`User`.** It is unnecessary (`markEmailAsVerified()` already exists via the
trait) and would activate the framework's verification listener. The `verified`
middleware alias stays applied to **zero** routes: **verification gates email
delivery and Google sign-in, never app access** — coordinator-created students
have no email at all, and a `verified` gate would lock out exactly the population
intake produces.

**With the credentials blank, `startFlow()` short-circuits to `not_configured`**
and the SPA says so. This guard is load-bearing, not defensive padding: without
it Socialite still builds a valid-looking redirect carrying an **empty
`client_id`**, and Google answers with its own "Access blocked" page, stranding
the user outside InternTrack with no route back. Scopes are `email` + `profile`,
both non-sensitive, so publishing needs no Google app review — but while the
consent screen is in **Testing**, each tester's Gmail must be added under Test
users.

Frontend: `web/src/lib/googleAuth.ts` holds the two entry-point URLs, the error-
code-to-copy map, and `consumeQueryParam()` (reads a one-shot return param and
strips it via `history.replaceState` so a refresh cannot replay the banner).
`web/vite.config.js` proxies `/auth/google` so dev stays same-origin.

## Reports

All three coordinator report editors share one shape: candidate rows are derived
from live data, the coordinator curates them, and **curation persists as an
override layer in a JSON column — source rows are never mutated.** Each stores
per-row overrides keyed by source id, `manual_rows[]` for missing data,
`deleted_ids[]` tombstones, and include/exclude. Only *included* rows export.

- **Annual SIPP Report** (`AnnualSippReportController`) — **per-program**, one
  tab per program in scope. Rows are sourced one-per-daily-journal-entry from
  students' `sipp:true` fields, joined to `batches` by `program_id` +
  `academic_year`. Persists in `sipp_annual_reports.report_data`, keyed by
  coordinator + program + AY. Annex **"C"**.
- **HTE & Student Interns List** (`HteReportController`) — a single **combined
  list per academic year** covering all programs in scope (not per-program tabs).
  Rows are one-per-enrollment from `batch_students`, mapped to 5 official
  columns: host establishment, student name ("Last, First M."), program (code +
  year level, e.g. `BSIT-4`), gender, and duration. Persists in
  `hte_reports.report_data`. Annex **"D"**.
  - The PDF **merges Host Establishment cells with `rowspan`** across consecutive
    same-company rows (`withHostEstablishmentSpans()`). The on-screen editable
    table is deliberately left one row per input — merging free-text-editable
    cells is not meaningful the way it is on a read-only printed table.
- **GROUP Student Information Sheet** (`GroupInfoSheetController`) — the
  per-company companion to the individual sheet, **filtered by COMPANY**. Persists
  in `group_info_sheets`, keyed `UNIQUE(coordinator_id, company_id, academic_year)`.

### The two SIPP annexes are measured facsimiles too (2026-08-30)

Annex "C" and Annex "D" were the last two official documents still estimated
rather than measured. Their references are **Word files**, not PDFs —
`docs/reference/ANNEX C - SIPP REPORT (2).docx` and
`ANNEX D - SIPP REPORT (1).docx` — so the geometry was read out of each
`word/document.xml` (Word stores lengths in **twips**, 1/20 pt) and now lives in
**`App\Support\SippAnnexLayout`**, the same shape as `ExitInterviewFormLayout`.

| measurement | reference | what the blades did before |
|---|---|---|
| page | 18711 x 12242 twips **landscape** = 935.55 x 612.1pt | Annex C: **no `setPaper()` at all** → dompdf's A4 **portrait**; Annex D: A4 landscape |
| margins | 1440 twips = 72pt all round | `2cm 1.8cm` / `1.6cm 1.4cm` |
| body type | 12pt | **12`px`** (~9pt) in Times New Roman |
| rules | `w:sz 4` = 0.5pt | 1px |
| cell padding | 108 twips = 5.4pt L/R | 5-8px all round |
| Annex C cols | 6769 / 5225 / 3513 twips | equal `33.33%` thirds |
| Annex D cols | 4957 / 3827 / 1797 / 1888 / 2753 twips | percentages by eye |

**The page is the SAME long bond the exit interview prints on, turned
landscape.** On A4 portrait the three-column Annex C table had 462pt of usable
width instead of 792pt — every column ~40% too narrow, wrapping to a shape the
real form never has. The portrait box is passed to `setPaper()` and
`'landscape'` is what swaps it; passing the already-swapped box would rotate it
back.

- **The type is a SUBSTITUTION and is documented as one.** The reference's theme
  font is **Aptos** (Office's current default) — proprietary, absent from the
  Linux image, not redistributable. **Carlito** already ships here (SIL OFL) and
  is registered for the weekly activity log, so it stands in at the same nominal
  12pt. Carlito is metric-compatible with *Calibri*, **not** with Aptos; this is
  the closest licensable face already in the project, not a metric match.
- **`App\Http\Controllers\Concerns\RegistersCarlitoFonts`** now owns that
  registration, extracted from `BuildsWeeklyActivityLogPdf` (which delegates to
  it) so the three documents cannot drift on the two details that bite:
  registration happens **in PHP, not `@font-face`** (a CSS `url()` to a local
  `.ttf` does not survive a Windows drive-letter path), and it is **best-effort**
  so a missing file degrades the type instead of 500-ing the download. Font
  subsetting is switched on per instance — a filled annex is ~24KB.
- **Column widths ride on the header row**, the only row with no `rowspan`, and
  are written **content-box** (measured width minus 10.8pt of padding). dompdf
  ignores `<colgroup>`, ignores a width on a spanning cell, and
  `table-layout: fixed` distributes columns equally regardless — the same three
  workarounds the GROUP info sheet documents.
- Annex D's Host Establishment column still merges with `rowspan`; that is why
  its widths must sit on the header row rather than the first data row.

Coverage: `tests/Feature/Coordinator/SippAnnexPageGeometryTest` asserts the
MediaBox on both downloads plus the raw measurements. **Verified to genuinely
fail** when `setPaper()` is removed — it reports
`MediaBox [0.000 0.000 595.280 841.890]`, i.e. A4 portrait. Every other test on
these endpoints passed the whole time the pages were wrong, because a 200 with a
valid PDF was all they checked.

Both official report PDFs use a clean/white table header (no gray fill) and a
"(Name and Signature)" caption under each signatory block.

### GROUP Info Sheet — settled decisions

- **Band 1 (the roster) is REUSED student data**; **Band 2 (the company block) is
  typed by the COORDINATOR, never pulled from any one student's sheet** — a dozen
  students at one company each type their own address/signatory/supervisor and
  those disagree, so the coordinator's copy is the single source of truth.
- **An override only counts when it is NON-EMPTY** (`trim(...) !== ''`), unlike
  the HTE shape. An empty string is not null, so the HTE pattern would let a
  single Save taken while a student had not yet filled a field freeze that blank
  permanently. Here a blank saved cell means "no edit" and the student's own
  sheet keeps flowing through. Trade-off: a cell **cannot be blanked** — use
  include/exclude or delete to drop a row.
- **The roster GROWS** — no 12-row cap and no per-page repagination. The
  reference form's 12 pre-printed rows are a paper artifact, not a limit.
- **Scope**: a company with **zero** in-scope interns for that AY **403s** rather
  than rendering an empty sheet; the company picker only offers companies that
  actually host in-scope interns.
- **`MI` is the middle name's first letter, capitalized, no trailing period**
  (`mb_*` throughout so a non-ASCII initial survives; a `\p{L}` match skips
  leading punctuation, so `" de la Cruz"` yields `D`, not a space). Sourced from
  the student's own sheet first, falling back to `student_profiles`.
- **`Program & Year` renders as short code + prettified year (`BSIT 4th Year`)**
  — the group table's column is far too narrow for the full program name the
  individual sheet prints. The individual sheet's own rendering is unchanged.
- **The roster draws from `active` + `completed` enrollments**, excluding
  `dropped` and any row with `archived_at` set — a graduated intern was still
  genuinely hosted there, a dropped one was not.
- **NO logo and no annex letter.** The reference PDF contains zero images, and
  that absence is exactly what lets a full 12-intern roster, the company block
  and the sketch box share one page. The individual sheet keeps its logo.
- The department header line is coordinator-editable, defaulting to the literal
  `College of Accountancy, Business and Management` (the reference's own
  wording). It is **not** derived from `departments.name`, which is seeded to the
  same short string as the code (`CABM-B`) and would print wrongly.

### GROUP Info Sheet PDF — measured facsimile

`resources/views/pdf/info-sheet-group.blade.php` is pixel-matched to the client
reference. **The numbers below were extracted from it — do not "tidy" them.**

- **Page: US Letter 612x792pt**, set via `->setPaper('letter', 'portrait')`.
  dompdf defaults to **A4**, which silently shifts every column. This is the
  single most important line in the trait.
- **Type: Arial (dompdf maps to Helvetica, metrically identical).** Every heading
  and field label is **bold**; only row numbers and the sketch caption are
  regular. Sizes: header block 10pt, labels 9pt, the signatory label 8pt, row
  numbers 10pt, footer 11pt, roster data 8pt.
- **Colour**: section bars are **`#1F3864`** with white 10pt text. The sketch box
  is stroked **1pt navy**, not a black hairline — the one element stroked rather
  than filled. Everything else is pure black on white.
- **Only the roster is a bordered grid** (0.4pt black). The company block is a
  **borderless fill-in form** with a rule under each *answer* cell only, and no
  rule under either label column.
- **Row numbers are LEFT-aligned** — "1" and "10" both begin at the same x on the
  reference, which only holds for left alignment.
- **Four dompdf workarounds, each load-bearing:** (1) dompdf ignores `<colgroup>`
  and any width on a cell carrying `rowspan`/`colspan`, and `table-layout: fixed`
  distributes columns equally regardless — so both tables open with an invisible
  zero-height sizer row carrying the widths, under **auto** layout. (2) Those
  widths are **content-box**: dompdf adds padding on top, so each is written as
  *target minus horizontal padding*. (3) Section bars are `div`s **outside** their
  tables; as a `colspan` first row they defeated column sizing entirely. (4)
  Roster data cells are `white-space: nowrap` — a contact number is fractionally
  wider than its column, and the resulting wrap pushed a 12-intern sheet onto a
  second page.
- **Footer "Page N of M" is stamped via `$dompdf->getCanvas()->page_text()`, not
  CSS** — `counter(pages)` renders as `0` because dompdf does not know the total
  until layout completes. The trait calls `render()` before stamping, and
  `download()` reuses that render.
- **Capacity**: a full 12-row roster + company block + sketch box fits on **one
  page**. Re-check after any spacing change.

### PDF blades are standalone — do not port them onto `pdf/layout.blade.php`

Every official PDF in the project (daily journal, weekly log, Annual SIPP, HTE,
both info sheets) is standalone. `pdf/layout.blade.php` still exists but is
extended by **no** view; its base contradicts the measured forms on every axis
(font, colour, margins, and forced title elements).

## Public landing page

`/` renders `web/src/pages/LandingPage.vue` rather than redirecting to `/login`.
It is the only **marketing** surface in the app; every other public route
(`/login`, `/forgot-password`, `/password-reset/:token`) is functional.

- **Eagerly imported — the ONLY statically-imported page in
  `router/index.ts`.** It is the first paint for an unauthenticated visitor at
  the bare domain, so a dynamic import costs a second round trip (entry chunk,
  THEN the page) before anything renders. Measured, not assumed: lazy is a
  41.20 kB gzip entry + a 6.26 kB page chunk + a 0.44 kB CSS chunk; eager is a
  47.12 kB entry and nothing else — ~5.9 kB gzip added to every other page load
  in exchange for that round trip. Re-measure before reverting.
- **A signed-in user visiting `/` sees the landing page, not their dashboard,
  and that is deliberate.** Bouncing them needs `auth.user` populated, but
  `beforeEach` only calls `fetchUser()` for `requiresAuth` routes — so a bounce
  would fire on in-app navigation and silently NOT fire on a cold load with a
  perfectly valid session (the same hazard documented under Google OAuth's
  "never redirect to `/`" rule). Closing that gap with a blocking `fetchUser()`
  would put an API round trip in front of every public visitor's first paint.
  **This is not a regression**: `/` already landed a signed-in user on the login
  form, because `LoginPage.vue` has never redirected an authenticated user.
- **The preview cards are illustrations, not dashboards.** The hero's stat grid
  and the Daily Time Record panel are static markup. The rule that dashboards
  render only fields the API returns governs the four REAL dashboards — this
  page has no session to read from. Both carry `aria-hidden`, so a screen reader
  is never told these are the visitor's own numbers. **Do not wire either to an
  endpoint.** The one figure that is real is **486**, the SIPP requirement the
  seeders write into `batches.required_hours`.
- **Animation is shared with `LoginPage.vue`, not reimplemented.** `.reveal`,
  `bg-drift` and `.blob-a/.blob-b` are the same mechanism, including the inline
  `--d` custom property that lets the sub-`lg` media query halve the stagger (a
  stylesheet cannot override an inline `transition-delay`, but it can re-derive
  from a custom property). `.on-scroll` extends the same grammar below the fold
  via ONE shared `IntersectionObserver` that unobserves on entry, so a section
  never re-animates on a second pass.
- **Reduced motion is a single switch**: one `prefers-reduced-motion` block
  stills every animation, and the observer is skipped entirely rather than being
  neutralized in CSS after the fact.
- **Colour follows the existing rules.** Exactly one filled blue button per
  viewport — the nav CTA and the hero CTA never share one, since the hero's
  scrolls away before the roles section arrives. Only the four sanctioned
  accents appear, in the established order (blue neutral, emerald good, amber
  waiting, rose needs attention).
- **The three role links in the nav target INDIVIDUAL role cards**
  (`#role-student`, `#role-supervisor`, `#role-coordinator`), not the `#roles`
  section. The source design lists four nav links against only three content
  sections, so pointing them all at `#roles` would ship three controls doing the
  identical thing. Each card carries its own id plus `scroll-mt-24` (6rem, which
  clears the `h-19`/76px sticky header).

Source design: Figma file `IcDGFFr5XSdfR96m1GRKj7`, frame `Landing — Desktop 1440`;
the translation table from Figma variables to Tailwind tokens is
`docs/LANDING_PAGE_HANDOFF_1.md`. Gradient stops cannot bind to Figma variables,
so the hero and closing band carry raw hex in Figma matching `blue-900` /
`blue-800` / `teal-500` — in code they are the `bg-linear-to-br from-blue-900
via-blue-800 to-teal-500` class `LoginPage.vue` already ships.

**KNOWN DEVIATION from the design: the footer's `Privacy` and `Support` links
are NOT built**, because neither page exists and a landing page linking to
nowhere is worse than one that does not offer the link. The footer ships the
brand block plus a `Sign in` link instead. Add them when there are real
destinations.

Two other gaps carried over from the handoff, neither blocking: there is **no
mobile Figma frame** (responsive behaviour below `lg` follows `LoginPage.vue`'s
breakpoint as a code-side judgement call), and **`teal-50` is used once** (the
Geofence chip) without being in the Figma token collection.

## Role Surfaces

### Admin

Creates accounts (`Admin/UserController`) and global structure (departments,
programs, companies). `Admin/UserController::index()` **unconditionally excludes
`role = 'admin'`** regardless of filter — the page manages day-to-day accounts,
not the admin account itself. Create Coordinator collects First/Middle/Family
name and takes a single `department_id`.

Departments carry an optional `dean_name`. The audit log deliberately **does not
record `Logged In`/`Logged Out`** (routine session noise drowned out real
events); it does record batch and roster transitions, info-sheet accept/reject,
enrollment, weekly-journal approve/return, and journal submission via
`SystemLog::record()`.

#### Programs are admin-managed, not hardcoded (2026-09-08)

`Admin/ProgramController` gained **`store`** and **`update`**
(`POST admin/programs`, `PUT admin/programs/{program}`) with
`StoreProgramRequest` / `UpdateProgramRequest`. Until this, the controller was
read-only and its own docblock said "the 7 programs are fixed at seed time" —
so a department the admin created had no way to be given a single program, and
the only route to one was editing `DepartmentProgramSeeder` and re-seeding.

**This RESTORES something that was deliberately removed.** Program CRUD existed
until commit `23da1f9` (2026-07-12), which stripped it along with the admin's
batch CRUD. That was a scope decision, not a technical blocker, and it is
reversed at the project owner's request. `ProgramControllerTest`'s
`test_store_and_update_routes_no_longer_exist` (which asserted 405 on both) is
gone with it — it pinned the absence of the feature.

**Two surfaces, and the department one is the primary:**

- **Admin → Departments → View → Programs** carries **"+ Add Program"**. A
  program is created inside the department it belongs to, so the department is
  *context* rather than another field to get wrong. Its modal is **`z-60`**, not
  the app's usual `z-50`, because it opens over the department detail modal.
  On success it **re-fetches the department detail** rather than pushing the row
  in by hand — that table carries per-program intern tallies the create response
  cannot know — and reloads the list behind it, whose rows carry `programs_count`.
- **Admin → Programs** carries the same action with a department picker, plus a
  per-row **Edit**. Creating while a department filter is applied seeds the
  picker with it (a default, not a lock). The Actions column is pinned at
  **165px**, measured against the two real buttons (View ~59px + 8px gap + Edit
  ~54px + the cell's own 32px of `px-4`), not against its heading.

**The rules, each deliberate:**

- **`code` is unique WITHIN a department, never globally** — mirroring the
  table's own `UNIQUE(department_id, code)`. Departments are independent
  top-level units, so two of them may legitimately run the same code; validating
  globally would refuse a legal program, and not validating at all would surface
  the index violation as a 500 instead of a 422.
- **`code` IS editable, unlike a department's** — a deliberate difference.
  Nothing in `app/` resolves a program by code (batches, users and templates all
  key off `program_id`); only the demo seeders do, and they run against a fresh
  database. A mistyped code must stay fixable, because the alternative is
  deactivate-and-recreate, which strands every batch pointing at the original row.
- **`department_id` is NOT accepted by `update`** and is disabled in the form.
  Re-parenting a program would hand every batch and intern under it to another
  department's coordinators in one silent write — a migration of live records,
  not an edit to a reference row.
- **There is no delete, matching the app's soft-deactivation posture.**
  `batches.program_id` and `journal_templates.program_id` are `cascadeOnDelete`
  and `batch_students` cascades from `batches`, so deleting a used program would
  take its batches, enrollments and journals with it. `is_active` is the control
  — though note it is **display-only today**: nothing in the app filters on it.

**CACHE INVALIDATION IS THE LOAD-BEARING PART, and the pre-2026-07-12 version
did not have it** (the caching layer landed after that code was removed).
`ProgramController::forgetCachesFor()` drops three things on every write:
`reference:programs`, `reference:departments` (its rows carry `programs_count`),
and — the one that matters — **`coordinator-program-ids:{id}` for every
coordinator of that department**. `User::coordinatorProgramIds()` resolves to
every program in the coordinator's department and caches it for a **DAY**, so
without this a newly-added program is invisible to the very coordinator who has
to build a batch for it, with the database perfectly correct the whole time.
Verified both ways: with the clause removed
`test_a_new_program_is_immediately_in_its_coordinators_scope` fails reporting
**0 programs in scope**, and in a browser `mdccore`'s Create Batch picker showed
a program added seconds earlier.

**`DepartmentProgramSeeder` IS NOW ADDITIVE, and that had to change first.** It
pruned — deleting every department outside its hardcoded list and, inside each,
every program outside its list. That was safe only while nothing could create an
eighth program. With admin-created programs it is unrecoverable data loss on a
live install, via the cascade above, and the trigger is the *documented* way to
correct reference data: `db:seed --class=DepartmentProgramSeeder`, exactly what
was run on 2026-09-08 to fix the department names. Both prunes are gone.
**`migrate:fresh --seed` was never affected** — it starts from an empty database,
so the prunes were always no-ops there, which is precisely why the hazard was
invisible. Pinned by
`test_an_admin_created_program_survives_a_reference_re_seed`, verified to fail
against the old seeder.

### Coordinator

All pages are department-scoped via `User::coordinatorProgramIds()`; out-of-scope
403s.

- **Dashboard** — active-intern count, journals submitted vs missing this week,
  active batches, and a "students behind" list.
- **Journal Activities** — read-only monitoring, default today, filterable by
  `from`/`to` date range + company + program + status. A `show` endpoint returns
  one student's full entry for a day (every section label + the text), keyed the
  same way the student write page keys `journal_entries.content`.
- **Partner Companies** — scoped CRUD where scope = companies used by the
  coordinator's students **plus companies not yet linked to any enrollment** (no
  creator column exists, so unlinked implies visible, keeping freshly-created
  companies in view). Includes the representatives and supervisor-login panels.
  **"Department" is a plain informational text field** (2026-09-11, corrected
  at the project owner's request) bound directly to `companies.industry` — it
  used to render as a dropdown of business-sector suggestions (with an
  "Other…" escape hatch) that could read as a constrained choice tied to the
  college's own Departments, when it is actually free text that gates nothing.
  Now a plain input mirroring the adjacent "Department Head" field exactly,
  with a caption stating it is descriptive-only. **Create/Attach Supervisor
  now toast on success** (they used to succeed silently) — wording matches the
  Users page's own supervisor-creation flow exactly (echoing the assigned
  username when one was auto-generated). See "The supervisor is tied to the
  company" above for the "Attach Existing Supervisor" scoping fix from the
  same pass.
- **Student Info Sheets** — the Accept/Reject queue; defaults to **All** statuses.
- **Journal Review** (`/coordinator/journal-review`) — the coordinator's OWN
  approve/return queue plus each intern's full notebook, for
  **coordinator-centered batches only**, filterable by batch, company and
  status (status defaults to **Pending**). The only coordinator surface that
  writes a review verdict. **Its nav item does not exist for a coordinator with
  no coordinator-centered batch**, and the route bounces to the dashboard. See
  OJT Type above.
- **Student Exit Interviews** (`/coordinator/exit-interviews`) — every
  in-scope intern's exit interview, filterable by program / status / name,
  with a per-row and in-modal **Download PDF**. Read the fourteen answers and
  fill the coordinator's own compliance block; there is **no accept/reject**,
  because an exit interview gates nothing. A second tab, **Summary Report**,
  gathers every intern's answer to each question together instead of one row
  per student — no PDF, no curation, drafts excluded. See Exit Interview
  above.
- **Weekly and Time Log Summary** (`/coordinator/weekly-time-logs`) — read-only
  list of every in-scope intern's MDC Weekly Activity Log sheet, with a per-row
  and in-modal **Download PDF**. See Weekly Activity Log above; there is no
  approval step on this form and no write action here.
- **Daily Time Record** (`/coordinator/dtr`) — **the only place the DTR on/off
  preference is set**, plus read-only hours-vs-required per intern and a
  **Sites** tab listing where each company's QR code is anchored so a fence
  generated somewhere other than the workplace can be spotted. With the DTR off
  the page explains the consequence and captures WHY instead of rendering an
  empty table. No adjust/void here; corrections belong to supervisors.
  **Its nav item does not exist for a coordinator with no SUPERVISOR-SUPPORTED
  batch** — the DTR runs on no other kind — and the route bounces to the
  dashboard. That is the OPPOSITE condition to Journal Review's, not the same
  one; see OJT Type above.
- **Users page** (`/coordinator/users`) — a secondary nav with an **Interns** tab
  (every in-scope student regardless of enrollment, each badged ENROLLED /
  NOT ENROLLED) and a **Supervisors** tab. With **no `created_by` column** on
  `users`, "supervisors the coordinator created" is realized as supervisors
  attached to any company in the coordinator's company-scope. Header actions are
  tab-contextual. "Create Supervisor" **requires a company first** — a supervisor
  is always a Company Supervisor. The Interns tab also has **"Bulk Import
  (Excel)"** (see Intake & Enrollment above). Both tabs' row actions are **View
  and Delete only** — the old per-row **"Resend"** moved to the Credential
  Manager in the profile popover on 2026-09-08 and must not come back here; see
  Credential Manager under Intake & Enrollment for why. The Supervisors tab
  gained its own View/Delete on 2026-09-11 — see Permanently deleting a
  supervisor account, below.
- **Batch roster management** is separate from the enroll flow, scoped by batch
  program. Adding a student who is already active in another batch **MOVES** them
  (old row dropped, new active row, behind a wrong-batch-guard confirm).

Coordinators can create **student or supervisor** accounts only — never
coordinator/admin.

### Supervisor

Gated by a `role:supervisor` route group, scoped by company (see
`ScopesSupervisorWork`). The core action is reviewing the weekly narrative,
from either of two cuts of the same data: **Journals** is the cross-intern
review queue (one status at a time, most recently submitted first), and the
**Journals** action on each My Interns row opens that intern's whole
notebook — every week they have handed in, front to back (see The per-intern
journal notebook above). They also own the **Daily Time Record** surface —
generating their company's clock-in QR codes, managing those sites through
their whole life (resize · retire · restore · permanently delete, see A site's
whole lifecycle above), and correcting the punches those codes produce.

### Student

Dashboard, journal calendar, write daily journal, my journals, weekly journals,
**Weekly and Time Log Summary**, **Daily Time Record** (only where the batch
coordinator enabled it), info sheet, and — last in the nav, as it is last in
the placement — the **Exit Interview**. The Student Dashboard is real, not
mock data — it returns submitted
counts, weekly logs approved/pending, this-week missing working days, two
completion-progress percentages, the student's own last-5 `SystemLog` rows, and
internship details.

Two facts about the dashboard week data that are easy to get wrong:

- **`week.end` is TODAY, not Sunday**, so the week strip spans Monday-to-today
  and grows from 1 to 7 segments across the week.
- **`missing_this_week` counts missing WORKING days** (it honours
  `working_days_per_week`) while the calendar-day count does not, so on a Mon-Fri
  batch viewed on a Saturday the two denominators genuinely differ.
- `progress.weekly_reports_approved_percent` is an approval rate over *weeks
  elapsed*, **not** the approved/pending split — do not fold it into a donut of
  those two counts; it would misreport.

KNOWN ISSUE: `Coordinator/CoordinatorDashboardController`'s
`journals_missing_this_week`/`students_behind` stats query
`JournalEntry::whereIn('status', ['missing','overdue'])` directly against the DB
column — which only ever holds `draft`/`submitted`, so **those two numbers are
silently always zero**. Fix by deriving them the way
`StudentDashboardController::countMissingWorkingDays()` does. Not yet done.

### Profile — a popover, not a page

There is **no `/{role}/profile` route**. The header avatar opens
`ProfileMenuPopover.vue`, which drills the same panel into Edit Profile / Change
Password / Activity Log / Log Out (a local `view` ref, not a route). Backed by
`ProfileController` — deliberately **not** role-namespaced, since the behavior is
identical for all four roles — exposing `PUT /api/profile`,
`PUT /api/profile/password`, `POST`/`DELETE /api/profile/photo`, and
`GET /api/profile/activity` (scoped to `system_logs.user_id = auth()->id()`).

- **Log Out is confirm-first**, default (blue) tone, not `danger` — nothing is
  destroyed, but a mis-tap on the avatar menu should not sign someone out.
- Students additionally get **Reminder Settings**; coordinators additionally get
  **Credential Manager** (2026-09-08 — see Intake & Enrollment above). Both are
  gated on `auth.user.role` in `ProfileMenuPopover.vue`, which is the whole
  mechanism: there is no per-item permission system here.
- **The Credential Manager is the ONE deliberate exception to the "a setting
  belongs beside the thing it governs" rule below**, and it is not really a
  setting — it is a critical, irreversible action deliberately kept OFF the list
  page whose rows it acts on, precisely so it cannot be mis-tapped while
  browsing. Do not read it as licence to move a *setting* back here.
- There is deliberately **no DTR item any more** — the coordinator's on/off
  switch moved onto `/coordinator/dtr` on 2026-08-20 and `DtrSettingsPanel.vue`
  was deleted. A setting belongs beside the thing it governs; do not put it
  back here.
- **Forced password change is popover-owned, not router-owned**: the popover
  watches `must_change_password` and force-opens into the password view with no
  back arrow, no outside-click dismissal, and a dimming backdrop, auto-closing
  once the flag clears. There is no page to redirect to.
- **The outside-click handler must use `event.composedPath()`, not
  `rootRef.contains(event.target)`.** Selecting a menu item unmounts the clicked
  button before the click finishes bubbling, so `event.target` is already
  detached and `contains()` always reads false — the popover slammed shut on
  every selection. It also returns early when the path contains a
  `role="dialog"`, since `ConfirmHost` teleports to `<body>` and its Cancel would
  otherwise read as an outside click.

### Notifications

`GET /api/notifications` (paginated + `unread_count`), `POST .../read-all`,
`POST .../{notification}/read` (403 unless owned), `DELETE /api/notifications`
(clear all, own rows only). `NotificationBell.vue` polls the unread count every
60s and shows the numeric count (capped display `9+`).

**`notifications.type` is a strict DB enum: `email` / `push` / `in_app`.** An
in-app business event uses `'in_app'`.

Business triggers so far: the missing-journal reminder; the **DTR auto
time-out**, which tells the student their session was closed with no clock-out
and does not yet count (see Daily Time Record — the supervisor is deliberately
NOT notified, since their Needs Attention queue already lists it); and **Info
Sheet submission**, which notifies the submitting student's batch's
**coordinator**
(`batches.coordinator_id` — the single unambiguous owner, not every coordinator
in the department) only on a transition **into** `submitted`. It deliberately
does not fire on a draft autosave, a resave of an already-submitted sheet, or an
edit to an approved sheet. **Exit interview submission** notifies the same
coordinator on the same rule, and cannot repeat: submitting locks the form.

## Architecture

### Routing & middleware

`routes/api.php` defines `/api/user` (`auth:sanctum`) plus role-gated groups.
Role gating is `App\Http\Middleware\EnsureRole`, aliased as `role` in
`bootstrap/app.php` (`role:admin`, or `role:admin,coordinator` for multi-role).
**It also blocks `is_active = false` accounts with a 403 before checking role.**

`Route::pattern()` constrains date-shaped route params (`date`, `weekStart`) to
`\d{4}-\d{2}-\d{2}` at the top of `routes/api.php`, so a malformed segment 404s
at the router instead of reaching an unguarded `Carbon::parse()` and throwing.

**ROUTE ORDERING HAZARD:** a literal segment must be registered **before** a
wildcard that would swallow it — `info-sheets/pending-count` before
`info-sheets/{student}`, and `group-info-sheets/{company}/{academicYear}/pdf`
before `{academicYear}`.

**`POST /register` was REMOVED** (and `Auth\RegisteredUserController` deleted).
Breeze's self-service registration created an **active `role: student` account
for any anonymous caller, unthrottled** — on a public URL, a stranger
self-enrolls into a coordinator's intake queue. Side effect: the `Registered`
event no longer fires anywhere.

**`/login` is split from the SPA's own page route.** The SPA posts credentials to
**`/auth/login`** (and `/auth/logout`), which both the Vercel rewrite and the
Vite dev proxy map back to the API's real `/login`/`/logout` — **no backend route
changed**. Necessary because `/login` is simultaneously Laravel's POST endpoint
and the SPA's router page path, and **Vercel rewrites match on path only, never
on HTTP method**, so proxying `/login` wholesale would send a page refresh (GET)
to Laravel, which only defines POST there, and answer **405**.

### Rate limiting

`RateLimiter::for('api', ...)` in `AppServiceProvider::boot()` — 120 req/min,
keyed by the authenticated user id falling back to the request IP. Per-user
rather than pure per-IP, so one abusive account on a shared office network cannot
throttle everyone behind that NAT. `throttleApi()` is appended **after** the
Sanctum prepend, so the session is resolved before the limiter reads the user.

`trustProxies(at: '*')` is set in `bootstrap/app.php`. Laravel trusts **no**
proxies by default, so behind Vercel it would ignore `X-Forwarded-For` and put
**every unauthenticated visitor in one rate-limit bucket**. Trade-off knowingly
accepted: a wildcard lets a caller spoof the header to dodge throttling, but
Vercel publishes no stable edge-IP list, and an accidental shared-bucket lockout
is the likelier harm.

### Controllers & requests

`app/Http/Controllers/{Admin,Auth,Coordinator,Student,Supervisor}/*`. Follow the
existing **Form Request + Controller** pattern (one Form Request per
create/update action) before inventing new patterns. There is no
`app/Http/Resources` directory; the one shared payload builder is
`App\Support\AuthUserPayload::build()`, called by **both** the `/api/user`
closure and `AuthenticatedSessionController::store` so the two can never drift.

### Web SPA structure

Pages in `web/src/pages/{admin,coordinator,student,supervisor}/`, role shells in
`web/src/layouts/*Layout.vue`. **Those four layouts are self-contained** — each
renders its own sidebar/header and shares only the smaller pieces
(`SidebarCollapseToggle`, `NotificationBell`, `ProfileMenuPopover`). There is no
shared `DashboardShell` wrapper.

Routes are central in `web/src/router/index.ts`, gated by
`meta: { requiresAuth, role }` and a global `beforeEach` calling the Pinia `auth`
store. API calls go through the shared Axios instance at `web/src/lib/axios.ts`.

Shared helpers live in `web/src/lib/` (not a `composables/` directory):
`toast.ts`, `apiError.ts`, `formDraft.ts`, `fieldLabels.ts`, `enrollment.ts`,
`googleAuth.ts`.

### Login critical path — one blocking round trip

`auth.login()` issues exactly **one** blocking request. `ensureCsrfCookie()`
memoises its in-flight promise at module scope and `LoginPage.vue` primes it
fire-and-forget on mount, so the cookie is fetched **while the user types**;
`login()` awaits the same promise, so correctness never depends on the prime
having run or succeeded. The POST response body carries the full user payload, so
there is **no follow-up `/api/user` fetch**. `LoginPage.vue` awaits
`router.push` so the button stays busy until the lazy chunks resolve.

If this file is ever re-merged or rebased, check those three script changes
explicitly — losing them silently reverts login to three blocking round trips,
and nothing fails loudly to signal it.

### Shared frontend infrastructure

- **`categorizeError(error, fallback?)`** (`lib/apiError.ts`) turns any Axios
  error into a `kind` / `message` / `fieldErrors` triple, where `kind` is
  `network` (no response at all, e.g. offline) / `validation` (422) / `auth`
  (401, 403) / `not_found` / `server` (5xx) / `unknown`. **Rollout is a pilot,
  not complete** — migrate remaining pages incrementally as they are touched.
- **`LoadStatus.vue`** replaces the copied three-state loading/error/content
  block and provides the app's Retry affordance.
- **`confirmAction()` is async** (a promise-based styled modal, **not**
  `window.confirm`) and **`promptAction()`** likewise; both are rendered by
  `ConfirmHost.vue`, mounted **once in `App.vue`**. `showToast()` instead needs
  `ToastHost.vue` mounted **per page**.
  - **Every call site passes an options object**, not a bare string: a `title`
    naming the outcome as a question, a `confirmLabel` matching the button the
    user clicked, and `tone: 'danger'` for anything that drops/archives/destroys.
- **Toast durations auto-scale**: `timeout` is optional; omitted, it derives from
  message length (3s base, +50ms per character past a 20-char grace window,
  capped at 8s; `error` toasts get a 4.5s floor since severity is its own
  signal). `MAX_VISIBLE_TOASTS = 4` evicts the oldest. Per-toast timer state
  lives in a module-level `Map`, deliberately kept off the reactive object.
- **`ValidationErrorList.vue` + `friendlyFieldLabel()`** render 422s as a proper
  alert with humanized labels ("End Date", not `end_date`). Also a partial
  rollout.

**UX RULE (app-wide, all four roles):** crucial/destructive actions
(delete/remove/deactivate/drop/archive) **confirm first**; successful saves
**toast after**.

### Form draft persistence (`lib/formDraft.ts`)

`useFormDraft(key, read, apply, options)` mirrors watched state into
**`sessionStorage`** (debounced 300ms, deep watch), restoring on mount. Keys are
namespaced `interntrack:draft:<key>`. Every storage access is `try/catch`-wrapped
— Safari private mode and a full quota both *throw*, and losing a draft must
never break a form. Malformed JSON is discarded rather than re-thrown.

- **`sessionStorage`, NOT `localStorage` — a privacy decision, not a technical
  one.** It survives refresh, back/forward and a same-tab crash, but is wiped
  when the tab closes. InternTrack runs on **shared MDC computer-lab machines**,
  where a half-typed info sheet (name, address, contact number, guardian details)
  must not remain readable to the next person. `localStorage` would keep it for
  days.
- **THE SECURITY RULE: never persist a credential through this helper.** What
  gets stored is exactly what `read()` returns, so **the allowlist IS the
  getter** and the exclusion is *structural*, not a denylist someone can forget
  to update. Web storage is plain text readable by any JS on the page, so
  persisting a credential turns any XSS into credential theft. Passwords have a
  correct home: the login form carries `autocomplete="username"` /
  `autocomplete="current-password"`, so the browser's own encrypted,
  OS-protected password manager handles refill. **Do not "helpfully" add a
  password to any `read()`.**
  - **`LoginPage.vue` is an explicit EXCEPTION** and does not use this helper at
    all — it persists username *and* password by direct `sessionStorage` write
    (keys `interntrack_login_username` / `interntrack_login_password`) at the
    project owner's instruction, clearing both on success and deliberately
    keeping them after a *failed* login. It was kept off `useFormDraft` precisely
    so the rule above still holds for the ~12 forms that do use it. Those writes
    are **synchronous per keystroke, not debounced**, so clear-on-success cannot
    be undone by a stale timer.
- **`autoRestore: false` is load-bearing on every server-populated form** — those
  pages fetch on mount, so an eager restore is silently overwritten by the
  response landing afterwards; they call `restore()` after the load resolves.
- **Drafts that must not cross contexts carry their own scope key** and are only
  re-applied on an exact match (the daily journal by date, Annual SIPP by program
  + academic year, HTE by year, Group sheet by company + year). Without this,
  switching tab/year/day spills one context's writing into another.
- **Create-modals only — edit-modals are deliberately excluded.** A modal is not
  a route, so a refresh closes it and the draft is re-applied on reopen.
  Restoring into an *edit* modal would paint a stale draft over a freshly-loaded
  record and present outdated values as current.
- **Report editors and System Settings genuinely clear on save, and that needs a
  `nextTick` before `clear()`** — applying the response mutates the watched refs
  and queues a write, so a synchronous clear is undone ~300ms later by the
  pending debounce.

### Caching — arrays only, never objects

Uses the already-configured **database** cache store (a `cache` table in the same
MySQL database), deliberately not Redis — no new infrastructure, matching the
zero-cost deploy stack. Cached with explicit invalidation at the known write
points (the TTL is a backstop, not the mechanism):
`User::coordinatorProgramIds()`, `Admin/ProgramController::index()`,
`Admin/DepartmentController::index()`, and `SystemSetting::cached()`.

`reference:programs` genuinely had **no** write point to invalidate at until
2026-09-08 — programs were uncreatable, so the day-long TTL *was* the mechanism.
Now that the admin creates them, `ProgramController::forgetCachesFor()` is that
hook, and it drops the per-coordinator scope cache as well as the two reference
lists. See Admin → Programs above for why that third one is the one that bites.

Dashboard aggregates are deliberately **not** cached — they are per-user, change
frequently, and were not found to be expensive.

**RULE: never put an object in `Cache::remember()` — cache arrays/scalars and
rehydrate.** `config/cache.php` ships Laravel's default
`'serializable_classes' => false`, so `DatabaseStore::unserialize()` passes
`allowed_classes: false` and **any object** read back from a serializing store
(database, file, redis) returns as `__PHP_Incomplete_Class`. A cached
`Collection` or Eloquent model therefore **works on the first (cold) request and
500s on every request after it**. Deliberately NOT "fixed" by enabling
`serializable_classes` — that would disable a real security control app-wide to
patch four call sites.

This escaped the original pass because `phpunit.xml` pins `CACHE_STORE=array`,
and `ArrayStore` holds live objects in memory without ever serializing, so the
failing warm read never executed under test. The regression test forces the
**`file`** store to reproduce production behaviour.

### Dev proxy & CORS

`web/vite.config.js` proxies `/api`, `/sanctum`, `/auth/google`, `/auth/login`,
`/auth/logout`, `/auth/forgot-password`, `/auth/reset-password` to
`VITE_BACKEND_URL` (default `http://localhost:8000`), so the Vite dev server
makes same-origin requests. The last four **rewrite** to the API's real
`/login`, `/logout`, `/forgot-password` and `/reset-password` — all four of
those paths are also SPA page routes, so they cannot be proxied under their own
names (see Password Reset above). `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` in `.env` must match
wherever `web/` actually runs. CORS `allowed_origins` is pinned to
`FRONTEND_URL`, never `*`.

## Deployment

The operational runbook is **`docs/DEPLOYMENT.md`**; cron/email operator setup is
**`docs/CRON-AND-EMAIL-SETUP.txt`**. This section is the decision record.

Chosen stack, driven by a hard **zero-cost / no-credit-card** constraint:

| Piece | Host | Notes |
|---|---|---|
| SPA | **Vercel Hobby** | Root Directory `web`, config in `web/vercel.json` |
| API | **Render free web service, via Docker** | Spins down after 15 min idle; 30-60s cold start |
| DB | **Aiven always-free managed MySQL** | 1GB. Deliberately **not** Render's free Postgres, which expires 30 days after creation |
| Cron | **External scheduler pings `/api/cron/run` hourly** | Render's free tier has no cron |
| Avatars | Local `public` disk | Discarded on every redeploy; R2 is wired and is a pure env flip |

**Deploys track a dedicated `deploy` branch — pushing to `main` deploys
nothing.** `render.yaml` sets `branch: deploy`, and `web/vercel.json` carries
`git.deploymentEnabled: {main: false}`. Vercel's Production Branch must also be
set to `deploy` in its dashboard (there is no vercel.json field for it). Shipping
is deliberate: `git checkout deploy && git merge main && git push origin deploy`.

This is not tidiness: `docker/entrypoint.sh` runs `migrate --force` on **every**
boot, and the API sleeps and re-boots on idle, so with auto-deploy on main a
half-finished migration would reach live data on the next wake.

The **Aiven database is external**, so redeploys and sleep/wake cycles never touch
the data. The only thing lost on a redeploy is uploaded avatars.

### The SPA-to-API model is a same-origin rewrite proxy, not cross-domain CORS

`web/vercel.json` rewrites `/api/*`, `/sanctum/*`, `/auth/google/*`,
`/auth/login`, `/auth/logout`, `/auth/forgot-password`, `/auth/reset-password`
to the API host, plus a catch-all to `/index.html` for the SPA's
`createWebHistory()` deep links. **The catch-all is what serves
`/forgot-password` and `/password-reset/:token` as pages** — those must NOT
appear as rewrites, or the page load would be proxied to Laravel instead. The browser therefore only ever sees one origin, which means **CORS never
fires, `SameSite=None` is not needed, and login does not depend on third-party
cookies** (the fragile part of the cross-domain alternative — Safari and hardened
browsers block them).

Four load-bearing consequences:

- **`SESSION_DOMAIN` must be EMPTY** so the session cookie is host-only and
  attaches to the Vercel domain the browser believes it is talking to. Setting it
  to the API's domain breaks login.
- **`SANCTUM_STATEFUL_DOMAINS` must be the VERCEL host**, not the API's. Sanctum
  decides "is this the frontend?" from the request's Referer/Origin, which
  through the proxy is the Vercel domain. Wrong value means every authenticated
  request 401s while `/login` itself appears to succeed.
- **`GOOGLE_REDIRECT_URI` must point at the VERCEL origin**, and that exact
  string must be registered on the OAuth client. Send Google straight to the API
  and the session cookie lands on the API's domain while the SPA reads the Vercel
  domain, so sign-in silently appears to fail.
- **`VITE_BACKEND_URL` must be LEFT UNSET — the deployed SPA takes NO environment
  variables at all.** Unset is what keeps the Google entry-point links
  **relative**, so they ride the rewrite. Set to the API origin, the browser
  navigates straight to the API host and the same cookie-domain failure occurs.

`SESSION_SAME_SITE=lax` is correct here (not `none`), because the requests are
first-party. Lax still rides the top-level GET redirect back from Google.

**`config('app.timezone')` is `env('APP_TIMEZONE', 'UTC')` and deployments set
`Asia/Manila`.** Every user is UTC+8, and on a UTC server anything before 08:00
Manila still reads as **yesterday** — so the journal calendar offers the wrong
date, "this week" counts are off, and reminder hours fire 8 hours early. **The
default stays UTC on purpose** so the test suite's `Carbon::setTestNow` travel
behaves exactly as before.

### Scheduled work runs over HTTP, because the deployed app has no cron

`App\Http\Controllers\CronController` (`GET|POST /api/cron/run`,
`throttle:12,1`, unauthenticated but guarded by `CRON_SECRET`). A free external
scheduler pings this hourly. `routes/console.php` is **unchanged** — a host that
does have cron keeps working, and the two paths are safe to run side by side.

- **It deliberately does NOT call `schedule:run`, and that is the central design
  point.** `schedule:run` only fires a task whose cron expression matches the
  **current minute**, and an external pinger cannot promise the minute it lands
  on — cron-job.org jitters and a sleeping Render instance adds 30-60s of cold
  start. A ping at 10:03 would find an `hourly()` task not due and silently do
  nothing, every hour, forever. Each command is invoked **directly** instead.
- **Reminders AND the DTR auto-close run on EVERY ping with no marker.** For the
  auto-close a marker would be actively wrong, not merely wasteful: it would
  *delay* closing a session that crossed the stale threshold shortly after the
  previous run. It self-gates on how long each session has been open (so it is
  correct at any ping minute) and re-running finds nothing, because closing a
  session moves it out of the `status = 'open'` set it selects.
- Reminders are safe on every ping because the command
  already self-gates twice (skip anyone whose hour differs; dedupe on user +
  title + today). That is also why it is *correct* at any minute.
- **The weekly-bundling marker is load-bearing, not an optimisation.** Bundling
  freely overwrites any unsubmitted draft, so an unguarded hourly ping would wipe
  a student's in-progress narrative once an hour. It may run **at most once per
  calendar week**. The purge marker is merely daily and avoids pointless work.
- Markers are **rows** in the existing `system_settings` table
  (`cron_last_purge_at`, `cron_last_bundling_at`) — no schema change. `Cache` was
  rejected because an eviction would silently re-run bundling and clobber drafts.
  A corrupt marker falls back to "due" rather than throwing.
- **A blank `CRON_SECRET` disables the endpoint (404), which is load-bearing** —
  without it `hash_equals('', '')` returns true and an unconfigured deployment
  would leave a public trigger that sends real email. Bad/missing keys 404 rather
  than 401/403, so probing cannot confirm the endpoint exists. Accepts
  `X-Cron-Key` **or** `?key=`, since several free cron services can only issue a
  plain GET with no custom headers.
- **The response body carries only each command's trailing summary line (counts),
  never per-student log lines** — cron providers retain response bodies in their
  execution history.
- Known limitation: mail sends inline, so a large cohort could exceed a cron
  provider's HTTP timeout. Fine at this scale; revisit if student numbers grow.

### Deploy artifacts & seeding

- `Dockerfile` (php:8.3-apache; GD built **with jpeg/webp/freetype** so avatar
  processing is not silently degraded to PNG; opcache tuned because a free tier
  pays a full cold start).
- `docker/entrypoint.sh` — binds Apache to `$PORT` (a container left on 80 fails
  Render's health check with no useful error), **hard-fails on a missing
  `APP_KEY`** and on `production` + `APP_DEBUG=true`, runs `migrate --force`,
  optional one-shot `SEED_ON_BOOT`, caches config/routes/views non-fatally.
- `.dockerignore` excludes `.env`, `vendor/`, `web/`, `tests/`, and any local
  `*.sqlite` — a committed sqlite file would otherwise become the production DB.
- `render.yaml` (free plan, **singapore** region as closest to Bohol,
  `healthCheckPath: /up`, secrets as `sync: false`).
- **`ProductionSeeder`** — `DepartmentProgramSeeder` + exactly one admin from
  `ADMIN_USERNAME`/`ADMIN_PASSWORD` (refuses without both; rejects passwords
  under 12 chars; never resets an already-changed admin password), plus the
  non-login `system` account with an unusable random password and
  `is_active = false`. **Never run bare `db:seed` on a deployment.**
- **`demo:set-password`** rotates every account except `system`, because **this
  repo is public and this file documents both the demo usernames and their shared
  password**. It clears `must_change_password` so a demo login is not interrupted
  mid-presentation, and rejects both the literal `password` and anything under 12
  chars (the literal check runs first, since a bare length error would hide the
  real reason).
- Avatar disk is configurable via `config('filesystems.avatars')` (`AVATAR_DISK`,
  default `public`); a dedicated **`r2`** disk is defined alongside `s3`. R2
  requires `region: auto`, path-style endpoints, and a **public** `R2_URL`, since
  the avatar URL is handed straight to an `img` tag.

## Avatars & Image Processing

`ProfileController::uploadPhoto()` runs every upload through
`App\Services\AvatarProcessingService`:

1. **`sniffType()` independently re-verifies the file is a genuine JPEG/PNG/WebP
   by reading its actual image header** (`getimagesizefromstring()`) — a real
   magic-byte check, not the extension/declared-MIME trust the `mimes` rule
   alone provides. Wired in via a `withValidator()` hook so a spoofed upload
   still 422s.
2. **`toAvatarImage()` center-crops to a square, resizes to exactly 250x250, and
   re-encodes via GD.** Re-encoding through a fresh `imagecreatetruecolor` canvas
   never carries EXIF forward, so **metadata stripping (including GPS) falls out
   of the resize for free** — no separate call needed.

The stored filename is always a fresh random `avatars/{random}.{ext}`; the
original filename and extension are fully discarded. Upload order is
**write-new-then-delete-old**, so a mid-request failure cannot leave a user with
no avatar.

**Encoding gracefully degrades to PNG when the runtime's GD lacks WebP**
(`toAvatarImage()` returns `{binary, extension}` and the caller builds the path
from it). This is a **defensive fallback, not a substitute for a proper GD
build** — a GD without JPEG support still cannot *decode* an uploaded `.jpg` at
all.

Client-side, `onPhotoSelected` does **not** auto-upload: it validates type/size
locally (specific messages before any network call), then opens
`AvatarCropperModal.vue` (plain canvas + pointer events, **no new npm
dependency**) for pan/zoom with a circular crop guide. Only on confirm does it
POST the cropped square blob.

## Gotchas

Hard-won, each from a real debugging session. Do not "simplify" any of these away.

### `session()->regenerate()` does not clear session DATA, only its ID — and that silently logs out the very login that just succeeded

Found 2026-08-23 chasing a report of "login just sits there" plus "Google sign-in
logs me in directly with no Google screen." The login itself was fixed by
removing `guest` from `/login` (see the Google OAuth section above), but fixing
that surfaced a SECOND, genuinely subtler bug: switching accounts on the same
browser (log in as A, then — without logging out — log in as B) always
succeeded with a 200 and the right user in the response body, yet **every
single API call the new dashboard made came back 401, permanently**, until the
tab was hard-reloaded.

Root cause, confirmed by literally tracing every SQL statement the `sessions`
table received: `Illuminate\Session\Middleware\AuthenticateSession` is enabled
by Laravel's own default `config/sanctum.php`
(`'authenticate_session' => AuthenticateSession::class`), and runs on every
stateful request. On first seeing a session, it stamps a `password_hash_web`
key — an HMAC of the CURRENT user's password hash — and on every later request
re-hashes `$request->user()`'s password and compares it against that stored
value, logging out (`session()->flush()` + throw `AuthenticationException`) on
a mismatch. This exists to catch a genuinely changed password invalidating
old sessions. **`Auth::attempt()`'s own internal `migrate(true)` and the
controller's explicit `session()->regenerate()` only ever rotate the session
ID — neither touches `$attributes`.** So logging in as B over A's still-live
session carries A's `password_hash_web` straight into B's brand-new session
row. The very next request hashes B's (different) password, compares it
against A's leftover hash, mismatches, and `AuthenticateSession` itself wipes
the row it's sitting in and 401s — a real logout, self-inflicted one request
after a real, successful login, with no error the SPA could ever show.

This is exactly why Laravel ships `guest` middleware on the stock login route
in the first place: the framework's assumption is that `POST /login` never
runs against an already-authenticated session, so this interaction never had a
chance to fire. Once that assumption is deliberately dropped (correctly, for
the reason above), the responsibility for a clean slate falls on the
controller. **Fix: `$request->session()->flush()` BEFORE `$request->authenticate()`
(or `Auth::login()`), never after** — flushing after authenticating would wipe
the very `login_web_*` key the login just wrote. Applied in both
`AuthenticatedSessionController::store()` and
`GoogleController::completeLogin()`, since Google sign-in's callback can hit
the identical stale-session case (`redirectToLogin()`'s early return only
guards the entry point, not the callback that actually calls `Auth::login()`).

Verified two ways: `php artisan test` (503 tests) stayed green — nothing else
in the suite exercises a login-over-a-live-session — and a live Playwright
repro (log in as a student, reload `/login` without logging out, log in as a
coordinator) went from 5-for-5 `401 Unauthenticated` on the new dashboard's own
API calls to a clean `200` with real data, confirmed by tracing the exact
`sessions` row: before the fix its payload went from a correct
`{"login_web_...":9,...}` to a blank `{"_flash":{...}}` within milliseconds of
the second login; after the fix it stays correct indefinitely.

### `date`-cast columns and plain equality under SQLite

MySQL's `DATE` truncates any time component on write; **SQLite does not**. So a
`date`-cast attribute written as `'2026-07-06'` can come back out of SQLite as
`'2026-07-06 00:00:00'`. A plain `where('week_start', $dateString)` — or
`updateOrCreate()`'s match array, which is the same thing internally — silently
fails to find the row it just created, and a second write inserts a duplicate
instead of updating.

**Always use `whereDate()` against a `date`-cast column.** The same flaw applies
to `whereBetween` on a range: the last day is dropped because
`"...-02 00:00:00"` sorts *after* a bare `"...-02"` bound. This bit
`WeeklyBundlingService`, `WeeklyLogController::store()`, and
`JournalCalendarController`'s month query before being fixed the same way.

### `truncate` inside `TooltipWrap` does nothing without `max-w-full` on BOTH

`TooltipWrap`'s root is `<span class="group relative inline-flex">`, and an
inline-flex sizes to its CONTENT. So a child carrying `truncate` (which is just
`overflow:hidden` + `text-overflow:ellipsis` + `whitespace:nowrap`) has no
constrained width to truncate against — the text runs straight out of the `<td>`
and paints over the next column, even under `table-fixed` with a `colgroup`.
Nothing errors, `npm run build` is clean, and it only shows with real data long
enough to overflow. Seen 2026-08-30 on the Journal Review tables, where a batch
name and a company name overlapped each other.

**The working pattern, already used by `AdminUsersPage`, needs the class on both
elements:**

```html
<TooltipWrap :label="value" placement="top" class="max-w-full">
  <span class="block max-w-full truncate">{{ value }}</span>
</TooltipWrap>
```

`class="max-w-full"` constrains the wrapper to the cell; `block max-w-full` on
the inner element is what gives `truncate` something to measure. Omitting either
silently reverts to overflow. `TooltipWrap`'s own docblock says it is for
icon-only controls — using it to truncate table text is a secondary use that
only works when it is constrained.

A cheap browser check catches the whole class:
`[...document.querySelectorAll('td,th')].filter(e => e.scrollWidth > e.clientWidth + 1).length`
should be **0**.

Related: in a `table-fixed` `colgroup`, give the LEFTOVER width to the column
holding the longest text. On that page every column was pinned except Student,
so Company — the longest value in the table — ended up the narrowest cell at
135px while Student had 230px of short names.

**A `whitespace-nowrap` cell with NO `truncate` does not clip — it SPILLS.**
Same family, opposite failure. `truncate` at least stops at the cell edge; a
bare `whitespace-nowrap` `<td>` paints its overflow across the next column, and
`table-fixed` will not widen to save it. Any pinned column holding a formatted
value therefore needs a width measured against that value's real width, not
against its heading. Found 2026-09-01 on the Journal Review queue, where the
Week column (two full ISO dates and an en dash, 174px of content) had been
trimmed to 165px to make room elsewhere.

**The corollary: SHRINKING a label means shrinking its column too.** The same
queue's row action was renamed **"Open Notebook" → "Open"** on 2026-09-08 at the
project owner's request (the old label named the destination page rather than
the act, and read as a different kind of thing from the Interns tab's
"Journals" beside it). Its column had been pinned at 140px for the long label,
so the rename alone would have parked **47px of dead space** in the widest-content
table on the page. Measured and re-pinned at **95px** — 61px of button plus the
cell's own 32px of `px-4` padding — and the slack goes to the unsized Batch
column, which also renders the company name beneath it. Verified in a browser:
Batch went 250px → 295px, the full "Bohol Provincial Cooperative Development
Office" now fits untruncated, `scrollWidth === clientWidth` on every cell, and
the table does not scroll sideways. The supervisor's own **"Open full
notebook"** link (in its review modal, a different surface) is deliberately
UNCHANGED — that one sits inside a modal where "Open" alone would not say what
it opens.

### A native `<select>` is as wide as its widest OPTION, and flex-wrap cannot shrink it

Found 2026-09-01 adding the batch/company filters to Journal Review. A
`<select>`'s intrinsic width comes from its longest `<option>`, not from the
selected one — so a filter listing a company called "Bohol Provincial
Cooperative Development Office" is ~46 characters wide **whatever is currently
chosen**, and no amount of `flex-wrap` on the row will shrink it. On a 390px
phone that pushed the filter row 8px past the viewport, and since
`web/src/style.css` sets no global `overflow-x` guard (deliberately — see the
mobile-overflow section above), it bled rather than being contained.

The rule for any filter `<select>` whose options are user-entered names
(companies, batches, students — as opposed to a fixed vocabulary like
program codes): **`w-full max-w-full` with `sm:w-auto`**, and `w-full min-w-0
sm:w-auto` on the wrapping `<label>`. Below `sm` each control takes the column;
above it the intrinsic width returns, still capped by `max-w-full`.

**The admin's five department dropdowns were the next to meet it, on
2026-09-08.** `departments.name` went from a 6-character code to a real ~70
character name (see Domain Facts), so `AdminBatchesPage`, `AdminInfoSheetsPage`,
`AdminProgramsPage` and `AdminUsersPage` (filter *and* create form) were all
guarded in the same pass. Verified at 390px: none of those pages scrolls
sideways, and the Programs filter measures 343px inside a 390px viewport.
Remaining filter bars elsewhere still share the unguarded pattern and have
simply not met a long enough option yet — a **fixed** vocabulary (program codes,
statuses) genuinely does not need the guard.

**Also: `@change="load"` passes the change EVENT into the handler's first
parameter.** A handler with an optional flag (`load(initial = false)`) then
receives a truthy `Event` and takes the wrong branch — here it meant every
filter change ran the FIRST-load path and blanked the page into its spinner.
Write `@change="load()"`. The same applies to `:retry` on `LoadStatus`, which is
why that page passes a separate unary `reload`.

**A filter control that lives INSIDE its own page's `<LoadStatus>` must not
flip the loading flag it is wrapped by.** Doing so unmounts the very control
that was just used mid-gesture, dropping keyboard focus and making the dropdown
vanish under the pointer. Split the state: `isLoading` for the first load only
(spinner), `isRefreshing` for every later fetch (content stays mounted, dimmed,
with its controls disabled and an `aria-live` "Updating…" note). Pages whose
filters sit OUTSIDE the loading block — `CoordinatorWeeklyJournalsPage` — do not
have this problem and need no split.

### SQLite silently accepts a column that does not exist; MySQL 1054s

Found 2026-08-30 building the coordinator's Journal Review page. An eager load
written as `with('student:id,name,student_id_number,avatar_url')` looks
reasonable — but **`avatar_url` is an accessor over `avatar_path`, not a
column.** The whole 604-test suite passed, `npm run build` passed, and the page
then 500'd on the very first real page load:

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'avatar_url' in 'field list'
```

The reason the tests are blind to it is **not** that they missed the code path
— `test_the_interns_index_...` exercises exactly that query. It is that
`phpunit.xml` pins `DB_CONNECTION=sqlite`, and SQLite's double-quoted-identifier
misfeature resolves an unknown `"avatar_url"` as a **string literal** instead of
erroring. Verified directly, not assumed: a throwaway test doing
`User::query()->get(['id', 'name', 'avatar_url'])` **passes** under SQLite.

Same family as the `Cache::remember()` object bug (`ArrayStore` never
serializes, so the failing warm read never ran under test): the test database is
not the production database, and a class of error exists that only the real one
raises.

**Rule: a column list in `select()` / `with('rel:cols')` may only name real
columns.** Anything derived — `avatar_url`, and any other accessor or appended
attribute — must be omitted and left to the model. When a query is built by
listing columns, open the page in a browser against MySQL before calling it
done; PROJECT.md already requires that for other reasons, and this is one more.

### `??` does not null-safe a chained expression

`$log->submitted_at?->toIso8601String() ?? null` still throws when `$log` itself
is null — PHP's `??` only suppresses the warning for a *direct* property/array
access on its left operand, not for a longer chained expression. Null-safe the
**base**: `$log?->submitted_at?->...`.

### `#[Fillable(...)]` exclusion means `->update()` silently no-ops

Columns deliberately kept out of the fillable list (`completed_at`,
`archived_at`) must be set by **direct property assignment + `save()`**. Using
`->update(['archived_at' => ...])` passes Laravel's fillable filter and does
nothing, with no error.

### `APP_URL` must carry the port, or every uploaded avatar is a broken image

`config/filesystems.php`'s `public` disk builds its URL from `APP_URL`, and
`User::avatarUrl()` builds `avatar_url` from that. Laravel's stock
`APP_URL=http://localhost` emits port 80 — which on a Laragon/Apache machine is
not `artisan serve` on :8000. Set `APP_URL=http://localhost:8000`; if you run
`serve --port=NNNN`, `APP_URL` has to follow.

**Diagnostic rule: on a broken avatar, 404 means the wrong host/port in
`APP_URL`; 403 means the file genuinely is not on disk.** A missing file under
`/storage/...` returns **403, not 404**, because the `local` disk's
`serve => true` registers a route resolving against `storage/app/private` while
real avatars live on the `public` disk and are served off the symlink before
Laravel is reached. Orphan files are normal — `migrate:fresh --seed` nulls
`users.avatar_path` but never sweeps the directory.

### A Windows PHP build ships NO CA bundle, so every outbound HTTPS call fails

The official php.net/WinGet Windows builds leave both `curl.cainfo` and
`openssl.cafile` empty, and Guzzle then dies with `cURL error 60: unable to get
local issuer certificate`. **This is not OAuth-specific** — it breaks Google's
token exchange, SMTP over TLS, and any third-party API call identically. The
symptom is indistinguishable from bad credentials.

Fix: download `https://curl.se/ca/cacert.pem` and point **both** ini keys at it.
**A WinGet upgrade of PHP replaces `php.ini` and silently wipes both lines** —
re-apply them.

**Diagnostic rule: when any integration "just fails", test raw TLS reachability
first** (a Guzzle GET to the provider from `artisan tinker`) before suspecting
credentials or app logic.

### Local verification: the Vite port must be in `SANCTUM_STATEFUL_DOMAINS`

If 5173 is occupied Vite silently falls back to 5174, and the symptom is deeply
misleading: **`POST /auth/login` returns 200 with the user JSON while the very
next `GET /api/user` returns 401**, and the SPA shows "Invalid credentials" —
because Sanctum decides statefulness from the Referer host and never issues the
session cookie. Passing `SANCTUM_STATEFUL_DOMAINS` as an env var to
`artisan serve` does **not** override the `.env` value. What works without
touching `.env`: run Vite on 8000 (already listed) and
`php artisan serve --port=8001` with `VITE_BACKEND_URL=http://localhost:8001`.

### Tailwind v4 emits `oklch()`, not `rgb()`

A colour assertion written against `rgb(37, 99, 235)` fails on a perfectly blue
element — `getComputedStyle` returns e.g. `oklch(0.546 0.245 262.881)` for
`blue-600`. Also: **a mistyped Tailwind utility fails silently, not at build
time**, so check the built CSS when introducing new utilities.

### Measuring layout in a browser test

Asserting "everything is vertically centred" by iterating leaf nodes gives a
false failure — a stacked two-line block is itself centred while each line sits
off-centre. Measure a container's **direct flex children**, not its leaves.

**Audit overflow in BOTH directions, and test dropdowns in their OPEN state.** A
`scrollWidth > clientWidth` check cannot see content spilling **leftward**, and a
closed dropdown hides that entire class of bug.

### The shared Axios instance has NO `baseURL` — always call `/api/...`

`web/src/lib/axios.ts` sets only `withCredentials`/`withXSRFToken`. A relative
path like `api.get('student/weekly-logs')` therefore resolves against the
**current page URL**, so from `/student/weekly-time-log` it requests
`/student/student/weekly-logs`, which the dev server answers with the SPA
fallback (or a 404) instead of JSON. Every call site must use the leading
`/api/` form.

**Build, type-check and the backend test suite all pass with the wrong path** —
it is a runtime string, the tests hit controllers directly, and TypeScript
cannot know a URL is wrong. Only loading the page catches it, which is why a new
page must be opened in a browser before it is called done.

### Vue: outside-click handlers must use `composedPath()`

Selecting a menu item unmounts the clicked button before the click finishes
bubbling to `document`, so `event.target` is already detached and
`rootRef.contains(target)` always reads false. `event.composedPath()` is captured
at dispatch time and stays correct even after the DOM mutates mid-event.

### Windows/tooling notes

- There is **no type-check script in the repo** — `npm run build` is plain
  `vite build`, which does **not** type-check. A bare `npx vue-tsc` fails with
  `ERR_PACKAGE_PATH_NOT_EXPORTED`; use a pinned isolated toolchain
  (`vue-tsc@2.2.10` + `typescript@5.9.3`).
- `intl` is not installed and is **not used** anywhere. The procedural `sqlite3`
  extension is absent but irrelevant — Laravel's SQLite testing driver uses
  `pdo_sqlite`.
- `storage/app/public` must be linked into `public/storage` (`storage:link`).

### Mobile overflow: no global `overflow-x` guard, so any fixed floor bleeds through

`web/src/style.css` sets no `overflow-x: hidden` on `html`/`body`. That is
deliberate — it would mask a genuine layout bug instead of surfacing it — but it
means any element with a hard minimum width wider than the viewport is never
contained unless ITS OWN wrapper is `overflow-x-auto`. Audited 2026-08-23 after
a report of journal input "running out of the frame" on a phone.

Found and fixed: `AnnualSippPaperView.vue`, `HtePaperView.vue`, and
`GroupInfoSheetPaperView.vue` (the coordinator/admin report-preview documents)
all carried `min-w-[44rem]` (704px) — a **hard floor with no relation to their
actual content**, since `AnnualSippPaperView`'s table is three `w-1/3` columns
and `HtePaperView`'s is percentage-based; both are fully fluid and needed no
minimum at all. Removed on all three (now `w-full max-w-4xl`, padding
`px-4 py-6` scaling up to `px-8 py-10` at `sm:`). **This is a live-preview
component, not the PDF** — dompdf renders the actual document from a separate
blade template with its own measured facsimile constraints (see the PDF
sections above), so narrowing this Vue component has zero effect on the
generated report. `GroupInfoSheetPaperView`'s roster table still carries a few
fixed-`w-8/w-10/w-24` narrow columns for `#`/MI/Program/Contact, so an 8-column
roster still needs horizontal scroll on a phone even after the floor is gone —
a `sm:hidden` "scroll sideways" hint now sits above it rather than leaving the
cutoff silent.

Also fixed: `JournalPaperView.vue` and `WeeklyJournalPaperView.vue` (the
daily/weekly journal "paper" read view) used a flat `p-10` (40px/side)
regardless of viewport, eating a quarter of a 375px phone's width in padding
alone — now `p-5 sm:p-8 md:p-10`. Their header row (student name / program name)
gained `flex-wrap` and `wrap-break-word` as a defensive measure against a name
or program with no natural break point.

`StudentWeeklyTimeLogPage.vue`'s Activity Log grid (`min-w-248` = 992px,
genuinely load-bearing — the five text columns need real width to be typable)
was NOT narrowed, since doing so would make the actual writing surface worse.
Instead it got the same `sm:hidden` scroll hint plus tighter mobile padding
(`px-2 py-5 sm:px-6`) around the scroll container, so a student sees why only
two columns fit instead of assuming the page is broken.

**Follow-up pass (same day):** `AdminUsersPage.vue` and `AdminBatchesPage.vue`
already carried a **dual layout** — a `hidden md:block` table plus a parallel
`md:hidden` stacked-card list reproducing the same rows — and so did
`SupervisorJournalsPage.vue`, `SupervisorInternsPage.vue`, and
`CoordinatorActivityLog.vue`. That pattern was applied to every coordinator and
student list page that was missing it and is plausibly checked from a phone:
`CoordinatorInternsPage.vue` (both the Interns and Supervisors tabs),
`CoordinatorCompaniesPage.vue`, `CoordinatorBatchesPage.vue`'s main list,
`CoordinatorInfoSheetsPage.vue` (the Accept/Reject queue),
`CoordinatorJournalActivitiesPage.vue`, `CoordinatorWeeklyJournalsPage.vue`,
`CoordinatorJournalTemplatesPage.vue`, and `StudentJournalsPage.vue`. Each card
list reuses the exact same reactive data and handler functions as its table —
no new script logic, purely a second `<template>` block — so the two views
cannot drift apart in what they show or do.

A full sweep for the OTHER classic overflow trigger — a hard `min-w-[...]`
floor — confirmed the three fixed above were the only ones in the entire
codebase; every DTR page (`StudentDtrPage`, `CoordinatorDtrPage`,
`SupervisorDtrPage`) and every other table already sizes its `<col>` widths in
relative Tailwind units with no artificial floor, which is what makes plain
horizontal scroll a safe fallback for them. A parallel sweep for `grid-cols-N`
used with no responsive prefix turned up only deliberately fixed grids (a
7-column calendar, 2-column key/value and signatory blocks) — none were
narrowing a form under load.

**Third pass (same day): the remaining admin/report tables converted too.**
`AdminInfoSheetsPage.vue`, `AdminAuditLogsPage.vue`, and `AdminProgramsPage.vue`
gained the same `hidden md:block` table / `md:hidden` card-list pattern.
`AdminDepartmentsPage.vue`'s own list was already a responsive card grid
(`grid sm:grid-cols-2 xl:grid-cols-3`, no table at all) — only its View modal's
two nested tables (Programs, Students) needed the treatment. The four nested
roster sub-tables inside `CoordinatorBatchesPage.vue`'s roster modal (Active /
Completed / Dropped / Archived) got it as well.

`CoordinatorAnnualSippPage.vue` and `CoordinatorHtePage.vue`'s own curation
tables (distinct from the `*PaperView` read-only previews fixed earlier) are
**genuine data-entry grids** — SIPP notes are typed into three side-by-side
`<textarea>`s per row, HTE rows are typed into five inputs — so unlike a
read-only list, stacking each row as a full-width mobile card is a real
usability improvement, not just a fallback: every textarea gets the whole
screen width instead of a table cell a few characters wide. Verified live
(mdcbalbero, real SIPP/HTE data) — the cards render with working checkboxes,
character counters, and Delete buttons, identical data to the desktop table
since both `v-for` the same `rows` array.

Every item from the original "deliberately left as plain scrollable" list is
now converted. Nothing remaining is known to need this treatment.

**A CSS-only "scroll shadow" affordance for every `overflow-x-auto` region was
attempted and reverted — do not retry it as a blanket rule.** Tailwind v4 wraps
its own utilities in named cascade layers via `@import "tailwindcss"`; a plain
top-level `.overflow-x-auto { background: ... }` rule in `style.css` is
**unlayered**, and unlayered CSS always wins over ANY layered rule regardless
of source order or specificity — so it silently stripped every element's own
`bg-white`/`bg-slate-100` utility wherever both classes landed on the same
node. Verified by re-deriving the cascade-layers spec, not by trial in a
browser. A per-container fix would need its own explicit background color
matching each context, or a technique that never touches `background`/
`background-color` (e.g. `mask-image`) — not a single global rule.

## Frontend UI conventions

Applies to the Vue SPA in `web/`. These describe what the code already does —
follow them rather than inventing a parallel style.

- **Card chrome**: `rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70` on
  every card. Tinted stat cards swap the white fill for a `-50/50` wash of their
  accent (`bg-blue-50/50`, `bg-emerald-50/50`, `bg-amber-50/50`, `bg-rose-50/50`)
  with `ring-1 ring-slate-200/60`.
- **Typography scale**: numerals `text-3xl font-semibold tracking-tight` (not
  extrabold); labels `text-xs font-medium uppercase tracking-wide text-slate-400`;
  section headings `text-sm font-semibold text-slate-900` with no left accent bar.
- **SVG progress convention**: always give the arc `pathLength="100"`, then
  `stroke-dasharray="100"` with `stroke-dashoffset="100 - percent"`. Never
  hand-compute a circumference — `pathLength` re-bases the geometry so the radius
  stops mattering. Gradient ids must be unique per instance (`gauge-ojt`, not
  `gauge`), since duplicate ids break rendering. Clamp every percentage to 0-100
  before use, and render the neutral track alone when the denominator is 0 rather
  than faking a fill. A zero-length segment still paints a dot under
  `stroke-linecap="round"`, so omit the element entirely instead of drawing it at 0.
- **Dashboards render only fields the API already returns.** No invented counts,
  placeholder trends, or sample time-series. A number with no honest denominator
  gets no bar; a missing value renders an em-dash.
- **Tooltips**: `web/src/components/ui/TooltipWrap.vue` provides the visual
  (`label`, optional `placement`). The tooltip element is `aria-hidden`
  decoration — the accessible name comes from an `aria-label` on the control
  itself, carrying the same string. Never add a native `title` alongside it; the
  browser would render a second tooltip on top. Shown on both `group-hover` and
  `group-focus-within`, so keyboard focus reveals it exactly like hover.
- **Sidebar collapse toggle** is anchored to the sidebar's right edge
  (`absolute right-0 translate-x-1/2` on the `aside`, which is `fixed` and
  therefore already the positioning context), not free-floating. It sits outside
  the scrolling `nav` so it never scrolls with the nav list.
- **Primary action buttons use the blue token** (`bg-blue-600` /
  `hover:bg-blue-700`); green is reserved for secondary and status actions, and
  black or dark-neutral primary buttons are not used.
- **Modals use a three-part flex shell, never `sticky` on the footer.** The
  overlay is `fixed inset-0 z-50 flex items-center justify-center p-4` and carries
  **no** `overflow`; the panel is
  `flex max-h-[90vh] w-full max-w-* flex-col overflow-hidden rounded-xl bg-white shadow-xl`;
  inside it sit exactly three siblings — a `shrink-0` header with `border-b`, a
  `flex-1 overflow-y-auto px-6 py-5` body that is the **only** scrolling element,
  and a `shrink-0` footer with `border-t bg-white px-6 py-4`. No element between
  the panel and the body may have its own overflow or height. A `sticky bottom-0`
  footer was tried and does not work: when the overlay is the scroller, the panel
  grows past the viewport and the footer's containing block has no bottom edge on
  screen to pin against, so the bar drifts up over the content.
- **Render a date by slicing the string, never by parsing it.** Take the leading
  10 characters (`value.slice(0, 10)`). Almost every date the API returns is
  either a `date`-cast column Laravel serialises at midnight UTC
  (`"2026-05-29T00:00:00.000000Z"`) or a bare unmarked `"2026-07-29 21:37:00"` —
  and `new Date()` on either can land a day earlier once `APP_TIMEZONE` is
  `Asia/Manila`, which is what deployments set. Slicing cannot drift. Where the
  time still matters, keep the untouched original in a `TooltipWrap` beside the
  sliced value. A wall-clock time (`"21:00:00"`) is likewise formatted by
  splitting on `:` — it has no date, so there is no instant for a `Date` to
  represent. Only a genuine instant carrying a timezone marker may be parsed.
- **Derive a journal field's label from its key — never hardcode a map.**
  `journal_entries.content` is keyed by whatever the coordinator's template
  defines, so the keys differ per program and the API returns no label alongside
  them. Humanise the key instead (split on `_`, capitalise each word:
  `task_performed` becomes `Task Performed`), which stays correct for a template
  nobody anticipated; a hardcoded lookup silently falls back to raw snake_case the
  moment a coordinator adds a section. Render each as a label above its value, and
  skip fields whose value is blank rather than printing an empty label.
- **A stat card links to a filtered page only if that page actually reads the
  query param.** Several list pages hold their filter in a local `ref` and never
  consult `route.query`, so a `?status=...` link navigates without filtering and
  silently lies about where it is taking you. Check the destination page first.
- **A table's actions cell is one right-aligned row of buttons at a fixed column
  width.** `flex items-center justify-end gap-2 whitespace-nowrap`, buttons
  `px-3 py-1.5 text-sm`, the neutral action a bordered outline button and the
  destructive one red text-only. The width belongs in a `colgroup` enforced by
  **`table-fixed`** — without `table-fixed` a browser treats col widths as hints
  and a long name can still squeeze the cell into wrapping onto two lines.
- **Data tables scroll inside their own container.** The table card is
  `overflow-x-auto`, never `overflow-hidden` (which clips instead of scrolling),
  so the page itself never scrolls horizontally.
- **Layouts are mobile-responsive via one shared off-canvas drawer pattern** —
  all four use a `mobileOpen` ref, `-translate-x-full` / `translate-x-0` slide, a
  `bg-black/40 md:hidden` backdrop, an `md:hidden` hamburger, and close-on-nav-link
  / close-on-backdrop. **Copy the pattern, do not re-invent it.**

### The sidebar is GROUPED, not flat (2026-09-08)

Three of the four layouts render their nav as `NAV_SECTIONS` — an array of
`{ heading: string | null, items: [...] }` — instead of one flat `navItems`
list. Fifteen equally-weighted rows gave a coordinator no clue that "Batches"
is set up once a term while "Student Info Sheets" is blocking a student right
now, so the whole rail read as one undifferentiated list.

| Layout | Sections | Sizes |
|---|---|---|
| Coordinator | *(none)* · Monitoring · SIPP Documents · Reports · Setup | 1 · 5 · 3 · 2 · 4 |
| Student | *(none)* · Journals · Time & Attendance · My Forms | 1 · 4 · 2 · 2 |
| Admin | *(none)* · Organization · People & Records · System | 1 · 3 · 2 · 2 |
| **Supervisor** | **deliberately still FLAT** | 4 items |

- **Supervisor is not an oversight.** At four items (Dashboard, Journals,
  Interns, Time Record) headings would add more chrome than they remove — three
  rules and three labels over four rows. Grouping is worth its cost somewhere
  around eight items; below that, leave it flat.
- **The first section is deliberately headless**, holding only the dashboard. A
  lone landing item under its own heading reads as a category of one.
- **Sections are by WHAT AN ITEM IS FOR, not by frequency of use.** The one
  place that visibly costs something: the coordinator's Student Info Sheets is
  arguably the most time-critical item on the rail (a student stays gated until
  it is accepted) and now sits seventh. That is accepted on purpose, because it
  carries the unread dot — the dot is what surfaces it when it actually needs
  attention, so its resting position matters less than being grouped with the
  documents it belongs with.
- **COLLAPSED TO THE 76px RAIL, A HEADING BECOMES A RULE**
  (`border-t border-white/15`), gated on the **same `!collapsed` switch the item
  labels themselves use** — so a heading can never outlive the labels it sits
  above. `role="group"` + `:aria-label` sits on the section wrapper, so the
  structure is still announced when it is invisible. Verified in a browser at
  80px: 0 headings visible, 4 rules, no horizontal overflow.
- **An empty section is dropped rather than rendering an orphan heading.** Not
  reachable for a coordinator (Monitoring keeps at least four items whichever
  OJT type they run), but the Student layout genuinely needs it — and its
  **gated/paused case returns a single UNHEADED section** holding only the info
  sheet, rather than a filtered "My Forms" group, since a lone item under a
  heading announces a category with nothing else in it.
- **Heading padding is `pt-3`, not `pt-4`, and that is measured.** Four headings
  cost 140px of nav height; at `pt-4` the coordinator's nav overflowed its
  viewport by 5px, and `pt-3` reclaims 16px so it fits. `overflow-y-auto` is
  still there as the backstop — re-measure if a section is added.

### The list-page shape — one pattern, all four roles

Every list page in the SPA is built the same way, and **the admin pages were
brought onto it on 2026-08-27** (they were the last holdouts). Top to bottom:
`<section class="space-y-5">` → `ToastHost` → header actions
(`flex flex-wrap items-center justify-end gap-4`) → filters → loading/error
paragraphs → a `<template v-else>` holding **both** a `hidden md:block` table and
an `md:hidden` stacked card list built from the same reactive array.

The table card is `rounded-lg bg-white shadow-sm ring-1 ring-slate-200` +
`overflow-x-auto`, the table `w-full table-fixed divide-y divide-slate-200` with
a `colgroup`, `<thead class="bg-slate-50">`, `th` =
`px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500`
(the Actions `th` is `text-right`), `tbody` = `divide-y divide-slate-100`, `td` =
`px-4 py-3`. The mobile list is
`divide-y divide-slate-100 rounded-lg bg-white px-4 shadow-sm ring-1 ring-slate-200 md:hidden`.
**The empty state lives INSIDE both** — a `<tr v-if="rows.length === 0">`
spanning every column and a matching `<li v-if>` — never as a separate card
above them, so "no rows" and "no rows matching your filters" are worded in one
place per surface.

What the admin pages looked like before, and why each was wrong:

- **`AdminDepartmentsPage` had no table at all** — a
  `grid sm:grid-cols-2 xl:grid-cols-3` of `<article>` cards. It was the only
  list surface in the app you could not scan down a column of, and the reason
  the admin section read as a different product from the coordinator section.
- **`AdminUsersPage` / `AdminBatchesPage` had card-shaped tables**: a padded
  `rounded-xl bg-white px-6 ring-slate-200/70` wrapper with **no `<thead>`
  band**, borderless headers in `text-slate-400`, and `pl-0`/`pr-0` edge cells,
  so rows floated on a card instead of sitting in a grid. Both also opened with
  a bare `<section>` and hand-placed `mt-6`/`mb-5` instead of `space-y-5`.
- Form-field labels across the three pages were
  `text-xs font-medium uppercase tracking-wide text-slate-400`; the app's label
  everywhere else is **`text-xs font-bold text-slate-600`**. The `<label for>` /
  `<input id>` association was kept — only the visual classes changed. Modal
  *group* headings (`<h4>`) stay uppercase-slate-400; that is a section heading,
  not a field label.
- The **View modals** on Departments, Programs and Users used the old two-part
  `max-h-[calc(100vh-4rem)] overflow-y-auto` shell while the Create/Edit modal
  on the *same page* already used the documented three-part flex shell. All
  three now use the three-part shell.

**No `min-w-[...]` floor on the Users or Batches table, deliberately — this was
tried and reverted after measuring.** Actions is the LAST column, so any floor
that forces horizontal scroll parks Deactivate/Reactivate behind a scrollbar,
and that is the one control on the row that must always be reachable. Verified
in a real browser at a 1280px viewport: with `min-w-[1024px]` the container
measured `clientWidth 945 / scrollWidth 1024` and the Deactivate button was
clipped to the letters "De". The fix is the other direction — size each fixed
column to its longest **real** value ("No Department", "Supervisor",
"Deactivate", an ISO date, the status pill) and leave the free-text columns
(Name + Email; Batch Name + Coordinator) with no width so they split the
remainder. They truncate under the `TooltipWrap` that is already on them, which
is exactly what that tooltip is for. Final measurement: `scrollWidth ===
clientWidth` on all four pages, Actions cell 195px holding 163px of buttons.

**`w-full table-fixed`, never `min-w-full table-fixed`.** A `table-fixed` table
with `min-width:100%` but no `width` sizes itself to the sum of its specified
columns *plus each auto column's content*, so it overflows its container instead
of distributing — which is how the Deactivate button first went missing. The
plain `min-w-full divide-y` (auto layout, no `table-fixed`) that the coordinator
pages use is fine on its own terms; it is only the *combination* with
`table-fixed` that misbehaves.

KNOWN, DELIBERATELY NOT CHANGED: `AdminInfoSheetsPage`'s single-action cell is
still left-aligned, matching `CoordinatorInfoSheetsPage` — the two are
deliberate twins (same queue, minus Accept/Reject), and right-aligning only the
admin copy would trade one inconsistency for another. `AdminAuditLogsPage`'s
"Action" column is log *data*, not an actions cell.

`SupervisorInternsPage` and `SupervisorJournalsPage` were the last two pages
on the padded card-table shape and were converted the same day. **Every list
page in the SPA is now on the pattern above — there are no holdouts left.**
Both keep their `LoadStatus` wrapper (loading/error/Retry) rather than the bare
loading/error paragraphs, which is the better of the two and is what new pages
should copy. Their blue notice row also dropped from `rounded-xl … p-6` to the
app's compact `rounded-md … px-4 py-3`.

One substantive change came out of that pass: **`SupervisorInternsPage`'s
"Review Journals" was the only filled blue button inside a table row anywhere in
the app** and is now a bordered outline button like every other row action. The
convention is that a row's actions are outline buttons (destructive ones red
text-only); a filled blue in a repeating row paints a solid stripe down the list
and competes with the page's actual primary action. The two remaining
`bg-blue-600 px-3 py-1.5` buttons in `CoordinatorCompaniesPage` are inside modal
forms, not table rows, and are correct where they are.

Also in that pass, the intern row's count pills moved from `whitespace-nowrap`
to `flex flex-wrap` — with three pills ("pending", "approved", "returned") they
exceed the column and previously overflowed the cell rather than wrapping.
### Dashboard structure

All four dashboards follow one order. A role skips a section only when it has no
real data for it.

1. **Scope/status notices** — role-specific, one compact row each (`px-4 py-3`),
   longer explanation moved into a `TooltipWrap` on an info icon.
2. **Hero card** — `rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/70`
   holding an `h-14 w-14` initials avatar on `bg-blue-50`, `Hello, {first name}`
   in `text-xl font-semibold tracking-tight`, a muted subtitle of the role's most
   relevant context, and the page's single blue CTA as a `rounded-full`
   `RouterLink` with a right-arrow. The CTA target is the role's primary sidebar
   route, **copied from the layout's `navItems` rather than retyped**. A meta grid
   is rendered **only** where the endpoint returns fields worth showing — today
   that is the student alone.
3. **Stat row** — `grid gap-4 sm:grid-cols-2 xl:grid-cols-4`, cards
   `flex h-full flex-col rounded-xl p-6 ring-1 ring-slate-200/60` with a `-50/40`
   tint, an `h-9 w-9` **white** icon tile carrying `ring-1 ring-slate-200/70` and a
   coloured glyph, the label beside the tile, the numeral
   `text-3xl font-semibold tracking-tight`, and a caption under. Accent order
   where the meaning matches: **blue** neutral, **emerald** good, **amber**
   waiting, **rose** needs attention. Only those four hues.
4. **Panels row** — under a `text-xs font-medium uppercase tracking-wide
   text-slate-400` section heading with `mb-3`; the grid is `items-stretch` and
   each panel `flex h-full flex-col` so a row is equal height. Every panel heading
   carries a one-line muted subtitle describing what it shows.
5. **Detail list** — activity feed, queue, or table.

- **Exactly one filled blue button per dashboard: the hero CTA.** Everything else
  is an outline button, a text link, or a card that happens to be a link. A second
  filled blue button means the page no longer has a primary action.
- **Amber means one thing: the single most actionable item.** At most one amber
  *notice* per page; any second notice drops to neutral slate (`bg-slate-50` +
  `ring-slate-200/70`), and the actionable notice sits above the informational
  one. Amber as a stat-card accent or a data-series colour is unaffected.
- Page rhythm is `space-y-6`.

## Conventions

- **Laravel**: RESTful API controllers, Form Requests for validation, Policies for
  authorization once introduced. Follow existing repo patterns before inventing
  new ones.
- **Vue**: pages in `src/pages/<role>/`, shared components in `src/components`,
  Pinia stores in `src/stores`, types in `src/types`. Inspect before creating.
- **Naming**: snake_case for DB columns, camelCase in TS/JS, StudlyCase for PHP
  classes.
- **Seeders must remain re-runnable** — `php artisan migrate:fresh --seed` must
  always work.

## Verification — Evidence Before Claims

Never say something is "done", "fixed", or "working" without running a check and
showing the output:

- **Backend**: `php artisan test` (or the specific test), plus
  `php artisan migrate:fresh --seed` when migrations changed.
- **Frontend**: `npm run build` (in `web/`) must succeed.
- **After fixing a bug**: reproduce it first if possible, then show the passing
  result.

## Common Commands

```bash
# API (run in repo root)
php artisan serve
php artisan migrate:fresh --seed
php artisan test
php artisan test --filter=TestName            # single test
php artisan test tests/Feature/Coordinator    # a whole role folder
composer install
./vendor/bin/pint                             # code style fixer (no composer script wired up)

# Backend + queue + logs + Vite together
composer run dev

# Manual triggers for the scheduled jobs
php artisan journal:run-weekly-bundling                    # optional --week-start=
php artisan journal:send-missing-entry-reminders --ignore-time
php artisan roster:purge-archived                          # optional --now=
php artisan dtr:auto-close-sessions                        # optional --now=

# Verify outbound mail works before relying on it for a roster import
php artisan mail:test you@example.com                      # optional --raw

# Web SPA (run inside web/)
npm install
npm run dev
npm run build      # no lint or test script configured; there is no frontend test runner yet

# Mobile (run inside mobile/)
npm install
npx expo start
```

`tests/Feature` splits into `Admin` / `Auth` / `Console` / `Coordinator` /
`Services` / `Student` / `Supervisor`. `tests/Unit` holds `Models` and
`Support` (the latter added for `GeoDistanceTest`, which exercises the haversine
geofence maths with no database at all, and now also
`ExitInterviewFormLayoutTest`, which pins the exit interview form's measured
geometry the same way — no database, just the numbers taken off the reference
PDF).

Three tests pin the 2026-08-27 journal-flexibility work specifically, and each
covers a rule that used to be the opposite — do not "restore" the old
behaviour they describe:
`JournalEntryTest::test_a_compiled_but_unsubmitted_week_leaves_its_daily_entries_writable`
(a late entry can still be written after bundling has run),
`WeeklyBundlingServiceTest::test_the_scheduled_job_leaves_a_returned_log_alone`
(the one point where the manual and scheduled compiles diverge), and
`WeeklyActivityLogTest::test_a_half_filled_row_is_saved_rather_than_lost`
(a partially-typed grid row reaches the database).

OJT-type coverage is two files, both added 2026-08-30.
`tests/Feature/Coordinator/BatchOjtTypeTest` pins the rules that used to be
unconditional — the default, enrolling at a company with no supervisor login,
the 422 that still fires for a supervisor-supported batch, the freeze once
anyone is enrolled (and that re-stating the same value is NOT a change), the
DTR gate on both sides, and above all
`test_a_coordinator_centered_intern_never_appears_on_a_supervisors_roster`,
which covers the mixed-company case that motivated the null-supervisor filter.
`tests/Feature/Coordinator/CoordinatorJournalReviewTest` covers the queue, both
verdicts, the notebook (drafts excluded, week numbers matching the PDF), the
interns index, the route-ordering hazard, and the boundary that matters most:
a coordinator is **403** on a supervisor-supported log. Four more cover the
2026-09-01 filters and the nav item, each pinning a rule that is easy to break
by touching only half of it: `test_the_queue_can_be_filtered_by_batch_and_by_company`,
`test_the_status_pill_counts_respect_the_filters` (counts and rows share one
query), `test_the_filter_options_do_not_shrink_to_the_current_selection`,
`test_the_interns_index_honours_the_batch_and_company_filters` (one filter bar,
both tabs), and
`test_the_auth_payload_reports_whether_the_coordinator_reviews_anything`.

DTR coverage lives in six files, and several of them exist to pin a bug that
was real rather than hypothetical — do not delete them as redundant:
`Unit/Support/GeoDistanceTest`, `Feature/Student/DtrPunchTest` (the toggle,
radius rejection, the `open_session_key` race, accuracy clamping, midnight
spans, the completed-student read/write split), `Feature/Supervisor/
DtrGeofenceAndReviewTest` (QR output, immovable coordinates, adjust/void
releasing a stuck student, and the retire → restore → delete lifecycle: a
restore that keeps the token, and both delete guards — refused while active,
refused once a single punch exists), `Feature/Services/DtrPurgeSafetyTest` (the
no-FK-to-`batch_students` invariant), and
`Feature/Coordinator/DtrPreferenceTest` (the opt-out reason's lifecycle — above
all that re-enabling CLEARS it, so a reason can never outlive the decision it
explained), and `Feature/Console/AutoCloseOpenDtrSessionsTest` (the auto
time-out: the assumed hours never count, closing releases `open_session_key`,
a session inside the threshold is left alone, a second run is a no-op, and a
supervisor's adjustment is never overwritten).

Two in `DtrPunchTest` earn their place specifically:
`test_a_stale_session_is_auto_closed_and_the_scan_reads_as_a_fresh_clock_in`
pins the cascade fix, and
`test_a_long_but_plausible_shift_still_clocks_out_normally` pins the other side
of the boundary — without it, tightening the threshold would silently turn every
long day into a flagged row.

### Seeded demo accounts

From `php artisan db:seed`, password `password`. **Log in by USERNAME** (email
also works for accounts that have one).

| Username | Role |
|---|---|
| `mdcadmin` | admin |
| `mdccore` | CAST/BSIT coordinator (DTR **on**) |
| `mdcbalbero` | CABM-B coordinator (Balbero, DTR **on**) |
| `mdcstudent` | enrolled student (CAST/BSIT) |
| `mdcsupervisor` | supervisor (TechPH Inc., `mdcstudent`'s company) |
| `mdcbalsup` | CABM-B company supervisor — Tagbilaran Cooperative Bank |
| `mdcbalintern1` · `mdcbalintern2` · `mdcbalintern3` | that supervisor's three interns |
| `mdcfield1` · `mdcfield2` · `mdcfield3` | interns on the **coordinator-centered** batch (no supervisor) |
| `system` | non-login automation account |

**`CabmbSupervisorDemoSeeder` is the clean supervisor world under
`mdcbalbero`.** The other CABM-B supervisors from `CabmbUsersDemoSeeder` are
realistic but their logins (`cabmb.sup.bsa`, `cabmb.sup.om2`, …) are awkward to
type and impossible to remember mid-demo. This one follows the `mdc*` convention
and gives a single supervisor a three-intern roster in Balbero's **BSBA-FM**
batch, so coordinator, supervisor and students all see each other.

It uses its **own** company deliberately: a company may have at most one
login-bearing supervisor (`guardSingleLogin`), so attaching `mdcbalsup` to a
company that already has one would be rejected by the app's own rule. The
company also carries a **named-only** contact (Mr. Elmer Bautista) so the
login-bearing vs named-only split is visible with no setup.

Both demo coordinators now have `dtr_enabled = true` so every role is testable
end to end. The opt-out is demonstrated live by switching it off in the
coordinator's own account menu, which is the real flow anyway.

**Both OJT types are seeded**, and the contrast is the point — see OJT Type
above. Every batch except one is supervisor-supported (`mdcbalsup` and the
`cabmb.sup.*` logins review those); `CabmbCoordinatorCenteredDemoSeeder` adds
**BSBA-OM 2026 Field Placement** under `mdcbalbero`, a coordinator-centered
cohort at a company with **no login-bearing supervisor at all** — so the demo
proves the branch in EnrollmentService rather than just showing a different
pill. Its three interns (`mdcfield1..3`) have five weeks of journals whose
approved/returned verdicts were given by the COORDINATOR, so
`/coordinator/journal-review` and its per-intern notebooks are non-empty on a
fresh seed. Deliberately a separate batch rather than flipping mdcbalsup’s:
flipping it would strip that supervisor’s entire world and trade one empty demo
for another.

**Supervisor workload demo** — two seeders added 2026-08-28, both keyed to
`mdcbalsup` and both running immediately after `CabmbSupervisorDemoSeeder`.
Before them that account had a roster and nothing else: all three Journals tabs
empty, every notebook saying "has not submitted any weekly journals yet", four
zeroes on the dashboard, and a Daily Time Record page with no sites and no rows.

`CabmbSupervisorJournalDemoSeeder` — **six weeks** of journals for the three
interns, spread rather than uniform so every surface has something in it:

| Intern | Approved | Returned | Pending | Never submitted |
|---|---|---|---|---|
| Jomar Bactol | 3 | 1 | 1 | 1 |
| Rhea Lumapas | 4 | — | 2 | — |
| Kenneth Auza | 3 | 1 | 1 | 1 |

- **The narrative is compiled in `WeeklyBundlingService`'s exact shape**
  (`"MONDAY\n<text>\n\nTUESDAY\n<text>"`), and this is the point of the seeder
  rather than a detail. `SupervisorReviewDemoSeeder`'s narrative is one flat
  paragraph, so the day-header parsing that `WeeklyJournalPaperView` and
  `pdf.weekly-log` both perform **never actually showed on demo data**. A seeded
  log that does not look like a bundled one demonstrates a document format the
  app does not produce.
- Every daily entry carries `daily_accomplishment` (the one key bundling
  compiles from) **plus the SIPP trio**, so the coordinator's Annual SIPP report
  picks up rows from these students too.
- **Two interns keep an unsubmitted week**, so the notebook's "still being
  drafted" line and the gap in its Week numbering appear on real data and not
  only in a test.

`CabmbSupervisorDtrDemoSeeder` — three clock-in sites and ~12 working days of
punches per intern. The sites are chosen to show the whole lifecycle at once:

- `Main Branch — Front Entrance` — **active**, clean capture, has records.
- `Vault Annex (2F)` — **retired WITH records** → Restore only, and the row says
  why it cannot be deleted.
- `Test — do not use` — **retired with ZERO records** → the one row that offers
  Delete permanently.

That contrast is the demonstration: the delete guard is visible as a difference
between two adjacent rows rather than something you have to read the code to
know about. Sessions cover `closed` (banking real hours against the batch's
486), one `flagged` per intern (the auto time-out shape — no `time_out`, an
assumed 8h that counts zero) so Needs attention is non-empty, and one `void`.

**Both seeders write timestamps anchored to `Asia/Manila`, never
`->setTime()`.** This is a real bug that was found and fixed on screen, not a
precaution. `setTime(8, 0)` writes 08:00 in the APP's timezone, and
`config('app.timezone')` defaults to **UTC** while deployments set Asia/Manila —
so on a normal dev box an 08:00 seed is 08:00Z, which the SPA renders in the
viewer's own timezone as **4:00 PM**: a morning shift reading as an afternoon
one. The same shift moved every `submitted_at` a day later in the review queue
(a 21:00Z submission is 5am the next day in Manila). Each seeder carries a
`manila()` helper that builds the instant in Asia/Manila and converts — correct
under both configurations, because it describes the moment rather than a number
on a clock. This does NOT apply to real punches, which `DtrService` stamps with
`now()` at the actual instant and which therefore always displayed correctly.

Both are re-runnable: weekly logs are located by (student, batch, `week_start`)
and sessions by (student, `work_date`) with **`whereDate()`**, never plain
equality — those are `date`-cast columns and SQLite keeps a time component on
them, so `where()` would miss the previous run's row and insert a duplicate.
Sites are keyed on (company, label).

**Both run under `DatabaseSeeder`'s `WithoutModelEvents`, which mutes
`CompanyGeofence::booted()` (generates `token`) and `DtrSession::booted()`
(keeps `open_session_key` in step with `status`).** Every value those hooks
would supply is written explicitly. Dropping either breaks the seed: `token` is
NOT NULL and unique, and a non-null `open_session_key` on a closed session
would hold that student's slot in the one-open-session unique index forever.

**Weekly and Time Log Summary demo** (`CabmbWeeklyTimeLogDemoSeeder`, added
2026-08-27) gives **mdcbalbero** eight sheets across **six students in all four
CABM-B programs** — `mdcbalintern1` · `mdcbalintern2` · `mdcbalintern3`
(BSBA-FM) · `cabmb.bsa1` (BSA) · `cabmb.mm1` (BSBA-MM) · `cabmb.om1` (BSBA-OM).
Six would only need five; spreading them over four programs is what makes the
coordinator page's **Program filter** mean anything, and three of the six are
the memorable `mdcbalintern*` logins so the same roster is reachable as
coordinator, as their supervisor (`mdcbalsup`) and as each student.

- **The Period Covered is THREE MONTHS** — 13 weeks, one sheet for the whole
  internship quarter, which is what 486 SIPP hours works out to at 8h/day. It is
  anchored to the **batch's own `start_date`**, never to `now()`, so it always
  lands inside the batch window whenever the database is seeded, and it is a
  whole number of weeks so the weekly rows tile it exactly.
- **Only FINISHED weeks get a row.** The declared period runs to the end of the
  quarter while the rows stop at last Friday — exactly what a coordinator sees
  opening a sheet mid-placement. Seeding a row dated in the future would be
  visibly wrong on a form a supervisor signs by hand.
- `mdcbalintern1` and `cabmb.bsa1` each get a **second, single-week sheet**, so
  "one student, several sheets" and the page's Period From / Period To filter
  both have something to act on.
- A 3-month sheet carries 9 rows and therefore prints on **two pages**. That is
  correct, not a regression — the one-page guarantee in the PDF section above is
  for the blank/sparse form; the table is documented to grow past
  `MIN_FORM_ROWS`.
- Activities are drawn from four program-appropriate 13-week vocabularies
  (banking / accounting / marketing / operations) and **rotated per student**, so
  three interns at the same bank do not file byte-identical sheets. Each row's
  signatory is the company's login supervisor, with the position read off
  `company_supervisors` rather than hardcoded.
- Re-runnable like every other seeder: the sheet is found by
  (student, batch, `week_start`) with **`whereDate()`**, never plain equality —
  `week_start` is a `date`-cast column and SQLite keeps a time component on it,
  so equality would miss the previous run's row and insert a duplicate. Rows are
  keyed by (sheet, `sort_order`) and any row left over from a longer previous run
  is pruned.

**Exit interview demo** (`CabmbExitInterviewDemoSeeder`, added 2026-08-30)
gives the same three `mdcbalintern*` logins one form each, **one in every
state**: `mdcbalintern1` **reviewed** (coordinator's block filled in, "With
pending requirements" ticked, so the printed PDF shows a ticked box),
`mdcbalintern2` **submitted** (the one row that needs the coordinator to act),
`mdcbalintern3` **draft** (visible in the list but refused for sign-off). The
three states are the point rather than the count — the Status filter and the
"a draft cannot be signed off" rule are only demonstrable side by side.
Answers are written in three distinct voices so a coordinator paging through
them is reading three students, and each fits its printed rules. Its
`interviewedOn()` anchors to **Asia/Manila** and **clamps the date to the
past**: the batch runs on beyond today, so a naive "start + 10 weeks" printed
a FUTURE interview date onto a form a coordinator signs by hand — caught on
screen, not in theory. See Exit Interview above.

**Intake-flow demo** (`CabmbIntakeDemoSeeder`, CABM-B under Balbero, both
NOT-enrolled, re-armed each seed): `mdcintake` has a **draft** sheet (log in
gated, fill, choose company, submit), and `mdcintake2` has a **submitted** sheet (carrying a pinned company location, so the sketch box on the demo PDF shows a real map)
naming a `supervisor_name` deliberately distinct from that company's login
supervisor — so it sits in Balbero's Submitted queue ready to **Accept**, a live
demonstration of the login-vs-named-individual split.

**Removed 2026-08-20** as dead weight: the one-off `PreOralDefenseDemoSeeder`
(and its `docs/PRE-ORAL-DEFENSE-DEMO-GUIDE.txt`), plus `DepartmentSeeder` and
`ProgramSeeder`, which `DepartmentProgramSeeder` had superseded and which
nothing referenced. Every seeder still present is registered in
`DatabaseSeeder` — except `ProductionSeeder`, which is deployment-only and must
never be run alongside the demo set.

## Workflow Preferences (project owner)

- Propose a plan for any multi-file change before implementing; wait for approval.
- Prefer direct numbered steps with exact terminal commands over conceptual
  explanations.
- When a task touches the database, always cross-check the schema first.
- If instructions in this file conflict with what you find in the repo, **stop and
  ask instead of guessing.**

## Roadmap Context

Seven-phase roadmap. Phases 1-2 (scaffolding, auth, base schema, admin
identity/role management) are complete. Coordinator, student, and supervisor
modules are built out. **Phase 7 (Expo mobile) is now ACTIVE, at the project
owner's explicit direction** — mobile auth and endpoints are wired (see the
`mobile/` bullet under Project Overview), so the former "do not wire mobile auth
yet" hold no longer applies.
