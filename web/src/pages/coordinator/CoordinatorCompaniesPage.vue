<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { confirmAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import type { CoordinatorCompany, EnrollmentOptionSupervisor } from '@/types/api'

const companies = ref<CoordinatorCompany[]>([])
const search = ref('')
const isLoading = ref(true)
const errorMessage = ref('')

const supervisorPool = ref<EnrollmentOptionSupervisor[]>([])

const isModalOpen = ref(false)
const isSaving = ref(false)
const modalErrors = ref<Record<string, string[]>>({})
const modalMessage = ref('')
const editingId = ref<number | null>(null)

const blankForm = () => ({
  name: '',
  address: '',
  location: '',
  industry: '',
  head_name: '',
  head_contact_number: '',
  head_email: '',
  department_head: '',
  contact_number: '',
  description: '',
  is_active: true,
})

const form = reactive(blankForm())
// The company's is_active value as loaded, so saveCompany() can tell a
// true->false deactivation apart from a reactivation or no change.
const originalIsActive = ref(true)

// Supervisors panel (edit mode only).
const activeCompany = ref<CoordinatorCompany | null>(null)
const attachForm = reactive({ user_id: null as number | null, position: '' })
const createSupForm = reactive({ name: '', email: '', password: '', position: '' })
const supErrors = ref<Record<string, string[]>>({})

// Representatives are purely informational (no login) — a separate concept
// from the OJT Supervisor Login below, which drives weekly-log review.
const repForm = reactive({ name: '', position: '' })
const repErrors = ref<Record<string, string[]>>({})
const representatives = computed(() => (activeCompany.value?.supervisors ?? []).filter((sup) => !sup.is_login))
const loginSupervisor = computed(() => (activeCompany.value?.supervisors ?? []).find((sup) => sup.is_login) ?? null)

const loadCompanies = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const params: Record<string, string> = {}
    if (search.value) params.search = search.value
    const { data } = await api.get<CoordinatorCompany[]>('/api/coordinator/companies', { params })
    companies.value = data
  } catch {
    errorMessage.value = 'Unable to load your partner companies.'
  } finally {
    isLoading.value = false
  }
}

const loadSupervisorPool = async () => {
  try {
    const { data } = await api.get<{ supervisors: EnrollmentOptionSupervisor[] }>('/api/coordinator/enrollment-options')
    supervisorPool.value = data.supervisors
  } catch {
    // Non-fatal; the attach dropdown just stays empty.
  }
}

const openCreate = () => {
  editingId.value = null
  activeCompany.value = null
  Object.assign(form, blankForm())
  originalIsActive.value = true
  modalErrors.value = {}
  modalMessage.value = ''
  isModalOpen.value = true
}

const openEdit = async (company: CoordinatorCompany) => {
  modalErrors.value = {}
  modalMessage.value = ''
  isModalOpen.value = true
  editingId.value = company.id

  try {
    const { data } = await api.get<CoordinatorCompany>(`/api/coordinator/companies/${company.id}`)
    applyCompanyToForm(data)
  } catch {
    modalMessage.value = 'Unable to load this company.'
  }
}

const applyCompanyToForm = (company: CoordinatorCompany) => {
  activeCompany.value = company
  form.name = company.name
  form.address = company.address
  form.location = company.location ?? ''
  form.industry = company.industry ?? ''
  form.head_name = company.head_name ?? ''
  form.head_contact_number = company.head_contact_number ?? ''
  form.head_email = company.head_email ?? ''
  form.department_head = company.department_head ?? ''
  form.contact_number = company.contact_number ?? ''
  form.description = company.description ?? ''
  form.is_active = company.is_active
  originalIsActive.value = company.is_active
}

const closeModal = () => {
  isModalOpen.value = false
  Object.assign(attachForm, { user_id: null, position: '' })
  Object.assign(createSupForm, { name: '', email: '', password: '', position: '' })
  supErrors.value = {}
  Object.assign(repForm, { name: '', position: '' })
  repErrors.value = {}
}

const saveCompany = async () => {
  // Deactivating a company is a critical action — confirm with the truthful
  // consequence before it goes out. Reactivating needs no confirm.
  if (editingId.value && originalIsActive.value && !form.is_active) {
    const confirmed = confirmAction(
      `Mark "${form.name}" as Inactive? It will no longer be selectable when enrolling students or adding interns to a batch. ` +
        'Existing enrollments at this company are unaffected. You can reactivate it later.',
    )
    if (!confirmed) return
  }

  isSaving.value = true
  modalErrors.value = {}
  modalMessage.value = ''

  try {
    if (editingId.value) {
      const { data } = await api.put<CoordinatorCompany>(`/api/coordinator/companies/${editingId.value}`, form)
      applyCompanyToForm(data)
      showToast('Company updated.')
    } else {
      const { data } = await api.post<CoordinatorCompany>('/api/coordinator/companies', form)
      editingId.value = data.id
      applyCompanyToForm(data)
      showToast('Company created.')
    }
    await loadCompanies()
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      modalErrors.value = error.response.data.errors ?? {}
      modalMessage.value = error.response.data.message ?? 'Please fix the errors below.'
    } else if (axios.isAxiosError(error) && error.response?.status === 403) {
      modalMessage.value = 'You do not have access to this company.'
    } else {
      modalMessage.value = 'Unable to save the company.'
    }
  } finally {
    isSaving.value = false
  }
}

const addRepresentative = async () => {
  if (!editingId.value) return
  repErrors.value = {}

  try {
    const { data } = await api.post<CoordinatorCompany>(`/api/coordinator/companies/${editingId.value}/representatives`, repForm)
    applyCompanyToForm(data)
    Object.assign(repForm, { name: '', position: '' })
    showToast('Representative added.')
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      repErrors.value = error.response.data.errors ?? {}
    } else {
      modalMessage.value = 'Unable to add the representative.'
    }
  }
}

const attachSupervisor = async () => {
  if (!editingId.value || !attachForm.user_id) return
  supErrors.value = {}

  try {
    const { data } = await api.post<CoordinatorCompany>(`/api/coordinator/companies/${editingId.value}/supervisors`, attachForm)
    applyCompanyToForm(data)
    Object.assign(attachForm, { user_id: null, position: '' })
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      supErrors.value = error.response.data.errors ?? {}
    } else {
      modalMessage.value = 'Unable to attach the supervisor.'
    }
  }
}

const createSupervisor = async () => {
  if (!editingId.value) return
  supErrors.value = {}

  try {
    const { data } = await api.post<CoordinatorCompany>(`/api/coordinator/companies/${editingId.value}/supervisors/new`, createSupForm)
    applyCompanyToForm(data)
    Object.assign(createSupForm, { name: '', email: '', password: '', position: '' })
    await loadSupervisorPool()
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      supErrors.value = error.response.data.errors ?? {}
    } else {
      modalMessage.value = 'Unable to create the supervisor.'
    }
  }
}

const detachSupervisor = async (companySupervisorId: number) => {
  if (!editingId.value) return
  if (!confirmAction('Remove this supervisor from the company?')) return

  try {
    const { data } = await api.delete<CoordinatorCompany>(`/api/coordinator/companies/${editingId.value}/supervisors/${companySupervisorId}`)
    applyCompanyToForm(data)
    showToast('Supervisor removed from company.')
  } catch {
    modalMessage.value = 'Unable to remove the supervisor.'
  }
}

// A company may have at most one login-bearing supervisor (the "company
// account") — mirrors the guard enforced server-side in CoordinatorCompanyController.
const hasLoginSupervisor = computed(() => (activeCompany.value?.supervisors ?? []).some((sup) => sup.is_login))

onMounted(async () => {
  await Promise.all([loadCompanies(), loadSupervisorPool()])
})
</script>

<template>
  <section class="space-y-5">
    <ToastHost />
    <div class="flex flex-wrap gap-3">
      <input
        v-model="search"
        class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
        placeholder="Search company..."
        @keyup.enter="loadCompanies"
      />
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" @click="loadCompanies">
        Search
      </button>
      <button type="button" class="ml-auto rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700" @click="openCreate">
        + Add Partner Company
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Location</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Industry</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Active Interns</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Representatives</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">OJT Supervisor</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="companies.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="7">No companies in your scope yet.</td>
          </tr>
          <tr v-for="company in companies" :key="company.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ company.name }}</p>
              <p class="text-xs text-slate-400">{{ company.description || '—' }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ company.location || '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ company.industry || '—' }}</td>
            <td class="px-4 py-3 font-mono text-sm font-bold text-slate-800">{{ company.active_interns_count ?? 0 }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-500">{{ company.supervisors?.filter((sup) => !sup.is_login).length ?? 0 }}</td>
            <td class="px-4 py-3 text-sm">
              <span
                class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                :class="company.supervisors?.some((sup) => sup.is_login) ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ company.supervisors?.some((sup) => sup.is_login) ? 'Yes' : 'None' }}
              </span>
            </td>
            <td class="px-4 py-3">
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="openEdit(company)">
                Manage
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create / Edit modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingId ? 'Manage Company' : 'Add Partner Company' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Close</button>
        </div>

        <div class="mt-5 grid gap-4 md:grid-cols-2">
          <label class="block md:col-span-2">
            <span class="text-xs font-bold text-slate-600">Name</span>
            <input v-model="form.name" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block md:col-span-2">
            <span class="text-xs font-bold text-slate-600">Address</span>
            <input v-model="form.address" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Location</span>
            <input v-model="form.location" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Industry</span>
            <input v-model="form.industry" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Contact Number (optional)</span>
            <input v-model="form.contact_number" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="flex items-center gap-2 pt-6 text-sm font-medium" :class="form.is_active ? 'text-slate-700' : 'text-red-700'">
            <input v-model="form.is_active" type="checkbox" />
            <span>Active</span>
          </label>
          <label class="block md:col-span-2">
            <span class="text-xs font-bold text-slate-600">Description (optional)</span>
            <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
          </label>
        </div>

        <div class="mt-5 rounded-md border border-slate-200 p-4">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Head of Company</p>
          <div class="mt-3 grid gap-4 md:grid-cols-3">
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Name</span>
              <input v-model="form.head_name" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Contact Number (optional)</span>
              <input v-model="form.head_contact_number" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Email (optional)</span>
              <input v-model="form.head_email" type="email" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
            <label class="block md:col-span-3">
              <span class="text-xs font-bold text-slate-600">Department Head (optional)</span>
              <input v-model="form.department_head" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </label>
          </div>
        </div>

        <div
          v-if="editingId && originalIsActive && !form.is_active"
          class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700"
        >
          Deactivating this company hides it from the enrollment company picker. Existing enrollments are unaffected.
        </div>

        <div v-if="Object.keys(modalErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
          <p v-for="(messages, field) in modalErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
        </div>
        <p v-if="modalMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalMessage }}</p>

        <div class="mt-5 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">Cancel</button>
          <button
            type="button"
            class="rounded-md px-4 py-2 text-sm font-semibold text-white disabled:bg-blue-300"
            :class="editingId && originalIsActive && !form.is_active ? 'bg-red-600' : 'bg-blue-600'"
            :disabled="isSaving"
            @click="saveCompany"
          >
            {{ isSaving ? 'Saving...' : editingId && originalIsActive && !form.is_active ? 'Deactivate & Save' : editingId ? 'Save Changes' : 'Create Company' }}
          </button>
        </div>

        <!-- Representatives panel (edit mode only, after the company exists) -->
        <div v-if="editingId && activeCompany" class="mt-6 border-t border-slate-200 pt-5">
          <h4 class="text-sm font-bold text-slate-900">Company Representatives</h4>
          <p class="mt-1 text-xs text-slate-400">Informational contacts at this company — no login, just a name and position to reach them by.</p>

          <div class="mt-3 divide-y divide-slate-100 rounded-md border border-slate-200">
            <p v-if="representatives.length === 0" class="px-3 py-3 text-sm text-slate-400">No representatives added yet.</p>
            <div v-for="rep in representatives" :key="rep.id" class="flex items-center justify-between gap-3 px-3 py-2">
              <div>
                <p class="text-sm font-semibold text-slate-800">{{ rep.display_name }}</p>
                <p class="text-xs text-slate-500">{{ rep.position || 'No position' }}</p>
              </div>
              <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-700" @click="detachSupervisor(rep.id)">Remove</button>
            </div>
          </div>

          <div v-if="Object.keys(repErrors).length > 0" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
            <p v-for="(messages, field) in repErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
          </div>

          <div class="mt-4 rounded-md border border-slate-200 p-3">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Add Representative</p>
            <div class="mt-2 grid gap-2 md:grid-cols-3">
              <input v-model="repForm.name" type="text" placeholder="Representative name" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm md:col-span-1" />
              <input v-model="repForm.position" type="text" placeholder="Position" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm md:col-span-1" />
              <button
                type="button"
                class="rounded-md bg-slate-950 px-3 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed md:col-span-1"
                :disabled="!repForm.name || !repForm.position"
                @click="addRepresentative"
              >
                + Add Representative
              </button>
            </div>
          </div>
        </div>

        <!-- OJT Supervisor Login panel (edit mode only) -->
        <div v-if="editingId && activeCompany" class="mt-6 border-t border-slate-200 pt-5">
          <h4 class="text-sm font-bold text-slate-900">OJT Supervisor Login</h4>
          <p class="mt-1 text-xs text-slate-400">The one account this company uses to sign in and review interns' weekly logs.</p>

          <div class="mt-3 divide-y divide-slate-100 rounded-md border border-slate-200">
            <p v-if="!loginSupervisor" class="px-3 py-3 text-sm text-slate-400">No supervisor login attached yet.</p>
            <div v-if="loginSupervisor" class="flex items-center justify-between gap-3 px-3 py-2">
              <div>
                <p class="text-sm font-semibold text-slate-800">{{ loginSupervisor.display_name }}</p>
                <p class="text-xs text-slate-500">{{ loginSupervisor.user?.email }} · {{ loginSupervisor.position || 'No position' }}</p>
              </div>
              <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-700" @click="detachSupervisor(loginSupervisor.id)">Detach</button>
            </div>
          </div>

          <div v-if="Object.keys(supErrors).length > 0" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
            <p v-for="(messages, field) in supErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
          </div>

          <p v-if="hasLoginSupervisor" class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
            This company already has a supervisor login — detach it first to attach or create a different one.
          </p>

          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <!-- Attach existing -->
            <div class="rounded-md border border-slate-200 p-3">
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Attach Existing Supervisor</p>
              <select v-model.number="attachForm.user_id" :disabled="hasLoginSupervisor" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100">
                <option :value="null">Select supervisor</option>
                <option v-for="sup in supervisorPool" :key="sup.id" :value="sup.id">{{ sup.name }} ({{ sup.email }})</option>
              </select>
              <input
                v-model="attachForm.position"
                type="text"
                placeholder="Position (optional)"
                :disabled="hasLoginSupervisor"
                class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100"
              />
              <button
                type="button"
                class="mt-2 rounded-md bg-slate-950 px-3 py-1.5 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
                :disabled="!attachForm.user_id || hasLoginSupervisor"
                @click="attachSupervisor"
              >
                Attach
              </button>
            </div>

            <!-- Create new -->
            <div class="rounded-md border border-slate-200 p-3">
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Create New Supervisor</p>
              <input v-model="createSupForm.name" type="text" placeholder="Name" :disabled="hasLoginSupervisor" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100" />
              <input v-model="createSupForm.email" type="email" placeholder="Email" :disabled="hasLoginSupervisor" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100" />
              <input v-model="createSupForm.password" type="password" placeholder="Password (min 8)" :disabled="hasLoginSupervisor" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100" />
              <input v-model="createSupForm.position" type="text" placeholder="Position (optional)" :disabled="hasLoginSupervisor" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm disabled:bg-slate-100" />
              <button
                type="button"
                class="mt-2 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
                :disabled="hasLoginSupervisor"
                @click="createSupervisor"
              >
                Create &amp; Attach
              </button>
            </div>
          </div>
        </div>

        <p v-else-if="!editingId" class="mt-6 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Save the company first to add representatives or a supervisor login.
        </p>
      </section>
    </div>
  </section>
</template>
