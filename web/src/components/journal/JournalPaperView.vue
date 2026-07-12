<script setup lang="ts">
import { computed } from 'vue'
import type { JournalTemplateSection } from '@/types/api'

// Mirrors pdf/daily-journal-entry.blade.php 1:1: uppercase name left /
// program right, "Nth Day (MM-DD-YYYY)" label, unlabeled Daily
// Accomplishment paragraphs, then bold inline labels for filled optional
// sections. entryOrdinalLabel comes from show()'s entry_ordinal_label so
// the ordinal-word logic lives server-side only.
const props = defineProps<{
  entryDate: string
  sections: JournalTemplateSection[]
  content: Record<string, string>
  studentName: string
  programName: string | null
  entryOrdinalLabel: string
}>()

// m-d-Y to match the PDF's day label exactly (e.g. 07-12-2022).
const formattedDate = computed(() => {
  const [year, month, day] = props.entryDate.split('-')
  return `${month}-${day}-${year}`
})

const accomplishmentText = computed(() => (props.content['daily_accomplishment'] ?? '').trim())

const filledSubSections = computed(() =>
  props.sections.filter(
    (section) => section.key !== 'daily_accomplishment' && (props.content[section.key] ?? '').trim() !== '',
  ),
)
</script>

<template>
  <article
    class="mx-auto max-w-[720px] bg-white p-10 text-black shadow-md"
    style="font-family: 'Times New Roman', Times, serif; font-size: 16px; line-height: 1.6"
  >
    <header class="flex items-start justify-between gap-6">
      <span class="uppercase">{{ studentName }}</span>
      <span class="text-right">{{ programName }}</span>
    </header>

    <p class="mt-8">{{ entryOrdinalLabel }} ({{ formattedDate }})</p>

    <p v-if="accomplishmentText" class="mt-5 whitespace-pre-wrap text-justify">{{ accomplishmentText }}</p>

    <p v-for="section in filledSubSections" :key="section.key" class="mt-4 whitespace-pre-wrap text-justify"><strong>{{ section.label }}:</strong> {{ (content[section.key] ?? '').trim() }}</p>
  </article>
</template>
