<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue'
import { extractSiteToken, resolveSite, type ScanPreview } from '@/lib/dtr'
import { categorizeError } from '@/lib/apiError'

/**
 * In-app QR scanner for clocking in and out.
 *
 * TWO GATES STAND BETWEEN A DECODE AND A PUNCH, and both exist because a punch
 * is a TOGGLE — an accidental read of a code lying on a desk does not merely
 * do nothing, it clocks the student OUT.
 *
 * 1. A decode is only accepted once the code sits inside the framing guide and
 *    reads the same on consecutive frames. QR error correction is deliberately
 *    good enough to decode a code half out of frame, from a glance, at an
 *    angle — which is a virtue everywhere except here, where it makes a punch
 *    feel like it fired by itself.
 * 2. Nothing is recorded until the student confirms a card naming the site and
 *    the direction. This is the gate that actually matters: with it, decoding
 *    fast is harmless. It also brings this surface in line with the QR landing
 *    page, which has always confirmed first, and with the project's rule that
 *    crucial actions confirm before they happen.
 *
 * Decoding strategy, in order:
 *  1. The browser's native BarcodeDetector where it exists (Chrome, Edge,
 *     Android Chrome) — hardware-accelerated and costs nothing.
 *  2. jsQR over a canvas frame everywhere else. This is the branch that makes
 *     iPhones work at all: Safari has no BarcodeDetector, so without it every
 *     iOS student would be locked out of the in-app scanner.
 *
 * Both paths feed the same extractSiteToken(), so the two can never disagree
 * about what counts as one of our codes.
 *
 * A printed code still opens fine in the phone's own camera app — that path is
 * untouched and remains the fallback whenever camera permission here is
 * refused.
 */

const emit = defineEmits<{ close: []; confirmed: [token: string] }>()

const videoRef = useTemplateRef<HTMLVideoElement>('video')

type Phase = 'starting' | 'scanning' | 'resolving' | 'confirming' | 'error'

const phase = ref<Phase>('starting')
const errorMessage = ref('')
const sawForeignCode = ref(false)
/** A code is in frame but has not yet cleared the framing/stability gate. */
const holdingSteady = ref(false)

const preview = ref<ScanPreview | null>(null)
const pendingToken = ref('')

let stream: MediaStream | null = null
let frameHandle: number | null = null
let canvas: HTMLCanvasElement | null = null
let detector: {
  detect: (source: CanvasImageSource) => Promise<
    Array<{ rawValue: string; cornerPoints?: Array<{ x: number; y: number }> }>
  >
} | null = null
// jsQR is ~90kB and is ONLY needed where the browser has no BarcodeDetector.
// Imported dynamically so Chrome and Android users never download it, and even
// on Safari it lands while the camera is still warming up rather than being
// bundled into the DTR page for everyone.
let decodeFallback: typeof import('jsqr').default | null = null
// Guards the async decode loop: once a token is locked (or the modal closes)
// no further frame may emit, or a lucky second read fires a duplicate punch.
let finished = false

/**
 * Fraction of the frame's short side the code must sit inside.
 *
 * The on-screen guide is h-3/5 w-3/5, i.e. 0.6. Accepting up to 0.75 leaves
 * real tolerance — the guide is an aim, not a tripwire — while still refusing a
 * code hanging off the edge of the frame, which is exactly the "it fired before
 * I had it lined up" case.
 */
const GUIDE_FRACTION = 0.75

/** Consecutive frames that must agree before a token is accepted. */
const REQUIRED_STABLE_FRAMES = 3

/**
 * Decodes are ignored for this long after the camera opens.
 *
 * Without it, a code already lying in frame when the modal opens is read on the
 * very first frame and the student never sees the scanner at all — the punch
 * appears to happen by itself, which is precisely the complaint.
 */
const WARMUP_MS = 600

let startedAt = 0
let stableToken = ''
let stableCount = 0

const stopCamera = () => {
  finished = true

  if (frameHandle !== null) {
    cancelAnimationFrame(frameHandle)
    frameHandle = null
  }

  // Releasing every track is what turns the phone's camera indicator off. A
  // missed track leaves the camera live after the modal is gone, which reads
  // to the user as the app spying on them.
  stream?.getTracks().forEach((track) => track.stop())
  stream = null
}

const close = () => {
  stopCamera()
  emit('close')
}

/**
 * The centred square the code must fall inside, in VIDEO pixel coordinates.
 *
 * The video is displayed with object-cover inside a square box, so what the
 * student sees is a centred square crop of a frame that is usually 4:3 or 16:9.
 * Deriving the guide from videoWidth alone would put it in the wrong place on
 * every phone whose camera is not square.
 */
const guideRect = (width: number, height: number) => {
  const side = Math.min(width, height) * GUIDE_FRACTION

  return {
    left: width / 2 - side / 2,
    top: height / 2 - side / 2,
    right: width / 2 + side / 2,
    bottom: height / 2 + side / 2,
  }
}

const cornersAreFramed = (
  corners: Array<{ x: number; y: number }> | null,
  width: number,
  height: number,
): boolean => {
  // Some BarcodeDetector implementations omit cornerPoints. Refusing the decode
  // there would lock those browsers out of the scanner entirely, which is far
  // worse than accepting a slightly off-centre code — the stability gate and
  // the confirmation card still apply.
  if (!corners || corners.length === 0) return true

  const guide = guideRect(width, height)

  return corners.every(
    (point) =>
      point.x >= guide.left &&
      point.x <= guide.right &&
      point.y >= guide.top &&
      point.y <= guide.bottom,
  )
}

type FrameRead = { token: string; corners: Array<{ x: number; y: number }> | null } | null

/** One frame: try the native detector, else jsQR over the pixel buffer. */
const scanFrame = async (): Promise<FrameRead> => {
  const video = videoRef.value

  if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) return null

  if (detector) {
    const codes = await detector.detect(video)

    for (const code of codes) {
      const token = extractSiteToken(code.rawValue)
      if (token) return { token, corners: code.cornerPoints ?? null }
      if (code.rawValue) sawForeignCode.value = true
    }

    return null
  }

  if (!decodeFallback) return null

  canvas ??= document.createElement('canvas')
  // willReadFrequently tells the browser to keep the buffer in main memory;
  // without it, getImageData on every animation frame forces a GPU readback
  // and the scanner visibly stutters on a mid-range phone.
  const context = canvas.getContext('2d', { willReadFrequently: true })

  if (!context) return null

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight

  if (!canvas.width || !canvas.height) return null

  context.drawImage(video, 0, 0, canvas.width, canvas.height)

  const image = context.getImageData(0, 0, canvas.width, canvas.height)
  const result = decodeFallback(image.data, image.width, image.height, {
    inversionAttempts: 'dontInvert',
  })

  if (!result?.data) return null

  const token = extractSiteToken(result.data)

  if (!token) {
    sawForeignCode.value = true
    return null
  }

  const spot = result.location

  return {
    token,
    corners: spot
      ? [spot.topLeftCorner, spot.topRightCorner, spot.bottomRightCorner, spot.bottomLeftCorner]
      : null,
  }
}

/**
 * A token cleared both gates. Stop the camera and ask the server what this code
 * actually is before offering to punch it — the token is opaque to the client,
 * so only the server can name the site, decide the direction, and reject a code
 * belonging to another company. Same round trip the QR landing page makes.
 */
const lockOn = async (token: string) => {
  if (finished) return

  stopCamera()
  pendingToken.value = token
  holdingSteady.value = false
  phase.value = 'resolving'

  try {
    preview.value = await resolveSite(token)
    phase.value = 'confirming'
  } catch (error) {
    errorMessage.value = categorizeError(error, 'That QR code could not be checked.').message
    phase.value = 'error'
  }
}

const loop = async () => {
  if (finished) return

  try {
    const read = await scanFrame()
    const video = videoRef.value

    if (read && video && performance.now() - startedAt >= WARMUP_MS) {
      const framed = cornersAreFramed(read.corners, video.videoWidth, video.videoHeight)

      if (framed && read.token === stableToken) {
        stableCount += 1
      } else if (framed) {
        stableToken = read.token
        stableCount = 1
      } else {
        // In frame but not lined up — tell the student that, rather than
        // leaving the scanner looking inert while it silently refuses.
        stableToken = ''
        stableCount = 0
      }

      holdingSteady.value = stableCount > 0 || !framed

      if (stableCount >= REQUIRED_STABLE_FRAMES) {
        await lockOn(read.token)
        return
      }
    } else if (!read) {
      stableToken = ''
      stableCount = 0
      holdingSteady.value = false
    }
  } catch {
    // A single bad frame is not worth surfacing — keep scanning.
  }

  if (!finished) {
    frameHandle = requestAnimationFrame(() => void loop())
  }
}

const start = async () => {
  if (!navigator.mediaDevices?.getUserMedia) {
    phase.value = 'error'
    errorMessage.value =
      'This browser cannot open the camera. Use your phone camera app on the QR code instead.'
    return
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({
      // The rear camera. Without this a phone opens the selfie camera, which
      // cannot see a code taped to the wall in front of the student.
      video: { facingMode: { ideal: 'environment' } },
      audio: false,
    })
  } catch (error) {
    phase.value = 'error'
    errorMessage.value =
      typeof error === 'object' && error !== null && (error as { name?: string }).name === 'NotAllowedError'
        ? 'Camera access is blocked. Allow it for this site, or point your phone camera app at the QR code instead.'
        : 'No camera could be opened. Point your phone camera app at the QR code instead.'
    return
  }

  const video = videoRef.value

  if (!video) {
    stopCamera()
    return
  }

  video.srcObject = stream
  // playsinline stops iOS from hijacking the stream into a fullscreen native
  // player, which would cover the whole modal.
  video.setAttribute('playsinline', 'true')
  await video.play().catch(() => undefined)

  const BarcodeDetectorCtor = (window as unknown as { BarcodeDetector?: new (options?: { formats: string[] }) => typeof detector })
    .BarcodeDetector

  if (BarcodeDetectorCtor) {
    try {
      detector = new BarcodeDetectorCtor({ formats: ['qr_code'] })
    } catch {
      // Present but refused qr_code — fall through to jsQR.
      detector = null
    }
  }

  if (!detector) {
    try {
      decodeFallback = (await import('jsqr')).default
    } catch {
      phase.value = 'error'
      errorMessage.value =
        'The scanner could not load. Point your phone camera app at the QR code instead.'
      stopCamera()
      return
    }
  }

  phase.value = 'scanning'
  finished = false
  startedAt = performance.now()
  stableToken = ''
  stableCount = 0
  void loop()
}

/** Back to the camera after a wrong code, or after the student cancels. */
const rescan = async () => {
  preview.value = null
  pendingToken.value = ''
  errorMessage.value = ''
  sawForeignCode.value = false
  holdingSteady.value = false
  phase.value = 'starting'
  await start()
}

const confirm = () => {
  if (!pendingToken.value) return
  emit('confirmed', pendingToken.value)
}

const actionLabel = computed(() =>
  preview.value?.next_action === 'clock_out' ? 'Time Out' : 'Time In',
)

const formatTime = (value: string | null | undefined): string => {
  if (!value) return '—'
  // A genuine instant carrying a timezone marker — the one case the project's
  // "slice, don't parse" date rule allows a real Date for.
  return new Date(value).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
}

onMounted(start)
onBeforeUnmount(stopCamera)
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
    aria-label="Scan the clock-in QR code"
  >
    <div class="absolute inset-0 bg-black/60" @click="close" />

    <div class="relative flex max-h-[90vh] w-full max-w-md flex-col overflow-hidden rounded-xl bg-white shadow-xl">
      <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
        <h2 class="text-sm font-semibold text-slate-900">
          {{ phase === 'confirming' ? 'Confirm before recording' : 'Scan the QR code' }}
        </h2>
        <button
          type="button"
          class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
          aria-label="Close scanner"
          @click="close"
        >
          <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none">
            <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
        </button>
      </div>

      <div class="flex-1 overflow-y-auto px-6 py-5">
        <div v-if="phase === 'error'" class="space-y-4">
          <p class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200/70">
            {{ errorMessage }}
          </p>
          <button
            type="button"
            class="w-full rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            @click="rescan"
          >
            Scan again
          </button>
        </div>

        <!--
          The confirmation gate. Nothing has been written at this point — the
          only thing that has happened is a read-only lookup of what the code
          names. A punch is a toggle, so this is what stops an accidental scan
          silently clocking someone out.
        -->
        <div v-else-if="phase === 'confirming' && preview" class="space-y-4">
          <div class="rounded-lg bg-slate-50 px-4 py-4 ring-1 ring-slate-200/70">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">You are about to</p>
            <p class="mt-1 text-xl font-semibold tracking-tight text-slate-900">
              {{ actionLabel }}
              <span class="text-slate-400">{{ preview.next_action === 'clock_out' ? 'of' : 'at' }}</span>
              {{ preview.site.label }}
            </p>
            <p v-if="preview.site.company" class="text-sm text-slate-500">{{ preview.site.company }}</p>

            <p v-if="preview.next_action === 'clock_out'" class="mt-3 text-sm text-slate-600">
              You clocked in at {{ formatTime(preview.open_session?.time_in) }}.
            </p>
          </div>

          <!--
            Whose record this lands on. On a shared or borrowed phone the
            browser may already hold another student's session, and the scan
            looks identical either way unless the account is named.
          -->
          <p class="text-xs text-slate-500">
            Recording as
            <span class="font-semibold text-slate-700">{{ preview.student.name ?? 'this account' }}</span>
            <span v-if="preview.student.username" class="text-slate-400"> ({{ preview.student.username }})</span>.
          </p>

          <div
            v-if="preview.next_action === 'blocked_other_site'"
            class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-200/70"
          >
            You still have an open session at
            <strong>{{ preview.open_session?.site ?? 'another site' }}</strong>. Ask your supervisor to close it
            before clocking in here.
          </div>

          <button
            v-else
            type="button"
            class="w-full rounded-full bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-700"
            @click="confirm"
          >
            Confirm {{ actionLabel }}
          </button>

          <button
            type="button"
            class="w-full rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            @click="rescan"
          >
            Scan a different code
          </button>

          <p class="text-center text-xs text-slate-400">
            Your location is checked against the workplace when you confirm.
          </p>
        </div>

        <template v-else>
          <div class="relative overflow-hidden rounded-lg bg-slate-900">
            <video ref="video" class="aspect-square w-full object-cover" muted playsinline />

            <!--
              Framing guide. NOT decorative any more — a decode is only accepted
              when the code's corners fall inside this square, so the box the
              student aims at is the box the scanner actually requires.
            -->
            <div class="pointer-events-none absolute inset-0 flex items-center justify-center">
              <div
                class="h-3/5 w-3/5 rounded-lg border-2 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)] transition-colors"
                :class="holdingSteady ? 'border-amber-400' : 'border-white/80'"
              />
            </div>

            <p
              v-if="phase === 'starting'"
              class="absolute inset-0 flex items-center justify-center text-sm text-white"
            >
              Starting camera...
            </p>

            <p
              v-else-if="phase === 'resolving'"
              class="absolute inset-0 flex items-center justify-center bg-black/50 text-sm font-semibold text-white"
            >
              Checking this code...
            </p>

            <p
              v-else-if="holdingSteady"
              class="absolute inset-x-0 bottom-0 bg-amber-500/90 py-2 text-center text-sm font-semibold text-white"
            >
              Hold steady — line the code up inside the box
            </p>
          </div>

          <p class="mt-4 text-center text-sm text-slate-600">
            Point the camera at the QR code your supervisor shows at your workplace, and fit it inside the box.
          </p>

          <p v-if="sawForeignCode" class="mt-2 text-center text-xs text-amber-700">
            That QR code is not an InternTrack clock-in code. Check you are scanning the right one.
          </p>

          <p class="mt-2 text-center text-xs text-slate-400">
            Nothing is recorded until you confirm on the next screen.
          </p>
        </template>
      </div>

      <div class="flex shrink-0 justify-end border-t border-slate-200 bg-white px-6 py-4">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          @click="close"
        >
          Cancel
        </button>
      </div>
    </div>
  </div>
</template>
