<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import type { Company, CompanyDetail, Department, PaginatedResponse } from '@/types/api'

type CompanyForm = {
  name: string
  address: string
  location: string
  industry: string
  contact_number: string
  head_name: string
  department_head: string
  is_active: boolean
}

const companies = ref<Company[]>([])
const departments = ref<Department[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const search = ref('')
const statusFilter = ref('')
const departmentFilter = ref('')

const isModalOpen = ref(false)
const editingCompanyId = ref<number | null>(null)
const isSaving = ref(false)
const modalError = ref('')

const emptyForm = (): CompanyForm => ({
  name: '',
  address: '',
  location: '',
  industry: '',
  contact_number: '',
  head_name: '',
  department_head: '',
  is_active: true,
})

const companyForm = ref<CompanyForm>(emptyForm())

const isViewOpen = ref(false)
const isViewLoading = ref(false)
const viewError = ref('')
const viewedCompany = ref<CompanyDetail | null>(null)

let searchDebounce: ReturnType<typeof setTimeout> | undefined

const loadDepartments = async () => {
  try {
    const response = await api.get<Department[]>('/api/admin/departments')
    departments.value = response.data
  } catch {
    // Filter dropdown just stays empty; not critical to the page loading.
  }
}

const loadCompanies = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.get<PaginatedResponse<Company>>('/api/admin/companies', {
      params: {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        department_id: departmentFilter.value || undefined,
      },
    })
    companies.value = response.data.data
  } catch {
    errorMessage.value = 'Unable to load companies.'
  } finally {
    isLoading.value = false
  }
}

watch(search, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(loadCompanies, 300)
})
watch([statusFilter, departmentFilter], loadCompanies)

const resetForm = () => {
  companyForm.value = emptyForm()
  modalError.value = ''
}

const openCreateModal = () => {
  editingCompanyId.value = null
  resetForm()
  isModalOpen.value = true
}

const openEditModal = (company: Company) => {
  editingCompanyId.value = company.id
  companyForm.value = {
    name: company.name,
    address: company.address,
    location: company.location ?? '',
    industry: company.industry ?? '',
    contact_number: company.contact_number ?? '',
    head_name: company.head_name ?? '',
    department_head: company.department_head ?? '',
    is_active: company.is_active,
  }
  modalError.value = ''
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
  resetForm()
}

const saveCompany = async () => {
  isSaving.value = true
  modalError.value = ''

  try {
    if (editingCompanyId.value) {
      await api.put(`/api/admin/companies/${editingCompanyId.value}`, companyForm.value)
    } else {
      await api.post('/api/admin/companies', companyForm.value)
    }
    closeModal()
    await loadCompanies()
  } catch (error) {
    const data = axios.isAxiosError(error) ? error.response?.data : null
    modalError.value = data?.message ?? 'Unable to save company. Please check the fields and try again.'
  } finally {
    isSaving.value = false
  }
}

const openViewModal = async (company: Company) => {
  isViewOpen.value = true
  isViewLoading.value = true
  viewError.value = ''
  viewedCompany.value = null

  try {
    const response = await api.get<CompanyDetail>(`/api/admin/companies/${company.id}`)
    viewedCompany.value = response.data
  } catch {
    viewError.value = 'Unable to load company details.'
  } finally {
    isViewLoading.value = false
  }
}

const closeViewModal = () => {
  isViewOpen.value = false
  viewedCompany.value = null
}

onMounted(() => {
  loadDepartments()
  loadCompanies()
})
</script>

<template>
  <section class="space-y-5">
    <div class="flex flex-wrap gap-3">
      <input v-model="search" class="min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="Search company..." />
      <select v-model="statusFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
      <select v-model="departmentFilter" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
        <option value="">All Departments</option>
        <option v-for="department in departments" :key="department.id" :value="department.id">
          {{ department.name }}
        </option>
      </select>
      <button
        type="button"
        class="ml-auto rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
        @click="openCreateModal"
      >
        + Add Company
      </button>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company Name</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Location</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Industry</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Active Interns</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Total (All-time)</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="companies.length === 0">
            <td colspan="7" class="px-4 py-6 text-center text-sm text-slate-500">No companies found.</td>
          </tr>
          <tr v-for="company in companies" :key="company.id">
            <td class="px-4 py-3 text-sm font-semibold text-slate-900">{{ company.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-500">{{ company.location ?? '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ company.industry ?? '—' }}</td>
            <td class="px-4 py-3 font-mono text-sm font-bold text-slate-800">{{ company.active_interns_count }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-500">{{ company.total_interns_count }}</td>
            <td class="px-4 py-3">
              <span
                class="rounded-full px-3 py-1 text-xs font-bold"
                :class="company.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ company.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3">
              <div class="flex gap-2">
                <button
                  type="button"
                  class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                  @click="openViewModal(company)"
                >
                  View
                </button>
                <button
                  type="button"
                  class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700"
                  @click="openEditModal(company)"
                >
                  Edit
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create / Edit modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingCompanyId ? 'Edit Company' : 'Add Company' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
          <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="company-name">Company Name</label>
            <input id="company-name" v-model="companyForm.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="company-address">Address</label>
            <input id="company-address" v-model="companyForm.address" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="company-location">Location</label>
            <input id="company-location" v-model="companyForm.location" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="company-industry">Industry</label>
            <input id="company-industry" v-model="companyForm.industry" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="company-contact">Contact Number</label>
            <input id="company-contact" v-model="companyForm.contact_number" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div>
            <label class="mb-2 block text-sm font-medium text-slate-700" for="company-head">Company Head</label>
            <input id="company-head" v-model="companyForm.head_name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div class="md:col-span-2">
            <label class="mb-2 block text-sm font-medium text-slate-700" for="company-dept-head">Department Head (contact label)</label>
            <input id="company-dept-head" v-model="companyForm.department_head" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2" />
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="companyForm.is_active" type="checkbox" />
              Active
            </label>
          </div>
        </div>

        <p v-if="modalError" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalError }}</p>

        <div class="mt-6 flex justify-end gap-3">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400"
            :disabled="isSaving"
            @click="saveCompany"
          >
            {{ isSaving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </section>
    </div>

    <!-- View (read-only preview) modal -->
    <div v-if="isViewOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4">
      <section class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <div class="flex items-center justify-between">
          <h3 class="text-lg font-semibold text-slate-950">Company Details</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeViewModal">Close</button>
        </div>

        <p v-if="isViewLoading" class="mt-6 text-sm text-slate-500">Loading...</p>
        <p v-else-if="viewError" class="mt-6 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ viewError }}</p>

        <div v-else-if="viewedCompany" class="mt-6 space-y-6">
          <div>
            <h4 class="text-xl font-bold text-slate-950">{{ viewedCompany.name }}</h4>
            <span
              class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-bold"
              :class="viewedCompany.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
            >
              {{ viewedCompany.is_active ? 'Active' : 'Inactive' }}
            </span>
          </div>

          <div class="grid gap-x-6 gap-y-3 text-sm md:grid-cols-2">
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Address</span>{{ viewedCompany.address }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Location</span>{{ viewedCompany.location ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Industry</span>{{ viewedCompany.industry ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Contact Number</span>{{ viewedCompany.contact_number ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Company Head</span>{{ viewedCompany.head_name ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Department Head</span>{{ viewedCompany.department_head ?? '—' }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Active Interns</span>{{ viewedCompany.active_interns_count }}</div>
            <div><span class="block text-xs font-semibold uppercase tracking-wide text-slate-400">Total Interns (All-time)</span>{{ viewedCompany.total_interns_count }}</div>
          </div>

          <div>
            <h5 class="text-xs font-bold uppercase tracking-wide text-slate-500">Partner Departments</h5>
            <div v-if="viewedCompany.departments.length > 0" class="mt-2 flex flex-wrap gap-2">
              <span
                v-for="department in viewedCompany.departments"
                :key="department.id"
                class="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700"
              >
                {{ department.name }}
              </span>
            </div>
            <p v-else class="mt-2 text-sm text-slate-400">No placements yet.</p>
          </div>

          <div>
            <h5 class="text-xs font-bold uppercase tracking-wide text-slate-500">Supervisors</h5>
            <div v-if="viewedCompany.supervisors.length > 0" class="mt-2 overflow-hidden rounded-lg ring-1 ring-slate-200">
              <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                  <tr>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Name</th>
                    <th class="px-3 py-2 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Position</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <tr v-for="supervisor in viewedCompany.supervisors" :key="supervisor.id">
                    <td class="px-3 py-2 text-sm text-slate-900">{{ supervisor.user.name }}</td>
                    <td class="px-3 py-2 text-sm text-slate-500">{{ supervisor.position }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-else class="mt-2 text-sm text-slate-400">No supervisors yet.</p>
          </div>
        </div>
      </section>
    </div>
  </section>
</template>
