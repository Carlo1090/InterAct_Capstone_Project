<script setup lang="ts">
const activePrograms = [
  { name: 'Program 2025-A', term: 'AY 2024-2025 - 2nd Sem', students: 42, start: 'Apr 7, 2025', end: 'Jun 27, 2025', status: 'Active', reminder: '9:00 PM' },
  { name: 'Program 2025-B', term: 'AY 2024-2025 - Summer', students: 18, start: 'Jun 2, 2025', end: 'Aug 22, 2025', status: 'Upcoming', reminder: '9:00 PM' },
]

const completedPrograms = [
  { name: 'Program 2024-B', term: '2024-2025 1st Sem', students: 39, period: 'Oct 7 - Dec 20, 2024', completion: '94%' },
  { name: 'Program 2024-A', term: '2023-2024 2nd Sem', students: 35, period: 'Apr 8 - Jun 28, 2024', completion: '91%' },
]
</script>

<template>
  <section class="space-y-5">
    <div class="flex justify-end">
      <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700">+ Create Program</button>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
      <article v-for="program in activePrograms" :key="program.name" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-base font-bold text-slate-950">{{ program.name }}</h2>
            <p class="mt-1 text-sm text-slate-500">{{ program.term }}</p>
          </div>
          <span
            class="rounded-full px-3 py-1 text-xs font-bold"
            :class="program.status === 'Active' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'"
          >
            {{ program.status }}
          </span>
        </div>

        <div class="mt-5 divide-y divide-slate-100">
          <div class="flex justify-between py-2 text-sm"><span class="text-slate-500">Students</span><strong>{{ program.students }}</strong></div>
          <div class="flex justify-between py-2 text-sm"><span class="text-slate-500">Start Date</span><strong>{{ program.start }}</strong></div>
          <div class="flex justify-between py-2 text-sm"><span class="text-slate-500">End Date</span><strong>{{ program.end }}</strong></div>
          <div class="flex justify-between py-2 text-sm"><span class="text-slate-500">Weekly Compilation</span><strong>Every Sunday evening</strong></div>
          <div class="flex justify-between py-2 text-sm"><span class="text-slate-500">Email Reminder</span><strong>{{ program.reminder }} daily</strong></div>
        </div>

        <div class="mt-4 flex gap-2">
          <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">Edit</button>
          <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">View Students</button>
        </div>
      </article>
    </div>

    <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <div class="border-b border-slate-100 px-5 py-4">
        <h2 class="text-sm font-bold text-slate-900">Completed Programs</h2>
      </div>
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">AY/Semester</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Students</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Period</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Completion Rate</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="program in completedPrograms" :key="program.name">
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ program.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ program.term }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ program.students }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ program.period }}</td>
            <td class="px-4 py-3 text-sm font-bold text-green-700">{{ program.completion }}</td>
            <td class="px-4 py-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">View</button>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  </section>
</template>
