<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { confirmAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { Department, PaginatedResponse, User } from '@/types/api'

type UserPayload = {
  first_name: string
  middle_name: string
  last_name: string
  email: string
  password: string
  role: 'coordinator'
  department_id: number | null
}

const users = ref<User[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const isModalOpen = ref(false)
const errorMessage = ref('')
const modalError = ref('')

const search = ref('')
const roleFilter = ref('')
const departmentFilter = ref('')

const viewingUser = ref<User | null>(null)

// An empty table means two different things — nothing exists, or the filters
// excluded everything — and only the second one has a way out. See clearFilters.
const hasFilters = computed(() => search.value !== '' || roleFilter.value !== '' || departmentFilter.value !== '')

const clearFilters = () => {
  search.value = ''
  roleFilter.value = ''
  departmentFilter.value = ''
}

/**
 * The password is revealed only while the control is actively held — a click
 * toggle can leave it in plain text on a shared machine after the admin has
 * moved on.
 */
const revealPassword = ref(false)

/** Space/Enter auto-repeat fires keydown continuously; only the first matters. */
const holdViaKey = (event: KeyboardEvent) => {
  if (event.repeat) return
  revealPassword.value = true
}

const hidePassword = () => {
  revealPassword.value = false
}

/**
 * Releasing outside the button, or switching windows mid-hold, never delivers
 * pointerup/keyup to the button itself — so the window has to be the backstop,
 * or the password would stay visible with nothing holding it.
 */
watch(revealPassword, (revealed) => {
  if (revealed) {
    window.addEventListener('pointerup', hidePassword)
    window.addEventListener('keyup', hidePassword)
    window.addEventListener('blur', hidePassword)
  } else {
    window.removeEventListener('pointerup', hidePassword)
    window.removeEventListener('keyup', hidePassword)
    window.removeEventListener('blur', hidePassword)
  }
})

onUnmounted(() => {
  window.removeEventListener('pointerup', hidePassword)
  window.removeEventListener('keyup', hidePassword)
  window.removeEventListener('blur', hidePassword)
})

let searchDebounce: ReturnType<typeof setTimeout> | undefined

const emptyForm = (): UserPayload => ({
  first_name: '',
  middle_name: '',
  last_name: '',
  email: '',
  password: '',
  role: 'coordinator',
  department_id: null,
})

const userForm = ref<UserPayload>(emptyForm())

const resetForm = () => {
  userForm.value = emptyForm()
  modalError.value = ''
  revealPassword.value = false
}

const loadUsers = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<PaginatedResponse<User>>('/api/admin/users', {
      params: {
        search: search.value || undefined,
        role: roleFilter.value || undefined,
        department_id: departmentFilter.value || undefined,
      },
    })
    users.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load users.'
  } finally {
    isLoading.value = false
  }
}

const loadDepartments = async () => {
  try {
    const response = await api.get<Department[]>('/api/admin/departments')
    departments.value = response.data
  } catch {
    // Filter/create dropdowns just stay empty; not critical to the page loading.
  }
}

watch(search, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(loadUsers, 300)
})
watch([roleFilter, departmentFilter], loadUsers)

const openModal = () => {
  resetForm()
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
  resetForm()
}

const createUser = async () => {
  if (!userForm.value.department_id) {
    modalError.value = 'Select a department for this coordinator.'
    return
  }

  isSaving.value = true
  modalError.value = ''

  try {
    await api.post('/api/admin/users', userForm.value)
    closeModal()
    await loadUsers()
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    modalError.value = data?.message ?? 'Unable to create user. Please check the fields and try again.'
  } finally {
    isSaving.value = false
  }
}

const deactivateUser = async (user: User) => {
  const confirmed = await confirmAction({
    title: 'Deactivate this account?',
    message: `Deactivate ${user.name}'s account? They won't be able to log in until reactivated.`,
    confirmLabel: 'Deactivate Account',
    tone: 'danger',
  })
  if (!confirmed) return

  try {
    await api.patch(`/api/admin/users/${user.id}/deactivate`)
    await loadUsers()
    showToast(`${user.name}'s account deactivated.`)
  } catch {
    errorMessage.value = 'Unable to deactivate user.'
  }
}

const reactivateUser = async (user: User) => {
  const confirmed = await confirmAction({
    title: 'Reactivate this account?',
    message: `Reactivate ${user.name}'s account? They will be able to log in again immediately.`,
    confirmLabel: 'Reactivate Account',
  })
  if (!confirmed) return

  try {
    await api.patch(`/api/admin/users/${user.id}/activate`)
    await loadUsers()
    showToast(`${user.name}'s account reactivated.`)
  } catch {
    errorMessage.value = 'Unable to reactivate user.'
  }
}

const openView = (user: User) => {
  viewingUser.value = user
}

const closeView = () => {
  viewingUser.value = null
}

onMounted(() => {
  loadUsers()
  loadDepartments()
})
</script>

<template>
  <section>
    <ToastHost />
    <div class="flex flex-wrap items-center justify-end gap-4">
      <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700" @click="openModal">Create Coordinator</button>
    </div>

    <div class="mt-6 grid gap-3 sm:grid-cols-3 xl:max-w-3xl">
      <label class="block">
        <span class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400">Search</span>
        <input v-model="search" class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm" placeholder="Search by name..." />
      </label>
      <label class="block">
        <span class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400">Role</span>
        <select v-model="roleFilter" class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm">
          <option value="">All Users</option>
          <option value="student">Student</option>
          <option value="coordinator">Coordinator</option>
          <option value="supervisor">Supervisor</option>
        </select>
      </label>
      <label class="block">
        <span class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400">Department</span>
        <select v-model="departmentFilter" class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm">
          <option value="">All Departments</option>
          <option v-for="department in departments" :key="department.id" :value="department.id">
            {{ department.name }}
          </option>
        </select>
      </label>
    </div>

    <p v-if="isLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="mt-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ errorMessage }}
    </p>

    <template v-else>
      <div v-if="users.length === 0" class="mt-6 rounded-xl bg-white px-6 py-8 text-center shadow-sm ring-1 ring-slate-200/70">
        <p class="text-sm text-slate-500">
          {{ hasFilters ? 'No users match these filters.' : 'No users yet. Use "Create Coordinator" to add one.' }}
        </p>
        <button
          v-if="hasFilters"
          type="button"
          class="mt-2 text-sm font-semibold text-blue-600 transition hover:text-blue-700"
          @click="clearFilters"
        >
          Clear filters
        </button>
      </div>

      <template v-else>
        <!--
          md and up: aligned table. Column widths live in the colgroup and are
          enforced by `table-fixed`, so the fixed-width Actions cell can never be
          squeezed into wrapping onto two lines by a long name or email.
        -->
        <div class="mt-6 hidden overflow-x-auto rounded-xl bg-white px-6 shadow-sm ring-1 ring-slate-200/70 md:block">
          <table class="w-full table-fixed">
            <colgroup>
              <col class="w-[200px]" />
              <col />
              <col class="w-[120px]" />
              <col class="w-[150px]" />
              <col class="w-[150px]" />
              <col class="w-[100px]" />
              <col class="w-[190px]" />
            </colgroup>
            <thead>
              <tr class="text-xs font-medium uppercase tracking-wide text-slate-400">
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pl-0 pr-4 pt-4 text-left font-medium">Name</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Email</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Role</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Program</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Department</th>
                <th class="whitespace-nowrap border-b border-slate-200 px-4 pb-3 pt-4 text-left font-medium">Status</th>
                <th class="whitespace-nowrap border-b border-slate-200 pb-3 pl-4 pr-0 pt-4 text-right font-medium">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="user in users" :key="user.id" class="transition-colors hover:bg-slate-50/70">
                <td class="truncate py-3.5 pl-0 pr-4 text-sm font-medium text-slate-900">{{ user.name }}</td>
                <td class="px-4 py-3.5 text-sm text-slate-500">
                  <TooltipWrap :label="user.email || 'No email'" placement="top" class="max-w-full">
                    <span class="block max-w-full truncate">{{ user.email || '—' }}</span>
                  </TooltipWrap>
                </td>
                <td class="truncate px-4 py-3.5 text-sm capitalize text-slate-500">{{ user.role }}</td>
                <td class="truncate px-4 py-3.5 text-sm text-slate-500">{{ user.program?.name ?? 'No Program' }}</td>
                <td class="truncate px-4 py-3.5 text-sm text-slate-500">
                  {{ user.departments_coordinated?.[0]?.name ?? user.program?.department?.name ?? 'No Department' }}
                </td>
                <td class="px-4 py-3.5 text-sm">
                  <span
                    class="rounded-full px-2 py-1 text-xs font-semibold"
                    :class="user.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
                  >
                    {{ user.is_active ? 'Active' : 'Inactive' }}
                  </span>
                </td>
                <td class="py-3.5 pl-4 pr-0">
                  <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                    <button
                      type="button"
                      class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                      @click="openView(user)"
                    >
                      View
                    </button>
                    <button
                      v-if="user.is_active"
                      type="button"
                      class="rounded-md px-3 py-1.5 text-sm font-medium text-red-600 transition hover:text-red-700"
                      @click="deactivateUser(user)"
                    >
                      Deactivate
                    </button>
                    <button
                      v-else
                      type="button"
                      class="rounded-md px-3 py-1.5 text-sm font-medium text-green-700 transition hover:text-green-800"
                      @click="reactivateUser(user)"
                    >
                      Reactivate
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Below md: one stacked block per user, so nothing scrolls sideways. -->
        <ul class="mt-6 divide-y divide-slate-100 rounded-xl bg-white px-6 shadow-sm ring-1 ring-slate-200/70 md:hidden">
          <li v-for="user in users" :key="user.id" class="py-4">
            <div class="flex items-start justify-between gap-3">
              <p class="min-w-0 truncate text-sm font-medium text-slate-900">{{ user.name }}</p>
              <span
                class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold"
                :class="user.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
              >
                {{ user.is_active ? 'Active' : 'Inactive' }}
              </span>
            </div>
            <p class="mt-1 truncate text-xs text-slate-500">{{ user.email || '—' }}</p>
            <p class="mt-1 truncate text-xs capitalize text-slate-500">
              {{ [user.role, user.program?.name ?? 'No Program', user.departments_coordinated?.[0]?.name ?? user.program?.department?.name ?? 'No Department'].join(' · ') }}
            </p>
            <div class="mt-3 flex items-center gap-2">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                @click="openView(user)"
              >
                View
              </button>
              <button
                v-if="user.is_active"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium text-red-600 transition hover:text-red-700"
                @click="deactivateUser(user)"
              >
                Deactivate
              </button>
              <button
                v-else
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium text-green-700 transition hover:text-green-800"
                @click="reactivateUser(user)"
              >
                Reactivate
              </button>
            </div>
          </li>
        </ul>
      </template>
    </template>

    <!-- Create Coordinator: three-part flex shell, body is the only scroller. -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <section class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">Create Coordinator</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">
            Cancel
          </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5">
          <section class="space-y-4">
            <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Name</h4>
            <div class="grid gap-4 sm:grid-cols-3">
              <div>
                <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400" for="user-first-name">First Name</label>
                <input id="user-first-name" v-model="userForm.first_name" type="text" class="h-10 w-full rounded-md border border-slate-300 px-3 text-sm" />
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400" for="user-middle-name">Middle Name</label>
                <input id="user-middle-name" v-model="userForm.middle_name" type="text" class="h-10 w-full rounded-md border border-slate-300 px-3 text-sm" />
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400" for="user-last-name">Family Name</label>
                <input id="user-last-name" v-model="userForm.last_name" type="text" class="h-10 w-full rounded-md border border-slate-300 px-3 text-sm" />
              </div>
            </div>
          </section>

          <section class="mt-5 space-y-4 border-t border-slate-100 pt-5">
            <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Login Credentials</h4>
            <div>
              <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400" for="user-email">Email / Username</label>
              <input id="user-email" v-model="userForm.email" type="email" class="h-10 w-full rounded-md border border-slate-300 px-3 text-sm" />
              <p class="mt-1 text-xs text-slate-400">Coordinators sign in with this. Must be a valid email address.</p>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400" for="user-password">Password</label>
              <!--
                The wrapper holds ONLY the input and the button — the helper text
                sits outside it, or `inset-y-0` would centre the button against
                the combined height instead of the field's.

                The absolute positioning lives on this span, NOT on TooltipWrap
                and not on the button: TooltipWrap's own root is
                `group relative inline-flex`, so an `absolute` class merged onto
                it fights `relative` for the same property and loses on
                stylesheet order (Tailwind emits `.absolute` first), which is
                exactly what dropped the icon into normal flow below the field.
                Positioning a plain span keeps TooltipWrap's relative context
                intact for anchoring its own bubble.
              -->
              <div class="relative">
                <input
                  id="user-password"
                  v-model="userForm.password"
                  :type="revealPassword ? 'text' : 'password'"
                  class="h-10 w-full rounded-md border border-slate-300 px-3 pr-11 text-sm"
                />
                <span class="absolute inset-y-0 right-0 flex items-center">
                  <TooltipWrap label="Hold to show password" placement="top" align="end">
                    <button
                      type="button"
                      tabindex="0"
                      aria-label="Hold to show password"
                      class="flex h-10 w-11 touch-none select-none items-center justify-center rounded-md text-slate-400 transition hover:text-slate-600 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500"
                      @pointerdown.prevent="revealPassword = true"
                      @pointerup="revealPassword = false"
                      @pointerleave="revealPassword = false"
                      @pointercancel="revealPassword = false"
                      @blur="revealPassword = false"
                      @contextmenu.prevent
                      @keydown.space.prevent="holdViaKey"
                      @keydown.enter.prevent="holdViaKey"
                      @keyup.space="revealPassword = false"
                      @keyup.enter="revealPassword = false"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-5 w-5 transition-opacity">
                        <g v-if="revealPassword">
                          <path d="M3 12s3.6-6 9-6 9 6 9 6-3.6 6-9 6-9-6-9-6Z" stroke="currentColor" stroke-width="1.6" />
                          <circle cx="12" cy="12" r="2.6" stroke="currentColor" stroke-width="1.6" />
                        </g>
                        <g v-else>
                          <path d="M3 12s3.6-6 9-6 9 6 9 6-3.6 6-9 6-9-6-9-6Z" stroke="currentColor" stroke-width="1.6" />
                          <path d="M4 4l16 16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </g>
                      </svg>
                    </button>
                  </TooltipWrap>
                </span>
              </div>
              <p class="mt-1.5 text-xs text-slate-400">At least 8 characters.</p>
            </div>
          </section>

          <section class="mt-5 space-y-4 border-t border-slate-100 pt-5">
            <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">Assignment</h4>
            <div>
              <label class="mb-1.5 block text-xs font-medium uppercase tracking-wide text-slate-400" for="user-department">Department</label>
              <select id="user-department" v-model="userForm.department_id" class="h-10 w-full rounded-md border border-slate-300 bg-white px-3 text-sm">
                <option :value="null">Select Department</option>
                <option v-for="department in departments" :key="department.id" :value="department.id">
                  {{ department.name }}
                </option>
              </select>
            </div>
          </section>

          <p v-if="modalError" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalError }}</p>
        </div>

        <div class="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="isSaving" @click="createUser">
            {{ isSaving ? 'Creating...' : 'Create Coordinator' }}
          </button>
        </div>
      </section>
    </div>


    <div v-if="viewingUser" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="max-h-[calc(100vh-4rem)] w-full max-w-md overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Account Details</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeView">
            Close
          </button>
        </div>

        <div class="mt-6 flex items-center gap-3">
          <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full bg-blue-600 text-sm font-bold text-white">
            <img v-if="viewingUser.avatar_url" :src="viewingUser.avatar_url" alt="Profile photo" class="h-full w-full object-cover" />
            <span v-else>{{ viewingUser.name.slice(0, 2).toUpperCase() }}</span>
          </div>
          <div>
            <p class="font-semibold text-slate-900">{{ viewingUser.name }}</p>
            <span
              class="rounded-full px-2 py-0.5 text-xs font-semibold"
              :class="viewingUser.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
            >
              {{ viewingUser.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
        </div>

        <dl class="mt-6 space-y-3 text-sm">
          <div class="flex justify-between gap-4">
            <dt class="font-medium text-slate-500">Username</dt>
            <dd class="text-right text-slate-900">{{ viewingUser.username ?? '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="font-medium text-slate-500">Email</dt>
            <dd class="text-right text-slate-900">{{ viewingUser.email || '—' }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="font-medium text-slate-500">Role</dt>
            <dd class="text-right capitalize text-slate-900">{{ viewingUser.role }}</dd>
          </div>
          <div v-if="viewingUser.program" class="flex justify-between gap-4">
            <dt class="font-medium text-slate-500">Program</dt>
            <dd class="text-right text-slate-900">{{ viewingUser.program.name }}</dd>
          </div>
          <div v-if="viewingUser.program?.department" class="flex justify-between gap-4">
            <dt class="font-medium text-slate-500">Department</dt>
            <dd class="text-right text-slate-900">{{ viewingUser.program.department.name }}</dd>
          </div>
          <div v-if="viewingUser.departments_coordinated?.length" class="flex justify-between gap-4">
            <dt class="font-medium text-slate-500">Department</dt>
            <dd class="text-right text-slate-900">{{ viewingUser.departments_coordinated[0].name }}</dd>
          </div>
          <div class="flex justify-between gap-4">
            <dt class="font-medium text-slate-500">Must change password</dt>
            <dd class="text-right text-slate-900">{{ viewingUser.must_change_password ? 'Yes' : 'No' }}</dd>
          </div>
        </dl>

        <p class="mt-6 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Passwords are hashed and cannot be viewed by anyone, including admins. Use "Issue Temporary Password" on
          System Settings if this user needs to sign in again.
        </p>

        <div class="mt-6 flex justify-end">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeView">
            Close
          </button>
        </div>
      </section>
    </div>
  </section>
</template>
