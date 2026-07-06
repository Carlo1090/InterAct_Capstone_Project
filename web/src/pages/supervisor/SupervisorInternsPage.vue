<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
type Intern = {
  name: string
  id: string
  department: string
  program: string
  compliance: number
  lastSubmission: string
}

const interns: Intern[] = [
  { name: 'Juan Dela Cruz', id: '2021-IT-001', department: 'CAST', program: 'BSIT', compliance: 92, lastSubmission: 'May 25, 2025' },
  { name: 'Michael Tan', id: '2021-IT-004', department: 'CAST', program: 'BSIT', compliance: 88, lastSubmission: 'May 25, 2025' },
  { name: 'Sarah Jane Ocampo', id: '2021-IT-006', department: 'CAST', program: 'BSIT', compliance: 76, lastSubmission: 'May 25, 2025' },
  { name: 'Rico Bautista', id: '2021-IT-009', department: 'CAST', program: 'BSIT', compliance: 58, lastSubmission: 'May 24, 2025' },
  { name: 'Faith Anne Custodio', id: '2021-IT-012', department: 'CAST', program: 'BSIT', compliance: 81, lastSubmission: 'May 25, 2025' },
  { name: 'Dennis Yap', id: '2021-IT-015', department: 'CAST', program: 'BSIT', compliance: 47, lastSubmission: 'May 20, 2025' },
  { name: 'Precious Abellana', id: '2021-IT-018', department: 'CAST', program: 'BSIT', compliance: 84, lastSubmission: 'May 25, 2025' },
  { name: 'Miguel Torres', id: '2021-IT-020', department: 'CAST', program: 'BSIT', compliance: 69, lastSubmission: 'May 23, 2025' },
]

const progressClass = (value: number): string => {
  if (value >= 80) return 'bg-green-600'
  if (value >= 60) return 'bg-blue-500'
  return 'bg-amber-600'
}
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap gap-3">
      <input class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search students..." />
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Department</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Program</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Compliance</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Last Submission</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="intern in interns" :key="intern.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ intern.name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ intern.id }}</p>
            </td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">{{ intern.department }}</span>
            </td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ intern.program }}</span>
            </td>
            <td class="px-4 py-3">
              <p class="mb-1 text-xs font-semibold text-slate-500">{{ intern.compliance }}%</p>
              <div class="h-2 w-28 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full" :class="progressClass(intern.compliance)" :style="{ width: `${intern.compliance}%` }"></div>
              </div>
            </td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ intern.lastSubmission }}</td>
            <td class="px-4 py-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">View</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
