<script setup lang="ts">
const stats = [
  { label: 'Active Interns', value: '10', sub: 'Business Administration', tone: 'blue' },
  { label: 'Partner Companies', value: '3', sub: 'Active placements', tone: 'green' },
  { label: 'Avg. Completion', value: '76%', sub: 'Current program progress', tone: 'amber' },
  { label: 'Needs Follow-up', value: '4', sub: 'Missing or returned work', tone: 'red' },
]

const compliance = [
  { company: 'BDO Unibank', value: 82 },
  { company: 'Prince Retail Group', value: 74 },
  { company: 'DTI Bohol Provincial Office', value: 69 },
]

const attention = [
  { name: 'Jose Santos Jr.', issue: '2 missing entries', tone: 'red' },
  { name: 'Paula Navarro', issue: 'No entry since May 21', tone: 'red' },
  { name: 'Bea Mangubat', issue: 'SIPP still in draft', tone: 'amber' },
  { name: 'Cedric Puno', issue: 'Late journal submission', tone: 'amber' },
]

const placements = [
  ['Program', 'Program 2025-A'],
  ['Academic Term', 'AY 2024-2025 - 2nd Sem'],
  ['Coordinator', 'Prof. Alicia Montoya'],
  ['Department', 'Business Administration'],
  ['Weekly Compilation', 'Every Sunday evening'],
  ['Daily Reminder', '9:00 PM'],
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

const badgeClass = (tone: string): string => {
  const classes: Record<string, string> = {
    amber: 'bg-amber-50 text-amber-700',
    red: 'bg-red-50 text-red-700',
  }

  return classes[tone]
}
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This coordinator workspace is scoped to the <strong>Business Administration Department</strong>. Sample data reflects
      <strong>Program 2025-A</strong> placements and journal activity for one department.
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
        <h2 class="text-sm font-bold text-slate-900">Compliance by Partner Company</h2>
        <div class="mt-5 space-y-4">
          <div v-for="item in compliance" :key="item.company">
            <div class="mb-2 flex justify-between text-sm">
              <span class="text-slate-600">{{ item.company }}</span>
              <span class="font-bold text-blue-700">{{ item.value }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
              <div class="h-full rounded-full bg-blue-600" :style="{ width: `${item.value}%` }"></div>
            </div>
          </div>
        </div>
      </section>

      <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-sm font-bold text-slate-900">Students Needing Attention</h2>
        <div class="mt-4 divide-y divide-slate-100">
          <div v-for="student in attention" :key="student.name" class="flex items-center justify-between gap-4 py-3">
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ student.name }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ student.issue }}</p>
            </div>
            <span class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-bold" :class="badgeClass(student.tone)">Follow up</span>
          </div>
        </div>
      </section>
    </div>

    <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div class="flex items-center justify-between">
        <h2 class="text-sm font-bold text-slate-900">Program Snapshot</h2>
        <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-bold text-green-700">Active</span>
      </div>
      <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="[label, value] in placements" :key="label" class="border-b border-slate-100 pb-3">
          <p class="text-xs text-slate-400">{{ label }}</p>
          <p class="mt-1 text-sm font-semibold text-slate-900">{{ value }}</p>
        </div>
      </div>
    </section>
  </section>
</template>
