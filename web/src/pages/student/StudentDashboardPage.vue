<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
const stats = [
  { label: 'Total Entries', value: '38', sub: 'This program', tone: 'blue' },
  { label: 'Approved', value: '32', sub: 'By supervisor', tone: 'green' },
  { label: 'Pending Review', value: '4', sub: 'Awaiting supervisor', tone: 'amber' },
  { label: 'Missing Entries', value: '2', sub: 'Not yet submitted', tone: 'red' },
]

const progress = [
  { label: 'Weekly Reports Approved', value: 86, barClass: 'bg-green-600', textClass: 'text-green-700' },
  { label: 'OJT Duration Progress', value: 58, barClass: 'bg-amber-600', textClass: 'text-amber-700' },
]

const activities = [
  { text: 'Week 5 journal approved by Engr. Villanueva', time: 'Today, 9:42 AM', dot: 'bg-green-600' },
  { text: 'Journal entry for May 22 submitted', time: 'Yesterday, 6:15 PM', dot: 'bg-blue-500' },
  { text: 'Week 4 journal returned for revisions', time: 'May 19, 8:03 AM', dot: 'bg-amber-600' },
  { text: 'Journal entry for May 18 submitted', time: 'May 18, 5:47 PM', dot: 'bg-blue-500' },
  { text: 'Week 4 journal re-submitted after revisions', time: 'May 19, 3:21 PM', dot: 'bg-green-600' },
]

const details = [
  ['Host Company', 'TechPH Inc.'],
  ['Supervisor', 'Engr. Ramon Villanueva'],
  ['Coordinator', 'Ma. Teresa Reyes'],
  ['Department', 'Information Technology'],
  ['Start Date', 'April 7, 2025'],
]

const statToneClass = (tone: string): string => {
  const classes: Record<string, string> = {
    blue: 'bg-blue-50 text-blue-700',
    green: 'bg-green-50 text-green-700',
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  }

  return classes[tone]
}
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      You have <strong>2 missing entries</strong> this week (Mon, Tue). The weekly compilation runs every <strong>Sunday evening</strong>.
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <article v-for="stat in stats" :key="stat.label" class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <div class="mb-4 flex h-9 w-9 items-center justify-center rounded-md text-sm font-bold" :class="statToneClass(stat.tone)">
          {{ stat.value }}
        </div>
        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ stat.label }}</p>
        <p class="mt-1 text-3xl font-bold text-slate-950">{{ stat.value }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ stat.sub }}</p>
      </article>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
      <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-bold text-slate-900">Completion Progress</h2>
        <div class="mt-5 space-y-4">
          <div v-for="item in progress" :key="item.label">
            <div class="mb-2 flex justify-between text-sm">
              <span class="text-slate-600">{{ item.label }}</span>
              <span class="font-bold" :class="item.textClass">{{ item.value }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full" :class="item.barClass" :style="{ width: `${item.value}%` }"></div>
            </div>
          </div>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-bold text-slate-900">Recent Activity</h2>
        <div class="mt-4 divide-y divide-slate-100">
          <div v-for="activity in activities" :key="activity.text" class="flex gap-3 py-3">
            <span class="mt-1.5 h-2 w-2 rounded-full" :class="activity.dot"></span>
            <div>
              <p class="text-sm text-slate-800">{{ activity.text }}</p>
              <p class="mt-1 text-xs text-slate-400">{{ activity.time }}</p>
            </div>
          </div>
        </div>
      </section>
    </div>

    <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-900">Internship Details</h2>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">Program 2025-A</span>
      </div>
      <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="[label, value] in details" :key="label" class="border-b border-slate-100 pb-3">
          <p class="text-xs text-slate-400">{{ label }}</p>
          <p class="mt-1 text-sm font-semibold text-slate-900">{{ value }}</p>
        </div>
      </div>
    </section>
  </section>
</template>
