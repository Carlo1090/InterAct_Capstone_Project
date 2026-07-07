# Claude Code Prompt — InternTrack Student Page cleanup + daily-journal fields

> Paste everything inside the fenced block into Claude Code from the repo root of `InterAct_Capstone_Project`. This is a revision on top of the student-page work; it self-checks current state in Part 0 so it applies whether or not the earlier student-page prompt has run.

```
You are a senior Laravel 13 + Vue 3 engineer on InternTrack (repo: InterAct_Capstone_Project). Read CLAUDE.md and follow it. Correctness over cleverness. Make ONLY the changes below — do not refactor unrelated code or add scope. This is the owner-approved plan; proceed part by part without re-proposing, but honor the Stop Conditions.

## Context (locked design decisions)
- Scope = STUDENT role pages only, under `web/src/pages/student/`:
  StudentDashboardPage.vue, StudentCalendarPage.vue, StudentJournalsPage.vue (My Journals),
  StudentWriteJournalPage.vue, StudentWeeklyJournalsPage.vue, StudentInfoSheetPage.vue.
  API through `web/src/lib/axios.ts`; student API routes live in `routes/api.php` behind `role:student`; student controllers in `app/Http/Controllers/Student/`.
- Daily journal storage already fits this: `journal_entries.content` is JSON keyed to sections; `journal_templates.sections` is an ordered JSON array; `batches.journal_template_id` links a batch to its template. The template is managed by the Department Coordinator (that management UI is OUT of scope here — this task only READS the template and renders it, plus seeds a demo template).
- DESIGN CHANGES to implement:
  1. Dashboard: REMOVE the "Daily Journal Completion Progress" element AND the "End Date" element.
  2. Daily journal is now a DYNAMIC, TEMPLATE-DRIVEN field set:
     - Exactly one always-on REQUIRED field: "Task Performed" (the default).
     - Zero or more OPTIONAL fields the student reveals with a CHECKBOX (checking the box shows its textarea): e.g. "Skills Applied", "Challenges Encountered".
     - The "Optional SIPP Notes" (Issues & Concerns Encountered, Solutions, Recommendations) are ALSO optional checkbox-triggered fields in this SAME mechanism, but flagged as SIPP so the coordinator can extract them later.
     - Which optional fields exist is defined by the batch's journal template — do NOT hardcode them in the Vue page; render whatever the template provides.
     - A WORD LIMIT keeps an entry to a single page: a live word counter, and submit is BLOCKED past the cap. The cap is configurable on the template (default 500 words); enforce on both frontend and backend.
  3. The "View" action (in My Journals, and after submitting in Write Daily Journal) opens a READ-ONLY paper-style page, NOT the editor — a clean document that looks like a real journal sheet: centered page container (~720px max width, white, padding, subtle shadow), SERIF body font, justified text, comfortable line-height (~1.6), a header with a title and the entry date formatted like "July 22, 2027 – Monday", then the filled sections as prose (Task Performed as the main body; any filled optional/SIPP fields as labeled sub-sections below). On-screen only — do NOT generate PDF/DOCX in this task. Follow the repo's Tailwind v4 setup and the frontend-design skill if present.
  4. Weekly Journal page: the SIPP Notes must be SEPARATED — the page has two clearly distinct sections: (a) the weekly narrative summary, and (b) a separate READ-ONLY "SIPP Notes" section that compiles that week's daily SIPP-flagged entries (grouped by day). SIPP is captured in the DAILY journal now, so REMOVE any SIPP input fields from the weekly editor; on the weekly page SIPP is read-only aggregation, never woven into the narrative prose.

## Section shape (convention for journal_templates.sections — JSON array, extended)
Each section object: { "key": snake_case, "label": string, "prompt": string, "required": bool, "sipp": bool }.
The current built shape is the OLD `[{label, prompt}]` (no flags) — extend it to add `key`, `required`, `sipp`.
WORD LIMIT is a real COLUMN on `journal_templates` (`word_limit`, unsignedSmallInteger, default 500) — NOT a JSON key. Add it by editing the unshipped `..._create_journal_templates_table.php` migration (per CLAUDE.md edit-not-add), so it matches the coordinator-editor companion prompt.
`journal_entries.content` stores ONLY keys that were filled, e.g. { "task_performed": "...", "challenges_encountered": "...", "issues_concerns": "..." }.

## Allowed / Forbidden
- Allowed: edit under `app/`, `routes/api.php`, `database/seeders/`, `database/factories/`, `tests/`, `web/src/`, and the unshipped `..._create_journal_templates_table.php` migration (ONLY to add the `word_limit` column). Extend the `sections` JSON shape and demo seed data.
- Forbidden: new tables or renamed columns; touching Supervisor/Coordinator/Admin pages or controllers, or `mobile/`; a coordinator template-management UI (out of scope); duplicate files/middleware/entry points; pushing, PRs, merging to main; blocking foreground dev servers.

## Stop Conditions — pause and ask when:
- Part 0 state contradicts this Context (e.g. daily editor isn't template-driven, or weekly_logs has SIPP columns from a prior run — ask whether to drop them).
- A choice affects the data model, or an error survives 2 attempts, or work falls outside scope.

## Working rules (evidence-based — no "done" without output; per CLAUDE.md)
- `git checkout -b feature/student-page-cleanup` (confirm clean tree, not on main).
- Do Parts in order; after EACH, PASTE REAL OUTPUT. Backend: `php artisan test` (+ `migrate:fresh --seed` if seeders/migrations changed). Frontend: `cd web && npm run build` must pass. Output `✅ Part N complete` + files changed. Keep seeders re-runnable.

---

### Part 0 — Branch + confirm known state
The prior student-page work is on `main`. Expected state (confirm each; STOP if any differs): student controllers exist under `app/Http/Controllers/Student/` (JournalEntryController, JournalCalendarController, WeeklyLogController, WeeklyActivityLogController, StudentInfoSheetController) with a `Concerns/ResolvesStudentEnrollment` trait; `database/seeders/StudentDemoEnrollmentSeeder.php` seeds the demo batch + template; `journal_templates.sections` is still the OLD `[{label, prompt}]` shape (three sections: Tasks Performed, Skills Applied, Challenges Encountered) with NO `required`/`sipp` flags and NO `word_limit` column; `weekly_logs` HAS `narrative` + `issues_concerns` + `solutions` + `recommendations` columns; `StoreJournalEntryRequest` validates `content` only as a generic array. Also report which StudentDashboardPage.vue elements render "Daily Journal Completion Progress" and "End Date". Continue if this matches.

### Part 1 — Dashboard cleanup
In StudentDashboardPage.vue, remove the "Daily Journal Completion Progress" element and the "End Date" element (and any now-dead data/props/computed feeding only those). Keep the rest of the dashboard intact.
**Verify:** `cd web && npm run build`; paste output and the diff of removed blocks.

### Part 2 — word_limit column + template section convention + demo seed
First add the `word_limit` column (unsignedSmallInteger, default 500) to the unshipped `..._create_journal_templates_table.php` migration, and update the JournalTemplate model casts/fillable (sections=>array, word_limit=>int).
Then update the demo template in `database/seeders/StudentDemoEnrollmentSeeder.php` (re-runnable) — normalize the existing "Tasks Performed" into the required default and extend to the new flagged shape, word_limit 500:
- task_performed ("Task Performed") — required:true, sipp:false
- skills_applied — required:false, sipp:false
- challenges_encountered — required:false, sipp:false
- issues_concerns ("Issues and Concerns Encountered") — required:false, sipp:true
- solutions ("Solutions") — required:false, sipp:true
- recommendations ("Recommendations") — required:false, sipp:true
**Verify:** `php artisan migrate:fresh --seed`; tinker-dump the demo template's sections + word_limit. Paste output.

### Part 3 — Write Daily Journal (dynamic fields + word limit)
Backend (`JournalEntryController` + `StoreJournalEntryRequest` — the latter currently validates `content` only as a generic array; REPLACE that): the fetch-or-init endpoint returns the template sections (with required/sipp flags) + word_limit + any existing entry content. Save/submit validates against the batch template: `task_performed` required and non-empty; only known section keys accepted; total words ≤ word_limit (reject over-limit); SIPP-flagged content is stored the same as other sections but remains identifiable via the template flag.
Frontend (StudentWriteJournalPage.vue): render "Task Performed" always (required). For each optional section, show a checkbox labeled with the section; checking it reveals its textarea; unchecking clears/omits it. Show a live word counter against word_limit and disable Submit when exceeded. Save Draft + Submit wired to the endpoint.
**Verify:** feature tests — (a) submit with only Task Performed succeeds, (b) submit missing Task Performed fails, (c) over-word-limit submit is rejected, (d) an optional SIPP field saves and is retrievable. Paste `php artisan test --filter=JournalEntry`.

### Part 4 — Paper-style read-only "View"
Create a shared read-only component (e.g. `web/src/components/journal/JournalPaperView.vue`) rendering a submitted entry as a clean journal sheet per the Context spec (serif, justified, ~720px page, shadow, header with title + "Month D, YYYY – Weekday", Task Performed as main body, filled optional/SIPP sections as labeled sub-sections). Wire the "View" action in StudentJournalsPage.vue and the post-submit view in StudentWriteJournalPage.vue to open this component (route or modal) — NOT the editor. Read-only.
**Verify:** `cd web && npm run build`; paste output. Briefly describe how View is reached from My Journals.

### Part 5 — Weekly Journal: separate the SIPP Notes
`weekly_logs` already has `issues_concerns` / `solutions` / `recommendations` columns AND a `narrative` column from the prior run. SIPP now lives in the DAILY journal, so remove any SIPP INPUT fields from the weekly editor and stop writing those three columns; keep `narrative`. Leave the three now-unused columns in place for now (do NOT drop them in this task — flag them for a later cleanup migration) unless I tell you otherwise. On StudentWeeklyJournalsPage.vue present two clearly separated sections: (1) the weekly narrative summary (unchanged mechanism), and (2) a separate READ-ONLY "SIPP Notes" section that compiles that week's daily entries' SIPP-flagged fields, grouped by day (date + each SIPP field present). Backend: an endpoint (or extend the weekly endpoint) returning that week's daily SIPP-flagged content for the aggregation. Never merge SIPP text into the narrative.
**Verify:** feature test — seed a week of daily entries with SIPP fields, assert the weekly SIPP aggregation endpoint returns them grouped by day and that the narrative is separate. Paste output.

---

### Final output
`git status` + files changed grouped by Part; one paragraph on anything deferred/stubbed or any Stop-Condition hit. Do NOT push; leave everything on `feature/student-page-cleanup`.
```

---

🎯 **Target: Claude Code** — 💡 Written as a revision prompt (Part 0 detects current state so it works whether or not the earlier student-page prompt ran), hardcoded to the real `journal_entries` / `journal_templates` JSON-section design so the checkbox fields, SIPP relocation, word limit, and paper-style view are data-driven rather than hardcoded.

**Before you paste:** clean tree at repo root (`git status`).
