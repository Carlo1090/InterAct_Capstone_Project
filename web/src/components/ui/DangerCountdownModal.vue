<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'

// A deliberate-friction confirmation for a permanent, irreversible action.
// The Confirm button stays disabled while a countdown runs (with an animated
// ring), and only unlocks when the timer reaches zero. Cancel is always active
// and focused. State machine: counting -> unlocked (or cancelled by closing).
const props = withDefaults(
  defineProps<{
    open: boolean
    title?: string
    message?: string
    confirmLabel?: string
    holdSeconds?: number
    busy?: boolean
  }>(),
  {
    title: 'Permanently delete',
    message: '',
    confirmLabel: 'Delete permanently',
    holdSeconds: 7,
    busy: false,
  },
)

const emit = defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>()

type Phase = 'counting' | 'unlocked'
const phase = ref<Phase>('counting')
const remaining = ref(props.holdSeconds)
const fraction = ref(0)
const cancelRef = ref<HTMLButtonElement | null>(null)
let rafId: number | undefined

const RADIUS = 26
const CIRCUMFERENCE = 2 * Math.PI * RADIUS
const dashOffset = computed(() => CIRCUMFERENCE * (1 - fraction.value))

const stopAnim = () => {
  if (rafId !== undefined) cancelAnimationFrame(rafId)
  rafId = undefined
}

const start = () => {
  stopAnim()
  phase.value = 'counting'
  remaining.value = props.holdSeconds
  fraction.value = 0
  const startTs = performance.now()

  const tick = (now: number) => {
    const elapsed = (now - startTs) / 1000
    fraction.value = Math.min(1, elapsed / props.holdSeconds)
    remaining.value = Math.max(0, Math.ceil(props.holdSeconds - elapsed))
    if (elapsed >= props.holdSeconds) {
      phase.value = 'unlocked'
      remaining.value = 0
      fraction.value = 1
      return
    }
    rafId = requestAnimationFrame(tick)
  }
  rafId = requestAnimationFrame(tick)
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      start()
      nextTick(() => cancelRef.value?.focus())
    } else {
      stopAnim()
    }
  },
  { immediate: true },
)

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('cancel')
  }
}

onBeforeUnmount(stopAnim)

const canConfirm = computed(() => phase.value === 'unlocked' && !props.busy)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/60 px-4"
      role="alertdialog"
      aria-modal="true"
      aria-labelledby="danger-title"
      aria-describedby="danger-message"
      @keydown="onKeydown"
    >
      <div class="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-red-200">
        <div class="border-b border-red-100 bg-red-50 px-6 py-4">
          <div class="flex items-center gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                <path
                  d="M10.3 4.3 2.5 18a1.8 1.8 0 0 0 1.6 2.7h15.8A1.8 1.8 0 0 0 21.5 18L13.7 4.3a2 2 0 0 0-3.4 0Z"
                  stroke="currentColor"
                  stroke-width="1.7"
                  stroke-linejoin="round"
                />
                <path d="M12 9v4m0 3.5h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
              </svg>
            </span>
            <h3 id="danger-title" class="text-base font-bold text-red-800">{{ title }}</h3>
          </div>
        </div>

        <div class="px-6 py-5">
          <p id="danger-message" class="whitespace-pre-line text-sm text-slate-600">{{ message }}</p>

          <div class="mt-4 flex items-center gap-4 rounded-lg border border-red-100 bg-red-50/50 p-4">
            <div class="relative flex h-16 w-16 shrink-0 items-center justify-center">
              <svg viewBox="0 0 64 64" class="h-16 w-16 -rotate-90">
                <circle cx="32" cy="32" :r="RADIUS" fill="none" stroke="#fecaca" stroke-width="6" />
                <circle
                  cx="32"
                  cy="32"
                  :r="RADIUS"
                  fill="none"
                  :stroke="phase === 'unlocked' ? '#16a34a' : '#dc2626'"
                  stroke-width="6"
                  stroke-linecap="round"
                  :stroke-dasharray="CIRCUMFERENCE"
                  :stroke-dashoffset="dashOffset"
                  style="transition: stroke-dashoffset 80ms linear"
                />
              </svg>
              <span class="absolute text-sm font-bold" :class="phase === 'unlocked' ? 'text-green-600' : 'text-red-600'">
                <svg v-if="phase === 'unlocked'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
                  <path d="m5 13 4 4L19 7" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <template v-else>{{ remaining }}</template>
              </span>
            </div>
            <p class="text-sm font-medium" aria-live="polite">
              <template v-if="phase === 'counting'">
                <span class="text-red-700">Holding for {{ remaining }}s…</span>
                <span class="mt-0.5 block text-xs font-normal text-slate-500">This action is permanent and cannot be undone.</span>
              </template>
              <template v-else>
                <span class="text-green-700">You can now confirm.</span>
                <span class="mt-0.5 block text-xs font-normal text-slate-500">Click delete only if you are certain.</span>
              </template>
            </p>
          </div>
        </div>

        <div class="flex justify-end gap-3 border-t border-slate-100 px-6 py-4">
          <button
            ref="cancelRef"
            type="button"
            class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            @click="emit('cancel')"
          >
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md px-4 py-2 text-sm font-semibold text-white transition"
            :class="canConfirm ? 'bg-red-600 hover:bg-red-700 ring-2 ring-red-300' : 'bg-red-300 cursor-not-allowed'"
            :disabled="!canConfirm"
            :aria-disabled="!canConfirm"
            @click="canConfirm && emit('confirm')"
          >
            {{ busy ? 'Deleting…' : confirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
