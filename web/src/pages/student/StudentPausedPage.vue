<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

// Shown when a student cleared intake but was dropped from their batch. It
// replaces the erroring journal pages with a calm, read-only explanation.
const auth = useAuthStore()
</script>

<template>
  <section class="mx-auto max-w-xl">
    <div class="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-200">
      <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-amber-600">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-7 w-7">
          <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7" />
          <path d="M12 7v5m0 3.5h.01" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
        </svg>
      </span>

      <h2 class="mt-5 text-lg font-bold text-slate-900">Your enrollment is currently inactive</h2>
      <p class="mt-2 text-sm text-slate-600">
        Hi {{ auth.user?.name }}, your account is not linked to an active OJT batch right now, so your
        journals and weekly logs are temporarily unavailable. Any entries you submitted before this are
        safely kept and are not deleted.
      </p>
      <p class="mt-3 text-sm text-slate-600">
        This usually means your coordinator has paused or moved your placement. Please contact your
        internship coordinator — once you're enrolled again, full access returns automatically.
      </p>

      <div class="mt-6 flex justify-center gap-3">
        <RouterLink
          to="/student/profile"
          class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
        >
          View My Profile
        </RouterLink>
      </div>
    </div>
  </section>
</template>
