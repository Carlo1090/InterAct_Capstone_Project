<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import api from '@/lib/axios'
import type { Department, PaginatedResponse, Program, User } from '@/types/api'

type UserPayload = {
  name: string
  email: string
  password: string
  role: User['role']
  program_id: number | null
}

const users = ref<User[]>([])
const programs = ref<Program[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const isModalOpen = ref(false)
const errorMessage = ref('')
const modalError = ref('')

const search = ref('')
const roleFilter = ref('')
const departmentFilter = ref('')

let searchDebounce: ReturnType<typeof setTimeout> | undefined

const userForm = ref<UserPayload>({
  name: '',
  email: '',
  password: '',
  role: 'student',
  program_id: null,
})

const groupedPrograms = computed(() => {
  return programs.value.reduce<Record<string, Program[]>>((groups, program) => {
    const departmentName = program.department?.name ?? 'No Department'
    groups[departmentName] = groups[departmentName] ?? []
    groups[departmentName].push(program)
    return groups
  }, {})
})

const resetForm = () => {
  userForm.value = {
    name: '',
    email: '',
    password: '',
    role: 'student',
    program_id: null,
  }
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

const loadPrograms = async () => {
  try {
    const response = await api.get<Program[]>('/api/admin/programs')
    programs.value = response.data
  } catch {
    modalError.value = 'Unable to load programs.'
  }
}

const loadDepartments = async () => {
  try {
    const response = await api.get<Department[]>('/api/admin/departments')
    departments.value = response.data
  } catch {
    // Filter dropdown just stays empty; not critical to the page loading.
  }
}

watch(search, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(loadUsers, 300)
})
watch([roleFilter, departmentFilter], loadUsers)

const openModal = async () => {
  resetForm()
  isModalOpen.value = true
  await loadPrograms()
}

const closeModal = () => {
  isModalOpen.value = false
  resetForm()
}

const createUser = async () => {
  isSaving.value = true
  modalError.value = ''

  try {
    await api.post('/api/admin/users', userForm.value)
    closeModal()
    await loadUsers()
  } catch {
    modalError.value = 'Unable to create user. Please check the fields and try again.'
  } finally {
    isSaving.value = false
  }
}

const deactivateUser = async (user: User) => {
  try {
    await api.patch(`/api/admin/users/${user.id}/deactivate`)
    await loadUsers()
  } catch {
    errorMessage.value = 'Unable to deactivate user.'
  }
}

const activateUser = async (user: User) => {
  try {
    await api.patch(`/api/admin/users/${user.id}/activate`)
    await loadUsers()
  } catch {
    errorMessage.value = 'Unable to activate user.'
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
        <option value="">All Roles</option>
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

    <div v-else class="mt-6 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
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
                @click="deactivateUser(user)"
              >
                Deactivate
              </button>
              <button
                v-else
                type="button"
                class="rounded-md border border-green-200 px-3 py-1.5 text-sm font-medium text-green-700 transition hover:bg-green-50"
                @click="activateUser(user)"
              >
                Activate
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Create User</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">
            Cancel
          </button>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
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
            <label class="mb-2 block text-sm font-medium text-slate-700" for="user-role">Role</label>
            <select id="user-role" v-model="userForm.role" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option value="student">Student</option>
              <option value="supervisor">Supervisor</option>
              <option value="coordinator">Coordinator</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="user-program">Program</label>
            <select id="user-program" v-model="userForm.program_id" class="w-full rounded-md border border-slate-300 px-3 py-2">
              <option :value="null">No Program</option>
              <optgroup v-for="(departmentPrograms, departmentName) in groupedPrograms" :key="departmentName" :label="departmentName">
                <option v-for="program in departmentPrograms" :key="program.id" :value="program.id">
                  {{ program.name }}
                </option>
              </optgroup>
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
