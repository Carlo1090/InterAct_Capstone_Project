<script setup lang="ts">
import { computed } from 'vue'
import type { JournalTemplateSection } from '@/types/api'

// Mirrors pdf/daily-journal-entry.blade.php 1:1: uppercase name left /
// program right, "Nth Day (MM-DD-YYYY)" label, unlabeled Daily
// Accomplishment paragraphs, then bold inline labels for filled optional
// sections. entryOrdinalLabel comes from show()'s entry_ordinal_label so
// the ordinal-word logic lives server-side only.
//
// With `editable`, each text region becomes an auto-growing textarea
// styled as the paragraph it replaces, writing into the SAME `content`
// object the form fields bind to — one source of truth, no forking.
const props = defineProps<{
  entryDate: string
  sections: JournalTemplateSection[]
  content: Record<string, string>
  studentName: string
  programName: string | null
  entryOrdinalLabel: string
  editable?: boolean
}>()

// Server-guarded per-SIPP-field cap (see StoreJournalEntryRequest).
const sippCharLimit = 300

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

// While editing, a section stays visible as long as it is enabled (its key
// exists in `content` — the form's Include/SIPP toggles add and remove the
// key), so clearing its text mid-edit doesn't yank the editor away. Empty
// sections still render nowhere in read-only view or the PDF.
const editableSubSections = computed(() =>
  props.sections.filter((section) => section.key !== 'daily_accomplishment' && section.key in props.content),
)

const setContent = (key: string, event: Event) => {
  // Direct nested mutation on purpose: `content` is the parent's reactive
  // object shared with the form fields, so edits here appear there too.
  props.content[key] = (event.target as HTMLTextAreaElement).value
}

const resizeToFit = (el: HTMLTextAreaElement) => {
  el.style.height = 'auto'
  el.style.height = `${el.scrollHeight}px`
}

// Auto-grow the inline editors to fit their text so they read as document
// paragraphs, not boxed form fields.
const vAutoGrow = {
  mounted: resizeToFit,
  updated: resizeToFit,
}
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

    <template v-if="editable">
      <textarea
        v-auto-grow
        :value="content['daily_accomplishment'] ?? ''"
        rows="3"
        placeholder="Write what you accomplished today..."
        class="mt-5 block w-full resize-none overflow-hidden rounded-sm bg-transparent p-0 text-justify placeholder:text-slate-400 focus:bg-slate-50 focus:outline-none"
        style="font: inherit; line-height: inherit"
        @input="setContent('daily_accomplishment', $event)"
      />

      <div v-for="section in editableSubSections" :key="section.key" class="mt-4">
        <strong>{{ section.label }}:</strong>
        <textarea
          v-auto-grow
          :value="content[section.key] ?? ''"
          rows="1"
          :maxlength="section.sipp ? sippCharLimit : undefined"
          class="block w-full resize-none overflow-hidden rounded-sm bg-transparent p-0 text-justify placeholder:text-slate-400 focus:bg-slate-50 focus:outline-none"
          style="font: inherit; line-height: inherit"
          @input="setContent(section.key, $event)"
        />
      </div>
    </template>

    <template v-else>
      <p v-if="accomplishmentText" class="mt-5 whitespace-pre-wrap text-justify">{{ accomplishmentText }}</p>

      <p v-for="section in filledSubSections" :key="section.key" class="mt-4 whitespace-pre-wrap text-justify"><strong>{{ section.label }}:</strong> {{ (content[section.key] ?? '').trim() }}</p>
    </template>
  </article>
</template>
