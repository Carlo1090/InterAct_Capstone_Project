<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { InfoSheet } from '@/types/api'

const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const statusMessage = ref('')
const submissionStatus = ref<InfoSheet['submission_status']>(null)
const departmentOptions = [
  'College of Arts, Sciences, and Technology',
  'College of Accountancy, Business and Management - Business',
  'College of Accountancy, Business and Management - Hospitality and Tourism',
]

const personalInfo = reactive({
  last_name: '',
  first_name: '',
  middle_name: '',
  parent_guardian_name: '',
  date_of_birth: '',
  sex: '',
  home_address: '',
  contact_number: '',
  email: '',
  student_id_number: '',
})

const academicInfo = reactive({
  program_course: '',
  year_level: '',
  department: '',
  internship_coordinator: '',
  coordinator_contact_no: '',
})

const ojtInfo = reactive({
  host_company: '',
  company_address: '',
  supervisor_name: '',
  supervisor_contact: '',
  ojt_start_date: '',
  ojt_end_date: '',
})

const loadInfoSheet = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<InfoSheet>('/api/student/info-sheet')
    submissionStatus.value = data.submission_status
    Object.assign(personalInfo, data.personal_info ?? {})
    Object.assign(academicInfo, data.academic_info ?? {})
    Object.assign(ojtInfo, data.ojt_info ?? {})
  } catch {
    errorMessage.value = 'Unable to load your info sheet.'
  } finally {
    isLoading.value = false
  }
}

const save = async (status: 'draft' | 'submitted') => {
  isSaving.value = true
  errorMessage.value = ''
  statusMessage.value = ''

  try {
    const { data } = await api.post<InfoSheet>('/api/student/info-sheet', {
      status,
      personal_info: personalInfo,
      academic_info: academicInfo,
      ojt_info: ojtInfo,
    })
    submissionStatus.value = data.submission_status
    statusMessage.value = status === 'submitted' ? 'Info sheet submitted.' : 'Draft saved.'
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    errorMessage.value = data?.message ?? 'Unable to save. Please check the fields and try again.'
  } finally {
    isSaving.value = false
  }
}

onMounted(loadInfoSheet)
</script>

<template>
  <section class="space-y-5">
    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>

    <label v-if="!isLoading" class="mx-auto block max-w-[1040px] rounded-lg bg-white p-5 text-sm font-medium text-slate-700 shadow-sm ring-1 ring-slate-200">
      Departments
      <select v-model="academicInfo.department" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        <option value="">Select department</option>
        <option v-for="department in departmentOptions" :key="department" :value="department">
          {{ department }}
        </option>
      </select>
    </label>

    <form v-if="!isLoading" class="mx-auto max-w-[1040px] rounded-lg bg-white p-8 shadow-sm ring-1 ring-slate-200" @submit.prevent>
      <header class="mb-5 text-center">
        <h1 class="text-2xl font-bold uppercase tracking-wide text-slate-950">Student Internship Program</h1>
        <p class="mt-1 text-lg text-slate-700">Student Information Sheet</p>
      </header>

      <div class="mb-4 bg-blue-900 py-2 text-center text-sm font-bold uppercase tracking-wide text-white">
        Student Trainee Information
      </div>

      <div class="rounded-lg bg-slate-50 p-6">
        <div class="grid gap-5 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">
            Family Name
            <input v-model="personalInfo.last_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Program &amp; Year
            <input v-model="academicInfo.year_level" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            First Name
            <input v-model="personalInfo.first_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Contact No.
            <input v-model="personalInfo.contact_number" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Middle Name
            <input v-model="personalInfo.middle_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <div class="hidden md:block"></div>
          <label class="block text-sm font-medium text-slate-700">
            Parent's/Guardian's Name
            <input v-model="personalInfo.parent_guardian_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Contact No.
            <input v-model="academicInfo.coordinator_contact_no" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Internship Coordinator
            <input :value="academicInfo.internship_coordinator" readonly class="mt-2 w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm" />
          </label>
        </div>
      </div>

      <p v-if="errorMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
      <p v-if="statusMessage" class="mt-4 rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>

    </form>

    <div v-if="!isLoading" class="mx-auto flex max-w-[1040px] justify-end gap-3">
        <button
          type="button"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="isSaving"
          @click="save('submitted')"
        >
          {{ isSaving ? 'Saving...' : 'Submit' }}
        </button>
      </div>
  </section>
</template>
