<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { acceptDialog, cancelDialog, useConfirmDialog } from '@/lib/toast'

// Single app-wide host for the styled confirm/prompt modal (replaces the native
// window.confirm/prompt "Localhost says…" popups). Mounted once in App.vue.
const dialog = useConfirmDialog()

const confirmButton = ref<HTMLButtonElement | null>(null)
const cancelButton = ref<HTMLButtonElement | null>(null)
const inputRef = ref<HTMLTextAreaElement | null>(null)

// When the dialog opens, move focus into it: the input for a prompt, otherwise
// the confirm button.
watch(
  () => dialog.open,
  (open) => {
    if (!open) return
    nextTick(() => {
      if (dialog.mode === 'prompt') inputRef.value?.focus()
      else confirmButton.value?.focus()
    })
  },
)

const onKeydown = (event: KeyboardEvent) => {
  if (!dialog.open) return
  if (event.key === 'Escape') {
    event.preventDefault()
    cancelDialog()
  }
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="dialog.open"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/50 px-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirm-title"
        @click.self="cancelDialog"
      >
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-2xl ring-1 ring-slate-200">
          <div class="flex items-start gap-3">
            <span
              class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
              :class="dialog.tone === 'danger' ? 'bg-red-100 text-red-600' : 'bg-blue-100 text-blue-600'"
            >
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                <path
                  v-if="dialog.tone === 'danger'"
                  d="M12 9v4m0 3.5h.01M10.3 4.3 2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8A1.8 1.8 0 0 0 21.5 18L13.7 4.3a2 2 0 0 0-3.4 0Z"
                  stroke="currentColor"
                  stroke-width="1.7"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
                <path
                  v-else
                  d="M12 8h.01M11 12h1v4h1M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"
                  stroke="currentColor"
                  stroke-width="1.7"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </span>
            <div class="min-w-0 flex-1">
              <h3 id="confirm-title" class="text-base font-bold text-slate-900">{{ dialog.title }}</h3>
              <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ dialog.message }}</p>

              <div v-if="dialog.mode === 'prompt'" class="mt-3">
                <textarea
                  ref="inputRef"
                  v-model="dialog.inputValue"
                  :placeholder="dialog.placeholder"
                  rows="3"
                  class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                  :class="dialog.inputError && 'border-red-400 ring-1 ring-red-300'"
                  @keydown.enter.prevent="acceptDialog"
                  @input="dialog.inputError = ''"
                />
                <p v-if="dialog.inputError" class="mt-1 text-xs text-red-600">{{ dialog.inputError }}</p>
              </div>
            </div>
          </div>

          <div class="mt-5 flex justify-end gap-3">
            <button
              ref="cancelButton"
              type="button"
              class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              @click="cancelDialog"
            >
              {{ dialog.cancelLabel }}
            </button>
            <button
              ref="confirmButton"
              type="button"
              class="rounded-md px-4 py-2 text-sm font-semibold text-white transition"
              :class="dialog.tone === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'"
              @click="acceptDialog"
            >
              {{ dialog.confirmLabel }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
