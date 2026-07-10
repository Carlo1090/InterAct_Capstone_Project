<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import api from '@/lib/axios'
import type { PaginatedResponse, SystemLogRecord } from '@/types/api'

type LogRole = 'coordinator' | 'supervisor' | 'student'

const logs = ref<SystemLogRecord[]>([])
const actionOptions = ref<string[]>([])
const isLoading = ref(true)
const errorMessage = ref('')
const isExporting = ref(false)

const search = ref('')
const actionFilter = ref('')
const roleFilter = ref('')
const dateFilter = ref('')

let searchDebounce: ReturnType<typeof setTimeout> | undefined

const roleLabel: Record<LogRole, string> = {
  coordinator: 'Coordinator',
  supervisor: 'Supervisor',
  student: 'Student',
}
const roleClass: Record<LogRole, string> = {
  coordinator: 'bg-purple-50 text-purple-700',
  supervisor: 'bg-amber-50 text-amber-700',
  student: 'bg-blue-50 text-blue-700',
}

const filterParams = () => ({
  search: search.value || undefined,
  action: actionFilter.value || undefined,
  role: roleFilter.value || undefined,
  date: dateFilter.value || undefined,
})

const loadLogs = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<PaginatedResponse<SystemLogRecord>>('/api/admin/audit-logs', {
      params: filterParams(),
    })
    logs.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load audit logs.'
  } finally {
    isLoading.value = false
  }
}

const loadActions = async () => {
  try {
    const response = await api.get<string[]>('/api/admin/audit-logs/actions')
    actionOptions.value = response.data
  } catch {
    // Action dropdown just stays empty; not critical to the page loading.
  }
}

const exportLogs = async () => {
  isExporting.value = true

  try {
    const response = await api.get('/api/admin/audit-logs/export', {
      params: filterParams(),
      responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = 'audit-logs.csv'
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch {
    errorMessage.value = 'Unable to export audit logs.'
  } finally {
    isExporting.value = false
  }
}

watch(search, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(loadLogs, 300)
})
watch([actionFilter, roleFilter, dateFilter], loadLogs)

onMounted(() => {
  loadLogs()
  loadActions()
})
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap gap-3">
      <input v-model="search" class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search logs..." />
      <select v-model="actionFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Actions</option>
        <option v-for="action in actionOptions" :key="action" :value="action">{{ action }}</option>
      </select>
      <select v-model="roleFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Roles</option>
        <option value="coordinator">Coordinator</option>
        <option value="supervisor">Supervisor</option>
        <option value="student">Student</option>
      </select>
      <input v-model="dateFilter" type="date" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" />
      <button
        type="button"
        class="ml-auto rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50"
        :disabled="isExporting"
        @click="exportLogs"
      >
        {{ isExporting ? 'Exporting...' : 'Export Logs' }}
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Timestamp</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">User</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Role</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Details</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">IP Address</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="logs.length === 0">
            <td colspan="6" class="px-4 py-6 text-center text-sm text-slate-500">No audit log entries found.</td>
          </tr>
          <tr v-for="log in logs" :key="log.id">
            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ log.logged_at }}</td>
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ log.user.name }}</td>
            <td class="px-4 py-3">
              <span
                v-if="log.user.role === 'admin'"
                class="rounded-full bg-red-50 px-3 py-1 text-xs font-bold text-red-700"
              >
                Admin
              </span>
              <span v-else class="rounded-full px-3 py-1 text-xs font-bold" :class="roleClass[log.user.role as LogRole]">
                {{ roleLabel[log.user.role as LogRole] }}
              </span>
            </td>
            <td class="px-4 py-3 text-sm font-semibold text-slate-700">{{ log.action }}</td>
            <td class="max-w-sm px-4 py-3 text-sm text-slate-500">{{ log.description }}</td>
            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ log.ip_address }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
