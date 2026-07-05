<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
const journals = [
  { date: 'May 26, 2025', day: 'Monday', title: 'UI Component Design & Testing', words: 312 },
  { date: 'May 23, 2025', day: 'Friday', title: 'Backend API Integration', words: 285 },
  { date: 'May 22, 2025', day: 'Thursday', title: 'Database Schema Optimization', words: 267 },
  { date: 'May 21, 2025', day: 'Wednesday', title: 'Team Stand-up & Sprint Planning', words: 310 },
  { date: 'May 20, 2025', day: 'Tuesday', title: 'Bug Fixing & Code Review', words: 245 },
  { date: 'May 19, 2025', day: 'Monday', title: 'Feature Development - Login Module', words: 298 },
  { date: 'May 16, 2025', day: 'Friday', title: 'Documentation Update', words: 220 },
  { date: 'May 15, 2025', day: 'Thursday', title: 'Client Demo Preparation', words: 334 },
]
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Daily journal entries only track <strong>submission status</strong>. Review and approval happen after the entries are compiled into your <strong>weekly journal</strong>.
    </div>

    <div class="flex flex-wrap gap-3">
      <input class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search journals..." />
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Status</option>
        <option>Submitted</option>
        <option>Missing</option>
      </select>
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>This Month</option>
        <option>Last Month</option>
        <option>All Time</option>
      </select>
      <button type="button" class="ml-auto rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Export PDF</button>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Date</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Day</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Title</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Word Count</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="journal in journals" :key="journal.date">
            <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ journal.date }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ journal.day }}</td>
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ journal.title }}</td>
            <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ journal.words }}</td>
            <td class="px-4 py-3 text-sm">
              <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">Submitted</span>
            </td>
            <td class="px-4 py-3 text-sm">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">View</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
