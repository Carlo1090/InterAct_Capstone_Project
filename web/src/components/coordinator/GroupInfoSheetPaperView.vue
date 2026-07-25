<script setup lang="ts">
import { computed } from 'vue'
import type { GroupInfoSheetCompany, GroupInfoSheetRow } from '@/types/api'

const props = defineProps<{
  departmentLine: string
  company: GroupInfoSheetCompany
  rows: GroupInfoSheetRow[]
}>()

const includedRows = computed(() => props.rows.filter((row) => row.included))

const formatDate = (raw: string) => {
  if (!raw) return ''
  const [year, month, day] = raw.slice(0, 10).split('-').map(Number)
  if (!year || !month || !day) return raw
  return new Date(year, month - 1, day).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
}
</script>

<template>
  <article class="report-paper mx-auto max-w-4xl rounded-md bg-white px-8 py-10 shadow-md ring-1 ring-slate-200">
    <header class="text-center leading-snug">
      <p class="text-base font-bold">Mater Dei College</p>
      <p class="text-sm">Tubigon, Bohol</p>
      <p class="text-sm font-bold">{{ departmentLine || 'College of Accountancy, Business and Management' }}</p>
      <p class="mt-1 text-sm font-bold uppercase">Student Internship Program</p>
      <p class="text-sm font-bold">Student Information Sheet</p>
    </header>

    <!-- Band 1 -->
    <div class="mt-6 bg-[#1F3864] px-3 py-1.5 text-sm font-bold text-white">STUDENT TRAINEE INFORMATION</div>
    <table class="w-full table-fixed border-collapse text-[11px]">
      <thead>
        <tr>
          <th class="w-8 border border-black px-1 py-1.5 text-center font-bold">#</th>
          <th class="border border-black px-1 py-1.5 text-center font-bold">Family Name</th>
          <th class="border border-black px-1 py-1.5 text-center font-bold">First Name</th>
          <th class="w-10 border border-black px-1 py-1.5 text-center font-bold">MI</th>
          <th class="w-24 border border-black px-1 py-1.5 text-center font-bold">Program &amp; Year</th>
          <th class="w-24 border border-black px-1 py-1.5 text-center font-bold">Contact no.</th>
          <th class="border border-black px-1 py-1.5 text-center font-bold">Parent's/Guardian's Name</th>
          <th class="w-24 border border-black px-1 py-1.5 text-center font-bold">Parent's/Guardian's Contact no.</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, index) in includedRows" :key="row.id">
          <td class="border border-black px-1 py-1.5 text-center">{{ index + 1 }}</td>
          <td class="border border-black px-1 py-1.5">{{ row.last_name }}</td>
          <td class="border border-black px-1 py-1.5">{{ row.first_name }}</td>
          <td class="border border-black px-1 py-1.5 text-center">{{ row.middle_initial }}</td>
          <td class="border border-black px-1 py-1.5">{{ row.program_year }}</td>
          <td class="border border-black px-1 py-1.5">{{ row.contact_number }}</td>
          <td class="border border-black px-1 py-1.5">{{ row.parent_guardian_name }}</td>
          <td class="border border-black px-1 py-1.5">{{ row.parent_guardian_contact }}</td>
        </tr>
        <tr v-if="includedRows.length === 0">
          <td colspan="8" class="border border-black px-2 py-6 text-center text-slate-400">No included interns.</td>
        </tr>
      </tbody>
    </table>

    <!-- Band 2 -->
    <div class="mt-6 bg-[#1F3864] px-3 py-1.5 text-sm font-bold text-white">INTERNSHIP COMPANY INFORMATION</div>
    <div class="border border-black text-sm">
      <div class="border-b border-black px-3 py-2">
        <span class="font-bold">Name of Company:</span> {{ company.host_company }}
      </div>
      <div class="border-b border-black px-3 py-2">
        <span class="font-bold">Company Address:</span> {{ company.company_address }}
      </div>
      <div class="grid grid-cols-2">
        <div class="border-b border-r border-black px-3 py-2">
          <span class="font-bold">Complete Name of Official Company Signatory (for MOA):</span> {{ company.company_signatory_moa }}
        </div>
        <div class="border-b border-black px-3 py-2">
          <span class="font-bold">Office Designation/Position:</span> {{ company.office_designation }}
        </div>
        <div class="border-b border-r border-black px-3 py-2">
          <span class="font-bold">Name of Supervisor/Office Head:</span> {{ company.supervisor_name }}
        </div>
        <div class="border-b border-black px-3 py-2">
          <span class="font-bold">Contact Number:</span> {{ company.supervisor_contact }}
        </div>
        <div class="border-b border-r border-black px-3 py-2">
          <span class="font-bold">Intern's Duty Schedule:</span> {{ company.intern_duty_schedule }}
        </div>
        <div class="border-b border-black px-3 py-2">
          <span class="font-bold">Start of Internship Duty:</span> {{ formatDate(company.ojt_start_date) }}
        </div>
        <div class="border-r border-black px-3 py-2">
          <span class="font-bold">Area Assigned:</span> {{ company.area_assigned }}
        </div>
        <div class="px-3 py-2">
          <span class="font-bold">Estimated date to finish internship:</span> {{ formatDate(company.ojt_end_date) }}
        </div>
      </div>
    </div>

    <!-- Sketch box -->
    <p class="mt-6 text-sm font-bold">Sketch of Internship Company Location:</p>
    <div class="mt-1 h-40 rounded border border-[#1F3864]"></div>
  </article>
</template>

<style scoped>
.report-paper {
  font-family: 'Times New Roman', Times, serif;
  color: #000;
}
</style>
