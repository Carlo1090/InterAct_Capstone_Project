<script setup lang="ts">
const companies = [
  { name: 'BDO Unibank', location: 'Mandaue City, Cebu', supervisor: 'Ms. Grace Fontanilla', students: 4, focus: 'Branch operations and customer service' },
  { name: 'Prince Retail Group', location: 'Tagbilaran City, Bohol', supervisor: 'Ms. Hazel Empleo', students: 3, focus: 'Merchandising and store administration' },
  { name: 'DTI Bohol Provincial Office', location: 'Tagbilaran City, Bohol', supervisor: 'Mr. Joel Sabandal', students: 3, focus: 'MSME support and records processing' },
]
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap gap-3">
      <input class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search company..." />
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Status</option>
        <option>Active</option>
        <option>Inactive</option>
      </select>
      <button type="button" class="ml-auto rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700">
        + Add Partner Company
      </button>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Location</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Primary Supervisor</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Assigned Interns</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Placement Focus</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="company in companies" :key="company.name">
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ company.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ company.location }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ company.supervisor }}</td>
            <td class="px-4 py-3 font-mono text-sm font-bold text-slate-800">{{ company.students }}</td>
            <td class="max-w-xs px-4 py-3 text-sm text-slate-500">{{ company.focus }}</td>
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
