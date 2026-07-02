<script setup lang="ts">
const weeks = [
  { number: 8, range: 'May 19-25, 2025', status: 'pending', entries: 5, compiled: 'May 25, 9:00 PM' },
  { number: 7, range: 'May 12-18, 2025', status: 'approved', entries: 5, compiled: 'May 18, 9:00 PM' },
  { number: 6, range: 'May 5-11, 2025', status: 'approved', entries: 5, compiled: 'May 11, 9:00 PM' },
  { number: 5, range: 'Apr 28-May 4, 2025', status: 'returned', entries: 4, compiled: 'May 4, 9:00 PM' },
  { number: 4, range: 'Apr 21-27, 2025', status: 'approved', entries: 5, compiled: 'Apr 27, 9:00 PM' },
]

const rows = [
  ['Mon', 'System Architecture Planning', '280'],
  ['Tue', 'Database Design Session', '315'],
  ['Wed', 'API Endpoint Development', '298'],
  ['Thu', 'Frontend Component Build', '265'],
  ['Fri', 'Testing & Documentation', '301'],
]

const statusLabel = (status: string): string => {
  if (status === 'approved') return 'Approved by Supervisor'
  if (status === 'returned') return 'Returned for Revision'
  return 'Pending Supervisor'
}

const statusClass = (status: string): string => {
  if (status === 'approved') return 'bg-green-50 text-green-700'
  if (status === 'returned') return 'bg-amber-50 text-amber-700'
  return 'bg-blue-50 text-blue-700'
}
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-start justify-between gap-4 rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      <p>Weekly compilations are automatically generated <strong>every Sunday evening</strong>. Approved weekly journals are forwarded to your coordinator.</p>
      <button type="button" class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-sm font-semibold text-blue-700">Download All</button>
    </div>

    <details v-for="week in weeks" :key="week.number" class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200" :open="week.number === 8">
      <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 transition hover:bg-slate-50">
        <div>
          <h2 class="text-sm font-bold text-slate-900">Week {{ week.number }} - {{ week.range }}</h2>
          <p class="mt-1 text-xs text-slate-500">Compiled: {{ week.compiled }} - {{ week.entries }} entries</p>
        </div>
        <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass(week.status)">
          {{ statusLabel(week.status) }}
        </span>
      </summary>

      <div class="border-t border-slate-100 p-5">
        <table class="min-w-full divide-y divide-slate-200">
          <thead>
            <tr>
              <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Date</th>
              <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Title</th>
              <th class="py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Words</th>
              <th class="py-2 text-right text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="row in rows" :key="row[0]">
              <td class="py-3 font-mono text-sm text-slate-600">{{ row[0] }}</td>
              <td class="py-3 text-sm text-slate-800">{{ row[1] }}</td>
              <td class="py-3 font-mono text-sm text-slate-600">{{ row[2] }}</td>
              <td class="py-3 text-right">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">View</button>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="week.status === 'returned'" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Supervisor's note: Please expand entries for Mon and Tue with more specific task descriptions.
        </div>
      </div>
    </details>
  </section>
</template>
