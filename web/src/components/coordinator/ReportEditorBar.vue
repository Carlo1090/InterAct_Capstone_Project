<script setup lang="ts">
/**
 * Shared toolbar for the three coordinator report editors (Group Info Sheet,
 * Annual SIPP, HTE). It carries the Edit ⇄ Preview segmented switch plus the
 * report actions, mirroring the student Write/Weekly journal flow: you edit,
 * switch to Preview to review the rendered document, and the Finalize / Download
 * actions only surface once you're in Preview.
 */
withDefaults(
  defineProps<{
    mode: 'edit' | 'preview'
    status: 'draft' | 'finalized'
    saving?: boolean
    downloading?: boolean
    disabled?: boolean
  }>(),
  { saving: false, downloading: false, disabled: false },
)

const emit = defineEmits<{
  (e: 'update:mode', mode: 'edit' | 'preview'): void
  (e: 'save'): void
  (e: 'finalize'): void
  (e: 'download'): void
}>()
</script>

<template>
  <div class="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white/95 p-3 shadow-sm backdrop-blur">
    <!-- Status pill -->
    <span
      class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
      :class="status === 'finalized' ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700'"
    >
      <span class="h-1.5 w-1.5 rounded-full" :class="status === 'finalized' ? 'bg-green-500' : 'bg-amber-500'" />
      {{ status === 'finalized' ? 'Finalized' : 'Draft' }}
    </span>

    <!-- Edit / Preview segmented switch — anchored right after the status pill so
         its position never shifts between modes (the actions come and go on the
         right without moving this control). -->
    <div class="inline-flex rounded-lg border border-slate-200 bg-slate-100 p-0.5 text-sm font-semibold">
      <button
        type="button"
        class="rounded-md px-4 py-1.5 transition"
        :class="mode === 'edit' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
        @click="emit('update:mode', 'edit')"
      >
        Edit
      </button>
      <button
        type="button"
        class="rounded-md px-4 py-1.5 transition"
        :class="mode === 'preview' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'"
        @click="emit('update:mode', 'preview')"
      >
        Preview
      </button>
    </div>

    <!-- Actions — Preview only, pinned to the far right so the switch stays put.
         Edit mode intentionally shows no action buttons. -->
    <div v-if="mode === 'preview'" class="ml-auto flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 disabled:grayscale disabled:cursor-not-allowed"
        :disabled="saving || disabled"
        @click="emit('save')"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
          <path d="M6 3h9l3 3v13.5A1.5 1.5 0 0116.5 21h-9A1.5 1.5 0 016 19.5V3z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" />
          <path d="M9 3v5h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        {{ saving ? 'Saving…' : 'Save Draft' }}
      </button>

      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100 disabled:grayscale disabled:cursor-not-allowed"
        :disabled="downloading || disabled"
        @click="emit('download')"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
          <path d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        {{ downloading ? 'Preparing…' : 'Download PDF' }}
      </button>

      <button
        type="button"
        class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:grayscale disabled:cursor-not-allowed"
        :disabled="saving || disabled"
        @click="emit('finalize')"
      >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
          <path d="M9 12.75l2 2 4-4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
          <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6" />
        </svg>
        Finalize
      </button>
    </div>
  </div>
</template>
