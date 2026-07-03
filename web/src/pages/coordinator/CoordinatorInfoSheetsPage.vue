<script setup lang="ts">
type SippStatus = 'submitted' | 'draft' | 'not-started'

const students = [
  { name: 'Maria Santos', id: '2021-BA-002', section: 'BA 4A', company: 'BDO Unibank', status: 'submitted' as SippStatus },
  { name: 'Jose Santos Jr.', id: '2021-BA-007', section: 'BA 4A', company: 'BDO Unibank', status: 'submitted' as SippStatus },
  { name: 'Angela Mercado', id: '2021-BA-011', section: 'BA 4A', company: 'Prince Retail Group', status: 'submitted' as SippStatus },
  { name: 'Paula Navarro', id: '2021-BA-014', section: 'BA 4A', company: 'DTI Bohol Provincial Office', status: 'draft' as SippStatus },
  { name: 'Kristine Lao', id: '2021-BA-016', section: 'BA 4B', company: 'BDO Unibank', status: 'submitted' as SippStatus },
  { name: 'Bea Mangubat', id: '2021-BA-019', section: 'BA 4B', company: 'Prince Retail Group', status: 'draft' as SippStatus },
  { name: 'Trisha Velasco', id: '2021-BA-021', section: 'BA 4B', company: 'DTI Bohol Provincial Office', status: 'submitted' as SippStatus },
  { name: 'Noel Gerona', id: '2021-BA-024', section: 'BA 4B', company: 'Prince Retail Group', status: 'submitted' as SippStatus },
]

const statusLabel: Record<SippStatus, string> = {
  submitted: 'Submitted',
  draft: 'Draft',
  'not-started': 'Not Started',
}

const statusClass: Record<SippStatus, string> = {
  submitted: 'bg-green-50 text-green-700',
  draft: 'bg-amber-50 text-amber-700',
  'not-started': 'bg-red-50 text-red-700',
}
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      This list is limited to <strong>Business Administration</strong> interns under one department coordinator.
    </div>

    <div class="flex flex-wrap gap-3">
      <input class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search student..." />
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Companies</option>
        <option>BDO Unibank</option>
        <option>Prince Retail Group</option>
        <option>DTI Bohol Provincial Office</option>
      </select>
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Status</option>
        <option>Submitted</option>
        <option>Draft</option>
        <option>Not Started</option>
      </select>
      <button type="button" class="ml-auto rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Export All</button>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Student</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Section</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">SIPP Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="student in students" :key="student.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ student.name }}</p>
              <p class="font-mono text-xs text-slate-400">{{ student.id }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ student.section }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ student.company }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass[student.status]">{{ statusLabel[student.status] }}</span>
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
