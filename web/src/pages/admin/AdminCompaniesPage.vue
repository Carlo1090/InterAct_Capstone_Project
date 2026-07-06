<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
type CompanyStatus = 'active' | 'inactive'

const companies = [
  { name: 'TechPH Inc.', location: 'IT Park, Cebu City', industry: 'Technology', activeInterns: 8, totalInterns: 22, status: 'active' as CompanyStatus },
  { name: 'BDO Unibank', location: 'Mandaue City, Cebu', industry: 'Banking', activeInterns: 4, totalInterns: 15, status: 'active' as CompanyStatus },
  { name: 'SGV & Co.', location: 'Makati City, Metro Manila', industry: 'Accounting', activeInterns: 2, totalInterns: 7, status: 'active' as CompanyStatus },
  { name: 'PwC Philippines', location: 'Makati City, Metro Manila', industry: 'Accounting', activeInterns: 1, totalInterns: 4, status: 'active' as CompanyStatus },
  { name: 'Crimson Hotel', location: 'Cebu City, Cebu', industry: 'Hospitality', activeInterns: 2, totalInterns: 6, status: 'active' as CompanyStatus },
  { name: 'Globe Telecom', location: 'Cebu City, Cebu', industry: 'Telecoms', activeInterns: 2, totalInterns: 5, status: 'active' as CompanyStatus },
  { name: 'Concentrix Corp', location: 'Cebu City, Cebu', industry: 'IT Services', activeInterns: 3, totalInterns: 10, status: 'active' as CompanyStatus },
  { name: 'Prince Retail Group', location: 'Tagbilaran City, Bohol', industry: 'Retail', activeInterns: 3, totalInterns: 9, status: 'active' as CompanyStatus },
  { name: 'DTI Bohol Provincial Office', location: 'Tagbilaran City, Bohol', industry: 'Government', activeInterns: 3, totalInterns: 11, status: 'active' as CompanyStatus },
  { name: 'ClearPath IT Consulting', location: 'Cebu City, Cebu', industry: 'IT Consulting', activeInterns: 0, totalInterns: 2, status: 'inactive' as CompanyStatus },
]

const statusLabel: Record<CompanyStatus, string> = { active: 'Active', inactive: 'Inactive' }
const statusClass: Record<CompanyStatus, string> = { active: 'bg-green-50 text-green-700', inactive: 'bg-slate-100 text-slate-500' }
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
        + Add Company
      </button>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company Name</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Location</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Industry</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Active Interns</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Total (All-time)</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="company in companies" :key="company.name">
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ company.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ company.location }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ company.industry }}</td>
            <td class="px-4 py-3 font-mono text-sm font-bold text-slate-800">{{ company.activeInterns }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-500">{{ company.totalInterns }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-3 py-1 text-xs font-bold" :class="statusClass[company.status]">{{ statusLabel[company.status] }}</span>
            </td>
            <td class="px-4 py-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">Edit</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
