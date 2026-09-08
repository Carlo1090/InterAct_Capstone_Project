---
name: run-interntrack
description: Build, run, and drive InternTrack (Laravel API + Vue SPA) — the capstone OJT monitoring app at the repo root and web/. Use when asked to start the app, log in as a demo user, take a screenshot of a page, or interact with the running app end to end. Not for mobile/ (Expo scaffold, deferred).
---

InternTrack is a Laravel 13 API (repo root) plus a Vue 3 SPA (`web/`) that
talks to it over cookie/session auth (Sanctum). "Running" it means both
processes up together; "driving" it means a real browser, because login goes
through a client-side CSRF/cookie dance a raw `curl` script cannot do. Drive
it via `.claude/skills/run-interntrack/driver.mjs`, a small Playwright
script piped a line-based command script over stdin (same idea as
`chromium-cli`, hand-rolled here since that tool isn't installed on this
machine).

All paths below are relative to the repo root (`InterAct_Capstone_Project/`),
not to this skill directory, unless stated otherwise.

This is a native Windows dev machine (WinGet PHP, Node, Composer, and a
Laragon-style local MySQL already installed) — not a disposable Linux
container. There is no `apt-get` here; skip straight to Setup.

## Prerequisites

Already present and verified on this machine — nothing to install to run the
app itself:

- PHP 8.5.5 (WinGet), Composer 2.9.4
- Node v22.19.0, npm 11.11.0
- MySQL listening on `127.0.0.1:3306` (Laragon), database `intertrack`,
  already migrated and seeded with the project's standard demo accounts

If any of those are missing, `PROJECT.md`'s Gotchas section (Windows/tooling
notes, the PHP CA-bundle issue, the Laragon-port-80 vs `artisan serve`
distinction) covers the actual fixes for this exact stack — don't re-derive
them here.

For the driver only, `web/`'s own dependencies:

```bash
cd .claude/skills/run-interntrack
npm install
```

This pulls in `playwright` (^1.62.1). It found Chromium already cached at
`%LOCALAPPDATA%\ms-playwright\chromium-1234` on this machine and launched
without downloading anything — if that cache is empty on another machine, run
`npx playwright install chromium` once.

## Setup

```bash
composer install
cp .env.example .env        # skip if .env already exists — see below
php artisan key:generate    # skip if APP_KEY is already set
php artisan migrate
cd web && npm install
```

`.env` on this machine already points at the real local MySQL DB
(`DB_CONNECTION=mysql`, `DB_DATABASE=intertrack`) and is already migrated and
seeded — confirmed via `php artisan migrate:status` (every migration `Ran`)
and a direct query (`mdcstudent` exists, 36 users total). **Do not run
`php artisan migrate:fresh --seed` against it without checking with the
project owner first** — it drops every table, and this is a live local dev
database, not a disposable fixture. If you're setting up a genuinely fresh
DB, `migrate:fresh --seed` is the documented, re-runnable way to get the
standard demo accounts (see PROJECT.md's "Seeded demo accounts" table).

## Build

No separate build step is required to *run* the app — `php artisan serve`
serves the API directly and Vite serves the SPA source in dev mode. A
production-style static build (for e.g. testing the Vercel artifact) is:

```bash
cd web && npm run build   # → web/dist/, verified: "built in 3.26s"
```

## Run (agent path)

Check whether the app is already running before launching it — this machine
had it up already from a previous session:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/up      # 200 = API up
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:5173/        # 200 = SPA up
```

If both are already `200`, skip straight to **Drive** below — do not kill and
relaunch a server you didn't start; another session may be using it.

**To launch from cold** (background both, from the repo root):

```bash
php artisan serve &
(cd web && npm run dev) &
timeout 30 bash -c 'until curl -sf http://localhost:8000/up >/dev/null; do sleep 1; done'
timeout 30 bash -c 'until curl -sf http://localhost:5173/ >/dev/null; do sleep 1; done'
```

Or the one-command form that also runs the queue listener and log tailer
(`composer.json`'s `dev` script): `composer run dev` (foreground, prints all
four processes interleaved, `Ctrl-C` stops all of them via `--kill-others`).

**To stop** a server you launched yourself: find and kill the listener on the
port, don't `pkill -f php` (too broad — can hit an unrelated PHP process):

```bash
netstat -ano | grep ':8000' | grep LISTENING   # note the PID in the last column
# then, from PowerShell: Stop-Process -Id <pid> -Force
```

### Drive

```bash
cd .claude/skills/run-interntrack
node driver.mjs --base http://localhost:5173 --session smoke <<'EOF'
nav /login
wait-for text=Welcome Back
screenshot login-page
login mdcstudent password
wait-for text=Hello
screenshot student-dashboard
console
EOF
```

Verified this session — output was:

```
> nav /login
> wait-for text=Welcome Back
> screenshot login-page
saved screenshots\smoke\login-page.png
> login mdcstudent password
> wait-for text=Hello
> screenshot student-dashboard
saved screenshots\smoke\student-dashboard.png
> console
(no console/page errors captured)
```

Both screenshots were inspected and show the real rendered app: the frosted
login card over the InternTrack splash panel, and Juan Dela Cruz's populated
student dashboard (progress gauges, company, coordinator, this-week strip).

Screenshots land in `.claude/skills/run-interntrack/screenshots/<session>/`
(gitignored). Login credentials for every role are the seeded demo accounts —
see PROJECT.md's "Seeded demo accounts" table (`mdcstudent` / `mdcsupervisor`
/ `mdccore` / `mdcbalbero` / `mdcadmin`, all password `password`).

**`--width` / `--height` set the viewport** (default 1280x900). PROJECT.md's
responsive rules — the dual table/card list layouts, the `<select>`-width
guard, the mobile-overflow audit — can only be checked below the `sm`/`md`
breakpoints, and at the default width the phone layout never renders at all.
Use `--width 390 --height 844` for a phone. The overflow check that goes with
it is `document.documentElement.scrollWidth > clientWidth` (page-level) and
`[...document.querySelectorAll('td,th')].filter(e => e.scrollWidth > e.clientWidth + 1)`
(cell-level).

Driver commands (one per line over stdin, blank lines and `#` comments
ignored):

| command | what it does |
|---|---|
| `nav <path-or-url>` | Navigate; a relative path resolves against `--base` |
| `login <username> <password>` | Fills `#identifier`/`#password`, submits, waits for the post-login redirect off `/login` |
| `wait-for text=<text>` | Wait for visible text (partial match) |
| `wait-for sel=<css>` | Wait for a CSS selector to be visible |
| `click <css \| text=...>` | Click; `text=...` uses `getByText`, otherwise a CSS locator (`:has-text()` works) |
| `fill <css> <text...>` | Fill an input/textarea |
| `press <key>` | `Keyboard.press` on the page, e.g. `Enter` |
| `screenshot [name]` | Full-page PNG, auto-settled past paint/animation races (see Gotchas) |
| `url` | Print the current URL |
| `console` | Print collected `console.error`/uncaught-exception lines since launch |
| `eval <js>` | `page.evaluate(js)`, prints the JSON result |
| `sleep <ms>` | Explicit wait — last resort, prefer `wait-for` |

For iterative debugging, run the same script under `tmux` and `send-keys`
one command at a time instead of piping a whole heredoc.

## Run (human path)

```bash
php artisan serve      # terminal 1 — API on :8000
cd web && npm run dev  # terminal 2 — SPA on :5173, open http://localhost:5173
```

Or `composer run dev` for all four processes (server, queue listener, log
tailer, Vite) in one foregrounded terminal.

## Test

```bash
php artisan test    # verified 2026-09-08: 631 passed, 2413 assertions, ~40s
cd web && npm run build   # verified: no separate test/lint script in web/, build is the check
```

`PROJECT.md` notes one pre-existing weekend-only flaky test in the reminder
suite (fails on main too, Saturday/Sunday only) — irrelevant today (Monday),
not encountered in this run.

---

## Gotchas

- **`waitUntil: 'load'` silently drops the login card from every screenshot,
  even seconds later — use `'networkidle'`.** Confirmed by direct comparison,
  not guessed: with `page.goto(url, {waitUntil:'load'})`, a full-page or
  even viewport-only screenshot of `/login` comes back as *only* the blue
  decorative background — the frosted-glass card (`backdrop-blur-2xl`) never
  gets composited by headless Chromium, regardless of how long you wait
  afterward. Screenshotting the card element directly (not full-page) *does*
  render it correctly under `load`, which is what pointed at a compositing
  race rather than a layout bug. Switching to `waitUntil: 'networkidle'`
  fixed it outright — plausible cause: under Vite dev, Tailwind's CSS is
  injected by a JS module after the document's `load` event, so `load`
  doesn't mean "the page actually looks like it will." `driver.mjs`'s `nav`
  and `login` both already use `networkidle`; keep it that way.
- **Even with `networkidle`, a screenshot taken immediately after `wait-for`
  can still race the compositor.** `wait-for` only proves an element passed
  Playwright's actionability check (present + visible), not that Chromium has
  actually painted a `backdrop-filter` layer for it — an immediate screenshot
  was flaky (worked some runs, blank on others) even navigating with
  `networkidle`. Fixed by settling in the `screenshot` command itself: a
  double `requestAnimationFrame` plus a fixed 400ms wait before capturing.
  Reproduced 3/3 consistent renders after adding this; don't remove it to
  "simplify" the driver.
- **The login form's entrance animation is real and cosmetic, not a bug.**
  `LoginPage.vue` sets an `entered` flag one frame after mount and CSS
  transitions stagger in over ~440ms+ (`.reveal`/`.entered .reveal` — see
  PROJECT.md's frontend conventions). A screenshot taken too early can catch
  the Login button mid-hover-gradient-transition; this is fine, not a
  regression — only a genuinely blank card (see above) is the real failure
  mode.
- **The dev servers were already running when this skill was authored.**
  `php -S 127.0.0.1:8000 ...` (PID owned by `php artisan serve`) and Vite on
  `:5173` were both live from a prior session on this machine. Always check
  with `curl` before launching — a second `php artisan serve` on the same
  port fails loudly, but a second Vite instance silently shifts to `:5174`
  and then every Sanctum request 401s because
  `SANCTUM_STATEFUL_DOMAINS`/`FRONTEND_URL` in `.env` still say `:5173` (this
  exact failure mode is already documented in PROJECT.md's Gotchas).
- **The shared Axios instance has no `baseURL`** (also in PROJECT.md) — this
  doesn't affect the driver, since it only ever navigates the SPA's own
  routes and lets the SPA make its own API calls, but if you're ever tempted
  to `eval fetch('/api/...')` directly from the driver against the SPA
  origin, remember relative API calls only work when they start with
  `/api/`.

## Troubleshooting

- **Driver hangs on `login`**: almost always means `/login` never actually
  redirected — check `console` right after for a 422/500, or that
  `mdcstudent` / `password` still matches a seeded account
  (`php artisan tinker --execute="..."` per PROJECT.md's Windows tinker
  note: use `tinker --execute="..."`, not the interactive REPL, on this
  setup).
- **A screenshot is blank except the blue background**: see the first two
  Gotchas above — this is the `networkidle`/compositor-settle issue, not a
  real app bug, *unless* `console` also shows real errors, in which case
  check those first.
- **New Vite instance on `:5174` instead of `:5173`**: something is already
  bound to `:5173` (probably the session already running per the Gotcha
  above). Don't chase the symptom by editing `.env` — find and stop the
  process actually holding the port instead.
