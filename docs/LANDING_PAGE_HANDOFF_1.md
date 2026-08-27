# InternTrack Landing Page — Claude Code Handoff

Figma file: `InternTrack — Landing Page (Sample)`
File key: `IcDGFFr5XSdfR96m1GRKj7`

---

## 1. Connect Claude Code to Figma (once)

```bash
claude mcp add --transport http figma https://mcp.figma.com/mcp
```

Then inside a Claude Code session:

```
/mcp
```

Complete the browser OAuth. Verify with `claude mcp list` — `figma` should show `connected`.

Scope note: the default is local (this project only). Use `--scope user` if you want it
in every project, or `--scope project` to write it into a committable `.mcp.json`.

---

## 2. Node map — paste the URL for the section you're building

Claude Code needs a **node-specific** URL. A bare file URL makes it ask for one.

Base: `https://www.figma.com/design/IcDGFFr5XSdfR96m1GRKj7/InternTrack--Landing-Page--Sample-?node-id=`

| Section | node-id | Full URL suffix |
|---|---|---|
| Whole page (1440 × 3132) | `1:40` | `?node-id=1-40` |
| Nav | `1:41` | `?node-id=1-41` |
| Hero | `2:2` | `?node-id=2-2` |
| Preview / Student dashboard | `4:2` | `?node-id=4-2` |
| Section / Roles | `6:2` | `?node-id=6-2` |
| Section / Capabilities | `7:2` | `?node-id=7-2` |
| Preview / Daily Time Record | `7:38` | `?node-id=7-38` |
| Section / How it works | `7:64` | `?node-id=7-64` |
| Section / CTA band | `7:92` | `?node-id=7-92` |
| Footer | `7:101` | `?node-id=7-101` |

Build **one section per session**. Pasting `1-40` returns the whole 3132px tree at once
and the output degrades.

---

## 3. Token translation table

This is the part that makes the output match InternTrack instead of generic Tailwind.
Figma variables do **not** auto-map to Tailwind classes — Claude Code will invent
`bg-[#1E3A8A]` unless you hand it this table.

### Colour

| Figma variable | Tailwind v4 class |
|---|---|
| `brand/blue-900` | `blue-900` |
| `brand/blue-800` | `blue-800` |
| `brand/blue-700` | `blue-700` |
| `brand/blue-600` | `blue-600` |
| `brand/blue-100` | `blue-100` |
| `brand/blue-50` | `blue-50` |
| `brand/teal-500` | `teal-500` |
| `brand/teal-600` | `teal-600` |
| `brand/teal-200` | `teal-200` |
| `accent/emerald-500` · `-50` | `emerald-500` · `emerald-50` |
| `accent/amber-500` · `-50` | `amber-500` · `amber-50` |
| `accent/rose-500` · `-50` | `rose-500` · `rose-50` |
| `neutral/slate-900 / 700 / 600 / 400 / 200 / 50` | same numbers |
| `neutral/white` | `white` |

### Radius — the trap

Figma radius values are **pixels**; Tailwind's names are offset by one step.

| Figma | px | Tailwind v4 |
|---|---|---|
| `radius/md` | 8 | `rounded-lg` |
| `radius/lg` | 12 | `rounded-xl` |
| `radius/xl` | 16 | `rounded-2xl` |
| `radius/2xl` | 24 | `rounded-3xl` |
| `radius/full` | 9999 | `rounded-full` |

### Spacing

| Figma px | Tailwind |
|---|---|
| 4 / 6 / 8 / 12 / 16 | `1` / `1.5` / `2` / `3` / `4` |
| 24 / 32 / 48 / 64 / 96 | `6` / `8` / `12` / `16` / `24` |

Section padding `120 / 96` → `px-30 py-24`. Use `px-6 md:px-12 lg:px-30`.

### Gradient

Figma cannot bind gradient stops to variables, so the hero and CTA band carry raw hex.
In code they are the class already shipping in `LoginPage.vue`:

```
bg-linear-to-br from-blue-900 via-blue-800 to-teal-500
```

Tailwind **v4** syntax — `bg-linear-to-br`, not v3's `bg-gradient-to-br`.

### Type

| Role | Figma | Tailwind |
|---|---|---|
| Hero H1 | 52 / 60 / -1.2 | `text-5xl leading-[60px] font-bold tracking-tight` |
| Section H2 | 36 / 44 / -0.9 | `text-4xl leading-11 font-bold tracking-tight` |
| Card title | 17 / 22 / -0.3 | `text-[17px] leading-[22px] font-semibold tracking-tight` |
| Body | 16 / 26 | `text-base leading-relaxed` |
| Small body | 14 / 21 | `text-sm leading-[21px]` |
| Eyebrow | 11 / 14 / +0.9 | `text-[11px] font-semibold uppercase tracking-wide` |

---

## 4. Paste-ready prompt

Replace the node id, then paste the whole block into Claude Code at the repo root.

```
Build the InternTrack landing page hero from Figma.

Figma node: https://www.figma.com/design/IcDGFFr5XSdfR96m1GRKj7/InternTrack--Landing-Page--Sample-?node-id=2-2

Before writing any code:
1. Call get_design_context and get_variable_defs on that node.
2. Read web/src/pages/LoginPage.vue and the "Frontend UI conventions" section of PROJECT.md.
3. Read docs/LANDING_PAGE_HANDOFF.md and use its token translation table.

Rules:
- Output a Vue 3 <script setup lang="ts"> SFC at web/src/pages/LandingPage.vue.
- Tailwind v4 utility classes only. No v3 syntax (bg-linear-to-br, not bg-gradient-to-br).
- Every colour, radius and spacing value maps to a Tailwind token via the handoff table.
  Zero arbitrary hex. Arbitrary values allowed ONLY for the type sizes listed there.
- Reuse LoginPage.vue's existing gradient, blob, and reveal-animation patterns rather
  than writing new ones. Import shared components from web/src/components; inspect first.
- The MDC seal is /images/mdc-logo.png with alt "Mater Dei College seal".
- Responsive: two-column above lg, single stacked column below, matching the breakpoint
  LoginPage.vue already uses for its brand panel.
- Accessibility: interactive targets >= 44px, visible focus states, and honour
  prefers-reduced-motion on every animation.

Do NOT:
- Touch web/src/router/index.ts. Routing is a separate decision.
- Modify LoginPage.vue.
- git add, commit, branch, or push. Show me the diff instead.

When done: run npm run build in web/ and show me the output.
Then draft the PROJECT.md entry for this change — do not write it until I approve.
```

---

## 5. Guardrails carried over from PROJECT.md

| Rule | Why it matters here |
|---|---|
| No commit / push / branch / PR without explicit per-action permission | The prompt ends with "show me the diff" for this reason |
| Every change recorded in PROJECT.md before the task is complete | Landing page adds a new public surface — it needs an entry |
| Schema is finalised | This is presentational only. No migrations, no model changes |
| Dashboards render only fields the API returns | The hero preview card is **decorative marketing**, not live data. Keep it static markup — do not wire it to an endpoint |

---

## 6. Open decisions before this ships

1. **Routing.** `/` currently redirects to `/login`. A landing page means `/` becomes
   public and login moves behind a click. Router change + PROJECT.md entry + a check
   that `roleRedirect` still lands authenticated users correctly.
2. **The "312 of 486 hours" figure is invented.** Replace with the real SIPP requirement
   or drop the denominator.
3. **Mobile frame.** The Figma file is desktop-only at 1440. The 390px breakpoint is
   currently a code-side judgement call, not a design.

---

## 7. Verification

```bash
cd web && npm run build          # must succeed
rg 'bg-\[#' src/pages/LandingPage.vue      # must return nothing
rg 'bg-gradient-to' src/pages/LandingPage.vue   # must return nothing (v3 syntax)
```
