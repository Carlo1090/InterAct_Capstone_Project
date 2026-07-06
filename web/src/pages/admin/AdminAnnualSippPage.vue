<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
const deploymentSummary = [
  { department: 'CAST', students: 8, note: 'IT & technology placements (TechPH Inc., Concentrix Corp, Globe Telecom)' },
  {
    department: 'CABM-B',
    students: 15,
    note: 'Business, financial and accounting placements (BDO Unibank, Prince Retail Group, DTI Bohol Provincial Office, SGV & Co., PwC Philippines)',
  },
  { department: 'CABM-H', students: 5, note: 'Hospitality and tourism placements (Crimson Hotel)' },
]
</script>

<template>
  <section class="grid gap-5 xl:grid-cols-2">
    <form class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="text-sm font-bold text-slate-900">System-wide SIPP Details</h2>

      <div class="mt-5 space-y-4">
        <label class="block">
          <span class="text-xs font-bold text-slate-600">Academic Year</span>
          <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
            <option>2024-2025</option>
            <option>2023-2024</option>
          </select>
        </label>
        <label class="block">
          <span class="text-xs font-bold text-slate-600">Semester</span>
          <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
            <option>2nd Semester</option>
            <option>1st Semester</option>
            <option>Summer</option>
          </select>
        </label>
        <label class="block">
          <span class="text-xs font-bold text-slate-600">Institution Name</span>
          <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="Mater Dei College" />
        </label>
        <label class="block">
          <span class="text-xs font-bold text-slate-600">Institution Address</span>
          <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="Cabulijan, Tubigon, Bohol" />
        </label>
        <label class="block">
          <span class="text-xs font-bold text-slate-600">Department</span>
          <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
            <option>All Departments (System-wide)</option>
            <option>CAST</option>
            <option>CABM-B</option>
            <option>CABM-H</option>
          </select>
        </label>
        <label class="block">
          <span class="text-xs font-bold text-slate-600">Prepared By</span>
          <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="InternTrack Admin Office" />
        </label>
        <div class="grid gap-4 md:grid-cols-2">
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Total Students Enrolled</span>
            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="28" type="number" />
          </label>
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Partner Companies</span>
            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="10" type="number" />
          </label>
        </div>
      </div>

      <button type="button" class="mt-5 w-full rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700">
        Save & Generate
      </button>
    </form>

    <aside class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 class="text-sm font-bold text-slate-900">Summary Data</h2>

      <div class="mt-5 rounded-md bg-slate-50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-blue-700">Deployment Summary</p>
        <div class="mt-3 divide-y divide-slate-200">
          <div v-for="item in deploymentSummary" :key="item.department" class="flex items-start justify-between gap-4 py-3">
            <div>
              <p class="text-sm font-semibold text-slate-900">{{ item.department }}</p>
              <p class="mt-1 text-xs text-slate-500">{{ item.note }}</p>
            </div>
            <p class="whitespace-nowrap text-sm font-bold text-slate-800">{{ item.students }} students</p>
          </div>
        </div>
      </div>

      <div class="mt-4 rounded-md border border-green-100 bg-green-50 px-4 py-3 text-sm text-green-800">
        All 28 interns across CAST, CABM-B, and CABM-H were placed for AY 2024-2025 Second Semester.
      </div>
    </aside>
  </section>
</template>
