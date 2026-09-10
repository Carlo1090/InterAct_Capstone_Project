<script setup lang="ts">
import { computed, ref } from 'vue'
import { WEEKDAY_LETTERS, WEEKDAY_NAMES, formatDayRange, isDayInRange } from '@/lib/weekdays'

const props = defineProps<{ start: number; end: number }>()
const emit = defineEmits<{ 'update:start': [number]; 'update:end': [number] }>()

/**
 * A click either STARTS a new range (this is the first click, or the range
 * shown is already complete) or FINISHES the one just started. There is no
 * third state — two clicks always produce a range, single-day included.
 */
const awaitingEnd = ref(false)

const onDayClick = (day: number) => {
  if (!awaitingEnd.value) {
    emit('update:start', day)
    emit('update:end', day)
    awaitingEnd.value = true
  } else {
    emit('update:end', day)
    awaitingEnd.value = false
  }
}

const rangeLabel = computed(() => formatDayRange(props.start, props.end))
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-2" role="group" aria-label="Working days of the week">
      <button
        v-for="(letter, index) in WEEKDAY_LETTERS"
        :key="index"
        type="button"
        class="flex h-10 w-10 items-center justify-center rounded-full border text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
        :class="
          isDayInRange(index + 1, start, end)
            ? 'border-blue-600 bg-blue-600 text-white'
            : 'border-slate-300 bg-white text-slate-600 hover:border-blue-300 hover:bg-blue-50'
        "
        :aria-pressed="isDayInRange(index + 1, start, end)"
        :aria-label="WEEKDAY_NAMES[index]"
        @click="onDayClick(index + 1)"
      >
        {{ letter }}
      </button>
    </div>
    <p class="mt-2 text-xs text-slate-500">
      <span class="font-semibold text-slate-700">{{ rangeLabel }}</span> —
      tap a day to start, then tap another to set the range. Wraps across the week if needed (e.g. Sat &rarr; Tue).
    </p>
  </div>
</template>
