<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Today is <strong>Monday, May 26, 2025</strong>. Deadline for submission is <strong>11:59 PM tonight</strong>. Missed entries are excluded from the Sunday compilation.
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
      <div class="space-y-5">
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Entry Details</h2>
          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-medium text-slate-700">
              Date
              <input value="May 26, 2025" readonly class="mt-2 w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm" />
            </label>
            <label class="block text-sm font-medium text-slate-700">
              Title / Summary
              <input value="UI Component Design & Testing" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
          </div>
        </section>

        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Journal Entry</h2>
          <div class="mt-4 flex gap-1 rounded-t-md border border-slate-300 border-b-transparent bg-slate-50 p-2">
            <button type="button" class="rounded px-2 py-1 text-sm font-bold text-slate-600 hover:bg-slate-200">B</button>
            <button type="button" class="rounded px-2 py-1 text-sm italic text-slate-600 hover:bg-slate-200">I</button>
            <button type="button" class="rounded px-2 py-1 text-sm underline text-slate-600 hover:bg-slate-200">U</button>
            <button type="button" class="rounded px-2 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-200">H1</button>
            <button type="button" class="rounded px-2 py-1 text-sm font-semibold text-slate-600 hover:bg-slate-200">List</button>
          </div>
          <textarea
            class="min-h-80 w-full rounded-b-md border border-slate-300 px-4 py-3 text-sm leading-6 text-slate-700 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
            value="Today, I was assigned to work on the UI component library for the InternTrack project. My tasks included designing and testing reusable components such as data tables, modal dialogs, badge indicators, and form elements following the established design system specifications.&#10;&#10;I collaborated with senior developer Engr. Beltran to review the component naming conventions and ensure consistency across all pages. We identified three accessibility issues in the form elements and filed them as tasks for tomorrow's sprint.&#10;&#10;In the afternoon, I worked on validating the responsive behavior of the sidebar layout across different screen sizes."
          />
        </section>
      </div>

      <aside class="space-y-5">
        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Submission Checklist</h2>
          <div class="mt-4 space-y-3 text-sm text-slate-700">
            <label class="flex items-center gap-2"><input type="checkbox" checked /> Entry title provided</label>
            <label class="flex items-center gap-2"><input type="checkbox" checked /> Journal content written</label>
            <label class="flex items-center gap-2"><input type="checkbox" /> Reviewed for grammar</label>
            <label class="flex items-center gap-2"><input type="checkbox" /> At least 150 words</label>
          </div>
        </section>

        <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Entry Summary</h2>
          <div class="mt-4 divide-y divide-slate-100 text-sm">
            <div class="flex justify-between py-2"><span class="text-slate-500">Word Count</span><span class="font-mono font-semibold">223</span></div>
            <div class="flex justify-between py-2"><span class="text-slate-500">Status</span><span class="font-semibold text-amber-700">Draft</span></div>
            <div class="flex justify-between py-2"><span class="text-slate-500">Deadline</span><span class="font-semibold">11:59 PM</span></div>
          </div>
        </section>

        <div class="flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Save Draft</button>
          <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Submit Entry</button>
        </div>
      </aside>
    </div>
  </section>
</template>
