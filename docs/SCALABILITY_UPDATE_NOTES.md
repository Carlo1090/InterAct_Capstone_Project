# InternTrack — Multi-Department Scalability Update

You asked whether the Phase 1 schema and Eloquent models can scale to
multiple departments and programs, specifically the confirmed coverage:

- **CAST** — BSIT (1 program)
- **CABM-B** (Business) — BSBA-FM, BSBA-MM, BSBA-OM, BSA (4 programs)
- **CABM-H** (Hospitality) — BSTM, BSHRM (2 programs)

**Short answer: yes, already.** Nothing in the schema, models, or
controllers needed to be redesigned. The `departments` → `programs`
relationship was already a genuine one-to-many — CAST having 1 program and
CABM-B having 4 is just data, not a different code path. I checked the
actual controllers and models for any hardcoded department/program
assumptions (e.g. something silently assuming "CAST" or "BSIT") — there
were none. Everything queries `department_id` / `program_id` dynamically.

That said, a few small, real things *did* need updating, and one quality-of-life improvement was worth making now given the program count roughly tripled. Here's exactly what changed.

---

## 1. Database — one schema tightening

**`database/migrations/2026_06_25_000002_create_programs_table.php`** — added
a composite unique constraint on `(department_id, code)`. Previously
`programs.code` had no uniqueness check at all (only `departments.code`
did). With 7 programs now in play across 3 departments, it's worth having
the database itself refuse a duplicate program code within the same
department, rather than relying on careful seeding alone.

## 2. Seeders — the real department/program list

**`database/seeders/DepartmentSeeder.php`** — now seeds the 3 real
departments: CAST, CABM-B, CABM-H (replacing the CBA/COED placeholders
flagged in the original Phase 1 notes).

**`database/seeders/ProgramSeeder.php`** — rewritten to seed all 7 real
programs, mapped to their correct departments:

```
CAST    -> BSIT
CABM-B  -> BSBA-FM, BSBA-MM, BSBA-OM, BSA
CABM-H  -> BSTM, BSHRM
```

**One modeling decision worth being aware of:** CABM-B and CABM-H are
seeded as two fully independent departments, not as one "CABM" department
with two sub-units. I read your naming ("CABM-B (Business Department)",
"CABM-H (Hospitality Department)") as describing two separate colleges
that happen to share a prefix, since each has its own distinct program
list. If your institution actually treats "CABM" as a single parent unit
with two divisions underneath — and you'd want, say, a combined CABM-wide
report someday — that would call for an actual 3-level hierarchy
(department → division → program) instead of the current 2-level one. The
current setup is simpler and matches everything else in the schema, so I'd
only add that extra level if you specifically need cross-division
reporting under one "CABM" umbrella. Worth a quick confirmation either way
before your defense, since changing this later (after real batches/students
exist) is more work than deciding it now.

## 3. Validation — matching the new DB constraint

**`app/Http/Requests/Admin/StoreProgramRequest.php`** — added a uniqueness
rule scoped to `department_id`, so attempting to create a duplicate program
code within the same department now returns a clean validation error
instead of a raw database constraint failure.

## 4. Web — grouped program dropdowns

**`web/src/views/admin/UserManagement.vue`** and
**`web/src/views/admin/BatchManagement.vue`** — the program `<select>` in
both create-forms now groups options by department (`<optgroup>`), and
shows each program's short code (e.g. `BSBA-FM`) instead of its full name
in the dropdown, with the full name available as a hover tooltip. With only
2 programs this didn't matter; with 7 spread across 3 departments, an
ungrouped list with full names would be hard to scan quickly. This is a
display-only change — no backend or data change was needed since
`GET /api/admin/programs` already returns each program with its
department attached.

**Verified for real:** ran `npm install` and `npm run build` after these
changes — 87 modules compiled with zero errors, same as the last
confirmed-clean build.

## 5. Roadmap — updated, not restructured

`docs/InternTrack_Development_Roadmap_v2.1.docx` (replacing v2.0) has two
changes:

- The cover page now lists the confirmed department coverage (CAST, CABM-B,
  CABM-H — 7 programs total) instead of leaving it implicit.
- Phase 2 has a new callout explicitly confirming multi-department
  scalability was checked and required no structural changes — useful to
  have on record for your defense panel, since "is this scalable" is
  exactly the kind of question they're likely to ask.

No phase was added, removed, or reordered. This was a data/seeding
correction, not a new feature requiring new development time.

---

## What to do next

1. Apply these file changes the same way as before — overwrite in place in
   your real repo folder, not alongside the old versions.
2. Re-run `php artisan migrate:fresh` (needed regardless, since the
   programs table's constraint changed) followed by `php artisan db:seed`.
3. Confirm all 3 departments and all 7 programs show up via
   `GET /api/admin/departments` and `GET /api/admin/programs`.
4. In the web UI, open the "New User" or "New Batch" form and confirm the
   program dropdown now shows three grouped sections.
5. Decide on the CABM-B/CABM-H hierarchy question above before you start
   creating real batches under either one.
