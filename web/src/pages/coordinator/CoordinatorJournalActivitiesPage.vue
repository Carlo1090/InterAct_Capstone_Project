<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
type EntryStatus = 'submitted' | 'late' | 'missing'

const entries = [
  { student: 'Maria Santos', company: 'BDO Unibank', date: 'May 26, 2025', submitted: '8:12 AM', activity: 'Prepared customer account filing and transaction logs.', status: 'submitted' as EntryStatus },
  { student: 'Jose Santos Jr.', company: 'BDO Unibank', date: 'May 26, 2025', submitted: '9:41 PM', activity: 'Documented branch support tasks and client queuing assistance.', status: 'late' as EntryStatus },
  { student: 'Angela Mercado', company: 'Prince Retail Group', date: 'May 26, 2025', submitted: '6:27 PM', activity: 'Logged merchandising updates and stockroom coordination work.', status: 'submitted' as EntryStatus },
  { student: 'Paula Navarro', company: 'DTI Bohol Provincial Office', date: 'May 26, 2025', submitted: 'No entry', activity: 'No daily journal submitted for this date.', status: 'missing' as EntryStatus },
  { student: 'Kristine Lao', company: 'BDO Unibank', date: 'May 25, 2025', submitted: '7:58 PM', activity: 'Summarized cashier support and records encoding duties.', status: 'submitted' as EntryStatus },
  { student: 'Bea Mangubat', company: 'Prince Retail Group', date: 'May 25, 2025', submitted: '8:54 PM', activity: 'Recorded display checks and inventory reconciliation tasks.', status: 'submitted' as EntryStatus },
  { student: 'Cedric Puno', company: 'BDO Unibank', date: 'May 25, 2025', submitted: '10:18 PM', activity: 'Captured end-of-day reporting assistance and records validation.', status: 'late' as EntryStatus },
  { student: 'Ivy Relova', company: 'DTI Bohol Provincial Office', date: 'May 24, 2025', submitted: '5:49 PM', activity: 'Logged MSME client support and permit records processing.', status: 'submitted' as EntryStatus },
]

const statusLabel: Record<EntryStatus, string> = {
  submitted: 'Submitted',
  late: 'Late Submission',
  missing: 'Missing Entry',
}

const statusClass: Record<EntryStatus, string> = {
  submitted: 'bg-green-50 text-green-700',
  late: 'bg-amber-50 text-amber-700',
  missing: 'bg-red-50 text-red-700',
}
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This view is <strong>read-only</strong>. Coordinators can monitor daily journal activity, while weekly approval remains with company supervisors.
    </div>

    <div class="flex flex-wrap gap-3">
      <input class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search..." />
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Companies</option>
        <option>BDO Unibank</option>
        <option>Prince Retail Group</option>
        <option>DTI Bohol Provincial Office</option>
      </select>
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>Today</option>
        <option>Last 3 Days</option>
        <option>This Week</option>
      </select>
      <button type="button" class="ml-auto rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Export Report</button>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Journal Date</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Submitted</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Activity Summary</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="entry in entries" :key="`${entry.student}-${entry.date}`">
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ entry.student }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ entry.company }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ entry.date }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ entry.submitted }}</td>
            <td class="max-w-sm px-4 py-3 text-sm text-slate-500">{{ entry.activity }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass[entry.status]">{{ statusLabel[entry.status] }}</span>
            </td>
            <td class="px-4 py-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">View</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
