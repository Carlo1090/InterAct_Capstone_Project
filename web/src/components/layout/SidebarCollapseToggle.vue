<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * The circular chevron on the sidebar's right edge. Same button as before —
 * same size, colour, ring and icon — but it can now be DRAGGED vertically
 * along that edge, because at a fixed 50% it can sit right on top of whatever
 * the user is reading in the rail.
 *
 * Extracted into one component on purpose: all four role layouts rendered a
 * byte-identical copy of this button, and the drag logic below is far too much
 * to paste four times and keep in sync.
 *
 * Click vs. drag is settled by DISTANCE, not time: the pointer has to move more
 * than a few pixels before it counts as a drag, so an ordinary click (which
 * always jitters a pixel or two) still toggles.
 */
const props = defineProps<{ collapsed: boolean }>()
const emit = defineEmits<{ toggle: [] }>()

const STORAGE_KEY = 'interntrack:sidebar-toggle-y'
/** Movement beyond this many px stops being a click and becomes a drag. */
const DRAG_THRESHOLD_PX = 4
/** Half of h-7 (28px) — the button is positioned by its CENTRE. */
const RADIUS_PX = 14

const buttonRef = ref<HTMLButtonElement | null>(null)
/** Centre offset from the sidebar's top, in px. `null` = the default 50%. */
const offsetY = ref<number | null>(null)

let pressed = false
let moved = false
let startPointerY = 0
let startOffsetY = 0
let activePointerId: number | null = null

/** The <aside>; the button is absolutely positioned against it. */
const track = (): HTMLElement | null => (buttonRef.value?.offsetParent as HTMLElement) ?? null

/** Keep the whole circle inside the sidebar, top and bottom. */
const clampToTrack = (y: number): number => {
  const height = track()?.clientHeight ?? 0
  if (height === 0) return y
  return Math.min(Math.max(y, RADIUS_PX), height - RADIUS_PX)
}

/**
 * `offsetTop` IS the centre, and must not have the radius added to it: the
 * button keeps `-translate-y-1/2`, so the box is painted half its height above
 * its CSS `top`. Adding RADIUS_PX here made every drag land 14px low.
 */
const currentOffset = (): number => offsetY.value ?? buttonRef.value?.offsetTop ?? 0

// sessionStorage (not localStorage) so the position lasts the session and is
// gone when the tab closes — matching the app's existing draft convention. Every
// access is best-effort: Safari private mode and a full quota both THROW, and a
// remembered button position must never break the sidebar.
const readStoredOffset = (): number | null => {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)
    if (raw === null) return null
    const parsed = Number(raw)
    return Number.isFinite(parsed) ? parsed : null
  } catch {
    return null
  }
}

const storeOffset = (y: number): void => {
  try {
    sessionStorage.setItem(STORAGE_KEY, String(Math.round(y)))
  } catch {
    /* storage unavailable — the position just won't survive navigation */
  }
}

const onPointerDown = (event: PointerEvent): void => {
  if (event.button !== 0) return
  pressed = true
  moved = false
  startPointerY = event.clientY
  startOffsetY = currentOffset()
  activePointerId = event.pointerId
  // Capture so the drag keeps tracking once the pointer leaves the 28px circle
  // — without this the button stops following as soon as you move off it.
  buttonRef.value?.setPointerCapture(event.pointerId)
}

const onPointerMove = (event: PointerEvent): void => {
  if (!pressed) return
  const delta = event.clientY - startPointerY
  if (!moved && Math.abs(delta) <= DRAG_THRESHOLD_PX) return
  moved = true
  offsetY.value = clampToTrack(startOffsetY + delta)
}

const endDrag = (): void => {
  if (!pressed) return
  pressed = false
  if (activePointerId !== null) {
    buttonRef.value?.releasePointerCapture(activePointerId)
    activePointerId = null
  }
  if (moved && offsetY.value !== null) storeOffset(offsetY.value)
}

/**
 * The toggle stays on `click`, deliberately: that keeps the keyboard path
 * working (Enter/Space fire click with no pointer events at all, so `moved` is
 * false and the sidebar toggles as before). A click that merely trails a drag
 * is swallowed here instead.
 */
const onClick = (): void => {
  if (moved) {
    moved = false
    return
  }
  emit('toggle')
}

// A stored offset can fall outside a now-shorter window, so re-clamp on resize.
const onResize = (): void => {
  if (offsetY.value !== null) offsetY.value = clampToTrack(offsetY.value)
}

onMounted(() => {
  const stored = readStoredOffset()
  if (stored !== null) offsetY.value = clampToTrack(stored)
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => window.removeEventListener('resize', onResize))
</script>

<template>
  <!--
    `touch-none` is required, not cosmetic: without it a vertical touch drag is
    claimed by the browser as a page scroll and the button never moves.
    `top-1/2` remains the default centre; the inline style overrides it only
    once the button has actually been dragged.
  -->
  <button
    ref="buttonRef"
    type="button"
    class="absolute -right-3.5 top-1/2 hidden h-7 w-7 -translate-y-1/2 cursor-grab touch-none items-center justify-center rounded-full bg-indigo-700 text-white shadow-md ring-2 ring-white transition hover:bg-indigo-800 active:cursor-grabbing md:flex"
    :style="offsetY === null ? undefined : { top: `${offsetY}px` }"
    :aria-label="props.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="endDrag"
    @pointercancel="endDrag"
    @click="onClick"
  >
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      class="pointer-events-none h-4 w-4 transition-transform"
      :class="props.collapsed && 'rotate-180'"
    >
      <path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
  </button>
</template>
