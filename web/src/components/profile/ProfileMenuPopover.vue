<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { confirmAction } from '@/lib/toast'
import EditProfilePanel from '@/components/profile/panels/EditProfilePanel.vue'
import ChangePasswordPanel from '@/components/profile/panels/ChangePasswordPanel.vue'
import ActivityLogPanel from '@/components/profile/panels/ActivityLogPanel.vue'
import ReminderSettingsPanel from '@/components/profile/panels/ReminderSettingsPanel.vue'

type View = 'menu' | 'edit' | 'password' | 'activity' | 'reminders'

const auth = useAuthStore()
const router = useRouter()

// Journal reminders only exist for students, so the menu item does too.
const isStudent = computed(() => auth.user?.role === 'student')

const rootRef = ref<HTMLElement | null>(null)
const isOpen = ref(false)
const view = ref<View>('menu')

// An admin-issued temporary password locks the user into the Change
// Password view until they set a new one — no dedicated route to redirect
// to anymore, so this popover owns the whole forced flow itself.
const forced = computed(() => auth.user?.must_change_password === true)

const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(' ')
    .map((part) => part[0])
    .join('')
    .slice(0, 2)
    .toUpperCase(),
)

const viewTitle = computed(() => ({
  menu: 'Account',
  edit: 'Edit Profile',
  password: 'Change Password',
  activity: 'Activity Log',
  reminders: 'Reminder Settings',
}[view.value]))

const openMenu = () => {
  if (forced.value) return
  isOpen.value = !isOpen.value
  if (isOpen.value) view.value = 'menu'
}

const goBack = () => {
  if (forced.value) return
  view.value = 'menu'
}

const selectView = (next: Exclude<View, 'menu'>) => {
  view.value = next
}

const isLoggingOut = ref(false)

const doLogout = async () => {
  const confirmed = await confirmAction({
    title: 'Log out?',
    message: 'You will be signed out on this device and returned to the login page.',
    confirmLabel: 'Log Out',
  })
  if (!confirmed) return

  isLoggingOut.value = true
  try {
    await auth.logout()
  } finally {
    isOpen.value = false
    router.push('/login')
  }
}

const onPasswordChanged = () => {
  // auth.fetchUser() inside ChangePasswordPanel already refreshed
  // must_change_password; the watcher below reacts once it flips false.
}

const onDocumentClick = (event: MouseEvent) => {
  if (forced.value || !isOpen.value || !rootRef.value) return

  // ConfirmHost teleports its modal to <body>, so a click on the logout
  // confirmation's Cancel button reads as "outside" and would close the
  // popover behind it. Treat any click inside a modal dialog as in-bounds.
  const path = event.composedPath()
  if (path.some((node) => node instanceof HTMLElement && node.getAttribute('role') === 'dialog')) {
    return
  }

  // Selecting a menu item (e.g. "Change Password") swaps `view` and unmounts
  // the clicked button in the same tick, before this bubbled listener runs —
  // so `event.target` is already detached and `rootRef.contains(target)`
  // would always read as "outside". composedPath() is captured at dispatch
  // time and stays accurate even after the DOM mutates mid-event.
  if (!path.includes(rootRef.value)) {
    isOpen.value = false
  }
}

watch(
  () => auth.user?.must_change_password,
  (mustChange) => {
    if (mustChange) {
      isOpen.value = true
      view.value = 'password'
    } else if (view.value === 'password' && isOpen.value === true) {
      // Just cleared by a successful forced password change — close the
      // panel automatically instead of leaving it sitting open.
      isOpen.value = false
      view.value = 'menu'
    }
  },
  { immediate: true },
)

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
})
</script>

<template>
  <div ref="rootRef" class="relative">
    <div v-if="forced" class="fixed inset-0 z-44 bg-slate-950/60" />

    <button
      type="button"
      title="Account"
      :aria-expanded="isOpen"
      class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-sm font-bold text-white ring-offset-2 transition hover:ring-2 hover:ring-blue-600"
      @click="openMenu"
    >
      <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" alt="Profile photo" class="h-full w-full object-cover" />
      <span v-else>{{ initials }}</span>
    </button>

    <div
      v-if="isOpen"
      class="absolute right-0 z-45 mt-2 w-96 rounded-lg bg-white shadow-xl ring-1 ring-slate-200"
    >
      <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3">
        <button
          v-if="view !== 'menu' && !forced"
          type="button"
          aria-label="Back"
          class="flex h-6 w-6 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-700"
          @click="goBack"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4">
            <path d="M15 6l-6 6 6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
        <p class="text-sm font-bold text-slate-900">{{ viewTitle }}</p>
      </div>

      <div class="max-h-112 overflow-y-auto">
        <div v-if="view === 'menu'" class="divide-y divide-slate-100">
          <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            @click="selectView('edit')"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 shrink-0 text-slate-400">
              <circle cx="12" cy="8.5" r="3.5" stroke="currentColor" stroke-width="1.6" />
              <path d="M4.5 19.5c1-3.6 3.8-5.5 7.5-5.5s6.5 1.9 7.5 5.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            Edit Profile
          </button>
          <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            @click="selectView('password')"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 shrink-0 text-slate-400">
              <rect x="5.5" y="10.5" width="13" height="9" rx="1.5" stroke="currentColor" stroke-width="1.6" />
              <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            Change Password
          </button>
          <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            @click="selectView('activity')"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 shrink-0 text-slate-400">
              <rect x="4.5" y="3.5" width="15" height="17" rx="1.5" stroke="currentColor" stroke-width="1.6" />
              <path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            Activity Log
          </button>
          <button
            v-if="isStudent"
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50"
            @click="selectView('reminders')"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 shrink-0 text-slate-400">
              <path d="M6.5 10a5.5 5.5 0 0 1 11 0c0 3 .7 4.6 1.5 5.5H5c.8-.9 1.5-2.5 1.5-5.5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M10 18.5a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            Reminder Settings
          </button>
          <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:grayscale disabled:cursor-not-allowed"
            :disabled="isLoggingOut"
            @click="doLogout"
          >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 shrink-0 text-slate-400">
              <path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3M16 16l4-4-4-4M20 12H9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ isLoggingOut ? 'Logging out...' : 'Log Out' }}
          </button>
        </div>

        <EditProfilePanel v-else-if="view === 'edit'" />
        <ChangePasswordPanel v-else-if="view === 'password'" :forced="forced" @success="onPasswordChanged" />
        <ActivityLogPanel v-else-if="view === 'activity'" />
        <ReminderSettingsPanel v-else-if="view === 'reminders'" />
      </div>
    </div>
  </div>
</template>
