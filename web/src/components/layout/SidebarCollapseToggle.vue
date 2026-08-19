<script setup lang="ts">
import TooltipWrap from '@/components/ui/TooltipWrap.vue'

/**
 * The circular chevron pinned to the sidebar's right edge.
 *
 * It is ANCHORED, not free-floating: the wrapper is absolutely positioned
 * against the <aside> (which is `fixed`, and so is already the positioning
 * context), straddling the right edge via `translate-x-1/2`. That is what makes
 * it track the sidebar's width on collapse/expand instead of drifting over page
 * content. It deliberately sits OUTSIDE the `<nav>`, which is the scrolling
 * element — so the toggle never scrolls with the nav list.
 *
 * This replaces an earlier drag-positionable version (vertical drag persisted
 * in sessionStorage under `interntrack:sidebar-toggle-y`). That was removed on
 * the project owner's instruction because a dragged position could be parked on
 * top of a nav item and then persisted. The click handler is unchanged: the
 * button still just emits `toggle`, so keyboard Enter/Space work as before.
 */
const props = defineProps<{ collapsed: boolean }>()
defineEmits<{ toggle: [] }>()
</script>

<template>
  <!--
    top-16 clears the first nav item, which begins ~101px down (84px logo block
    + 9px divider + 8px nav padding); the button spans 64-96px. It cannot clear
    the logo block as well — the gap between the two is only ~8px, far less than
    the 32px button — so it sits beside the logo block's empty right margin,
    where nothing is drawn in either the expanded or collapsed state.
    Hidden below md, where the sidebar is a drawer with its own controls.
  -->
  <span class="absolute right-0 top-16 z-40 hidden translate-x-1/2 md:block">
    <TooltipWrap :label="props.collapsed ? 'Expand sidebar' : 'Collapse sidebar'" placement="right">
      <button
        type="button"
        class="flex h-8 w-8 items-center justify-center rounded-full bg-white text-slate-600 shadow-md ring-1 ring-slate-200 transition hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
        :aria-label="props.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
        @click="$emit('toggle')"
      >
        <svg
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 24 24"
          fill="none"
          class="pointer-events-none h-4 w-4 transition-transform duration-200"
          :class="props.collapsed && 'rotate-180'"
        >
          <path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
    </TooltipWrap>
  </span>
</template>
