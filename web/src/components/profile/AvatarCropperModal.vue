<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps<{ imageFile: File }>()
const emit = defineEmits<{ cancel: []; cropped: [blob: Blob] }>()

// On-screen crop circle size. Exported at a higher resolution than this so
// the backend (which resizes down to 250x250 anyway) has headroom to work
// with rather than upscaling a blurry 320px capture.
const CANVAS_SIZE = 320
const OUTPUT_SIZE = 500

const canvasRef = ref<HTMLCanvasElement | null>(null)
const image = ref<HTMLImageElement | null>(null)
const objectUrl = ref('')
const loadError = ref('')
const isReady = ref(false)

const minScale = ref(1)
const maxScale = ref(4)
const scale = ref(1)
const offsetX = ref(0)
const offsetY = ref(0)
const isDragging = ref(false)
const dragStart = ref({ x: 0, y: 0 })
const dragOrigin = ref({ x: 0, y: 0 })

// Renders just the image (no overlay) onto any square canvas context, scaled
// proportionally to that canvas's size — shared by the live preview and the
// final export so what you see is exactly what you get.
const renderImage = (ctx: CanvasRenderingContext2D, size: number) => {
  const img = image.value
  if (!img) return

  const factor = size / CANVAS_SIZE
  const drawWidth = img.naturalWidth * scale.value * factor
  const drawHeight = img.naturalHeight * scale.value * factor
  const dx = size / 2 - drawWidth / 2 + offsetX.value * factor
  const dy = size / 2 - drawHeight / 2 + offsetY.value * factor

  ctx.clearRect(0, 0, size, size)
  ctx.drawImage(img, dx, dy, drawWidth, drawHeight)
}

const draw = () => {
  const canvas = canvasRef.value
  const ctx = canvas?.getContext('2d')
  if (!canvas || !ctx) return

  renderImage(ctx, CANVAS_SIZE)

  // Dim everything outside the crop circle so it reads as a mask, even
  // though the actual export is the full square behind it.
  ctx.save()
  ctx.fillStyle = 'rgba(15, 23, 42, 0.55)'
  ctx.beginPath()
  ctx.rect(0, 0, CANVAS_SIZE, CANVAS_SIZE)
  ctx.arc(CANVAS_SIZE / 2, CANVAS_SIZE / 2, CANVAS_SIZE / 2, 0, Math.PI * 2, true)
  ctx.fill('evenodd')
  ctx.restore()

  ctx.strokeStyle = '#ffffff'
  ctx.lineWidth = 2
  ctx.beginPath()
  ctx.arc(CANVAS_SIZE / 2, CANVAS_SIZE / 2, CANVAS_SIZE / 2 - 1, 0, Math.PI * 2)
  ctx.stroke()
}

// Keeps the image fully covering the crop circle — panning or zooming out
// can never reveal empty canvas inside the circle.
const clampOffsets = () => {
  const img = image.value
  if (!img) return

  const drawWidth = img.naturalWidth * scale.value
  const drawHeight = img.naturalHeight * scale.value
  const maxOffsetX = Math.max(0, (drawWidth - CANVAS_SIZE) / 2)
  const maxOffsetY = Math.max(0, (drawHeight - CANVAS_SIZE) / 2)

  offsetX.value = Math.min(maxOffsetX, Math.max(-maxOffsetX, offsetX.value))
  offsetY.value = Math.min(maxOffsetY, Math.max(-maxOffsetY, offsetY.value))
}

const onScaleInput = () => {
  clampOffsets()
  draw()
}

const onPointerDown = (event: PointerEvent) => {
  isDragging.value = true
  dragStart.value = { x: event.clientX, y: event.clientY }
  dragOrigin.value = { x: offsetX.value, y: offsetY.value }
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
}

const onPointerMove = (event: PointerEvent) => {
  if (!isDragging.value) return
  offsetX.value = dragOrigin.value.x + (event.clientX - dragStart.value.x)
  offsetY.value = dragOrigin.value.y + (event.clientY - dragStart.value.y)
  clampOffsets()
  draw()
}

const onPointerUp = () => {
  isDragging.value = false
}

const cancel = () => emit('cancel')

const confirmCrop = () => {
  if (!image.value) return

  const exportCanvas = document.createElement('canvas')
  exportCanvas.width = OUTPUT_SIZE
  exportCanvas.height = OUTPUT_SIZE
  const ctx = exportCanvas.getContext('2d')
  if (!ctx) return

  renderImage(ctx, OUTPUT_SIZE)
  exportCanvas.toBlob((blob) => {
    if (blob) emit('cropped', blob)
  }, 'image/png')
}

onMounted(() => {
  objectUrl.value = URL.createObjectURL(props.imageFile)

  const img = new Image()
  img.onload = () => {
    image.value = img
    const shortestSide = Math.min(img.naturalWidth, img.naturalHeight)
    minScale.value = CANVAS_SIZE / shortestSide
    maxScale.value = minScale.value * 4
    scale.value = minScale.value
    offsetX.value = 0
    offsetY.value = 0
    isReady.value = true
    draw()
  }
  img.onerror = () => {
    loadError.value = 'Unable to load this image. Please choose a different file.'
  }
  img.src = objectUrl.value
})

onBeforeUnmount(() => {
  if (objectUrl.value) URL.revokeObjectURL(objectUrl.value)
})
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
    <section class="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-slate-950">Adjust Photo</h3>
        <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="cancel">Cancel</button>
      </div>

      <p v-if="loadError" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ loadError }}</p>

      <template v-else>
        <div class="mt-5 flex justify-center">
          <canvas
            ref="canvasRef"
            :width="CANVAS_SIZE"
            :height="CANVAS_SIZE"
            class="touch-none rounded-md"
            :class="isDragging ? 'cursor-grabbing' : 'cursor-grab'"
            @pointerdown="onPointerDown"
            @pointermove="onPointerMove"
            @pointerup="onPointerUp"
            @pointerleave="onPointerUp"
          />
        </div>

        <div class="mt-4">
          <label class="mb-1 block text-xs font-medium text-slate-500" for="avatar-zoom">Zoom</label>
          <input
            id="avatar-zoom"
            v-model.number="scale"
            type="range"
            :min="minScale"
            :max="maxScale"
            step="0.01"
            class="w-full"
            :disabled="!isReady"
            @input="onScaleInput"
          />
        </div>

        <p class="mt-2 text-xs text-slate-400">Drag the photo to reposition it, and use the slider to zoom.</p>
      </template>

      <div class="mt-5 flex justify-end gap-2">
        <button
          type="button"
          class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700"
          @click="cancel"
        >
          Cancel
        </button>
        <button
          type="button"
          class="rounded-md bg-blue-600 px-4 py-1.5 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
          :disabled="!isReady"
          @click="confirmCrop"
        >
          Save
        </button>
      </div>
    </section>
  </div>
</template>
