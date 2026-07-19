<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import api from '@/lib/axios'
import { categorizeError } from '@/lib/apiError'
import { confirmAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import { useAuthStore } from '@/stores/auth'
import type { InfoSheet, InfoSheetStatus, StudentCompanyOption } from '@/types/api'

const auth = useAuthStore()

const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
// Keyed by the dotted field path (e.g. 'personal_info.first_name'); drives the
// red ring + "field required" hint next to each offending input.
const fieldErrors = ref<Record<string, boolean>>({})
const hasFieldError = (key: string): boolean => fieldErrors.value[key] === true
const submissionStatus = ref<InfoSheetStatus | null>(null)
const rejectionReason = ref<string | null>(null)
const companies = ref<StudentCompanyOption[]>([])

const personalInfo = reactive({
  last_name: '',
  first_name: '',
  middle_name: '',
  contact_number: '',
  parent_guardian_name: '',
  parent_guardian_contact: '',
})

const academicInfo = reactive({
  program_course: '',
  year_level: '',
  department: '',
  internship_coordinator: '',
})

const ojtInfo = reactive({
  company_id: null as number | null,
  host_company: '',
  company_address: '',
  company_signatory_moa: '',
  office_designation: '',
  supervisor_name: '',
  supervisor_contact: '',
  intern_duty_schedule: '',
  area_assigned: '',
  ojt_start_date: '',
  ojt_end_date: '',
})

// --- Status-driven UI -------------------------------------------------------
const isApproved = computed(() => submissionStatus.value === 'approved')
const isRejected = computed(() => submissionStatus.value === 'rejected')
const isSubmitted = computed(() => submissionStatus.value === 'submitted')
// Program, Year, and the assigned Company are locked once enrolled — the
// coordinator's Accept step used them to place the student; everything
// else on the sheet stays editable for profile upkeep.
const systemLocked = computed(() => isApproved.value)
const submitLabel = computed(() => (isRejected.value ? 'Resubmit' : 'Submit'))

const onCompanyChange = () => {
  const picked = companies.value.find((company) => company.id === ojtInfo.company_id)
  ojtInfo.host_company = picked?.name ?? ''
}

// A saved sheet (draft or beyond) can be downloaded as the official MDC PDF.
const canDownload = computed(() => submissionStatus.value !== null)
const downloadPdf = () => {
  window.open('/api/student/info-sheet/pdf', '_blank')
}

const loadInfoSheet = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [{ data }, companyResponse] = await Promise.all([
      api.get<InfoSheet>('/api/student/info-sheet'),
      api.get<StudentCompanyOption[]>('/api/student/companies'),
    ])
    companies.value = companyResponse.data
    submissionStatus.value = data.submission_status
    rejectionReason.value = data.rejection_reason ?? null
    Object.assign(personalInfo, data.personal_info ?? {})
    Object.assign(academicInfo, data.academic_info ?? {})
    Object.assign(ojtInfo, data.ojt_info ?? {})
    // If a company_id was saved, keep the dropdown selection; otherwise try to
    // match the stored name back to a known company.
    if (!ojtInfo.company_id && ojtInfo.host_company) {
      ojtInfo.company_id = companies.value.find((company) => company.name === ojtInfo.host_company)?.id ?? null
    }
  } catch {
    errorMessage.value = 'Unable to load your info sheet.'
  } finally {
    isLoading.value = false
  }
}

// The fields the student must fill before a submission (a draft can be saved
// with anything). Kept in form order so the first missing one is the one we
// scroll to.
const REQUIRED_FIELDS: { key: string; get: () => string }[] = [
  { key: 'personal_info.last_name', get: () => personalInfo.last_name },
  { key: 'personal_info.first_name', get: () => personalInfo.first_name },
  { key: 'academic_info.year_level', get: () => academicInfo.year_level },
  { key: 'ojt_info.company_id', get: () => (ojtInfo.company_id ? String(ojtInfo.company_id) : '') },
]

const scrollToFirstError = () => {
  nextTick(() => {
    const el = document.querySelector<HTMLElement>('[data-invalid="true"]')
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    el?.focus()
  })
}

const save = async (status: 'draft' | 'submitted') => {
  errorMessage.value = ''
  fieldErrors.value = {}

  if (status === 'submitted') {
    // Client-side required-field check with a generic, field-directing message
    // (no raw "personal info.first name" server strings shown to the student).
    const missing: Record<string, boolean> = {}
    for (const field of REQUIRED_FIELDS) {
      if (!field.get()?.trim()) missing[field.key] = true
    }
    if (Object.keys(missing).length > 0) {
      fieldErrors.value = missing
      errorMessage.value = 'Please complete the required fields highlighted below.'
      scrollToFirstError()
      return
    }

    if (!isApproved.value) {
      const confirmed = await confirmAction(
        'Submit your Information Sheet for coordinator review? You can still edit it until they act on it.',
      )
      if (!confirmed) return
    }
  }

  isSaving.value = true

  try {
    const { data } = await api.post<InfoSheet>('/api/student/info-sheet', {
      status,
      personal_info: personalInfo,
      academic_info: academicInfo,
      ojt_info: ojtInfo,
    })
    submissionStatus.value = data.submission_status
    rejectionReason.value = data.rejection_reason ?? null
    showToast(status === 'submitted' ? 'Information Sheet submitted for review.' : 'Draft saved.')
    // Submitting clears the gate check on the next full load; refresh the user
    // so nav/guard state stays in sync (still gated until approved).
    await auth.fetchUser().catch(() => {})
  } catch (error) {
    const categorized = categorizeError(error, 'Unable to save. Please check the fields and try again.')
    if (categorized.kind === 'validation' && categorized.fieldErrors) {
      fieldErrors.value = Object.fromEntries(Object.keys(categorized.fieldErrors).map((key) => [key, true]))
      errorMessage.value = 'Please complete the required fields highlighted below.'
      scrollToFirstError()
    } else {
      errorMessage.value = categorized.message
    }
  } finally {
    isSaving.value = false
  }
}

onMounted(loadInfoSheet)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />

    <!-- Status banners -->
    <div v-if="isApproved" class="rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
      Your Information Sheet has been <strong>approved</strong>. Your Program, Year, and assigned Company are locked, but you can still update the rest of your details below.
    </div>
    <div v-else-if="isRejected" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
      <p class="font-semibold">Your Information Sheet was returned for changes.</p>
      <p v-if="rejectionReason" class="mt-1">Reason: {{ rejectionReason }}</p>
      <p class="mt-1">Please update the details below and resubmit.</p>
    </div>
    <div v-else-if="isSubmitted" class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      Submitted — awaiting your coordinator's review. You may still edit and resubmit until they act on it.
    </div>
    <div v-else class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Complete this Student Information Sheet and submit it. Your coordinator reviews it and, once accepted, you'll be enrolled and gain full access.
    </div>

    <div v-if="canDownload" class="flex justify-end">
      <button
        type="button"
        class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
        @click="downloadPdf"
      >
        Download PDF
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>

    <form v-else class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200" @submit.prevent>
      <!-- STUDENT TRAINEE INFORMATION -->
      <div class="rounded-lg bg-slate-50 p-5">
        <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700">Student Trainee Information</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">
            Family Name <span class="text-red-500">*</span>
            <input
              v-model="personalInfo.last_name"
              :data-invalid="hasFieldError('personal_info.last_name')"
              class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100"
              :class="hasFieldError('personal_info.last_name') && 'border-red-400 ring-1 ring-red-300'"
            />
            <span v-if="hasFieldError('personal_info.last_name')" class="mt-1 block text-xs font-normal text-red-600">This field is required.</span>
          </label>
          <label class="block text-sm font-medium text-slate-700">
            First Name <span class="text-red-500">*</span>
            <input
              v-model="personalInfo.first_name"
              :data-invalid="hasFieldError('personal_info.first_name')"
              class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100"
              :class="hasFieldError('personal_info.first_name') && 'border-red-400 ring-1 ring-red-300'"
            />
            <span v-if="hasFieldError('personal_info.first_name')" class="mt-1 block text-xs font-normal text-red-600">This field is required.</span>
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Middle Name
            <input v-model="personalInfo.middle_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Contact No.
            <input v-model="personalInfo.contact_number" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <div class="grid grid-cols-2 gap-2 md:col-span-2">
            <label class="block text-sm font-medium text-slate-700">
              Program
              <input :value="academicInfo.program_course" readonly class="mt-2 w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm" />
            </label>
            <label class="block text-sm font-medium text-slate-700">
              Year <span class="text-red-500">*</span>
              <select
                v-model="academicInfo.year_level"
                :disabled="systemLocked"
                :data-invalid="hasFieldError('academic_info.year_level')"
                class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
                :class="hasFieldError('academic_info.year_level') && 'border-red-400 ring-1 ring-red-300'"
              >
                <option value="">Select Year</option>
                <option value="1st-year">1st-year</option>
                <option value="2nd-year">2nd-year</option>
                <option value="3rd-year">3rd-year</option>
                <option value="4th-year">4th-year</option>
              </select>
            </label>
          </div>
          <label class="block text-sm font-medium text-slate-700">
            Parent's / Guardian's Name
            <input v-model="personalInfo.parent_guardian_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Parent's / Guardian's Contact No.
            <input v-model="personalInfo.parent_guardian_contact" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700 md:col-span-2">
            Internship Coordinator
            <input :value="academicInfo.internship_coordinator" readonly class="mt-2 w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm" />
          </label>
        </div>
      </div>

      <!-- INTERNSHIP COMPANY INFORMATION -->
      <div class="mt-5 rounded-lg bg-slate-50 p-5">
        <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700">Internship Company Information</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700 md:col-span-2">
            Name of Company <span class="text-red-500">*</span>
            <select
              v-model.number="ojtInfo.company_id"
              :disabled="systemLocked"
              :data-invalid="hasFieldError('ojt_info.company_id')"
              class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
              :class="hasFieldError('ojt_info.company_id') && 'border-red-400 ring-1 ring-red-300'"
              @change="onCompanyChange"
            >
              <option :value="null">Select Company</option>
              <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
            </select>
            <span v-if="hasFieldError('ojt_info.company_id')" class="mt-1 block text-xs font-normal text-red-600">Please select a company.</span>
          </label>
          <label class="block text-sm font-medium text-slate-700 md:col-span-2">
            Company Address
            <input v-model="ojtInfo.company_address" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Complete Name of Official Company Signatory (for MOA)
            <input v-model="ojtInfo.company_signatory_moa" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Office Designation / Position
            <input v-model="ojtInfo.office_designation" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Name of Supervisor / Office Head
            <input v-model="ojtInfo.supervisor_name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Contact Number
            <input v-model="ojtInfo.supervisor_contact" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Intern's Duty Schedule
            <input v-model="ojtInfo.intern_duty_schedule" placeholder="e.g. Mon–Fri, 8:00 AM – 5:00 PM" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Area Assigned
            <input v-model="ojtInfo.area_assigned" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Start of Internship Duty
            <input v-model="ojtInfo.ojt_start_date" type="date" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Estimated Date to Finish Internship
            <input v-model="ojtInfo.ojt_end_date" type="date" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100" />
          </label>
        </div>
      </div>

      <p v-if="errorMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ errorMessage }}</p>

      <div v-if="isApproved" class="mt-6 flex justify-end">
        <button
          type="button"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
          :disabled="isSaving"
          @click="save('submitted')"
        >
          {{ isSaving ? 'Saving...' : 'Save Changes' }}
        </button>
      </div>
      <div v-else class="mt-6 flex justify-end gap-3">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 disabled:grayscale disabled:cursor-not-allowed"
          :disabled="isSaving"
          @click="save('draft')"
        >
          {{ isSaving ? 'Saving...' : 'Save Draft' }}
        </button>
        <button
          type="button"
          class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
          :disabled="isSaving"
          @click="save('submitted')"
        >
          {{ isSaving ? 'Saving...' : submitLabel }}
        </button>
      </div>
    </form>
  </section>
</template>
