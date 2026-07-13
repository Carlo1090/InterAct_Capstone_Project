<script setup lang="ts">
import { computed } from 'vue'

// Read-only document-styled renderer of a weekly narrative log, the weekly
// sibling of JournalPaperView (daily): same white-paper card treatment and
// Times New Roman family, at the weekly PDF's smaller body size. Mirrors
// resources/views/pdf/weekly-log.blade.php's parsing exactly — a
// WeeklyBundlingService-compiled "MONDAY\ntext" block gets a bold uppercase
// day header, while freely-edited text without that shape still renders as
// a plain paragraph. No time ranges, no status badges — page chrome owns
// status and actions; this component is only the document.
const props = defineProps<{
  narrative: string
  studentName: string
  weekStart: string
  weekEnd: string
}>()

const WEEKDAY_NAMES = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY']

const narrativeBlocks = computed(() => {
  const narrative = (props.narrative ?? '').trim()
  if (!narrative) return []

  return narrative
    .split(/\n{2,}/)
    .map((block) => block.trim())
    .filter(Boolean)
    .map((block) => {
      const [firstLine, ...rest] = block.split('\n')
      if (WEEKDAY_NAMES.includes(firstLine.trim())) {
        return { day: firstLine.trim(), text: rest.join('\n').trim() }
      }
      return { day: null as string | null, text: block }
    })
})

// m-d-Y to match the document family's date style (e.g. 06-29-2026).
const formatDate = (iso: string): string => {
  const [year, month, day] = iso.split('-')
  return `${month}-${day}-${year}`
}

const weekRange = computed(() => `${formatDate(props.weekStart)} to ${formatDate(props.weekEnd)}`)
</script>

<template>
  <article
    class="mx-auto max-w-[720px] bg-white p-10 text-black shadow-md"
    style="font-family: 'Times New Roman', Times, serif; font-size: 14px; line-height: 1.6"
  >
    <header class="mb-6">
      <p class="font-bold uppercase">{{ studentName }}</p>
      <p>Week of {{ weekRange }}</p>
    </header>

    <p v-if="narrativeBlocks.length === 0" class="italic text-slate-400">No narrative was written for this week.</p>

    <div v-for="(block, index) in narrativeBlocks" :key="index" class="mb-4 last:mb-0">
      <p v-if="block.day" class="font-bold">{{ block.day }}</p>
      <p class="whitespace-pre-wrap text-justify">{{ block.text }}</p>
    </div>
  </article>
</template>
