<script setup lang="ts">
import { computed } from 'vue'
import type { JournalTemplateSection } from '@/types/api'

const props = defineProps<{
  entryDate: string
  sections: JournalTemplateSection[]
  content: Record<string, string>
}>()

const formattedDate = computed(() => {
  const date = new Date(`${props.entryDate}T00:00:00`)
  const datePart = date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
  const weekday = date.toLocaleDateString(undefined, { weekday: 'long' })
  return `${datePart} – ${weekday}`
})

const mainSection = computed(() => props.sections.find((section) => section.key === 'daily_accomplishment'))

const filledSubSections = computed(() =>
  props.sections.filter(
    (section) => section.key !== 'daily_accomplishment' && (props.content[section.key] ?? '').trim() !== '',
  ),
)
</script>

<template>
  <article
    class="mx-auto max-w-[720px] rounded-sm bg-white p-10 leading-[1.6] text-slate-900 shadow-md"
    style="font-family: 'Times New Roman', Times, serif"
  >
    <header class="mb-8 border-b border-slate-200 pb-4 text-center">
      <h1 class="text-xl font-bold tracking-wide">Daily Journal Entry</h1>
      <p class="mt-1 text-sm text-slate-500">{{ formattedDate }}</p>
    </header>

    <section v-if="mainSection" class="mb-8">
      <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">{{ mainSection.label }}</h2>
      <p class="whitespace-pre-line text-justify text-base">{{ content[mainSection.key] || '—' }}</p>
    </section>

    <section v-for="section in filledSubSections" :key="section.key" class="mb-6">
      <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">{{ section.label }}</h2>
      <p class="whitespace-pre-line text-justify text-base">{{ content[section.key] }}</p>
    </section>

    <p v-if="!mainSection && filledSubSections.length === 0" class="text-center text-sm text-slate-400">
      No content was recorded for this entry.
    </p>
  </article>
</template>
