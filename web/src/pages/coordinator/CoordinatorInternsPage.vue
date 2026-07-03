<script setup lang="ts">
type Intern = {
  name: string
  id: string
  section: string
  company: string
  supervisor: string
  completion: number
}

const interns: Intern[] = [
  { name: 'Maria Santos', id: '2021-BA-002', section: 'BA 4A', company: 'BDO Unibank', supervisor: 'Ms. Grace Fontanilla', completion: 88 },
  { name: 'Jose Santos Jr.', id: '2021-BA-007', section: 'BA 4A', company: 'BDO Unibank', supervisor: 'Ms. Grace Fontanilla', completion: 55 },
  { name: 'Angela Mercado', id: '2021-BA-011', section: 'BA 4A', company: 'Prince Retail Group', supervisor: 'Ms. Hazel Empleo', completion: 72 },
  { name: 'Paula Navarro', id: '2021-BA-014', section: 'BA 4A', company: 'DTI Bohol Provincial Office', supervisor: 'Mr. Joel Sabandal', completion: 49 },
  { name: 'Kristine Lao', id: '2021-BA-016', section: 'BA 4B', company: 'BDO Unibank', supervisor: 'Ms. Grace Fontanilla', completion: 91 },
  { name: 'Bea Mangubat', id: '2021-BA-019', section: 'BA 4B', company: 'Prince Retail Group', supervisor: 'Ms. Hazel Empleo', completion: 68 },
  { name: 'Trisha Velasco', id: '2021-BA-021', section: 'BA 4B', company: 'DTI Bohol Provincial Office', supervisor: 'Mr. Joel Sabandal', completion: 79 },
  { name: 'Noel Gerona', id: '2021-BA-024', section: 'BA 4B', company: 'Prince Retail Group', supervisor: 'Ms. Hazel Empleo', completion: 84 },
  { name: 'Cedric Puno', id: '2021-BA-027', section: 'BA 4C', company: 'BDO Unibank', supervisor: 'Ms. Grace Fontanilla', completion: 75 },
  { name: 'Ivy Relova', id: '2021-BA-030', section: 'BA 4C', company: 'DTI Bohol Provincial Office', supervisor: 'Mr. Joel Sabandal', completion: 83 },
]

const progressClass = (completion: number): string => {
  if (completion >= 80) return 'bg-green-600'
  if (completion >= 60) return 'bg-blue-500'
  return 'bg-amber-600'
}
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This list is limited to <strong>Business Administration</strong> interns under one department coordinator.
    </div>

    <div class="flex flex-wrap gap-3">
      <input class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search students..." />
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Companies</option>
        <option>BDO Unibank</option>
        <option>Prince Retail Group</option>
        <option>DTI Bohol Provincial Office</option>
      </select>
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Sections</option>
        <option>BA 4A</option>
        <option>BA 4B</option>
        <option>BA 4C</option>
      </select>
      <button type="button" class="ml-auto rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Export CSV</button>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Section</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Supervisor</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Completion</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="intern in interns" :key="intern.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ intern.name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ intern.id }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ intern.section }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ intern.company }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ intern.supervisor }}</td>
            <td class="px-4 py-3">
              <p class="mb-1 text-xs font-semibold text-slate-500">{{ intern.completion }}%</p>
              <div class="h-2 w-28 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full" :class="progressClass(intern.completion)" :style="{ width: `${intern.completion}%` }"></div>
              </div>
            </td>
            <td class="px-4 py-3">
              <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-bold text-green-700">Active</span>
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
