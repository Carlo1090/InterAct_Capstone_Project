<script setup lang="ts">
import { computed } from 'vue'
import type { AnnualSippMeta, AnnualSippRow } from '@/types/api'

const props = defineProps<{
  rows: AnnualSippRow[]
  meta: AnnualSippMeta
  academicYear: string
}>()

const includedRows = computed(() => props.rows.filter((row) => row.included))
</script>

<template>
  <article class="report-paper mx-auto min-w-[44rem] max-w-4xl rounded-md bg-white px-8 py-10 shadow-md ring-1 ring-slate-200">
    <p class="text-right text-xs font-bold">Annex "C"</p>

    <header class="text-center leading-snug">
      <p class="text-sm font-bold uppercase">Annual Report in the Implementation of</p>
      <p class="text-sm font-bold uppercase">Student Internship Program in the Philippines (SIPP)</p>
      <p class="mt-2 text-sm font-bold">AY: {{ academicYear || '—' }}</p>
    </header>

    <div class="mt-5 space-y-0.5 text-sm">
      <p><span class="font-bold">HEI:</span> MATER DEI COLLEGE, INC.</p>
      <p><span class="font-bold">ADDRESS:</span> CABULIJAN, TUBIGON, BOHOL, PHILIPPINES</p>
      <p><span class="font-bold">DEGREE PROGRAM:</span> {{ meta.heading || '—' }}</p>
    </div>

    <table class="mt-3 w-full table-fixed border-collapse text-xs">
      <thead>
        <tr>
          <th class="w-1/3 border border-black px-2 py-1.5 text-center font-bold">Issues and Concerns Encountered</th>
          <th class="w-1/3 border border-black px-2 py-1.5 text-center font-bold">Solutions</th>
          <th class="w-1/3 border border-black px-2 py-1.5 text-center font-bold">Recommendations</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in includedRows" :key="row.id">
          <td class="border border-black px-2 py-1.5 align-top whitespace-pre-wrap">{{ row.issues_concerns }}</td>
          <td class="border border-black px-2 py-1.5 align-top whitespace-pre-wrap">{{ row.solutions }}</td>
          <td class="border border-black px-2 py-1.5 align-top whitespace-pre-wrap">{{ row.recommendations }}</td>
        </tr>
        <tr v-if="includedRows.length === 0">
          <td colspan="3" class="border border-black px-2 py-6 text-center text-slate-400">No included rows.</td>
        </tr>
      </tbody>
    </table>

    <div class="mt-10 grid grid-cols-2 gap-8 text-sm">
      <div>
        <p class="mb-12">PREPARED BY:</p>
        <p class="font-bold uppercase">{{ meta.signatory_prepared_name || ' ' }}</p>
        <p>{{ meta.signatory_prepared_title }}</p>
        <p class="text-[10px] italic text-slate-500">(Name and Signature)</p>
      </div>
      <div>
        <p class="mb-12">CERTIFIED CORRECT:</p>
        <p class="font-bold uppercase">{{ meta.signatory_certified_name || ' ' }}</p>
        <p>{{ meta.signatory_certified_title }}</p>
        <p class="text-[10px] italic text-slate-500">(Name and Signature)</p>
      </div>
    </div>
  </article>
</template>

<style scoped>
.report-paper {
  font-family: 'Times New Roman', Times, serif;
  color: #000;
}
</style>
