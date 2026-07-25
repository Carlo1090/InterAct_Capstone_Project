<script setup lang="ts">
import { computed } from 'vue'
import type { HteMeta, HteRow } from '@/types/api'

const props = defineProps<{
  rows: HteRow[]
  meta: HteMeta
  academicYear: string
}>()

/** Only included rows print; group consecutive same-company rows visually. */
const displayRows = computed(() => {
  const included = props.rows.filter((row) => row.included)
  return included.map((row, index) => {
    const prevSame = index > 0 && included[index - 1].host_establishment === row.host_establishment
    let rowspan = 1
    if (!prevSame) {
      let next = index + 1
      while (next < included.length && included[next].host_establishment === row.host_establishment) {
        rowspan += 1
        next += 1
      }
    }
    return { ...row, showHost: !prevSame, rowspan }
  })
})
</script>

<template>
  <article class="report-paper mx-auto max-w-4xl rounded-md bg-white px-8 py-10 shadow-md ring-1 ring-slate-200">
    <p class="text-right text-xs font-bold">Annex "D"</p>

    <header class="text-center leading-snug">
      <p class="text-sm font-bold uppercase">Report on the</p>
      <p class="text-sm font-bold uppercase">List Host Training Establishments (HTEs) and Student Interns Participating in the</p>
      <p class="text-sm font-bold uppercase">Student Internship Program in the Philippines (SIPP)</p>
      <p class="mt-2 text-sm font-bold">AY: {{ academicYear || '—' }}</p>
    </header>

    <div class="mt-5 space-y-0.5 text-sm">
      <p><span class="font-bold">HEI:</span> MATER DEI COLLEGE, INC.</p>
      <p><span class="font-bold">ADDRESS:</span> CABULIJAN, TUBIGON, BOHOL, PHILIPPINES</p>
    </div>

    <table class="mt-3 w-full table-fixed border-collapse text-xs">
      <thead>
        <tr>
          <th class="w-[28%] border border-black px-2 py-1.5 text-center font-bold">PARTNER HOST TRAINING ESTABLISHMENTS</th>
          <th class="w-[24%] border border-black px-2 py-1.5 text-center font-bold">NAME OF STUDENT INTERNS</th>
          <th class="w-[12%] border border-black px-2 py-1.5 text-center font-bold">PROGRAM</th>
          <th class="w-[10%] border border-black px-2 py-1.5 text-center font-bold">GENDER</th>
          <th class="w-[26%] border border-black px-2 py-1.5 text-center font-bold">DATES OF DURATION OF THE INTERNSHIP</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in displayRows" :key="row.id">
          <td v-if="row.showHost" :rowspan="row.rowspan" class="border border-black px-2 py-1.5 align-middle">
            {{ row.host_establishment }}
          </td>
          <td class="border border-black px-2 py-1.5 align-top">{{ row.student_name }}</td>
          <td class="border border-black px-2 py-1.5 text-center align-top">{{ row.program }}</td>
          <td class="border border-black px-2 py-1.5 text-center align-top">{{ row.gender }}</td>
          <td class="border border-black px-2 py-1.5 align-top">{{ row.duration }}</td>
        </tr>
        <tr v-if="displayRows.length === 0">
          <td colspan="5" class="border border-black px-2 py-6 text-center text-slate-400">No included rows.</td>
        </tr>
      </tbody>
    </table>

    <div class="mt-10 grid grid-cols-2 gap-8 text-sm">
      <div>
        <p class="mb-12">PREPARED BY:</p>
        <p class="font-bold uppercase">{{ meta.signatory_prepared_name || ' ' }}</p>
        <p>{{ meta.signatory_prepared_title }}</p>
        <p class="text-[10px] italic text-slate-500">(Name and Signature)</p>
      </div>
      <div>
        <p class="mb-12">CERTIFIED CORRECT:</p>
        <p class="font-bold uppercase">{{ meta.signatory_certified_name || ' ' }}</p>
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
