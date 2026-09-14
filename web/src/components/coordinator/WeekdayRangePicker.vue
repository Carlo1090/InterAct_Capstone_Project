<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { WEEKDAY_NAMES, formatDayRange, isDayInRange } from '@/lib/weekdays'

/**
 * The selection is two points: a START and an optional END. `end === null`
 * means only the start is chosen (one highlighted day); `start === null`
 * means nothing is chosen at all. A single-day range is therefore always
 * (day, null), never (day, day) — the parent collapses null to the start when
 * it submits, and expands an equal pair back to null when it loads.
 */
const props = defineProps<{ start: number | null; end: number | null }>()
const emit = defineEmits<{ 'update:start': [number | null]; 'update:end': [number | null] }>()

/**
 * Every tap on a chosen point toggles it OFF; a tap elsewhere adds or
 * restarts. Spelled out:
 *
 *   nothing chosen        tap X          -> start X
 *   start only            tap start      -> nothing chosen
 *   start only            tap X          -> range start..X
 *   full range            tap end        -> start only (pick a different end)
 *   full range            tap start      -> nothing chosen
 *   full range            tap other X    -> start X (fresh selection)
 *
 * Deselecting the START empties everything rather than promoting the end,
 * because the start is the anchor the range hangs off — the two points are
 * not symmetric, and "I tapped Monday and now only Friday is lit" reads as a
 * glitch. Deselecting the END keeps the start so a different end can be
 * picked without starting over, which is the whole point of the gesture.
 */
const onDayClick = (day: number) => {
  if (justDragged.value) {
    justDragged.value = false
    return
  }
  const { start, end } = props
  if (start === null) {
    emit('update:start', day)
    emit('update:end', null)
  } else if (day === start) {
    emit('update:start', null)
    emit('update:end', null)
  } else if (end !== null && day === end) {
    emit('update:end', null)
  } else if (end === null) {
    emit('update:end', day)
  } else {
    emit('update:start', day)
    emit('update:end', null)
  }
}

/**
 * Click-and-drag is a second way to arrive at the same two points: press a
 * day, glide across the others, release on the last one. It writes the same
 * (start, end) pair the taps do, so the tap rules above apply to the result
 * exactly as if it had been tapped in — release on the end, tap it again, and
 * the end drops off. Gliding back onto the pressed day reads as "start only",
 * matching the single-day representation. `elementFromPoint` (not per-button
 * `pointerenter`) drives it so a fast or touch drag that skips over a button's
 * hit box still tracks correctly.
 */
const dragAnchor = ref<number | null>(null)
const dragMoved = ref(false)
const justDragged = ref(false)

const onPointerMove = (event: PointerEvent) => {
  if (dragAnchor.value === null) return
  const hit = document.elementFromPoint(event.clientX, event.clientY) as HTMLElement | null
  const button = hit?.closest<HTMLElement>('[data-weekday]')
  if (!button) return
  const day = Number(button.dataset.weekday)
  if (Number.isNaN(day)) return
  if (day !== dragAnchor.value) dragMoved.value = true
  if (dragMoved.value) {
    emit('update:start', dragAnchor.value)
    emit('update:end', day === dragAnchor.value ? null : day)
  }
}

const endDrag = () => {
  if (dragMoved.value) {
    // Swallow the single stray `click` a browser still fires when a drag
    // happens to start and end on the SAME button. A real cross-button drag
    // never gets a click at all, so this must also self-clear on its own —
    // relying only on the next onPointerDown to reset it would leave a stale
    // `true` sitting there to wrongly swallow a later KEYBOARD activation
    // (Enter/Space fires `click` with no preceding pointerdown). The
    // `setTimeout` still runs after any same-tick stray click, since click
    // dispatch is synchronous with pointerup.
    justDragged.value = true
    setTimeout(() => {
      justDragged.value = false
    }, 0)
  }
  dragAnchor.value = null
  dragMoved.value = false
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', endDrag)
  window.removeEventListener('pointercancel', endDrag)
}

const onPointerDown = (day: number, event: PointerEvent) => {
  if (event.pointerType === 'mouse' && event.button !== 0) return
  justDragged.value = false
  dragAnchor.value = day
  dragMoved.value = false
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', endDrag)
  window.addEventListener('pointercancel', endDrag)
}

onBeforeUnmount(() => {
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerup', endDrag)
  window.removeEventListener('pointercancel', endDrag)
})

const isSelected = (day: number): boolean => {
  if (props.start === null) return false
  if (props.end === null) return day === props.start
  return isDayInRange(day, props.start, props.end)
}

const rangeLabel = computed(() => {
  if (props.start === null) return 'No days selected'
  return formatDayRange(props.start, props.end ?? props.start)
})
</script>

<template>
  <div>
    <div class="flex flex-wrap gap-2 touch-none select-none" role="group" aria-label="Working days of the week">
      <button
        v-for="(name, index) in WEEKDAY_NAMES"
        :key="index"
        type="button"
        :data-weekday="index + 1"
        class="flex h-10 min-w-12 items-center justify-center rounded-full border px-3 text-sm font-bold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
        :class="
          isSelected(index + 1)
            ? 'border-blue-600 bg-blue-600 text-white'
            : 'border-slate-300 bg-white text-slate-600 hover:border-blue-300 hover:bg-blue-50'
        "
        :aria-pressed="isSelected(index + 1)"
        :aria-label="WEEKDAY_NAMES[index]"
        @pointerdown="onPointerDown(index + 1, $event)"
        @click="onDayClick(index + 1)"
      >
        {{ name }}
      </button>
    </div>
    <p class="mt-2 text-xs text-slate-500">
      <span class="font-semibold" :class="start === null ? 'text-red-600' : 'text-slate-700'">{{ rangeLabel }}</span> —
      tap a start day, then an end day, or press and drag across them. Tap a chosen day again to deselect it. Wraps
      across the week if needed (e.g. Sat &rarr; Tue).
    </p>
  </div>
</template>
