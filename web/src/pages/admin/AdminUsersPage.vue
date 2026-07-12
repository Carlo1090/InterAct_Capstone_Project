<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { Department, PaginatedResponse, User } from '@/types/api'

type UserPayload = {
  name: string
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
const roleFilter = ref('coordinator')
const departmentFilter = ref('')

let searchDebounce: ReturnType<typeof setTimeout> | undefined

const emptyForm = (): UserPayload => ({
  name: '',
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

const deleteUser = async (user: User) => {
  if (!window.confirm(`Delete ${user.name}? This deactivates their account.`)) return

  try {
    await api.patch(`/api/admin/users/${user.id}/deactivate`)
    await loadUsers()
  } catch {
    errorMessage.value = 'Unable to delete user.'
  }
}

onMounted(() => {
  loadUsers()
  loadDepartments()
})
</script>

<template>
  <section>
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
        <option value="student">Student</option>
        <option value="coordinator">Coordinator</option>
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
            <td class="px-4 py-3 text-sm text-slate-700">{{ user.program?.department?.name ?? 'No Department' }}</td>
            <td class="px-4 py-3 text-sm">
              <span
                class="rounded-full px-2 py-1 text-xs font-semibold"
                :class="user.is_active ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'"
              >
                {{ user.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3 text-sm">
              <button
                v-if="user.is_active"
                type="button"
                class="rounded-md border border-red-200 px-3 py-1.5 text-sm font-medium text-red-700 transition hover:bg-red-50"
                @click="deleteUser(user)"
              >
                Delete
              </button>
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
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="user-name">Full Name</label>
            <input id="user-name" v-model="userForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
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
  </section>
</template>
