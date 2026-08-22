<script setup lang="ts">
/**
 * The signed-out page frame shared by Forgot Password and Reset Password.
 *
 * Deliberately a plain, still version of LoginPage's card: the same gradient,
 * blobs and frosted panel, but none of its pointer-tilt, entrance stagger or
 * sheen. Those flourishes belong to the front door; these two pages are a
 * detour someone takes when something has already gone wrong, and animating
 * them would slow down the one thing they came to do.
 */
defineProps<{
  title: string
  subtitle?: string
}>()
</script>

<template>
  <main
    class="relative flex min-h-dvh w-full items-center justify-center overflow-hidden bg-linear-to-br from-blue-900 via-blue-800 to-teal-500 px-5 py-10"
  >
    <div aria-hidden="true" class="pointer-events-none absolute inset-0">
      <span
        class="absolute top-[-10%] -left-24 h-[28rem] w-[28rem] rounded-full bg-linear-to-br from-teal-300 to-blue-400 opacity-25 blur-3xl"
      />
      <span
        class="absolute -right-32 bottom-[-15%] h-[32rem] w-[32rem] rounded-full bg-linear-to-tr from-sky-300 to-teal-200 opacity-25 blur-3xl"
      />
    </div>

    <div class="relative w-full max-w-sm">
      <img
        src="/images/mdc-logo.png"
        alt="Mater Dei College seal"
        class="mx-auto mb-6 h-20 w-20 rounded-full bg-white object-contain p-1 shadow-lg"
      />

      <!--
        bg-white/75 rather than a lighter wash, matching LoginPage: over the
        blue-900 stop a 60% white card composites to roughly #a5b0d0, on which
        slate-600 body text measures 3.50:1 and misses WCAG AA.
      -->
      <div
        class="frost relative overflow-hidden rounded-2xl border border-white/50 bg-white/75 p-6 shadow-2xl backdrop-blur-2xl sm:p-8"
      >
        <!-- Sits above the ::before top-edge highlight, which is positioned. -->
        <div class="relative">
          <h1 class="text-2xl font-bold tracking-tight text-slate-900">{{ title }}</h1>
          <p v-if="subtitle" class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ subtitle }}</p>

          <slot />
        </div>
      </div>

      <p class="mt-6 text-center text-xs text-blue-200">&copy; Mater Dei College</p>
    </div>
  </main>
</template>

<style scoped>
/*
 * Without backdrop-filter the card would render as flat 75% white over the
 * gradient, which drops the body text under AA. Fall back to solid.
 */
@supports not (backdrop-filter: blur(1px)) {
  .frost {
    background-color: #fff;
  }
}

/* Top-edge highlight — the thin band of light that sells a pane of glass. */
.frost::before {
  content: '';
  position: absolute;
  inset: 0 0 auto 0;
  height: 5rem;
  background: linear-gradient(to bottom, rgb(255 255 255 / 0.5), transparent);
  pointer-events: none;
}
</style>
