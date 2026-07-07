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
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Please complete all required fields. This form is part of your SIPP compliance documentation.
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>

    <form v-else class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200" @submit.prevent>
      <div class="rounded-lg bg-slate-50 p-5">
        <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700">I. Personal Information</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">
            Last Name
            <input v-model="personalInfo.last_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            First Name
            <input v-model="personalInfo.first_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Middle Name
            <input v-model="personalInfo.middle_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Student ID Number
            <input v-model="personalInfo.student_id_number" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Date of Birth
            <input v-model="personalInfo.date_of_birth" type="date" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Sex
            <select v-model="personalInfo.sex" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option value="male">Male</option>
              <option value="female">Female</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-slate-700 md:col-span-2">
            Home Address
            <input v-model="personalInfo.home_address" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Contact Number
            <input v-model="personalInfo.contact_number" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Email Address
            <input v-model="personalInfo.email" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
        </div>
      </div>

      <div class="mt-5 rounded-lg bg-slate-50 p-5">
        <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700">II. Academic Information</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">
            Program / Course
            <input v-model="academicInfo.program_course" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Year Level
            <input v-model="academicInfo.year_level" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Department
            <input :value="academicInfo.department" readonly class="mt-2 w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            OJT Coordinator
            <input :value="academicInfo.internship_coordinator" readonly class="mt-2 w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm" />
          </label>
        </div>
      </div>

      <div class="mt-5 rounded-lg bg-slate-50 p-5">
        <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700">III. Internship Assignment</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">
            Host Company
            <input v-model="ojtInfo.host_company" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Company Supervisor
            <input v-model="ojtInfo.supervisor_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Start Date
            <input v-model="ojtInfo.ojt_start_date" type="date" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            End Date
            <input v-model="ojtInfo.ojt_end_date" type="date" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
        </div>
      </div>

      <p v-if="errorMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>
      <p v-if="statusMessage" class="mt-4 rounded-md bg-green-50 px-3 py-2 text-sm text-green-700">{{ statusMessage }}</p>
      <p v-if="submissionStatus" class="mt-2 text-xs uppercase tracking-wide text-slate-400">Status: {{ submissionStatus }}</p>

      <div class="mt-6 flex justify-end gap-3">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50"
          :disabled="isSaving"
          @click="save('draft')"
        >
          {{ isSaving ? 'Saving...' : 'Save Draft' }}
        </button>
        <button
          type="button"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="isSaving"
          @click="save('submitted')"
        >
          {{ isSaving ? 'Saving...' : 'Submit' }}
        </button>
      </div>
    </form>
  </section>
</template>
