<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { CoordinatorCompany, EnrollmentOptionSupervisor } from '@/types/api'

const companies = ref<CoordinatorCompany[]>([])
const search = ref('')
const isLoading = ref(true)
const errorMessage = ref('')
const statusMessage = ref('')

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
  department_head: '',
  contact_number: '',
  description: '',
  is_active: true,
})

const form = reactive(blankForm())

// Supervisors panel (edit mode only).
const activeCompany = ref<CoordinatorCompany | null>(null)
const attachForm = reactive({ user_id: null as number | null, position: '' })
const createSupForm = reactive({ name: '', email: '', password: '', position: '' })
const supErrors = ref<Record<string, string[]>>({})

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
  form.department_head = company.department_head ?? ''
  form.contact_number = company.contact_number ?? ''
  form.description = company.description ?? ''
  form.is_active = company.is_active
}

const closeModal = () => {
  isModalOpen.value = false
  Object.assign(attachForm, { user_id: null, position: '' })
  Object.assign(createSupForm, { name: '', email: '', password: '', position: '' })
  supErrors.value = {}
}

const saveCompany = async () => {
  isSaving.value = true
  modalErrors.value = {}
  modalMessage.value = ''

  try {
    if (editingId.value) {
      const { data } = await api.put<CoordinatorCompany>(`/api/coordinator/companies/${editingId.value}`, form)
      applyCompanyToForm(data)
      statusMessage.value = 'Company updated.'
    } else {
      const { data } = await api.post<CoordinatorCompany>('/api/coordinator/companies', form)
      editingId.value = data.id
      applyCompanyToForm(data)
      statusMessage.value = 'Company created.'
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

const detachSupervisor = async (userId: number) => {
  if (!editingId.value) return
  if (!window.confirm('Remove this supervisor from the company?')) return

  try {
    const { data } = await api.delete<CoordinatorCompany>(`/api/coordinator/companies/${editingId.value}/supervisors/${userId}`)
    applyCompanyToForm(data)
  } catch {
    modalMessage.value = 'Unable to remove the supervisor.'
  }
}

onMounted(async () => {
  await Promise.all([loadCompanies(), loadSupervisorPool()])
})
</script>

<template>
  <section class="space-y-5">
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

    <p v-if="statusMessage" class="rounded-md bg-green-50 px-4 py-2 text-sm text-green-700">{{ statusMessage }}</p>
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
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Supervisors</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="companies.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">No companies in your scope yet.</td>
          </tr>
          <tr v-for="company in companies" :key="company.id">
            <td class="px-4 py-3">
              <p class="text-sm font-semibold text-slate-900">{{ company.name }}</p>
              <p class="text-xs text-slate-400">{{ company.description || '—' }}</p>
            </td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ company.location || '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ company.industry || '—' }}</td>
            <td class="px-4 py-3 font-mono text-sm font-bold text-slate-800">{{ company.active_interns_count ?? 0 }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ company.supervisors?.length ?? 0 }}</td>
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
            <span class="text-xs font-bold text-slate-600">Head of Company</span>
            <input v-model="form.head_name" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Department Head</span>
            <input v-model="form.department_head" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block">
            <span class="text-xs font-bold text-slate-600">Contact Number (optional)</span>
            <input v-model="form.contact_number" type="text" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="flex items-center gap-2 pt-6">
            <input v-model="form.is_active" type="checkbox" />
            <span class="text-sm font-medium text-slate-700">Active</span>
          </label>
          <label class="block md:col-span-2">
            <span class="text-xs font-bold text-slate-600">Description (optional)</span>
            <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"></textarea>
          </label>
        </div>

        <div v-if="Object.keys(modalErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
          <p v-for="(messages, field) in modalErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
        </div>
        <p v-if="modalMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalMessage }}</p>

        <div class="mt-5 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">Cancel</button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white disabled:bg-blue-300"
            :disabled="isSaving"
            @click="saveCompany"
          >
            {{ isSaving ? 'Saving...' : editingId ? 'Save Changes' : 'Create Company' }}
          </button>
        </div>

        <!-- Supervisors panel (edit mode only, after the company exists) -->
        <div v-if="editingId && activeCompany" class="mt-6 border-t border-slate-200 pt-5">
          <h4 class="text-sm font-bold text-slate-900">Company Supervisors</h4>

          <div class="mt-3 divide-y divide-slate-100 rounded-md border border-slate-200">
            <p v-if="(activeCompany.supervisors?.length ?? 0) === 0" class="px-3 py-3 text-sm text-slate-400">No supervisors attached yet.</p>
            <div v-for="sup in activeCompany.supervisors ?? []" :key="sup.id" class="flex items-center justify-between gap-3 px-3 py-2">
              <div>
                <p class="text-sm font-semibold text-slate-800">{{ sup.user?.name }}</p>
                <p class="text-xs text-slate-500">{{ sup.user?.email }} · {{ sup.position || 'No position' }}</p>
              </div>
              <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-700" @click="detachSupervisor(sup.user_id)">Detach</button>
            </div>
          </div>

          <div v-if="Object.keys(supErrors).length > 0" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
            <p v-for="(messages, field) in supErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
          </div>

          <div class="mt-4 grid gap-4 md:grid-cols-2">
            <!-- Attach existing -->
            <div class="rounded-md border border-slate-200 p-3">
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Attach Existing Supervisor</p>
              <select v-model.number="attachForm.user_id" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option :value="null">Select supervisor</option>
                <option v-for="sup in supervisorPool" :key="sup.id" :value="sup.id">{{ sup.name }} ({{ sup.email }})</option>
              </select>
              <input v-model="attachForm.position" type="text" placeholder="Position (optional)" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              <button type="button" class="mt-2 rounded-md bg-slate-950 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="!attachForm.user_id" @click="attachSupervisor">
                Attach
              </button>
            </div>

            <!-- Create new -->
            <div class="rounded-md border border-slate-200 p-3">
              <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Create New Supervisor</p>
              <input v-model="createSupForm.name" type="text" placeholder="Name" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              <input v-model="createSupForm.email" type="email" placeholder="Email" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              <input v-model="createSupForm.password" type="password" placeholder="Password (min 8)" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              <input v-model="createSupForm.position" type="text" placeholder="Position (optional)" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              <button type="button" class="mt-2 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white" @click="createSupervisor">
                Create &amp; Attach
              </button>
            </div>
          </div>
        </div>

        <p v-else-if="!editingId" class="mt-6 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Save the company first to attach supervisors.
        </p>
      </section>
    </div>
  </section>
</template>
