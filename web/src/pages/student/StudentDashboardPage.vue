<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
const stats = [
  { label: 'Total Entries', value: '38', sub: 'This program', tone: 'blue' },
  { label: 'Approved', value: '32', sub: 'By supervisor', tone: 'green' },
  { label: 'Pending Review', value: '4', sub: 'Awaiting supervisor', tone: 'amber' },
  { label: 'Missing Entries', value: '2', sub: 'Not yet submitted', tone: 'red' },
]

const progress = [
  { label: 'Weekly Reports Approved', value: 86, barClass: 'bg-green-500', textClass: 'text-green-700' },
  { label: 'OJT Duration Progress', value: 58, barClass: 'bg-amber-500', textClass: 'text-amber-700' },
]

const activities = [
  { text: 'Week 5 journal approved by Engr. Villanueva', time: 'Today, 9:42 AM', dot: 'bg-green-500' },
  { text: 'Journal entry for May 22 submitted', time: 'Yesterday, 6:15 PM', dot: 'bg-blue-500' },
  { text: 'Week 4 journal returned for revisions', time: 'May 19, 8:03 AM', dot: 'bg-amber-500' },
  { text: 'Journal entry for May 18 submitted', time: 'May 18, 5:47 PM', dot: 'bg-blue-500' },
  { text: 'Week 4 journal re-submitted after revisions', time: 'May 19, 3:21 PM', dot: 'bg-green-500' },
]

const details = [
  ['Host Company', 'TechPH Inc.'],
  ['Supervisor', 'Engr. Ramon Villanueva'],
  ['Coordinator', 'Ma. Teresa Reyes'],
  ['Department', 'Information Technology'],
  ['Start Date', 'April 7, 2025'],
]

const statIcon = (tone: string): string => {
  const icons: Record<string, string> = {
    blue: '📋',
    green: '✅',
    amber: '⏳',
    red: '❗',
  }

  return icons[tone]
}

const statBgClass = (tone: string): string => {
  const classes: Record<string, string> = {
    blue: 'from-blue-500 to-blue-600',
    green: 'from-emerald-500 to-emerald-600',
    amber: 'from-amber-500 to-amber-600',
    red: 'from-rose-500 to-rose-600',
  }

  return classes[tone]
}
</script>

<template>
  <section class="space-y-6">
    <!-- Warning Banner -->
    <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-gradient-to-r from-amber-50 to-orange-50 px-5 py-4 shadow-sm">
      <span class="mt-0.5 text-xl">⚠️</span>
      <div>
        <p class="text-sm font-semibold text-amber-800">
          You have <span class="font-bold underline decoration-amber-400 decoration-2 underline-offset-2">2 missing entries</span> this week (Mon, Tue).
        </p>
      </div>
    </div>

    <!-- Stat Cards -->
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <article
        v-for="stat in stats"
        :key="stat.label"
        class="group relative overflow-hidden rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200/80 transition-all duration-200 hover:shadow-md hover:ring-slate-300"
      >
        <div class="mb-4 flex items-center justify-between">
          <span class="text-2xl">{{ statIcon(stat.tone) }}</span>
          <div class="h-10 w-10 rounded-lg bg-gradient-to-br text-xs font-bold text-white flex items-center justify-center shadow-sm" :class="statBgClass(stat.tone)">
            {{ stat.value }}
          </div>
        </div>
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ stat.label }}</p>
        <p class="mt-1 text-3xl font-extrabold text-slate-900 tracking-tight">{{ stat.value }}</p>
        <p class="mt-1 text-xs text-slate-500">{{ stat.sub }}</p>
        <div class="absolute -right-4 -top-4 h-16 w-16 rounded-full opacity-[0.06] transition-opacity group-hover:opacity-[0.10]" :class="{ 'bg-blue-500': stat.tone === 'blue', 'bg-emerald-500': stat.tone === 'green', 'bg-amber-500': stat.tone === 'amber', 'bg-rose-500': stat.tone === 'red' }"></div>
      </article>
    </div>

    <!-- Progress & Activity -->
    <div class="grid gap-5 xl:grid-cols-2">
      <!-- Completion Progress -->
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
        <h2 class="text-sm font-bold text-slate-900">Completion Progress</h2>
        <div class="mt-6 space-y-5">
          <div v-for="item in progress" :key="item.label">
            <div class="mb-2.5 flex justify-between text-sm">
              <span class="font-medium text-slate-600">{{ item.label }}</span>
              <span class="font-bold" :class="item.textClass">{{ item.value }}%</span>
            </div>
            <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
              <div
                class="h-full rounded-full transition-all duration-500 ease-out"
                :class="item.barClass"
                :style="{ width: `${item.value}%` }"
              ></div>
            </div>
          </div>
        </div>
      </section>

      <!-- Recent Activity -->
      <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
        <h2 class="text-sm font-bold text-slate-900">Recent Activity</h2>
        <div class="mt-5 space-y-0 divide-y divide-slate-100">
          <div v-for="activity in activities" :key="activity.text" class="flex gap-3 py-3.5 transition-colors hover:bg-slate-50 -mx-1 px-1 rounded-lg">
            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ring-2 ring-offset-1" :class="[activity.dot, `ring-current ${activity.dot.replace('bg-', 'text-')}/30`]"></span>
            <div class="min-w-0">
              <p class="text-sm text-slate-700">{{ activity.text }}</p>
              <p class="mt-1 text-xs font-medium text-slate-400">{{ activity.time }}</p>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- Internship Details -->
    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200/80">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-900">Internship Details</h2>
        <span class="rounded-full bg-blue-50 px-3.5 py-1.5 text-xs font-semibold text-blue-700 ring-1 ring-blue-200/50">Program 2025-A</span>
      </div>
      <div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="[label, value] in details" :key="label" class="rounded-lg bg-slate-50 px-4 py-3.5 ring-1 ring-slate-100">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ label }}</p>
          <p class="mt-1 text-sm font-semibold text-slate-900">{{ value }}</p>
        </div>
      </div>
    </section>
  </section>
</template>
