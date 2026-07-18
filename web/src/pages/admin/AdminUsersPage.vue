<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { confirmAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
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
  if (!confirmAction(`Deactivate ${user.name}'s account? They won't be able to log in until reactivated.`)) return

  try {
    await api.patch(`/api/admin/users/${user.id}/deactivate`)
    await loadUsers()
    showToast(`${user.name}'s account deactivated.`)
  } catch {
    errorMessage.value = 'Unable to deactivate user.'
  }
}

const reactivateUser = async (user: User) => {
  if (!confirmAction(`Reactivate ${user.name}'s account?`)) return

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
    <div class="flex items-center justify-between gap-4">
      <h2 class="text-2xl font-bold text-slate-950">Users</h2>
      <button
        type="button"
        class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800"
        @click="openModal"
      >
        Create User
      </button>
    </div>

    <div class="mt-6 flex flex-wrap gap-3">
      <input v-model="search" class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search by name..." />
      <select v-model="roleFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Users</option>
        <option value="student">Student</option>
        <option value="coordinator">Coordinator</option>
        <option value="supervisor">Supervisor</option>
      </select>
      <select v-model="departmentFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Departments</option>
        <option v-for="department in departments" :key="department.id" :value="department.id">
          {{ department.name }}
        </option>
      </select>
    </div>

    <p v-if="isLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="mt-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ errorMessage }}
    </p>

    <div v-else class="mt-6 overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Email</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Role</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Program</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Department</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="users.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="7">No users found.</td>
          </tr>
          <tr v-for="user in users" :key="user.id">
            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ user.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ user.email }}</td>
            <td class="px-4 py-3 text-sm capitalize text-slate-700">{{ user.role }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ user.program?.name ?? 'No Program' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ user.departments_coordinated?.[0]?.name ?? user.program?.department?.name ?? 'No Department' }}</td>
            <td class="px-4 py-3 text-sm">
              <span
                class="rounded-full px-2 py-1 text-xs font-semibold"
                :class="user.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
              >
                {{ user.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3 text-sm">
              <div class="flex flex-wrap gap-2">
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
                  class="rounded-md border border-red-200 px-3 py-1.5 text-sm font-medium text-red-700 transition hover:bg-red-50"
                  @click="deactivateUser(user)"
                >
                  Deactivate
                </button>
                <button
                  v-else
                  type="button"
                  class="rounded-md border border-green-200 px-3 py-1.5 text-sm font-medium text-green-700 transition hover:bg-green-50"
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

    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Create Coordinator</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">
            Cancel
          </button>
        </div>

        <div class="mt-6 space-y-4">
          <div class="grid gap-4 md:grid-cols-3">
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="user-first-name">First Name</label>
              <input id="user-first-name" v-model="userForm.first_name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="user-middle-name">Middle Name (optional)</label>
              <input id="user-middle-name" v-model="userForm.middle_name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="user-last-name">Family Name</label>
              <input id="user-last-name" v-model="userForm.last_name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
            </div>
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="user-email">Email</label>
            <input id="user-email" v-model="userForm.email" type="email" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="user-password">Password</label>
            <input id="user-password" v-model="userForm.password" type="password" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="user-department">Department</label>
            <select id="user-department" v-model="userForm.department_id" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option :value="null">Select Department</option>
              <option v-for="department in departments" :key="department.id" :value="department.id">
                {{ department.name }}
              </option>
            </select>
          </div>
        </div>

        <p v-if="modalError" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalError }}</p>

        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400"
            :disabled="isSaving"
            @click="createUser"
          >
            {{ isSaving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </section>
    </div>

    <div v-if="viewingUser" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
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
