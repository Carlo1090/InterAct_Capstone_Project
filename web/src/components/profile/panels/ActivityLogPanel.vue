<script setup lang="ts">
import { onMounted, ref } from 'vue'
import api from '@/lib/axios'
import type { ProfileActivityLog } from '@/types/api'

const activityLogs = ref<ProfileActivityLog[]>([])
const isLoading = ref(false)
const errorMessage = ref('')

const loadActivity = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get('/api/profile/activity')
    activityLogs.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load your activity log.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadActivity)
</script>

<template>
  <div>
    <p v-if="isLoading" class="px-4 py-6 text-center text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="px-4 py-6 text-center text-sm text-red-700">{{ errorMessage }}</p>
    <div v-else class="overflow-x-auto">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-3 py-2 text-left text-[10px] font-bold uppercase tracking-wide text-slate-500">Timestamp</th>
            <th class="px-3 py-2 text-left text-[10px] font-bold uppercase tracking-wide text-slate-500">Action</th>
            <th class="px-3 py-2 text-left text-[10px] font-bold uppercase tracking-wide text-slate-500">Details</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="activityLogs.length === 0">
            <td colspan="3" class="px-3 py-6 text-center text-sm text-slate-500">No activity recorded yet.</td>
          </tr>
          <tr v-for="log in activityLogs" :key="log.id">
            <td class="whitespace-nowrap px-3 py-2 font-mono text-[11px] text-slate-500">{{ log.logged_at }}</td>
            <td class="whitespace-nowrap px-3 py-2 text-xs font-semibold text-slate-700">{{ log.action }}</td>
            <td class="max-w-[10rem] px-3 py-2 text-xs text-slate-500">{{ log.description }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
