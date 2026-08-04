<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { confirmAction, showToast } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import TooltipWrap from '@/components/ui/TooltipWrap.vue'
import type { CompanySupervisorRecord, CoordinatorCompany, EnrollmentOptionSupervisor } from '@/types/api'

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

/**
 * The company-level contact_number is no longer edited here (the Head of Company
 * block already collects one), but it is still SENT so an existing value is not
 * wiped. This holds the value exactly as loaded — including a genuine `null` —
 * because `form.contact_number` coerces null to '' and writing '' back would
 * turn "no number on file" into "an empty number on file".
 */
const loadedContactNumber = ref<string | null>(null)

/**
 * Company status is an explicit choice with NOTHING preselected, so saving can
 * never silently flip a company's availability. It writes through to
 * `form.is_active` — the same boolean the API already takes.
 */
const statusChoice = ref<'active' | 'inactive' | null>(null)

watch(statusChoice, (choice) => {
  if (choice !== null) form.is_active = choice === 'active'
})

/**
 * Department is a plain string column with no enum, constants file, or options
 * endpoint behind it, so the dropdown is built from the distinct values already
 * in use across the loaded company list. Nothing is invented, and a value the
 * list does not cover is still reachable through "Other…".
 *
 * Sentinel rather than a real value, so a company genuinely named "Other" could
 * never collide with it.
 */
const OTHER_DEPARTMENT = '__other__'

/** The department exactly as loaded, so it survives even when no other company shares it. */
const loadedIndustry = ref('')

const departmentChoice = ref('')
const departmentOther = ref('')
const departmentOtherInput = ref<HTMLInputElement | null>(null)

const departmentOptions = computed(() => {
  // Keyed case-insensitively, first-seen casing wins. The loaded value is
  // seeded FIRST and stored raw, so re-saving an untouched record sends back a
  // byte-identical string rather than a re-cased or re-trimmed near-match.
  const seen = new Map<string, string>()

  const remember = (raw: string) => {
    const trimmed = raw.trim()
    if (trimmed === '') return
    const key = trimmed.toLocaleLowerCase()
    if (!seen.has(key)) seen.set(key, raw)
  }

  remember(loadedIndustry.value)
  for (const company of companies.value) remember(company.industry ?? '')

  return [...seen.values()].sort((a, b) => a.trim().localeCompare(b.trim()))
})

/**
 * The select and its "Other…" input write through to `form.industry` — the same
 * plain string under the same payload key the text input used. An empty
 * "Other…" input means an empty department, never the literal "Other…".
 */
watch([departmentChoice, departmentOther], () => {
  form.industry = departmentChoice.value === OTHER_DEPARTMENT ? departmentOther.value : departmentChoice.value
})

watch(departmentChoice, async (choice, previous) => {
  if (choice === OTHER_DEPARTMENT) {
    await nextTick()
    departmentOtherInput.value?.focus()
  } else if (previous === OTHER_DEPARTMENT) {
    departmentOther.value = ''
  }
})

/** Mirrors a loaded/blank department onto the control without going through the watcher's inverse. */
const syncDepartmentChoice = (value: string) => {
  loadedIndustry.value = value
  departmentChoice.value = value.trim() === '' ? '' : value
  departmentOther.value = ''
}

const canSaveCompany = computed(() => !isSaving.value && statusChoice.value !== null)

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
  syncDepartmentChoice('')
  originalIsActive.value = true
  statusChoice.value = null
  loadedContactNumber.value = null
  modalErrors.value = {}
  modalMessage.value = ''
  isModalOpen.value = true
}

const openEdit = async (company: CoordinatorCompany) => {
  modalErrors.value = {}
  modalMessage.value = ''
  isModalOpen.value = true
  editingId.value = company.id
  statusChoice.value = null
  loadedContactNumber.value = null

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
  syncDepartmentChoice(form.industry)
  form.head_name = company.head_name ?? ''
  form.head_contact_number = company.head_contact_number ?? ''
  form.head_email = company.head_email ?? ''
  form.department_head = company.department_head ?? ''
  form.contact_number = company.contact_number ?? ''
  loadedContactNumber.value = company.contact_number ?? null
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
  pendingRemovalId.value = null
  removedRecords.value = []
}

const saveCompany = async () => {
  // Deactivating a company is a critical action — confirm with the truthful
  // consequence before it goes out. Reactivating needs no confirm.
  if (editingId.value && originalIsActive.value && !form.is_active) {
    const confirmed = await confirmAction({
      title: 'Deactivate this company?',
      message:
        `Mark "${form.name}" as Inactive? It will no longer be selectable when enrolling students or adding interns to a batch. ` +
        'Existing enrollments at this company are unaffected. You can reactivate it later.',
      confirmLabel: 'Deactivate Company',
      tone: 'danger',
    })
    if (!confirmed) return
  }

  isSaving.value = true
  modalErrors.value = {}
  modalMessage.value = ''

  // contact_number is no longer an input, so send back exactly what was loaded
  // rather than form's ''-coerced copy — writing '' would overwrite a real
  // number, or turn a null into an empty string.
  // A hand-typed department is trimmed; a value picked from the list is sent
  // back exactly as it was loaded, whitespace and casing included.
  const payload = {
    ...form,
    contact_number: loadedContactNumber.value,
    industry: departmentChoice.value === OTHER_DEPARTMENT ? form.industry.trim() : form.industry,
  }

  try {
    if (editingId.value) {
      const { data } = await api.put<CoordinatorCompany>(`/api/coordinator/companies/${editingId.value}`, payload)
      applyCompanyToForm(data)
      showToast('Company updated.')
    } else {
      const { data } = await api.post<CoordinatorCompany>('/api/coordinator/companies', payload)
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

/**
 * Removal is confirmed inline on the row and can be undone until the modal
 * closes. The strip below is deliberately SESSION-SCOPED and nothing about it is
 * persisted — once the modal closes the removal is final, so the UI never claims
 * otherwise.
 */
type RemovedRecord = {
  key: number
  name: string
  position: string
  /** Login-bearing rows re-attach by user id; representatives by name. */
  userId: number | null
  error: string
  isRestoring: boolean
}

const pendingRemovalId = ref<number | null>(null)
const removedRecords = ref<RemovedRecord[]>([])

const removeCompanySupervisor = async (record: CompanySupervisorRecord) => {
  if (!editingId.value) return
  pendingRemovalId.value = null

  try {
    const { data } = await api.delete<CoordinatorCompany>(
      `/api/coordinator/companies/${editingId.value}/supervisors/${record.id}`,
    )
    applyCompanyToForm(data)
    removedRecords.value.push({
      key: record.id,
      name: record.display_name,
      position: record.position ?? '',
      userId: record.user_id,
      error: '',
      isRestoring: false,
    })
  } catch {
    modalMessage.value = 'Unable to remove the supervisor.'
  }
}

const undoRemoval = async (removed: RemovedRecord) => {
  if (!editingId.value) return
  removed.error = ''
  removed.isRestoring = true

  try {
    // Re-attach through the same endpoints the panel already uses: a login
    // supervisor by user id, a named-only representative by name.
    const { data } =
      removed.userId !== null
        ? await api.post<CoordinatorCompany>(
            `/api/coordinator/companies/${editingId.value}/supervisors`,
            { user_id: removed.userId, position: removed.position },
          )
        : await api.post<CoordinatorCompany>(
            `/api/coordinator/companies/${editingId.value}/representatives`,
            { name: removed.name, position: removed.position },
          )

    applyCompanyToForm(data)
    removedRecords.value = removedRecords.value.filter((entry) => entry.key !== removed.key)
    showToast(`${removed.name} restored.`)
  } catch (error) {
    // Surface the server's own reason and leave the strip in place so the
    // coordinator can retry.
    removed.error = axios.isAxiosError(error)
      ? (error.response?.data?.message ?? 'Unable to restore this record.')
      : 'Unable to restore this record.'
  } finally {
    removed.isRestoring = false
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
        class="w-full sm:w-auto sm:min-w-72 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
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

    <div v-else class="overflow-x-auto rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Company</th>
            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Location</th>
            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Department</th>
            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Active Interns</th>
            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Representatives</th>
            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">OJT Supervisor</th>
            <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-slate-500">Action</th>
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
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
      <!--
        Three-part flex column: the header and footer are shrink-0 siblings and
        the body is the ONLY scrolling element.

        The overlay deliberately no longer scrolls. While it did, the panel grew
        past the viewport with no height cap, so the footer's `sticky bottom-0`
        had nothing to pin against inside its own containing block — it drifted
        up over the Company Status block instead of staying put. A real flex
        shell removes the need for sticky entirely.
      -->
      <section class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingId ? 'Manage Company' : 'Add Partner Company' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Close</button>
        </div>

        <!-- The only scroll container in the modal. -->
        <div class="flex-1 overflow-y-auto px-6 py-5">
          <div class="grid gap-4 md:grid-cols-2">
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
              <span class="text-xs font-bold text-slate-600">Department</span>
              <select v-model="departmentChoice" class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option v-if="departmentChoice === ''" value="" disabled>Select department</option>
                <option v-for="option in departmentOptions" :key="option" :value="option">{{ option }}</option>
                <option :value="OTHER_DEPARTMENT">Other…</option>
              </select>
              <span v-if="departmentChoice === OTHER_DEPARTMENT" class="mt-2 block">
                <span class="text-xs font-bold text-slate-600">Department name</span>
                <input
                  ref="departmentOtherInput"
                  v-model="departmentOther"
                  type="text"
                  class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                />
              </span>
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

          <!-- Representatives panel (edit mode only, after the company exists) -->
          <div v-if="editingId && activeCompany" class="mt-6 border-t border-slate-200 pt-5">
            <!--
              Undo strips for anything removed in this session. They vanish when the
              modal closes — the removal is only recoverable while this stays open,
              so the copy never promises more than that.
            -->
            <div v-if="removedRecords.length > 0" class="mb-4 space-y-2">
              <div
                v-for="removed in removedRecords"
                :key="removed.key"
                class="rounded-md bg-slate-50 px-3 py-2"
              >
                <div class="flex items-center justify-between gap-3">
                  <div class="flex min-w-0 items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" class="h-4 w-4 shrink-0 text-slate-400">
                      <path d="M4 7h16M9.5 7V5.5A1.5 1.5 0 0 1 11 4h2a1.5 1.5 0 0 1 1.5 1.5V7M6.5 7l.8 12A1.5 1.5 0 0 0 8.8 20.5h6.4a1.5 1.5 0 0 0 1.5-1.4l.8-12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <p class="min-w-0 truncate text-sm text-slate-600">Removed {{ removed.name }}</p>
                  </div>
                  <button
                    type="button"
                    class="shrink-0 text-xs font-semibold text-blue-600 transition hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="removed.isRestoring"
                    @click="undoRemoval(removed)"
                  >
                    {{ removed.isRestoring ? 'Restoring…' : 'Undo' }}
                  </button>
                </div>
                <p v-if="removed.error" class="mt-1 text-xs text-red-600">{{ removed.error }}</p>
              </div>
            </div>

            <h4 class="text-sm font-bold text-slate-900">Company Representatives</h4>
            <p class="mt-1 text-xs text-slate-400">Informational contacts at this company — no login, just a name and position to reach them by.</p>

            <div class="mt-3 divide-y divide-slate-100 rounded-md border border-slate-200">
              <p v-if="representatives.length === 0" class="px-3 py-3 text-sm text-slate-400">No representatives added yet.</p>
              <div v-for="rep in representatives" :key="rep.id" class="flex items-center justify-between gap-3 px-3 py-2">
                <div>
                  <p class="text-sm font-semibold text-slate-800">{{ rep.display_name }}</p>
                  <p class="text-xs text-slate-500">{{ rep.position || 'No position' }}</p>
                </div>
                <button
                  v-if="pendingRemovalId !== rep.id"
                  type="button"
                  class="shrink-0 text-xs font-semibold text-red-600 transition hover:text-red-700"
                  :aria-label="`Remove ${rep.display_name}`"
                  @click="pendingRemovalId = rep.id"
                >
                  Remove
                </button>
                <div v-else class="flex shrink-0 items-center gap-3">
                  <span class="text-xs text-slate-600">Remove representative?</span>
                  <button type="button" class="text-xs font-semibold text-red-600 transition hover:text-red-700" @click="removeCompanySupervisor(rep)">Remove</button>
                  <button type="button" class="text-xs font-semibold text-slate-500 transition hover:text-slate-700" @click="pendingRemovalId = null">Cancel</button>
                </div>
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
                  class="rounded-md bg-blue-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:col-span-1"
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
                <button
                  v-if="pendingRemovalId !== loginSupervisor.id"
                  type="button"
                  class="shrink-0 text-xs font-semibold text-red-600 transition hover:text-red-700"
                  :aria-label="`Remove ${loginSupervisor.display_name}`"
                  @click="pendingRemovalId = loginSupervisor.id"
                >
                  Remove
                </button>
                <div v-else class="flex shrink-0 items-center gap-3">
                  <span class="text-xs text-slate-600">Remove supervisor?</span>
                  <button type="button" class="text-xs font-semibold text-red-600 transition hover:text-red-700" @click="removeCompanySupervisor(loginSupervisor)">Remove</button>
                  <button type="button" class="text-xs font-semibold text-slate-500 transition hover:text-slate-700" @click="pendingRemovalId = null">Cancel</button>
                </div>
              </div>
            </div>

            <div v-if="Object.keys(supErrors).length > 0" class="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
              <p v-for="(messages, field) in supErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
            </div>

            <p v-if="hasLoginSupervisor" class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500">
              This company already has a supervisor login — remove it first to attach or create a different one.
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
                  class="mt-2 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
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
                  class="mt-2 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
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

          <div
            v-if="editingId && originalIsActive && !form.is_active"
            class="mt-6 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700"
          >
            Deactivating this company hides it from the enrollment company picker. Existing enrollments are unaffected.
          </div>

          <div v-if="Object.keys(modalErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
            <p v-for="(messages, field) in modalErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
          </div>
          <p v-if="modalMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalMessage }}</p>

          <div class="mt-5 rounded-md border border-slate-200 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Company Status</p>
            <div class="mt-3 flex flex-wrap items-center gap-6">
              <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input v-model="statusChoice" type="radio" value="active" name="company-status" />
                <span>Active</span>
              </label>
              <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                <input v-model="statusChoice" type="radio" value="inactive" name="company-status" />
                <span>Not Active</span>
              </label>
            </div>
            <p v-if="editingId" class="mt-2 text-xs text-slate-400">
              Currently: {{ originalIsActive ? 'Active' : 'Not Active' }}
            </p>
          </div>
        </div>

        <div class="flex shrink-0 justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">Cancel</button>
          <TooltipWrap :label="canSaveCompany ? '' : 'Select a company status to save'" placement="top" align="end">
            <button
              type="button"
              class="rounded-md px-4 py-2 text-sm font-semibold text-white transition focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
              :class="editingId && originalIsActive && !form.is_active ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'"
              :disabled="!canSaveCompany"
              @click="saveCompany"
            >
              {{ isSaving ? 'Saving...' : editingId && originalIsActive && !form.is_active ? 'Deactivate & Save' : editingId ? 'Save Changes' : 'Create Company' }}
            </button>
          </TooltipWrap>
        </div>
      </section>
    </div>
  </section>
</template>
