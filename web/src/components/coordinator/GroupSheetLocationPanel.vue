<script setup lang="ts">
/**
 * The group sheet's "Sketch of Internship Company Location", as the
 * coordinator edits it: where it will print, why, and every intern's own pin.
 *
 * By default the box follows the place most interns pinned (the server's
 * majority, recomputed live). A choice — one intern's pin, or the
 * coordinator's own — overrides it until "Reset to majority". Choosing only
 * changes local state; it is saved with the rest of the sheet.
 */
import { computed, ref, watch } from 'vue'
import CompanyLocationPicker from '@/components/infosheet/CompanyLocationPicker.vue'
import {
  AGREEMENT_RADIUS_METRES,
  GROUP_SKETCH_RATIO,
  distanceFrom,
  effectiveLocation,
  formatDistance,
  previewUrl,
} from '@/lib/groupSheetLocation'
import type { GroupSheetInternPin, GroupSheetLocation, GroupSheetLocationChoice } from '@/types/api'

const props = defineProps<{
  majority: GroupSheetLocation | null
  choice: GroupSheetLocationChoice | null
  pins: GroupSheetInternPin[]
  unpinnedCount: number
}>()

const emit = defineEmits<{ 'update:choice': [GroupSheetLocationChoice | null] }>()

const picker = ref<InstanceType<typeof CompanyLocationPicker> | null>(null)
const previewFailed = ref(false)

const location = computed(() => effectiveLocation(props.choice, props.majority))
const imageUrl = computed(() => previewUrl(location.value))

watch(imageUrl, () => {
  previewFailed.value = false
})

/** Each pin measured against the point that will actually print. */
const rows = computed(() =>
  props.pins.map((pin) => {
    const metres = location.value ? distanceFrom(location.value, pin) : null

    return {
      pin,
      metres,
      agrees: metres !== null && metres <= AGREEMENT_RADIUS_METRES,
      inUse: location.value?.source !== 'coordinator' && location.value?.enrollment_id === pin.enrollment_id,
    }
  }),
)

const agreeCount = computed(() => rows.value.filter((row) => row.agrees).length)

const chosenName = computed(() =>
  props.choice?.source === 'intern'
    ? props.pins.find((pin) => pin.enrollment_id === props.choice?.enrollment_id)?.name ?? 'an intern'
    : '',
)

const coordinates = computed(() =>
  location.value ? `${location.value.lat.toFixed(6)}, ${location.value.lng.toFixed(6)}` : '',
)

const useInternPin = (pin: GroupSheetInternPin) => {
  emit('update:choice', {
    lat: pin.lat,
    lng: pin.lng,
    zoom: pin.zoom,
    label: pin.label,
    source: 'intern',
    enrollment_id: pin.enrollment_id,
  })
}

const onOwnPin = (value: { lat: number | null; lng: number | null; zoom: number | null; label: string | null }) => {
  if (value.lat === null || value.lng === null) return

  emit('update:choice', {
    lat: value.lat,
    lng: value.lng,
    zoom: value.zoom,
    label: value.label,
    source: 'coordinator',
    enrollment_id: null,
  })
}

const pinCaption = (pin: GroupSheetInternPin): string =>
  pin.label || `${pin.lat.toFixed(5)}, ${pin.lng.toFixed(5)}`
</script>

<template>
  <section class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 class="text-sm font-bold text-slate-900">Sketch of Internship Company Location</h3>
        <p class="mt-1 text-xs text-slate-500">
          By default this is the place most interns pinned on their own information sheets.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-if="choice"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50"
          @click="emit('update:choice', null)"
        >
          Reset to majority
        </button>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
          @click="picker?.open()"
        >
          Set my own location
        </button>
      </div>
    </div>

    <!-- Why this point -->
    <p
      v-if="!choice && majority?.contested"
      class="mt-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200"
    >
      <span class="font-semibold">Interns disagree.</span>
      No place has a majority, so this is the most central pin. Check it before printing.
    </p>
    <p v-else-if="!choice && majority" class="mt-4 flex items-center gap-2 text-sm text-emerald-700">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 shrink-0" aria-hidden="true">
        <path d="M5.5 12.5l4 4 9-9.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
      </svg>
      {{ agreeCount }} of {{ pins.length }} pinned {{ pins.length === 1 ? 'intern' : 'interns' }}
      {{ agreeCount === 1 ? 'is' : 'agree' }} within {{ AGREEMENT_RADIUS_METRES }} m.
    </p>
    <p v-else-if="choice" class="mt-4 text-sm text-slate-600">
      {{ choice.source === 'intern' ? `Using ${chosenName}'s pin, chosen by you.` : 'Using your own pin.' }}
      <template v-if="pins.length">
        {{ agreeCount }} of {{ pins.length }} pinned {{ pins.length === 1 ? 'intern' : 'interns' }}
        {{ agreeCount === 1 ? 'is' : 'are' }} within {{ AGREEMENT_RADIUS_METRES }} m of it.
      </template>
    </p>
    <p v-else class="mt-4 text-sm text-slate-500">
      No intern has pinned this company yet. The box prints blank unless you set a location.
    </p>

    <!-- Exactly what will print, drawn at the box's own shape -->
    <div v-if="location && !previewFailed" class="mt-4 overflow-hidden rounded-md ring-1 ring-slate-200">
      <img
        :src="imageUrl"
        alt="Map of the company location that will print"
        class="block w-full"
        :style="{ aspectRatio: String(GROUP_SKETCH_RATIO) }"
        loading="lazy"
        decoding="async"
        @error="previewFailed = true"
      />
    </div>
    <p v-if="location" class="mt-2 flex flex-wrap gap-x-2 text-xs text-slate-500">
      <span class="font-medium text-slate-700">{{ coordinates }}</span>
      <span v-if="location.label" class="min-w-0 truncate">· {{ location.label }}</span>
    </p>

    <!-- Every intern's own pin -->
    <div v-if="pins.length" class="mt-5">
      <h4 class="text-xs font-bold text-slate-600">Interns' pins</h4>
      <ul class="mt-2 divide-y divide-slate-100 border-t border-slate-100">
        <li v-for="row in rows" :key="row.pin.enrollment_id" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
          <div class="min-w-0">
            <p class="truncate text-sm font-medium text-slate-900">{{ row.pin.name }}</p>
            <p class="mt-0.5 truncate text-xs text-slate-500">{{ pinCaption(row.pin) }}</p>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <span
              v-if="row.metres !== null"
              class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
              :class="row.agrees ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'"
            >
              {{ row.agrees ? 'Agrees' : `${formatDistance(row.metres)} away` }}
            </span>
            <span v-if="row.inUse" class="text-xs font-semibold text-blue-700">In use</span>
            <button
              v-else
              type="button"
              class="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50"
              @click="useInternPin(row.pin)"
            >
              Use this
            </button>
          </div>
        </li>
      </ul>
    </div>
    <p v-if="unpinnedCount > 0" class="mt-3 text-xs text-slate-500">
      {{ unpinnedCount }} {{ unpinnedCount === 1 ? "intern hasn't" : "interns haven't" }} pinned this company.
    </p>

    <CompanyLocationPicker
      ref="picker"
      headless
      :allow-empty="false"
      api-base="/api/coordinator"
      :print-ratio="GROUP_SKETCH_RATIO"
      :lat="location?.lat ?? null"
      :lng="location?.lng ?? null"
      :zoom="location?.zoom ?? null"
      :label="location?.label ?? null"
      @change="onOwnPin"
    />
  </section>
</template>
