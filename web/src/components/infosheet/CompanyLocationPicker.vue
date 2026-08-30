<script setup lang="ts">
/**
 * Pins the internship company on a map, for the Student Information Sheet's
 * "Sketch of Internship Company Location" box.
 *
 * The PDF cannot hold an interactive map — dompdf runs no JavaScript — so this
 * only ever produces coordinates. The server rasterises them back into a
 * picture at download time (App\Services\StaticMapService).
 *
 * OpenStreetMap, not Google: Google Maps Platform needs a billing account with
 * a card even inside its free credit, and this project's whole deployment
 * story is zero-cost / no-card. The tile URL and attribution come from the
 * SERVER (`student/location-options`) rather than being hardcoded here, so the
 * map the student pins on and the map that prints can never be two different
 * maps.
 *
 * THE MAP LIVES BEHIND A BUTTON, AND THAT IS THE OPTIMISATION.
 * Rendered inline it cost every student who opened the info sheet a ~43kB
 * gzip map library plus a dozen live tile requests, whether or not they ever
 * touched it — on a form where most fields are typed. Collapsed, the section
 * costs ONE cached PNG from `student/location-preview`, drawn at the printed
 * box's own aspect ratio, so it is a true preview of the sheet rather than an
 * approximation. Leaflet, its stylesheet and every tile are fetched on the
 * first press of "Set location" and never before.
 *
 * Dragging leads, "Use my location" follows — deliberately. The information
 * sheet is the intake gateway, filled in BEFORE the student is enrolled, so
 * they are almost always at home or on campus rather than at the company.
 * Defaulting to their current position would confidently pin the wrong
 * building.
 */
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
import type { Map as LeafletMap, Marker } from 'leaflet'
import api from '@/lib/axios'
import { currentPosition, isPermissionDenied, locationErrorMessage } from '@/lib/dtr'

type SearchResult = { label: string; lat: number; lng: number }

type LocationOptions = {
  enabled: boolean
  tile_url: string
  attribution: string
  default_center: { lat: number; lng: number; zoom: number }
  min_zoom: number
  max_zoom: number
  search_enabled: boolean
}

const props = withDefaults(
  defineProps<{
    lat: number | null
    lng: number | null
    zoom: number | null
    label: string | null
    readonly?: boolean
  }>(),
  { readonly: false },
)

const emit = defineEmits<{
  change: [{ lat: number | null; lng: number | null; zoom: number | null; label: string | null }]
}>()

/**
 * The printed box is 504.57pt x 255.12pt (the full content column by 90mm).
 * The collapsed preview and the modal's guide rectangle are both drawn at
 * exactly that ratio, so what the student sees is what survives the crop.
 */
const PRINT_ASPECT = '504.57 / 255.12'
const PRINT_RATIO = 504.57 / 255.12

const isOpen = ref(false)

const container = ref<HTMLDivElement | null>(null)
const map = shallowRef<LeafletMap | null>(null)
const marker = shallowRef<Marker | null>(null)
// Leaflet's module object, held outside the reactive graph — Vue must never
// try to make a map instance reactive. Cached across opens so the second press
// of "Set location" costs nothing.
const leaflet = shallowRef<typeof import('leaflet') | null>(null)

const options = ref<LocationOptions | null>(null)
const booting = ref(false)
const bootError = ref('')
const locating = ref(false)
const locateError = ref('')
const previewFailed = ref(false)

const searchTerm = ref('')
const searching = ref(false)
const searchError = ref('')
const results = ref<SearchResult[]>([])

/**
 * The pin being edited inside the modal. Nothing reaches the form until "Use
 * this location" — so Cancel really does cancel, which is what a dialog with a
 * Cancel button has to mean.
 */
const draft = ref<{ lat: number | null; lng: number | null; zoom: number | null; label: string | null }>({
  lat: null,
  lng: null,
  zoom: null,
  label: null,
})

const hasPin = computed(() => props.lat !== null && props.lng !== null)
const draftHasPin = computed(() => draft.value.lat !== null && draft.value.lng !== null)

const format = (lat: number, lng: number) => `${lat.toFixed(6)}, ${lng.toFixed(6)}`

const coordinates = computed(() => (hasPin.value ? format(props.lat!, props.lng!) : ''))
const draftCoordinates = computed(() =>
  draftHasPin.value ? format(draft.value.lat!, draft.value.lng!) : 'Tap the map to drop a pin',
)

/** The exact image the PDF will carry, served from the server's own cache. */
const previewUrl = computed(() => {
  if (!hasPin.value) return ''

  const query = new URLSearchParams({
    lat: String(props.lat),
    lng: String(props.lng),
    zoom: String(props.zoom ?? 16),
  })

  return `/api/student/location-preview?${query.toString()}`
})

/**
 * A teardrop matching the marker StaticMapService draws on the PDF, as inline
 * SVG in a divIcon. Leaflet's stock marker loads PNGs by a path relative to its
 * own stylesheet, which a bundler rewrites — the classic "marker is a broken
 * image" bug. Inline SVG has no asset to lose.
 */
const pinSvg = `
<svg width="26" height="36" viewBox="0 0 26 36" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <path d="M13 35C13 35 24 21.5 24 13A11 11 0 1 0 2 13c0 8.5 11 22 11 22z" fill="#1e3a8a"/>
  <circle cx="13" cy="13" r="4.4" fill="#ffffff"/>
</svg>`

const placeMarker = (lat: number, lng: number) => {
  const L = leaflet.value
  if (!L || !map.value) return

  if (marker.value) {
    marker.value.setLatLng([lat, lng])
    return
  }

  marker.value = L.marker([lat, lng], {
    draggable: true,
    icon: L.divIcon({ className: 'company-pin', html: pinSvg, iconSize: [26, 36], iconAnchor: [13, 36] }),
  }).addTo(map.value)

  marker.value.on('dragend', () => {
    const position = marker.value!.getLatLng()
    locateError.value = ''
    draft.value = { lat: position.lat, lng: position.lng, zoom: map.value?.getZoom() ?? null, label: null }
  })
}

const setDraftPin = (lat: number, lng: number, label: string | null = null, zoom?: number) => {
  placeMarker(lat, lng)
  map.value?.setView([lat, lng], zoom ?? map.value.getZoom())
  draft.value = { lat, lng, zoom: map.value?.getZoom() ?? zoom ?? null, label }
}

const useMyLocation = async () => {
  locating.value = true
  locateError.value = ''

  try {
    const position = await currentPosition()
    // 17 is close enough to read street names on the printed 90mm box.
    setDraftPin(position.coords.latitude, position.coords.longitude, null, 17)
  } catch (error) {
    locateError.value = isPermissionDenied(error)
      ? 'Location permission was denied. You can still drag the pin to your company.'
      : locationErrorMessage(error, 'pin your location')
  } finally {
    locating.value = false
  }
}

const search = async () => {
  const term = searchTerm.value.trim()

  searchError.value = ''
  results.value = []

  if (term.length < 3) {
    searchError.value = 'Type at least 3 characters to search.'
    return
  }

  searching.value = true

  try {
    const { data } = await api.get<{ results: SearchResult[]; unavailable?: boolean }>(
      '/api/student/location-search',
      { params: { q: term } },
    )

    results.value = data.results

    if (data.unavailable) {
      searchError.value = 'Address search is unavailable right now — drag the pin to your company instead.'
    } else if (data.results.length === 0) {
      searchError.value = 'No match found. Try the town or a nearby landmark, then drag the pin.'
    }
  } catch {
    searchError.value = 'Address search failed — drag the pin to your company instead.'
  } finally {
    searching.value = false
  }
}

const chooseResult = (result: SearchResult) => {
  results.value = []
  searchTerm.value = ''
  setDraftPin(result.lat, result.lng, result.label, 17)
}

let resizeObserver: ResizeObserver | null = null

/**
 * Size the "Printed area" guide in pixels rather than leaving it to CSS.
 *
 * The guide has to fit inside the map on BOTH axes and still be exactly the
 * printed ratio, and no combination of width/max-height does that: once a max
 * clamps one axis, `aspect-ratio` does not re-derive the other, so the guide
 * silently stops matching the print. That never bit while the box was a 3.56:1
 * strip — at the 90mm box's 1.98:1 it clamps on any short viewport.
 */
const guideStyle = ref<Record<string, string>>({ aspectRatio: PRINT_ASPECT })

const sizeGuide = () => {
  const el = container.value
  if (! el) return

  const width = Math.min(el.clientWidth * 0.92, el.clientHeight * 0.8 * PRINT_RATIO)
  guideStyle.value = { aspectRatio: PRINT_ASPECT, width: `${Math.round(width)}px` }
}

const destroyMap = () => {
  resizeObserver?.disconnect()
  resizeObserver = null
  map.value?.remove()
  map.value = null
  marker.value = null
}

const buildMap = async () => {
  const L = leaflet.value
  if (!L || !options.value || !container.value) return

  const centre = draftHasPin.value
    ? { lat: draft.value.lat!, lng: draft.value.lng!, zoom: draft.value.zoom ?? 17 }
    : options.value.default_center

  map.value = L.map(container.value, {
    center: [centre.lat, centre.lng],
    zoom: centre.zoom,
    minZoom: options.value.min_zoom,
    maxZoom: options.value.max_zoom,
    // A map inside a scrolling dialog must not swallow the scroll.
    scrollWheelZoom: false,
  })

  L.tileLayer(options.value.tile_url, {
    maxZoom: options.value.max_zoom,
    attribution: options.value.attribution,
    // Both of these exist to be a good citizen of a free tile server we do not
    // own: only ask for tiles once a pan settles, and keep one screen of
    // off-view buffer rather than Leaflet's default two.
    updateWhenIdle: true,
    keepBuffer: 1,
  }).addTo(map.value)

  if (draftHasPin.value) {
    placeMarker(draft.value.lat!, draft.value.lng!)
  }

  map.value.on('click', (event) => setDraftPin(event.latlng.lat, event.latlng.lng, null))

  // The printed image is centred on the pin at whatever zoom is showing, so a
  // zoom change is a real edit to what the sheet will look like.
  map.value.on('zoomend', () => {
    if (draftHasPin.value) draft.value = { ...draft.value, zoom: map.value?.getZoom() ?? null }
  })

  // Leaflet measures its container once, and inside a dialog that measurement
  // happens while the panel is still sizing itself.
  resizeObserver = new ResizeObserver(() => {
    map.value?.invalidateSize()
    sizeGuide()
  })
  resizeObserver.observe(container.value)
  sizeGuide()
}

const open = async () => {
  if (props.readonly) return

  draft.value = { lat: props.lat, lng: props.lng, zoom: props.zoom, label: props.label }
  locateError.value = ''
  searchError.value = ''
  results.value = []
  searchTerm.value = ''
  isOpen.value = true

  if (!options.value) {
    booting.value = true
    try {
      const { data } = await api.get<LocationOptions>('/api/student/location-options')
      options.value = data
    } catch {
      bootError.value = 'The map could not be loaded. Your sheet still saves normally without a pinned location.'
      booting.value = false
      return
    }
  }

  // Leaflet and its stylesheet are pulled in HERE, on the first open — never on
  // page load — so a student who does not use the map never downloads it. The
  // same call already made for jsQR on the DTR scanner.
  if (!leaflet.value) {
    try {
      const [L] = await Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')])
      leaflet.value = L
    } catch {
      bootError.value = 'The map could not be loaded. Your sheet still saves normally without a pinned location.'
      booting.value = false
      return
    }
  }

  booting.value = false
  await nextTick()
  await buildMap()
}

const close = () => {
  isOpen.value = false
  destroyMap()
}

const commit = () => {
  previewFailed.value = false
  emit('change', { ...draft.value })
  close()
}

const clearDraftPin = () => {
  marker.value?.remove()
  marker.value = null
  draft.value = { lat: null, lng: null, zoom: null, label: null }
}

const clearPin = () => {
  previewFailed.value = false
  emit('change', { lat: null, lng: null, zoom: null, label: null })
}

// A fresh pin means a fresh preview URL; forget any previous load failure.
watch(() => previewUrl.value, () => { previewFailed.value = false })

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && isOpen.value) close()
}

watch(isOpen, (openNow) => {
  if (openNow) window.addEventListener('keydown', onKeydown)
  else window.removeEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  destroyMap()
})
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-xs font-bold text-slate-600">Company Location</p>
        <p class="mt-1 text-xs text-slate-500">
          Optional. Pin your company on a map and it prints in the
          &ldquo;Sketch of Internship Company Location&rdquo; box on your Information Sheet.
        </p>
      </div>
      <div v-if="!readonly" class="flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
          @click="open"
        >
          {{ hasPin ? 'Change location' : 'Set location' }}
        </button>
        <button
          v-if="hasPin"
          type="button"
          class="rounded-md px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50"
          @click="clearPin"
        >
          Clear pin
        </button>
      </div>
    </div>

    <!-- Collapsed state: one cached image of exactly what will print. -->
    <div v-if="hasPin && !previewFailed" class="overflow-hidden rounded-lg ring-1 ring-slate-200">
      <img
        :src="previewUrl"
        alt="Map of the pinned company location"
        class="block w-full"
        :style="{ aspectRatio: PRINT_ASPECT }"
        loading="lazy"
        decoding="async"
        @error="previewFailed = true"
      />
    </div>
    <div
      v-else-if="!hasPin"
      class="flex items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center text-xs text-slate-500"
    >
      No location pinned — the sketch box will print blank.
    </div>

    <p v-if="hasPin" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
      <span class="font-medium text-slate-700">{{ coordinates }}</span>
      <span v-if="label" class="truncate">· {{ label }}</span>
    </p>

    <!-- ------------------------------------------------------ map dialog -->
    <div
      v-if="isOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="dialog"
      aria-modal="true"
      aria-label="Set company location"
      @click.self="close"
    >
      <div class="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="shrink-0 border-b border-slate-200 px-6 py-4">
          <h3 class="text-sm font-semibold text-slate-900">Set company location</h3>
          <p class="mt-1 text-xs text-slate-500">
            Search for your company, or tap the map to drop the pin. You can drag the pin to fine-tune it.
          </p>
        </div>

        <div class="flex-1 space-y-3 overflow-y-auto px-6 py-5">
          <p
            v-if="bootError"
            class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200"
          >
            {{ bootError }}
          </p>

          <template v-else>
            <div class="flex flex-wrap items-center gap-2">
              <div v-if="options?.search_enabled" class="relative min-w-0 flex-1">
                <form class="flex gap-2" @submit.prevent="search">
                  <input
                    v-model="searchTerm"
                    type="search"
                    placeholder="Search a company, landmark or town"
                    class="w-full min-w-0 rounded-md border border-slate-300 px-3 py-2 text-sm"
                  />
                  <button
                    type="submit"
                    class="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                    :disabled="searching"
                  >
                    {{ searching ? 'Searching…' : 'Search' }}
                  </button>
                </form>

                <ul
                  v-if="results.length"
                  class="absolute z-1000 mt-1 w-full overflow-hidden rounded-md bg-white shadow-lg ring-1 ring-slate-200"
                >
                  <li v-for="result in results" :key="`${result.lat},${result.lng}`">
                    <button
                      type="button"
                      class="block w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                      @click="chooseResult(result)"
                    >
                      {{ result.label }}
                    </button>
                  </li>
                </ul>
              </div>

              <button
                type="button"
                class="shrink-0 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                :disabled="locating || !map"
                @click="useMyLocation"
              >
                {{ locating ? 'Locating…' : 'Use my location' }}
              </button>
            </div>

            <p v-if="searchError" class="text-xs text-slate-500">{{ searchError }}</p>

            <div class="relative overflow-hidden rounded-lg ring-1 ring-slate-200">
              <div ref="container" class="h-[52vh] min-h-64 w-full bg-slate-100"></div>

              <p
                v-if="booting"
                class="absolute inset-0 flex items-center justify-center text-sm text-slate-500"
              >
                Loading map…
              </p>

              <!--
                What the 90mm box will actually contain, at the printed aspect.

                z-500 is load-bearing: Leaflet gives its own panes z-index 400
                in the SHARED stacking context (the map container is
                position:relative with z-index auto), so an un-layered overlay
                is painted underneath the tiles and silently never appears.
                500 clears the panes and still sits below Leaflet's controls
                at 800.
              -->
              <div
                v-if="draftHasPin"
                class="pointer-events-none absolute left-1/2 top-1/2 z-500 -translate-x-1/2 -translate-y-1/2 rounded-sm border-2 border-dashed border-blue-700/70"
                :style="guideStyle"
              >
                <span
                  class="absolute -top-px left-1/2 -translate-x-1/2 -translate-y-full rounded-t bg-blue-700/80 px-2 py-0.5 text-[10px] font-medium text-white"
                >
                  Printed area
                </span>
              </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
              <span :class="draftHasPin ? 'font-medium text-slate-700' : 'text-slate-500'">
                {{ draftCoordinates }}
              </span>
              <button
                v-if="draftHasPin"
                type="button"
                class="font-medium text-red-600 hover:underline"
                @click="clearDraftPin"
              >
                Remove pin
              </button>
            </div>

            <p
              v-if="locateError"
              class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200"
            >
              {{ locateError }}
            </p>
          </template>
        </div>

        <div class="flex shrink-0 items-center justify-end gap-2 border-t border-slate-200 bg-white px-6 py-4">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
            :disabled="!!bootError"
            @click="commit"
          >
            {{ draftHasPin ? 'Use this location' : 'Save without a pin' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Leaflet sizes its own panes; the divIcon wrapper must not add a box of its
   own or the pin sits offset from the point it marks. */
:deep(.company-pin) {
  background: none;
  border: none;
}
</style>
