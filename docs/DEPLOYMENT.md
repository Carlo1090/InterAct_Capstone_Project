# InternTrack — Deployment Guide

**Audience:** whoever is actually clicking through the hosting dashboards.
**Goal:** get InternTrack publicly reachable for the capstone defense at **zero
cost** — no paid tier, and no credit card required anywhere in this path.

Everything in the repo is already prepared for this. What remains is creating
three free accounts, pasting environment variables, and filling one placeholder.

> **Current status:** the application code is deploy-ready (416 tests passing,
> SPA build clean, `composer audit` clean). The Docker image has **never been
> built** — there is no Docker daemon on the machine it was written on, so
> Render's first build log is its first real test. Budget time for one or two
> build fixes.

---

## 1. The stack, and why each piece

| Layer | Service | Cost | Notes |
|---|---|---|---|
| Vue SPA (`web/`) | **Vercel Hobby** | Free | Static Vite build. No card. |
| Laravel API (repo root) | **Render**, free web service, Docker | Free | No card. **Sleeps after 15 min idle**; first request then takes 30–60s. |
| Database | **Aiven** managed MySQL, free plan | Free | No card, 1 GB, **does not expire**. |
| Scheduled jobs | **cron-job.org** → `/api/cron/run` | Free | Render cron has no free tier, so an external scheduler pings an endpoint instead. Setup: [`CRON-AND-EMAIL-SETUP.txt`](CRON-AND-EMAIL-SETUP.txt). |
| Avatar storage | container disk (ephemeral) | Free | Uploaded photos are lost on redeploy. See [§9](#9-known-limitations-of-this-free-stack). |

Two choices worth understanding before you start, because they explain
configuration that otherwise looks arbitrary:

**The database is Aiven, not Render's own.** Render's free PostgreSQL **expires
30 days after creation**, and this app is built on MySQL — switching database
engines shortly before a defense is risk with no upside.

**The SPA reaches the API through Vercel rewrites, not cross-domain calls.**
`web/vercel.json` forwards `/api/*`, `/sanctum/*`, `/auth/*` and the
password-reset routes to the API host. The browser therefore only ever sees
**one origin**, which keeps the Laravel session cookie first-party. The
alternative — calling the API directly on its own domain — needs
`SameSite=None`, which makes login depend on third-party cookies that Safari and
hardened browsers block outright. Several settings below exist *only* to support
this proxy, and each is flagged.

---

## 2. Before you touch a dashboard

**Confirm you can install GitHub Apps on the repo.** Render and Vercel each
install one on `Carlo1090/InterAct_Capstone_Project`. As the repo owner this is
yours to approve; if a teammate is doing the setup instead, you'll need to
approve it for them.

**Collect these four values first** — having them ready turns the rest into
copy-paste:

| Value | Where it comes from |
|---|---|
| `APP_KEY` | Run `php artisan key:generate --show` locally. Copy the whole `base64:...` string. Do **not** let it overwrite your `.env`. |
| `DEMO_PASSWORD` | Invent one. **12+ characters, and not `password`.** See the warning below. |
| Vercel project name | Decide now (e.g. `interntrack`) so you can predict `interntrack.vercel.app` and skip a round trip. |
| Aiven MySQL credentials | From step 3 below: host, port, database, user, password. |

> **Why `DEMO_PASSWORD` is mandatory.** This repository is **public**, and
> `CLAUDE.md` documents both the seeded demo usernames (`mdcadmin`, `mdccore`,
> `mdcbalbero`, `mdcstudent`, …) and the fact that they all use `password`.
> Deploying the demo dataset as-is hands anyone who finds the repo an **admin
> login**. The deploy pipeline refuses to seed demo data without a replacement
> password, and rotates every account immediately after seeding.

---

## 3. Aiven — the database

1. Sign up at [aiven.io](https://aiven.io) and create a **MySQL** service on the
   **free** plan. Pick the region closest to the Philippines.
2. Wait for it to report *Running* (a few minutes).
3. From the service overview, copy: **host**, **port**, **database name**,
   **user**, **password**.

Nothing else here. Migrations run automatically on the API's first boot.

---

## 4. Render — the API

1. Sign up at [render.com](https://render.com) with GitHub and grant access to
   the repo.
2. **New → Blueprint**, select the repo. Render reads
   [`render.yaml`](../render.yaml) and pre-configures the service: free plan,
   Singapore region, Docker build, `/up` health check, and — importantly — it
   deploys the **`deploy` branch, not `main`** (see [§8](#8-deploying-changes-later)).
3. Fill in the variables Render prompts for (these are marked `sync: false` in
   the blueprint, meaning "never commit this"):

   | Variable | Value |
   |---|---|
   | `APP_KEY` | the `base64:...` string from §2 |
   | `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from Aiven |
   | `SEED_ON_BOOT` | `demo` |
   | `DEMO_PASSWORD` | your 12+ character password |
   | `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `GOOGLE_*` | **leave blank for now** — filled in §6 |
   | `CRON_SECRET`, `MAIL_*` | **leave blank for now** — see [`CRON-AND-EMAIL-SETUP.txt`](CRON-AND-EMAIL-SETUP.txt). A blank `CRON_SECRET` disables the cron endpoint (404) rather than leaving it open, so skipping it is safe |

   Everything else (`APP_ENV=production`, `APP_DEBUG=false`,
   `APP_TIMEZONE=Asia/Manila`, session and queue settings) is already committed
   in the blueprint and needs no input.

4. Deploy, and **read the build log**. This image has never been built before;
   see [§10](#10-troubleshooting) if it fails.
5. When it goes live, **copy the assigned URL** — something like
   `https://interntrack-api.onrender.com`.
6. Sanity check, before going further:

   ```bash
   curl -i https://YOUR-API.onrender.com/up
   ```

   Expect **200**. If this fails, stop and fix it here — nothing downstream can
   work until it passes.

---

## 5. Vercel — the SPA

1. **Edit `web/vercel.json` and replace every `REPLACE-WITH-API-HOST`** with
   your Render hostname (no `https://`, no trailing slash — the scheme is
   already in the file). There are **7** of them.

   ```bash
   # from the repo root, on the deploy branch
   sed -i 's|REPLACE-WITH-API-HOST|interntrack-api.onrender.com|g' web/vercel.json
   git commit -am "Point the SPA proxy at the deployed API"
   ```

   > These cannot be environment variables. **Vercel does not interpolate env
   > vars inside `vercel.json` rewrites** — the destination must be a literal.

2. Sign up at [vercel.com](https://vercel.com), **Add New → Project**, import
   the repo.
3. **Set Root Directory to `web`.** This is the single most common mistake — the
   repo root is a Laravel app, and Vercel will fail or deploy nothing useful if
   pointed there.
4. **Settings → Git → Production Branch → `deploy`.** There is no `vercel.json`
   field for this, so it must be set in the dashboard. (`vercel.json` already
   disables deployments for `main`.)
5. **Add no environment variables.** The SPA needs none.

   > **Specifically, do NOT set `VITE_BACKEND_URL`.** Leaving it unset is what
   > keeps the two Google entry-point links relative so they ride the proxy.
   > Setting it to the API origin breaks Google sign-in in a way that looks like
   > a Google fault: the browser goes straight to the API host, so the session
   > cookie the callback creates lands on the API's domain while the SPA reads
   > the Vercel domain, and the user is silently returned to the login page.

6. Deploy, then copy the assigned domain (e.g. `interntrack.vercel.app`).

---

## 6. Close the loop — back to Render

Render and Vercel each need the other's URL, so return to Render now and set the
four variables left blank in §4:

| Variable | Value | Why it must be exactly this |
|---|---|---|
| `APP_URL` | `https://YOUR-API.onrender.com` | The API's **own** origin. |
| `FRONTEND_URL` | `https://YOUR-APP.vercel.app` | Read by CORS, and by the Google callback when bouncing the browser back into the app. |
| `SANCTUM_STATEFUL_DOMAINS` | `YOUR-APP.vercel.app` | **Host only — no `https://`, no trailing slash.** The **Vercel** host, not the API's. Sanctum decides "is this my frontend?" from the request's `Referer`/`Origin`, and through the proxy that is the Vercel domain. Get this wrong and login *appears* to succeed while every authenticated request 401s. |
| `GOOGLE_REDIRECT_URI` | `https://YOUR-APP.vercel.app/auth/google/callback` | The **Vercel** origin, not the API's — see §7. |

Leave `SESSION_DOMAIN` **empty** (it already is). An empty value makes the
session cookie host-only, which is what lets it attach to the Vercel domain the
browser thinks it is talking to. Setting it to the API's domain breaks login.

Redeploy. Then **remove `SEED_ON_BOOT`** from the environment — the service
re-runs its entrypoint every time it wakes from sleep, and leaving it set means
a later wake could re-seed over live data.

---

## 7. Google sign-in (optional)

Google sign-in is purely additive — **username + password login always works
without it.** If you skip this, leave `GOOGLE_CLIENT_ID` and
`GOOGLE_CLIENT_SECRET` blank and the buttons cleanly report "not set up on this
server yet".

To enable it, in [Google Cloud Console](https://console.cloud.google.com):

1. **APIs & Services → Credentials →** your OAuth client (or create a **Web
   application** one).
2. Under **Authorized redirect URIs**, add **exactly**:
   `https://YOUR-APP.vercel.app/auth/google/callback`
   Scheme, host and path must match `GOOGLE_REDIRECT_URI` character for
   character, or Google refuses the request.
3. **OAuth consent screen:** while it is in *Testing*, only Gmail addresses
   listed under **Test users** can sign in. **Add every panelist's Gmail**, or
   publish the consent screen. Scopes are `email` + `profile`, both
   non-sensitive, so publishing needs no Google review.

> **Why the redirect URI points at Vercel and not the API.** The callback signs
> the user in, and the browser stores that session cookie against whichever host
> it believes it called. Send Google straight to the API host and the cookie
> lands on the API's domain while the SPA reads the Vercel domain — the user is
> silently returned to the login page and it looks like sign-in failed. Routing
> the callback through Vercel keeps everything on one origin.

Also note: Google sign-in only works for an account whose email was **already
verified through Google**. It never creates accounts.

---

## 8. Deploying changes later

**Pushing to `main` deploys nothing.** That is deliberate: `render.yaml` tracks
the `deploy` branch and `web/vercel.json` disables Vercel builds for `main`, so
day-to-day work cannot break the live demo, and a half-finished migration cannot
reach live data (the container runs `migrate --force` on every boot).

To ship:

```bash
git checkout deploy
git merge main
git push origin deploy      # Render + Vercel both rebuild
git checkout main
```

What is **not** affected by ongoing work:

- **Local edits and commits** — zero effect on the deployed copy.
- **The database** — Aiven is external, so redeploys and sleep/wake cycles never
  touch the data.
- The only thing lost on redeploy is **uploaded avatars** (ephemeral container
  filesystem).

---

## 9. Known limitations of this free stack

| Limitation | Impact | Mitigation |
|---|---|---|
| API sleeps after 15 min idle | First request takes 30–60s | Open the site a minute before demoing, or point a free pinger (cron-job.org, UptimeRobot) at `/up` every 10 min |
| No cron | Weekly bundling, the nightly archive purge, and hourly journal reminders have no scheduler | **Solved** — set `CRON_SECRET` and point cron-job.org at `/api/cron/run` hourly. See [`CRON-AND-EMAIL-SETUP.txt`](CRON-AND-EMAIL-SETUP.txt). (The admin → System Settings manual triggers this table used to recommend were removed on 2026-08-01; the endpoint replaces them.) |
| Ephemeral filesystem | Uploaded avatars vanish on redeploy | Set `AVATAR_DISK=r2` plus the `R2_*` variables — already wired, pure env flip, but R2 needs a card on Cloudflare |
| `MAIL_MAILER=log` is the default | No email is sent at all until you change it | Intentional, so a deploy cannot email real students by accident. To send for real, add the Gmail app-password variables — see [`CRON-AND-EMAIL-SETUP.txt`](CRON-AND-EMAIL-SETUP.txt) §3 |
| Email needs a *verified* address | Even with SMTP configured, a fresh seed emails **nobody** | Not a bug. `email_verified_at` is set **only** by Google "Verify with Google", so reminder email depends on §7 being finished first. Unverified students still get the in-app bell row |
| Aiven free: 1 GB | Ample for a demo | — |

---

## 10. Troubleshooting

Symptoms mapped to their actual cause. Most of these are one wrong environment
variable.

| Symptom | Cause | Fix |
|---|---|---|
| Container starts then is killed as unhealthy | Apache not listening on Render's `$PORT` | The entrypoint handles this; check the log for `Binding Apache to port` |
| Log: `APP_KEY is not set` and exit | Deliberate hard fail | Set `APP_KEY` (§2) |
| Log: `APP_DEBUG=true with APP_ENV=production` and exit | Deliberate hard fail — would leak stack traces publicly | Set `APP_DEBUG=false` |
| Login **page** returns 405 | Something re-added a `/login` rewrite to `vercel.json` | The SPA must post to **`/auth/login`**; `/login` is the SPA's own page route, and Vercel rewrites cannot match on HTTP method |
| Login succeeds, then **every** request 401s | `SANCTUM_STATEFUL_DOMAINS` wrong | Must be the **Vercel host, no scheme** (§6) |
| Logged out immediately after logging in | `SESSION_DOMAIN` is set | Leave it **empty** |
| Google sign-in returns to the login page as if it failed | `GOOGLE_REDIRECT_URI` points at the API instead of Vercel | See §7 |
| In-app: *"Google sign-in is not set up on this server yet"* | `GOOGLE_CLIENT_ID` or `GOOGLE_CLIENT_SECRET` is blank on the API. **This is the app failing safely on purpose**, not a bug | Set both on Render, plus `GOOGLE_REDIRECT_URI`, then redeploy (§7) |
| Google: *"Access blocked — Missing required parameter: client_id"* | Should no longer be reachable — the guard above catches it first | If you see it, the guard was bypassed; check `config/services.php` |
| Google sign-in returns *"That Google account is not linked"* | **Expected on fresh data.** No seeded account has a Google-verified email | Sign in with username + password first, then Edit Profile → **Verify with Google**. Only then does Google *sign-in* work |
| Google sign-in silently returns to the login page | `VITE_BACKEND_URL` was set on Vercel | Remove it and redeploy the SPA — see §5 step 5 |
| Google: `cURL error 60: unable to get local issuer certificate` | No CA bundle in PHP | Should not happen on this image (Debian ships `ca-certificates`). If you move hosts, point `curl.cainfo` and `openssl.cafile` at `cacert.pem` |
| Profile photo broken, **404** | Wrong host/port in `APP_URL` | Set `APP_URL` to the real API origin |
| Profile photo broken, **403** | The file genuinely is not on disk | Expected after a redeploy — ephemeral filesystem (§9) |
| Demo logins rejected | `demo:set-password` rotated them | Use your `DEMO_PASSWORD`, not `password` |
| Migrations fail on first boot | DB credentials or Aiven not reachable | Re-check the five `DB_*` values; confirm the Aiven service is *Running* |
| `/api/cron/run` 404s with the right key | `CRON_SECRET` unset, or the service has not redeployed since you set it | Set it, wait for the redeploy to go green. A blank secret disables the endpoint **by design** |
| Cron runs 200 but `emailed: 0` | Usually correct: nobody has a Google-verified address, or it is nobody's chosen reminder hour, or they were already reminded today | See the email row in §9 |
| Mail: `The "tls" scheme is not supported` | `MAIL_SCHEME=tls` | Set it to `null` — port 587 negotiates STARTTLS itself. Symfony Mailer only accepts `smtp`/`smtps` |
| Mail: `Failed to authenticate on SMTP server` | Using the Gmail account password instead of a 16-character App Password | Generate an App Password (2-Step Verification must be on first) and paste it with the spaces removed |
| Email arrives signed *"Laravel"* | `APP_NAME` not set | Set `APP_NAME=InternTrack` |

---

## 11. Post-deploy verification

Run these in order. Each one isolates a different layer, so the first failure
tells you where the problem is.

```bash
API=https://YOUR-API.onrender.com
APP=https://YOUR-APP.vercel.app

# 1. API is alive (bypasses the proxy entirely)
curl -s -o /dev/null -w "health: %{http_code}\n" $API/up
#    expect 200

# 2. The SPA is served
curl -s -o /dev/null -w "spa: %{http_code}\n" $APP/
#    expect 200

# 3. The rewrite proxy actually reaches Laravel.
#    401 is the CORRECT answer here — it proves Laravel replied, unauthenticated.
#    404 or an HTML body means the rewrite is wrong or the placeholder was missed.
curl -s -o /dev/null -w "proxy: %{http_code}\n" $APP/api/user
#    expect 401

# 4. Self-service registration really is closed (security check).
#    Anyone who could reach this endpoint used to get an active student account.
curl -s -o /dev/null -w "register: %{http_code}\n" -X POST $API/register \
  -H "Accept: application/json" \
  -d 'name=x&email=x@example.com&password=xxxxxxxxxxxx&password_confirmation=xxxxxxxxxxxx'
#    expect 404

# 5. A deep link survives a hard refresh (SPA history fallback)
curl -s -o /dev/null -w "deeplink: %{http_code}\n" $APP/coordinator/batches
#    expect 200
```

Then in a browser:

1. Log in as the admin with your `DEMO_PASSWORD`. *(First load may take 30–60s
   if the service was asleep.)*
2. Hard-refresh on an inner page — it must stay put, not 404.
3. Open a coordinator report and **download a PDF** — this exercises dompdf,
   memory limits, and fonts all at once.
4. Log in as a student and open the journal calendar; confirm **today's date is
   correct** (this is what `APP_TIMEZONE=Asia/Manila` is for).
5. Check the notification bell and one report page for console errors.

---

## 12. Never do these

- **Never run bare `php artisan db:seed`** on the deployment. `DatabaseSeeder`
  calls twelve *demo* seeders and creates ~30 fictional accounts with a password
  published in this repo. Use `SEED_ON_BOOT=demo` (which rotates them) or
  `--class=ProductionSeeder`.
- **Never set `APP_DEBUG=true`** in production — the app refuses to boot, by
  design, because it would leak stack traces publicly.
- **Never commit a filled-in `.env`.** `.env` is gitignored; the deployment
  reads real environment variables. `.env.production.example` is the template
  and is safe to commit because it holds no values.
- **Never leave `SEED_ON_BOOT` set** after the first successful boot.
- **Never point `SANCTUM_STATEFUL_DOMAINS` at the API's own domain.** It is the
  Vercel host.

---

## Reference

- [`.env.production.example`](../.env.production.example) — every variable, with
  the reasoning inline
- [`render.yaml`](../render.yaml) — API service definition
- [`web/vercel.json`](../web/vercel.json) — SPA build + proxy rewrites
- [`Dockerfile`](../Dockerfile) / [`docker/entrypoint.sh`](../docker/entrypoint.sh)
  — image build and boot sequence
- [`CLAUDE.md`](../CLAUDE.md) — architecture, domain rules, and the full
  deployment decision record (search for **DECIDED STACK**)
