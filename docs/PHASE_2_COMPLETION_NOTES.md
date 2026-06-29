# InternTrack — Phase 2 Build Notes

This covers Identity, Roles & Account Management — built directly on the
now-confirmed-clean Phase 1 schema. As before: how to apply this, what's
genuinely verified vs. what needs your machine, and an important gotcha to
watch for given what's happened with the last two file drops.

---

## ⚠️ Before you copy these files in — please read

The last two rounds both ran into the same problem: the zip got *added
alongside* the old files instead of *replacing* them, which is what caused
the duplicate-migration mess. To avoid a repeat of that with the new files
below, please:

1. **Copy these files directly into your real repo folder**
   (`InterAct_Capstone_Project`), not into a separate `Downloads` folder
   that you then reference loosely.
2. **Overwrite in place** — `routes/api.php`, `bootstrap/app.php`, and the
   four web nav files already exist in your repo and need to be replaced,
   not duplicated alongside a second copy.
3. After copying, run `git status` and actually read what it lists as
   changed before committing — it should show **modified** files for
   things like `routes/api.php`, and **new** files for the controllers,
   requests, middleware, and observer. If it shows something unexpected
   (e.g. a nested folder structure, or files appearing as both old and new),
   stop and check before committing.

---

## 1. Role Middleware

**New:** `app/Http/Middleware/EnsureUserHasRole.php` — checks the
authenticated user's `role` column against a list of allowed roles, and
also blocks deactivated accounts (`is_active = false`) from getting through
at all, regardless of role.

**Changed:** `bootstrap/app.php` — registered the alias `'role' =>
EnsureUserHasRole::class`. Usage in routes looks like
`->middleware('role:admin')` or `->middleware('role:admin,coordinator')`
for routes more than one role can reach.

---

## 2. Auto-creating Student Profiles

**New:** `app/Observers/UserObserver.php` — listens for the `User` model's
`created` event. If the new user's `role` is `student`, it automatically
creates a matching `student_profiles` row with a temporary placeholder
`student_id_number` (format `PENDING-XXXXXXXX`) since the real registrar ID
isn't known at account-creation time. This needs to be filled in for real
later (worth deciding now: does the admin fill it in immediately, or does
the student supply it once during onboarding?).

**Changed:** `app/Models/User.php` — added the `#[ObservedBy([UserObserver::class])]`
attribute so the observer is actually wired up. This is the Laravel 13
attribute-based way (no service provider registration needed) — matches the
`#[Fillable]` style you're already using elsewhere.

**Why an observer instead of just code in the controller:** this way the
student profile gets created no matter *how* a student account comes into
existence — through the admin endpoint below, through a future seeder,
through `php artisan tinker`, or anything else — instead of only working if
that one controller method happens to be the thing that ran.

---

## 3. Admin Endpoints

**New controllers** in `app/Http/Controllers/Admin/`:

| Controller | Routes |
|---|---|
| `UserController` | list, create, view, update, deactivate, reactivate users |
| `DepartmentController` | list, create, view departments |
| `ProgramController` | list, create, view programs |
| `BatchController` | list, create, view, update batches |

**New form requests** in `app/Http/Requests/Admin/` — one per create/update
action, each with validation rules matching the schema doc exactly (e.g.
`StoreBatchRequest` requires `end_date` to be after `start_date`, and
requires `coordinator_id` to actually belong to a user whose role is
`coordinator` — not just any user).

**Deactivation is soft, not destructive** — `UserController::deactivate()`
sets `is_active = false` rather than deleting the row, since a deactivated
student/supervisor's journal entries and weekly logs need to stay intact.
There's no `destroy` (hard delete) route for users at all, by design.

**Changed:** `routes/api.php` — added an `admin/` route group gated by
`auth:sanctum` + `role:admin`, wiring all four controllers in.

---

## 4. Web — Three New Admin Pages

**New:**
- `web/src/views/admin/UserManagement.vue` — table of users, create-user
  form (name/email/password/role/program), and deactivate/reactivate
  buttons that call the live API
- `web/src/views/admin/Departments.vue` — table of departments with a
  create-department form
- `web/src/views/admin/BatchManagement.vue` — table of batches with a
  full create-batch form (program, coordinator, dates, hours, working days)

All three actually call `/api/admin/...` through the existing `api.js`
Axios instance — they're not mockups. Each page calls
`/sanctum/csrf-cookie` before its first POST request, which Sanctum's
cookie-based auth requires.

**Changed — fixed a real bug that resurfaced:** `web/src/router/index.js`
was missing its `import { createRouter, createWebHistory } from
'vue-router'` line again (same issue from the original Phase 1 audit). This
has been fixed, along with adding the three new admin routes
(`/admin/users`, `/admin/departments`, `/admin/batches`).

**Changed:** `DashboardShell.vue` — sidebar nav items used to be plain
text with dead `href="#"` links. They're now real `RouterLink`s, so
clicking "User Management" in the sidebar actually navigates there. This
required updating the nav item format from a plain string list to
`{ label, to }` objects in **all seven** dashboard/page files (student,
supervisor, coordinator, admin, plus the three new admin pages) — if you
had any local edits to those nav arrays, they'll need to be redone in the
new format.

**Cleaned up again:** `HelloWorld.vue`, the unused starter assets, and the
stray `asda.css` file had all reappeared since the last cleanup (almost
certainly from the same overlay issue mentioned at the top) — removed once
more.

---

## What was and wasn't actually tested

**Actually run and confirmed working:**
- `web/`: real `npm install` (0 vulnerabilities), real `npm run build`
  (87 modules compiled clean), and a live dev server confirming
  `/admin/users`, `/admin/departments`, and `/admin/batches` all return
  HTTP 200.

**Not run — needs your machine (no PHP available in this environment):**
- The actual API endpoints (`POST /api/admin/users`, etc.) — the code was
  written carefully against your existing controller/request conventions
  and the schema doc, but hasn't been hit with a real HTTP request.
- Confirming the `role` middleware alias resolves correctly — this is
  config wiring in `bootstrap/app.php`, should work, but please verify by
  trying to hit an `/api/admin/...` route while logged in as a non-admin
  and confirming you get a 403, not a 500 error (a 500 would mean
  something in the middleware registration didn't take).
- Confirming the `student_profiles` row actually gets created when you
  create a student account through the new endpoint.

## Suggested verification steps, in order

1. Copy/overwrite the files into your real repo folder.
2. `php artisan route:list --path=api/admin` — confirm all the new routes
   show up.
3. Log in as your seeded admin (`admin@interntrack.local` / `password`),
   then try `GET /api/admin/users` — should return your seeded admin and
   system accounts.
4. Try creating a student account through `POST /api/admin/users` (or
   through the new web UI form) and then check the `student_profiles`
   table — there should be a new row with a `PENDING-...` ID.
5. Log in as that same new student account and try hitting
   `/api/admin/users` — should get a 403, not data back.
6. In `web/`, run `npm install && npm run dev`, then click through the
   sidebar nav on `/admin` to confirm the three new pages load and the
   create-forms actually submit.

Once all six of those check out, Phase 2 is genuinely done.
