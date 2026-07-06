<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
const pendingEntries = [
  { student: 'Juan Dela Cruz', id: '2021-IT-001', week: 8, range: 'May 19-25, 2025', entries: 5, submitted: 'May 25, 2025 · 9:02 PM' },
  { student: 'Michael Tan', id: '2021-IT-004', week: 8, range: 'May 19-25, 2025', entries: 5, submitted: 'May 25, 2025 · 9:14 PM' },
  { student: 'Sarah Jane Ocampo', id: '2021-IT-006', week: 8, range: 'May 19-25, 2025', entries: 4, submitted: 'May 25, 2025 · 8:47 PM' },
  { student: 'Rico Bautista', id: '2021-IT-009', week: 8, range: 'May 19-25, 2025', entries: 5, submitted: 'May 25, 2025 · 9:30 PM' },
  { student: 'Faith Anne Custodio', id: '2021-IT-012', week: 8, range: 'May 19-25, 2025', entries: 5, submitted: 'May 25, 2025 · 9:55 PM' },
]
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      5 weekly journals are pending your review. Weekly journals are compiled automatically every <strong>Sunday evening</strong>.
    </div>

    <div class="flex gap-1 border-b border-slate-200">
      <button type="button" class="border-b-2 border-blue-600 px-4 py-2 text-sm font-semibold text-blue-700">Pending (5)</button>
      <button type="button" class="border-b-2 border-transparent px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700">
        Approved (3)
      </button>
      <button type="button" class="border-b-2 border-transparent px-4 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700">
        Returned (1)
      </button>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Week</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Date Range</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Entries</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Submitted</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="entry in pendingEntries" :key="entry.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ entry.student }}</p>
              <p class="font-mono text-xs text-slate-400">{{ entry.id }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700">Week {{ entry.week }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ entry.range }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ entry.entries }} entries</span>
            </td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ entry.submitted }}</td>
            <td class="px-4 py-3">
              <div class="flex gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">
                  View
                </button>
                <button
                  type="button"
                  class="rounded-md border border-green-200 bg-green-50 px-3 py-1.5 text-sm font-semibold text-green-700 transition hover:bg-green-100"
                >
                  Approve
                </button>
                <button
                  type="button"
                  class="rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-semibold text-red-700 transition hover:bg-red-100"
                >
                  Return
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
