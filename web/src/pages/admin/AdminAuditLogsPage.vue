<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
type LogRole = 'admin' | 'coordinator' | 'supervisor' | 'student' | 'auto'

const logs = [
  { timestamp: '2025-05-26 08:15:03', user: 'Test Admin', role: 'admin' as LogRole, action: 'User Created', details: 'Created new student account (Juan Dela Cruz, 2021-IT-001)', ip: '192.168.1.10' },
  { timestamp: '2025-05-26 07:50:41', user: 'System', role: 'auto' as LogRole, action: 'Weekly Compilation', details: 'Compiled 42 weekly journals for Week 8 across all departments', ip: '127.0.0.1' },
  { timestamp: '2025-05-25 21:00:12', user: 'System', role: 'auto' as LogRole, action: 'Email Reminder Batch', details: 'Sent daily journal reminder emails to 28 active interns', ip: '127.0.0.1' },
  { timestamp: '2025-05-25 16:22:37', user: 'Test Admin', role: 'admin' as LogRole, action: 'Department Updated', details: 'Updated program list for College of Accountancy, Business and Management - Business', ip: '192.168.1.10' },
  { timestamp: '2025-05-24 14:05:09', user: 'Test Admin', role: 'admin' as LogRole, action: 'Settings Changed', details: 'Updated daily email reminder time to 9:00 PM', ip: '192.168.1.10' },
  { timestamp: '2025-05-24 10:47:55', user: 'Test Admin', role: 'admin' as LogRole, action: 'User Deactivated', details: 'Deactivated account for Noel Gerona (2021-BA-024)', ip: '192.168.1.10' },
  { timestamp: '2025-05-23 13:12:20', user: 'Test Admin', role: 'admin' as LogRole, action: 'Company Added', details: 'Added partner company "Globe Telecom" (Cebu City, Cebu)', ip: '192.168.1.10' },
  { timestamp: '2025-05-23 09:30:44', user: 'Prof. Alicia Montoya', role: 'coordinator' as LogRole, action: 'Report Generated', details: 'Generated Annual SIPP Report for CABM-B, AY 2024-2025 2nd Semester', ip: '10.0.0.24' },
  { timestamp: '2025-05-22 20:41:16', user: 'Engr. Ramon Villanueva', role: 'supervisor' as LogRole, action: 'Journal Approved', details: 'Approved Week 7 weekly journal for Juan Dela Cruz', ip: '10.0.0.57' },
  { timestamp: '2025-05-22 18:03:29', user: 'Juan Dela Cruz', role: 'student' as LogRole, action: 'Journal Submitted', details: 'Submitted daily journal entry for May 22, 2025', ip: '10.0.0.88' },
]

const actionOptions = [
  'User Created',
  'Weekly Compilation',
  'Email Reminder Batch',
  'Department Updated',
  'Settings Changed',
  'User Deactivated',
  'Company Added',
  'Report Generated',
  'Journal Approved',
  'Journal Submitted',
]

const roleLabel: Record<LogRole, string> = {
  admin: 'Admin',
  coordinator: 'Coordinator',
  supervisor: 'Supervisor',
  student: 'Student',
  auto: 'System',
}
const roleClass: Record<LogRole, string> = {
  admin: 'bg-red-50 text-red-700',
  coordinator: 'bg-purple-50 text-purple-700',
  supervisor: 'bg-amber-50 text-amber-700',
  student: 'bg-blue-50 text-blue-700',
  auto: 'bg-slate-100 text-slate-500',
}
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap gap-3">
      <input class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search logs..." />
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Actions</option>
        <option v-for="action in actionOptions" :key="action">{{ action }}</option>
      </select>
      <select class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option>All Roles</option>
        <option>Admin</option>
        <option>Coordinator</option>
        <option>Supervisor</option>
        <option>Student</option>
        <option>System</option>
      </select>
      <input type="date" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" value="2025-05-26" />
      <button type="button" class="ml-auto rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">
        Export Logs
      </button>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
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
          <tr v-for="log in logs" :key="`${log.timestamp}-${log.user}`">
            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ log.timestamp }}</td>
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ log.user }}</td>
            <td class="px-4 py-3">
              <span class="rounded-full px-3 py-1 text-xs font-bold" :class="roleClass[log.role]">{{ roleLabel[log.role] }}</span>
            </td>
            <td class="px-4 py-3 text-sm font-semibold text-slate-700">{{ log.action }}</td>
            <td class="max-w-sm px-4 py-3 text-sm text-slate-500">{{ log.details }}</td>
            <td class="px-4 py-3 font-mono text-xs text-slate-400">{{ log.ip }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
