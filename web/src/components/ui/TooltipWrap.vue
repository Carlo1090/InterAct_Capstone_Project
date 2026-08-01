<script setup lang="ts">
import { computed, ref } from 'vue'

/**
 * Hover + keyboard-focus tooltip for icon-only controls.
 *
 * The tooltip is DECORATION ONLY — it is `aria-hidden`, and the accessible name
 * must come from an `aria-label` on the wrapped control itself. Never rely on
 * this for accessibility, and never add a native `title` alongside it (the
 * browser would render its own tooltip on top of this one).
 *
 * Visibility is explicit state rather than CSS `group-hover`/`group-focus-within`,
 * for two reasons a pure-CSS version cannot handle:
 *  1. Clicking a control leaves it focused, so `group-focus-within` pinned the
 *     tooltip open until something else was clicked. `suppressed` closes it on
 *     activation and keeps it closed until the pointer actually leaves.
 *  2. Focus alone is not a reason to show a tooltip — only KEYBOARD focus is.
 *     `:focus-visible` is what separates a Tab from a mouse click.
 */
type Placement = 'top' | 'right' | 'bottom'
type Align = 'center' | 'start' | 'end'

const props = withDefaults(
  defineProps<{
    label: string
    placement?: Placement
    align?: Align
  }>(),
  { placement: 'top', align: 'center' },
)

const isHovered = ref(false)
const isKeyboardFocused = ref(false)
/** Set on activation so the tooltip cannot linger over the control you just used. */
const suppressed = ref(false)

const visible = computed(() => (isHovered.value || isKeyboardFocused.value) && !suppressed.value)

const onFocusIn = (event: FocusEvent): void => {
  const target = event.target
  if (!(target instanceof Element)) return

  try {
    // A mouse click also fires focusin; :focus-visible is false there, which is
    // what stops a click from pinning the tooltip open.
    isKeyboardFocused.value = target.matches(':focus-visible')
  } catch {
    // Older engines do not know the selector and throw on matches().
    isKeyboardFocused.value = false
  }
}

/** Main-axis offset. Cross-axis anchoring is `align`, below. */
const PLACEMENT_CLASSES: Record<Placement, string> = {
  top: 'bottom-full mb-2',
  right: 'left-full ml-2 top-1/2 -translate-y-1/2',
  bottom: 'top-full mt-2',
}

/**
 * Only meaningful for `top`/`bottom` — `right` is already anchored on both axes.
 * `end` pins the tooltip's right edge to the control's, so it grows leftward and
 * stays inside the viewport for controls sitting near the right edge.
 */
const ALIGN_CLASSES: Record<Align, string> = {
  center: 'left-1/2 -translate-x-1/2',
  start: 'left-0',
  end: 'right-0',
}

const positionClasses = computed(() =>
  props.placement === 'right'
    ? PLACEMENT_CLASSES.right
    : `${PLACEMENT_CLASSES[props.placement]} ${ALIGN_CLASSES[props.align]}`,
)

/**
 * Short labels read better on one line; longer ones wrap inside the max width
 * instead of running off the screen.
 */
const wrapClasses = computed(() =>
  props.label.length <= 24 ? 'whitespace-nowrap' : 'whitespace-normal text-center leading-snug',
)
</script>

<template>
  <span
    class="group relative inline-flex"
    @mouseenter="isHovered = true"
    @mouseleave="isHovered = false; suppressed = false"
    @focusin="onFocusIn"
    @focusout="isKeyboardFocused = false; suppressed = false"
    @pointerdown="suppressed = true"
    @keyup.enter="suppressed = true"
    @keyup.space="suppressed = true"
  >
    <slot />
    <span
      v-show="visible"
      aria-hidden="true"
      class="pointer-events-none absolute z-50 max-w-[240px] rounded-md bg-slate-900 px-2 py-1 text-xs font-medium text-white shadow-lg transition duration-150 motion-reduce:transition-none"
      :class="[positionClasses, wrapClasses]"
    >
      {{ label }}
    </span>
  </span>
</template>
