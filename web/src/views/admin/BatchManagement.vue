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

const batches = ref([])
const programs = ref([])
const coordinators = ref([])
const loading = ref(true)
const error = ref('')
const showForm = ref(false)
const saving = ref(false)

const form = ref({
  program_id: '', coordinator_id: '', name: '', academic_year: '', semester: '',
  start_date: '', end_date: '', required_hours: 600, working_days_per_week: 5,
})

async function loadBatches() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/api/admin/batches')
    batches.value = data.data ?? data
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Failed to load batches.'
  } finally {
    loading.value = false
  }
}

async function loadOptions() {
  try {
    const [{ data: progData }, { data: userData }] = await Promise.all([
      api.get('/api/admin/programs'),
      api.get('/api/admin/users', { params: { role: 'coordinator' } }),
    ])
    programs.value = progData
    coordinators.value = userData.data ?? userData
  } catch {
    // Non-fatal — dropdowns just stay empty if this fails.
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

async function createBatch() {
  saving.value = true
  error.value = ''
  try {
    await api.get('/sanctum/csrf-cookie')
    await api.post('/api/admin/batches', form.value)
    showForm.value = false
    await loadBatches()
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Failed to create batch. Check the form for errors.'
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadBatches()
  loadOptions()
})
</script>

<template>
  <DashboardShell role-label="Admin" :nav-items="navItems">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-semibold text-slate-800">Batch Management</h2>
      <button class="rounded-md bg-slate-900 px-4 py-2 text-sm text-white hover:bg-slate-700" @click="showForm = !showForm">
        {{ showForm ? 'Cancel' : 'New Batch' }}
      </button>
    </div>

    <p v-if="error" class="mb-3 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ error }}</p>

    <div v-if="showForm" class="mb-6 grid grid-cols-2 gap-3 rounded-md border border-slate-200 bg-white p-4">
      <input v-model="form.name" placeholder="Batch name (e.g. BSIT Batch 2025-A)" required class="col-span-2 rounded border border-slate-300 px-3 py-2 text-sm" />

      <select v-model="form.program_id" required class="rounded border border-slate-300 px-3 py-2 text-sm">
        <option value="" disabled>Select program</option>
        <optgroup v-for="(progs, deptName) in programsByDepartment" :key="deptName" :label="deptName">
          <option v-for="p in progs" :key="p.id" :value="p.id" :title="p.name">{{ p.code }}</option>
        </optgroup>
      </select>

      <select v-model="form.coordinator_id" required class="rounded border border-slate-300 px-3 py-2 text-sm">
        <option value="" disabled>Select coordinator</option>
        <option v-for="c in coordinators" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>

      <input v-model="form.academic_year" placeholder="Academic year (e.g. 2025-2026)" required class="rounded border border-slate-300 px-3 py-2 text-sm" />
      <input v-model="form.semester" placeholder="Semester (e.g. 2nd Semester)" required class="rounded border border-slate-300 px-3 py-2 text-sm" />

      <label class="text-xs text-slate-500">Start date
        <input v-model="form.start_date" type="date" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" />
      </label>
      <label class="text-xs text-slate-500">End date
        <input v-model="form.end_date" type="date" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" />
      </label>

      <label class="text-xs text-slate-500">Required hours
        <input v-model.number="form.required_hours" type="number" min="1" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" />
      </label>
      <label class="text-xs text-slate-500">Working days / week
        <input v-model.number="form.working_days_per_week" type="number" min="1" max="7" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" />
      </label>

      <button :disabled="saving" type="button" class="col-span-2 rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50" @click="createBatch">
        {{ saving ? 'Creating…' : 'Create Batch' }}
      </button>
    </div>

    <p v-if="loading" class="text-sm text-slate-500">Loading batches…</p>

    <table v-else class="w-full text-left text-sm">
      <thead>
        <tr class="border-b border-slate-200 text-slate-500">
          <th class="py-2">Name</th>
          <th class="py-2">Program</th>
          <th class="py-2">Coordinator</th>
          <th class="py-2">A.Y. / Semester</th>
          <th class="py-2">Students</th>
          <th class="py-2">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="batch in batches" :key="batch.id" class="border-b border-slate-100">
          <td class="py-2">{{ batch.name }}</td>
          <td class="py-2">{{ batch.program?.name ?? '—' }}</td>
          <td class="py-2">{{ batch.coordinator?.name ?? '—' }}</td>
          <td class="py-2">{{ batch.academic_year }} / {{ batch.semester }}</td>
          <td class="py-2">{{ batch.batch_students_count }}</td>
          <td class="py-2">
            <span :class="batch.is_active ? 'text-green-600' : 'text-red-600'">
              {{ batch.is_active ? 'Active' : 'Inactive' }}
            </span>
          </td>
        </tr>
      </tbody>
    </table>
  </DashboardShell>
</template>
