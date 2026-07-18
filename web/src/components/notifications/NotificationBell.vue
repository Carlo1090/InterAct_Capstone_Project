<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { AppNotification } from '@/types/api'

const POLL_INTERVAL_MS = 60_000

const rootRef = ref<HTMLElement | null>(null)
const isOpen = ref(false)
const unreadCount = ref(0)
const notifications = ref<AppNotification[]>([])
const isLoading = ref(false)
let pollTimer: ReturnType<typeof setInterval> | undefined

const refreshUnreadCount = async () => {
  try {
    const { data } = await api.get<{ unread_count: number }>('/api/notifications')
    unreadCount.value = data.unread_count
  } catch {
    // Non-fatal; the badge just stays at its last known value.
  }
}

const formatSentAt = (isoString: string): string => {
  const date = new Date(isoString)
  return Number.isNaN(date.getTime())
    ? isoString
    : date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

const openDropdown = async () => {
  isOpen.value = true
  isLoading.value = true

  try {
    const { data } = await api.get<{ data: AppNotification[]; unread_count: number }>('/api/notifications')
    notifications.value = data.data

    if (data.unread_count > 0) {
      await api.post('/api/notifications/read-all')
      notifications.value = notifications.value.map((n) => ({ ...n, is_read: true }))
    }
    unreadCount.value = 0
  } catch {
    // Non-fatal; dropdown just shows whatever it last had (possibly empty).
  } finally {
    isLoading.value = false
  }
}

const toggleDropdown = () => {
  if (isOpen.value) {
    isOpen.value = false
  } else {
    openDropdown()
  }
}

const onDocumentClick = (event: MouseEvent) => {
  if (isOpen.value && rootRef.value && !rootRef.value.contains(event.target as Node)) {
    isOpen.value = false
  }
}

onMounted(() => {
  refreshUnreadCount()
  pollTimer = setInterval(refreshUnreadCount, POLL_INTERVAL_MS)
  document.addEventListener('click', onDocumentClick)
})

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer)
  document.removeEventListener('click', onDocumentClick)
})
</script>

<template>
  <div ref="rootRef" class="relative">
    <button
      type="button"
      title="Notifications"
      class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
      @click="toggleDropdown"
    >
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5">
        <path
          d="M6 9a6 6 0 0 1 12 0c0 3.2 1 5 1.8 6H4.2C5 14 6 12.2 6 9Z"
          stroke="currentColor"
          stroke-width="1.6"
          stroke-linejoin="round"
        />
        <path d="M10 19a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
      </svg>
      <span
        v-if="unreadCount > 0"
        class="absolute right-1.5 top-1.5 h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-white"
      />
    </button>

    <div
      v-if="isOpen"
      class="absolute right-0 z-20 mt-2 w-80 rounded-lg bg-white shadow-xl ring-1 ring-slate-200"
    >
      <div class="border-b border-slate-100 px-4 py-3">
        <p class="text-sm font-bold text-slate-900">Notifications</p>
      </div>

      <div class="max-h-96 overflow-y-auto">
        <p v-if="isLoading" class="px-4 py-6 text-center text-sm text-slate-500">Loading...</p>
        <p v-else-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-slate-400">No notifications yet.</p>
        <div v-else class="divide-y divide-slate-100">
          <div v-for="notification in notifications" :key="notification.id" class="px-4 py-3">
            <p class="text-sm font-semibold text-slate-800">{{ notification.title }}</p>
            <p v-if="notification.message" class="mt-0.5 text-xs text-slate-500">{{ notification.message }}</p>
            <p class="mt-1 text-[11px] text-slate-400">{{ formatSentAt(notification.sent_at) }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
