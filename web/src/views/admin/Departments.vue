<script setup>
import { ref, onMounted } from 'vue'
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

const departments = ref([])
const loading = ref(true)
const error = ref('')
const showForm = ref(false)
const saving = ref(false)
const form = ref({ name: '', code: '' })

async function loadDepartments() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/api/admin/departments')
    departments.value = data
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Failed to load departments.'
  } finally {
    loading.value = false
  }
}

async function createDepartment() {
  saving.value = true
  error.value = ''
  try {
    await api.get('/sanctum/csrf-cookie')
    await api.post('/api/admin/departments', form.value)
    showForm.value = false
    form.value = { name: '', code: '' }
    await loadDepartments()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Failed to create department. Check the form for errors.'
  } finally {
    saving.value = false
  }
}

onMounted(loadDepartments)
</script>

<template>
  <DashboardShell role-label="Admin" :nav-items="navItems">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-slate-800">Departments</h2>
      <button class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-700" @click="showForm = !showForm">
        {{ showForm ? 'Cancel' : 'New Department' }}
      </button>
    </div>

    <p v-if="error" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

    <div v-if="showForm" class="mb-6 grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-white p-4">
      <input v-model="form.name" placeholder="Department name" required class="rounded border border-slate-300 px-3 py-2 text-sm" />
      <input v-model="form.code" placeholder="Code (e.g. CAST)" required class="rounded border border-slate-300 px-3 py-2 text-sm" />
      <button :disabled="saving" type="button" class="col-span-2 rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="createDepartment">
        {{ saving ? 'Creating…' : 'Create Department' }}
      </button>
    </div>

    <p v-if="loading" class="text-sm text-slate-500">Loading departments…</p>

    <table v-else class="w-full text-left text-sm">
      <thead>
        <tr class="border-b border-slate-200 text-slate-500">
          <th class="py-2">Name</th>
          <th class="py-2">Code</th>
          <th class="py-2">Programs</th>
          <th class="py-2">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="dept in departments" :key="dept.id" class="border-b border-slate-100">
          <td class="py-2">{{ dept.name }}</td>
          <td class="py-2">{{ dept.code }}</td>
          <td class="py-2">{{ dept.programs_count }}</td>
          <td class="py-2">
            <span :class="dept.is_active ? 'text-green-600' : 'text-red-600'">
              {{ dept.is_active ? 'Active' : 'Inactive' }}
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  </DashboardShell>
</template>
