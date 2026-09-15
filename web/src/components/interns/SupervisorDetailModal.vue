<script setup lang="ts">
import { computed } from 'vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { SupervisorDetail } from '@/types/api'

const props = defineProps<{ detail: SupervisorDetail | null; isLoading: boolean; errorMessage?: string }>()
defineEmits<{ close: [] }>()

const initials = computed(() =>
  (props.detail?.name ?? '')
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

const statusStyle = (status: string) =>
  status === 'active'
    ? 'bg-green-50 text-green-700'
    : status === 'completed'
      ? 'bg-blue-50 text-blue-700'
      : 'bg-slate-100 text-slate-500'
</script>

<template>
  <!-- Three-part flex shell: the body is the only scrolling element. -->
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
    <section class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
      <div class="shrink-0 border-b border-slate-200 px-6 py-4">
        <div class="flex items-start justify-between gap-4">
          <div class="flex min-w-0 items-center gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-lg font-bold text-white">
              <img v-if="detail?.avatar_url" :src="detail.avatar_url" alt="Profile photo" class="h-full w-full object-cover" />
              <span v-else>{{ initials || '—' }}</span>
            </div>
            <div class="min-w-0">
              <h3 class="truncate text-xl font-semibold text-slate-900">{{ detail?.name ?? 'Supervisor Details' }}</h3>
              <p class="mt-0.5 truncate text-sm text-slate-500">{{ detail?.username ? `@${detail.username}` : 'No username on file' }}</p>
            </div>
          </div>
          <button type="button" class="shrink-0 text-sm font-medium text-slate-500 hover:text-slate-900" @click="$emit('close')">
            Close
          </button>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto px-6 py-5">
        <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
        <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>

        <template v-else-if="detail">
          <div class="grid gap-x-8 gap-y-5 sm:grid-cols-2">
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Email</p>
              <p class="mt-1 text-sm font-medium text-slate-900">
                <TooltipWrap v-if="detail.email" :label="detail.email" placement="top" class="max-w-full">
                  <a :href="`mailto:${detail.email}`" class="block max-w-full truncate text-blue-600 transition hover:text-blue-700">
                    {{ detail.email }}
                  </a>
                </TooltipWrap>
                <span v-else>—</span>
              </p>
            </div>
            <div class="min-w-0">
              <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Status</p>
              <p class="mt-1 text-sm font-medium text-slate-900">
                <span
                  class="rounded-full px-3 py-1 text-xs font-bold"
                  :class="detail.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
                >
                  {{ detail.is_active ? 'Active' : 'Inactive' }}
                </span>
              </p>
            </div>
          </div>

          <div class="mt-5 border-t border-slate-100 pt-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Companies</p>
            <div v-if="detail.companies.length" class="mt-2 flex flex-wrap gap-1.5">
              <span
                v-for="company in detail.companies"
                :key="company.id"
                class="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-700"
              >
                {{ company.name }}<span v-if="company.position" class="text-slate-400"> · {{ company.position }}</span>
              </span>
            </div>
            <p v-else class="mt-2 text-sm text-slate-400">Not currently attached to any company.</p>
          </div>

          <div class="mt-5 border-t border-slate-100 pt-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">
              Interns Supervised ({{ detail.interns.length }})
            </p>
            <ul v-if="detail.interns.length" class="mt-2 divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
              <li v-for="intern in detail.interns" :key="intern.id" class="flex items-center justify-between gap-3 px-3 py-2">
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium text-slate-900">{{ intern.name }}</p>
                  <p class="truncate text-xs text-slate-500">
                    {{ intern.batch?.name ?? '—' }}<span v-if="intern.company"> · {{ intern.company.name }}</span>
                  </p>
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold capitalize" :class="statusStyle(intern.status)">
                  {{ intern.status }}
                </span>
              </li>
            </ul>
            <p v-else class="mt-2 text-sm text-slate-400">Has not been assigned any interns yet.</p>
          </div>
        </template>
      </div>

      <div class="flex shrink-0 justify-end border-t border-slate-200 bg-white px-6 py-4">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          @click="$emit('close')"
        >
          Close
        </button>
      </div>
    </section>
  </div>
</template>
