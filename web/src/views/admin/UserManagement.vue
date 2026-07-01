<script setup>
import { ref, computed, onMounted } from 'vue'
import DashboardShell from '../../components/layout/DashboardShell.vue'
import api from '../../services/api.js'

const navItems = [
  { label: 'Dashboard', to: '/admin' },
  { label: 'User Management', to: '/admin/users' },
  { label: 'Departments', to: '/admin/departments' },
  { label: 'Batch Management', to: '/admin/batches' },
  { label: 'Companies', to: '/admin/companies' },
  { label: 'Student Info Sheet', to: '/admin/student-info-sheets' },
  { label: 'Annual SIPP Report', to: '/admin/sipp-report' },
  { label: 'System Settings', to: '/admin/settings' },
  { label: 'Audit Logs', to: '/admin/audit-logs' },
]

const users = ref([])
const programs = ref([])
const loading = ref(true)
const error = ref('')
const showForm = ref(false)
const saving = ref(false)

const form = ref({
  name: '', email: '', password: '', password_confirmation: '',
  role: 'student', program_id: '', is_active: true,
})

async function loadUsers() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/api/admin/users')
    users.value = data.data ?? data
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Failed to load users.'
  } finally {
    loading.value = false
  }
}

async function loadPrograms() {
  try {
    const { data } = await api.get('/api/admin/programs')
    programs.value = data
  } catch {
    // Non-fatal — the dropdown just stays empty if this fails.
  }
}

// Groups the flat /api/admin/programs list by department so the dropdown
// reads as CAST / CABM-B / CABM-H sections instead of one long flat list —
// matters once there are 7+ programs across 3 departments.
const programsByDepartment = computed(() => {
  const groups = {}
  for (const program of programs.value) {
    const deptName = program.department?.name ?? 'Other'
    if (!groups[deptName]) groups[deptName] = []
    groups[deptName].push(program)
  }
  return groups
})

async function createUser() {
  saving.value = true
  error.value = ''
  try {
    await api.get('/sanctum/csrf-cookie')
    await api.post('/api/admin/users', form.value)
    showForm.value = false
    form.value = { name: '', email: '', password: '', password_confirmation: '', role: 'student', program_id: '', is_active: true }
    await loadUsers()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Failed to create user. Check the form for errors.'
  } finally {
    saving.value = false
  }
}

async function toggleActive(user) {
  try {
    await api.get('/sanctum/csrf-cookie')
    const endpoint = user.is_active ? 'deactivate' : 'reactivate'
    await api.post(`/api/admin/users/${user.id}/${endpoint}`)
    await loadUsers()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Failed to update user status.'
  }
}

onMounted(() => {
  loadUsers()
  loadPrograms()
})
</script>

<template>
  <DashboardShell role-label="Admin" :nav-items="navItems">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-slate-800">User Management</h2>
      <button
        class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-700"
        @click="showForm = !showForm"
      >
        {{ showForm ? 'Cancel' : 'New User' }}
      </button>
    </div>

    <p v-if="error" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

    <div v-if="showForm" class="mb-6 grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-white p-4">
      <input v-model="form.name" placeholder="Full name" required class="rounded border border-slate-300 px-3 py-2 text-sm" />
      <input v-model="form.email" type="email" placeholder="Email" required class="rounded border border-slate-300 px-3 py-2 text-sm" />
      <input v-model="form.password" type="password" placeholder="Password" required class="rounded border border-slate-300 px-3 py-2 text-sm" />
      <input v-model="form.password_confirmation" type="password" placeholder="Confirm password" required class="rounded border border-slate-300 px-3 py-2 text-sm" />
      <select v-model="form.role" class="rounded border border-slate-300 px-3 py-2 text-sm">
        <option value="student">Student</option>
        <option value="supervisor">Supervisor</option>
        <option value="coordinator">Coordinator</option>
        <option value="admin">Admin</option>
      </select>
      <select v-model="form.program_id" class="rounded border border-slate-300 px-3 py-2 text-sm">
        <option value="">No program</option>
        <optgroup v-for="(progs, deptName) in programsByDepartment" :key="deptName" :label="deptName">
          <option v-for="p in progs" :key="p.id" :value="p.id" :title="p.name">{{ p.code }}</option>
        </optgroup>
      </select>
      <button :disabled="saving" type="button" class="col-span-2 rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="createUser">
        {{ saving ? 'Creating…' : 'Create User' }}
      </button>
    </div>

    <p v-if="loading" class="text-sm text-slate-500">Loading users…</p>

    <table v-else class="w-full text-left text-sm">
      <thead>
        <tr class="border-b border-slate-200 text-slate-500">
          <th class="py-2">Name</th>
          <th class="py-2">Email</th>
          <th class="py-2">Role</th>
          <th class="py-2">Program</th>
          <th class="py-2">Status</th>
          <th class="py-2"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="user in users" :key="user.id" class="border-b border-slate-100">
          <td class="py-2">{{ user.name }}</td>
          <td class="py-2">{{ user.email }}</td>
          <td class="py-2 capitalize">{{ user.role }}</td>
          <td class="py-2">{{ user.program?.name ?? '—' }}</td>
          <td class="py-2">
            <span :class="user.is_active ? 'text-green-600' : 'text-red-600'">
              {{ user.is_active ? 'Active' : 'Deactivated' }}
            </span>
          </td>
          <td class="py-2">
            <button class="text-xs text-blue-600 hover:underline" @click="toggleActive(user)">
              {{ user.is_active ? 'Deactivate' : 'Reactivate' }}
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </DashboardShell>
</template>
