<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast, confirmAction } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import type { JournalTemplateProgramOption, JournalTemplateRecord, JournalTemplateSection } from '@/types/api'

/**
 * Fixed, structural section every template carries — mirrors the server's
 * ValidatesJournalTemplate::FIXED_SECTION. Not part of customSections: the
 * coordinator can't remove, rename, or re-key it, so it's rendered as its
 * own locked row and always prepended to the saved payload.
 */
const FIXED_SECTION: JournalTemplateSection = {
  key: 'daily_accomplishment',
  label: 'Daily Accomplishment',
  prompt: 'Summarize what you accomplished today.',
  required: true,
  sipp: false,
}

type SippKey = 'issues_concerns' | 'solutions' | 'recommendations'

const SIPP_LABELS: Record<SippKey, string> = {
  issues_concerns: 'Issues & Concerns',
  solutions: 'Solutions',
  recommendations: 'Recommendations',
}

const SIPP_DEFAULT_PROMPTS: Record<SippKey, string> = {
  issues_concerns: 'Describe any issues or concerns encountered.',
  solutions: 'What solutions were applied or proposed?',
  recommendations: 'Any recommendations going forward?',
}

type TemplateForm = {
  program_ids: number[]
  name: string
  char_limit: number
  is_active: boolean
}

const templates = ref<JournalTemplateRecord[]>([])
const programs = ref<JournalTemplateProgramOption[]>([])
const isLoading = ref(true)
const errorMessage = ref('')

const isModalOpen = ref(false)
const editingTemplateId = ref<number | null>(null)
const originalKeys = ref<string[]>([])
const isSaving = ref(false)
const modalErrors = ref<Record<string, string[]>>({})
const modalMessage = ref('')
const pendingRemovedKeys = ref<string[]>([])
const lastSavedNotice = ref('')

const form = reactive<TemplateForm>({
  program_ids: [],
  name: '',
  char_limit: 1500,
  is_active: true,
})

// Non-SIPP sections the coordinator authors freely (key auto-generated from label).
const customSections = ref<JournalTemplateSection[]>([])

// SIPP (Annex C) is a fixed trio, toggled as one group — never individually flagged.
const sippEnabled = ref(false)
const sippPrompts = reactive<Record<SippKey, string>>({ ...SIPP_DEFAULT_PROMPTS })

const slugify = (label: string): string =>
  label
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .replace(/^(\d)/, '_$1')

const sippSections = computed<JournalTemplateSection[]>(() =>
  sippEnabled.value
    ? (Object.keys(SIPP_LABELS) as SippKey[]).map((key) => ({
        key,
        label: SIPP_LABELS[key],
        prompt: sippPrompts[key],
        required: false,
        sipp: true,
      }))
    : [],
)

const allSections = computed<JournalTemplateSection[]>(() => [FIXED_SECTION, ...customSections.value, ...sippSections.value])

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<{ templates: JournalTemplateRecord[]; programs: JournalTemplateProgramOption[] }>(
      '/api/coordinator/journal-templates',
    )
    templates.value = data.templates
    programs.value = data.programs
  } catch {
    errorMessage.value = 'Unable to load journal templates.'
  } finally {
    isLoading.value = false
  }
}

const resetForm = () => {
  form.program_ids = []
  form.name = ''
  form.char_limit = 1500
  form.is_active = true
  customSections.value = []
  sippEnabled.value = false
  Object.assign(sippPrompts, SIPP_DEFAULT_PROMPTS)
  modalErrors.value = {}
  modalMessage.value = ''
  pendingRemovedKeys.value = []
  originalKeys.value = []
}

const openCreateModal = () => {
  editingTemplateId.value = null
  resetForm()
  isModalOpen.value = true
}

const openEditModal = (template: JournalTemplateRecord) => {
  editingTemplateId.value = template.id
  form.program_ids = template.programs.map((program) => program.id)
  form.name = template.name
  form.char_limit = template.char_limit
  form.is_active = template.is_active

  const existingSipp = template.sections.filter((section) => section.sipp)
  sippEnabled.value = existingSipp.length > 0
  Object.assign(sippPrompts, SIPP_DEFAULT_PROMPTS)
  existingSipp.forEach((section) => {
    if (section.key in sippPrompts) sippPrompts[section.key as SippKey] = section.prompt || sippPrompts[section.key as SippKey]
  })

  customSections.value = template.sections
    .filter((section) => !section.sipp && section.key !== FIXED_SECTION.key)
    .map((section) => ({ ...section }))
  originalKeys.value = template.sections.map((section) => section.key)
  modalErrors.value = {}
  modalMessage.value = ''
  pendingRemovedKeys.value = []
  isModalOpen.value = true
}

const closeModal = () => {
  isModalOpen.value = false
}

const addSection = () => {
  customSections.value.push({ key: '', label: '', prompt: '', required: false, sipp: false })
}

const removeSection = (index: number) => {
  customSections.value.splice(index, 1)
}

const moveSection = (index: number, direction: -1 | 1) => {
  const target = index + direction
  if (target < 0 || target >= customSections.value.length) return

  const [section] = customSections.value.splice(index, 1)
  customSections.value.splice(target, 0, section)
}

const onLabelInput = (index: number) => {
  customSections.value[index].key = slugify(customSections.value[index].label)
}

const isProgramDisabled = (program: JournalTemplateProgramOption) =>
  program.assigned_template_id !== null && program.assigned_template_id !== editingTemplateId.value

const toggleProgram = (programId: number, checked: boolean) => {
  if (checked) {
    if (!form.program_ids.includes(programId)) form.program_ids.push(programId)
  } else {
    form.program_ids = form.program_ids.filter((id) => id !== programId)
  }
}

const hasRequiredSection = computed(() => allSections.value.some((section) => section.required))
const duplicateKeys = computed(() => {
  const keys = allSections.value.map((section) => section.key).filter(Boolean)
  return keys.filter((key, index) => keys.indexOf(key) !== index)
})
const hasEmptyFields = computed(() => customSections.value.some((section) => !section.key || !section.label))
const canSave = computed(
  () =>
    form.program_ids.length > 0 &&
    form.name.trim() !== '' &&
    allSections.value.length > 0 &&
    hasRequiredSection.value &&
    duplicateKeys.value.length === 0 &&
    !hasEmptyFields.value,
)

const removedKeysIfSaved = computed(() => {
  if (!editingTemplateId.value) return []
  const currentKeys = allSections.value.map((section) => section.key)
  return originalKeys.value.filter((key) => !currentKeys.includes(key))
})

const performSave = async () => {
  isSaving.value = true
  modalErrors.value = {}
  modalMessage.value = ''

  const payload = {
    program_ids: form.program_ids,
    name: form.name,
    char_limit: form.char_limit,
    is_active: form.is_active,
    sections: allSections.value,
  }

  try {
    if (editingTemplateId.value) {
      const { data } = await api.put(`/api/coordinator/journal-templates/${editingTemplateId.value}`, payload)
      lastSavedNotice.value =
        data.affected_entries > 0
          ? `Saved. ${data.affected_entries} existing entr${data.affected_entries === 1 ? 'y has' : 'ies have'} data in the removed field(s); that data is preserved but no longer shown here.`
          : 'Template saved.'
    } else {
      await api.post('/api/coordinator/journal-templates', payload)
      lastSavedNotice.value = 'Template created.'
    }

    showToast(editingTemplateId.value ? 'Template saved.' : 'Template created.')
    await load()
    closeModal()
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 422) {
      modalErrors.value = error.response.data.errors ?? {}
      modalMessage.value = error.response.data.message ?? 'Please fix the errors below.'
    } else if (axios.isAxiosError(error) && error.response?.status === 403) {
      modalMessage.value = 'You are not allowed to edit this template.'
    } else {
      modalMessage.value = 'Unable to save this template.'
    }
  } finally {
    isSaving.value = false
  }
}

const save = () => {
  if (removedKeysIfSaved.value.length > 0 && pendingRemovedKeys.value.length === 0) {
    pendingRemovedKeys.value = removedKeysIfSaved.value
    return
  }

  performSave()
}

const cancelRemovalConfirm = () => {
  pendingRemovedKeys.value = []
}

const toggleActive = async (template: JournalTemplateRecord) => {
  errorMessage.value = ''

  // Deactivating is the crucial action — confirm first.
  if (template.is_active && !confirmAction(`Turn off "${template.name}" for use in batches?`)) return

  try {
    await api.patch(`/api/coordinator/journal-templates/${template.id}/toggle-active`)
    await load()
    showToast(template.is_active ? 'Template turned off.' : 'Template turned on.')
  } catch {
    errorMessage.value = 'Unable to update template status.'
  }
}

const programNames = (template: JournalTemplateRecord): string =>
  template.programs.map((program) => program.code ?? program.name).join(', ') || '—'

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <ToastHost />
    <div class="flex items-center justify-between gap-4">
      <div>
        <h2 class="text-2xl font-bold text-slate-950">Journal Templates</h2>
        <p class="mt-1 text-sm text-slate-500">Shape the daily journal fields students fill in for your programs.</p>
      </div>
      <button
        type="button"
        class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:grayscale disabled:cursor-not-allowed"
        :disabled="programs.length === 0"
        @click="openCreateModal"
      >
        Create Template
      </button>
    </div>

    <p v-if="lastSavedNotice" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-700">{{ lastSavedNotice }}</p>
    <p v-if="isLoading" class="text-sm text-slate-500">Loading...</p>
    <p v-else-if="errorMessage" class="rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{{ errorMessage }}</p>
    <p v-else-if="programs.length === 0" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      You are not currently assigned as coordinator of any batch, so there are no programs to author templates for.
    </p>

    <div v-else class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
      <table class="min-w-full divide-y divide-slate-200">
        <thead class="bg-slate-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Name</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Programs</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Sections</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Character Limit</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <tr v-if="templates.length === 0">
            <td class="px-4 py-6 text-center text-sm text-slate-500" colspan="6">No journal templates yet.</td>
          </tr>
          <tr v-for="template in templates" :key="template.id">
            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ template.name }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ programNames(template) }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ template.sections.length }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ template.char_limit }}</td>
            <td class="px-4 py-3 text-sm">
              <span
                class="rounded-full px-3 py-1 text-xs font-bold"
                :class="template.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ template.is_active ? 'Available' : 'Off' }}
              </span>
            </td>
            <td class="px-4 py-3 text-sm">
              <div class="flex gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="openEditModal(template)">
                  Edit
                </button>
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="toggleActive(template)">
                  {{ template.is_active ? 'Turn Off' : 'Turn On' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Template modal: capped height, header/footer pinned, body scrolls internally so the whole form is reachable top-to-bottom. -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 px-4 py-8">
      <section class="flex max-h-[calc(100vh-4rem)] w-full max-w-5xl flex-col rounded-lg bg-white shadow-xl">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-100 px-6 py-4">
          <h3 class="text-lg font-semibold text-slate-950">{{ editingTemplateId ? 'Edit Template' : 'Create Template' }}</h3>
          <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
        </div>

        <div class="grid gap-6 overflow-y-auto px-6 py-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
          <div>
            <div class="grid gap-4 md:grid-cols-2">
              <div class="md:col-span-2">
                <label class="mb-2 block text-sm font-medium text-slate-700" for="template-name">Name</label>
                <input id="template-name" v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </div>
              <div class="md:col-span-2">
                <span class="mb-2 block text-sm font-medium text-slate-700">Programs</span>
                <div class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-md border border-slate-200 p-3">
                  <label
                    v-for="program in programs"
                    :key="program.id"
                    class="flex items-center gap-2 text-sm"
                    :class="isProgramDisabled(program) ? 'cursor-not-allowed text-slate-400' : 'text-slate-700'"
                  >
                    <input
                      type="checkbox"
                      :checked="form.program_ids.includes(program.id)"
                      :disabled="isProgramDisabled(program)"
                      @change="toggleProgram(program.id, ($event.target as HTMLInputElement).checked)"
                    />
                    {{ program.code ?? program.name }}
                    <span v-if="isProgramDisabled(program)" class="text-xs text-slate-400">(unavailable — claimed by another template)</span>
                  </label>
                </div>
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-slate-700" for="template-char-limit">Character Limit</label>
                <input id="template-char-limit" v-model.number="form.char_limit" type="number" min="100" max="10000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
              </div>
              <div>
                <label class="mt-7 flex items-center gap-2 text-sm font-medium text-slate-700">
                  <input v-model="form.is_active" type="checkbox" />
                  Available for use in batches
                </label>
                <p class="mt-1 text-xs text-slate-400">Turn off to retire this template without deleting its history.</p>
              </div>
            </div>

            <!-- Fixed, structural section — always present, cannot be removed/renamed/re-keyed. -->
            <div class="mt-6 rounded-md border border-slate-300 bg-slate-50 p-3">
              <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-semibold text-slate-900">{{ FIXED_SECTION.label }}</p>
                <span class="rounded-full bg-slate-900 px-2.5 py-0.5 text-xs font-bold text-white">Required — used for Weekly Bundling</span>
              </div>
              <p class="mt-1 text-xs text-slate-500">{{ FIXED_SECTION.prompt }}</p>
              <p class="mt-2 text-xs text-slate-400">Fixed on every template. It can't be removed, renamed, or made optional.</p>
            </div>

            <div class="mt-6">
              <div class="flex items-center justify-between">
                <h4 class="text-sm font-bold text-slate-900">Sections</h4>
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700" @click="addSection">
                  + Add Section
                </button>
              </div>

              <div class="mt-3 space-y-3">
                <div v-for="(section, index) in customSections" :key="index" class="rounded-md border border-slate-200 p-3">
                  <div class="flex items-start gap-2">
                    <div class="flex flex-col gap-1 pt-1">
                      <button type="button" class="text-xs text-slate-400 hover:text-slate-700" :disabled="index === 0" @click="moveSection(index, -1)">▲</button>
                      <button type="button" class="text-xs text-slate-400 hover:text-slate-700" :disabled="index === customSections.length - 1" @click="moveSection(index, 1)">▼</button>
                    </div>

                    <div class="flex-1 space-y-2">
                      <input
                        v-model="section.label"
                        type="text"
                        placeholder="Label (e.g. Skills Applied)"
                        class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                        @input="onLabelInput(index)"
                      />
                      <input v-model="section.prompt" type="text" placeholder="Prompt shown to the student (optional)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                      <label class="flex items-center gap-1.5 text-xs font-semibold text-slate-600">
                        <input v-model="section.required" type="checkbox" />
                        Always-on required field
                      </label>
                    </div>

                    <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-800" @click="removeSection(index)">
                      Remove
                    </button>
                  </div>
                </div>

                <p v-if="customSections.length === 0" class="text-sm text-slate-400">No additional sections yet. Daily Accomplishment (above) is already required on every entry.</p>
              </div>

              <!-- SIPP (Annex C) — one checkbox toggles the fixed trio as a group. -->
              <div class="mt-4 rounded-md border border-slate-300 p-3">
                <label class="flex items-center gap-2 text-sm font-semibold text-slate-800">
                  <input v-model="sippEnabled" type="checkbox" />
                  Include SIPP Report (Annex C)
                </label>
                <p class="mt-1 text-xs text-slate-400">Adds the three fixed compliance fields below to this template's daily journal.</p>

                <div v-if="sippEnabled" class="mt-3 space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                  <div v-for="key in (Object.keys(SIPP_LABELS) as SippKey[])" :key="key">
                    <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ SIPP_LABELS[key] }}</p>
                    <input
                      v-model="sippPrompts[key]"
                      type="text"
                      placeholder="Prompt shown to the student"
                      class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                    />
                  </div>
                </div>
              </div>

              <ul class="mt-3 space-y-1 text-xs text-red-600">
                <li v-if="duplicateKeys.length > 0">Section labels must be unique (they generate the same underlying key).</li>
                <li v-if="hasEmptyFields">Every section needs a label.</li>
                <li v-if="form.program_ids.length === 0">Select at least one program for this template.</li>
              </ul>
            </div>

            <div v-if="Object.keys(modalErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
              <p v-for="(messages, field) in modalErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
            </div>
            <p v-if="modalMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalMessage }}</p>
          </div>

          <aside class="rounded-lg border border-slate-200 bg-slate-50 p-4">
            <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">Student Preview</h4>
            <p class="mt-1 text-xs text-slate-400">How this template will render on the daily journal.</p>

            <div class="mt-4 space-y-3">
              <div class="rounded-md bg-white p-3 ring-1 ring-slate-300">
                <div class="flex items-center justify-between gap-2">
                  <p class="text-sm font-semibold text-slate-900">{{ FIXED_SECTION.label }}</p>
                  <span class="text-xs font-semibold text-red-500">Always shown</span>
                </div>
                <p class="mt-1 text-xs text-slate-400">{{ FIXED_SECTION.prompt }}</p>
              </div>

              <div v-for="(section, index) in customSections" :key="index" class="rounded-md bg-white p-3 ring-1 ring-slate-200">
                <div class="flex items-center justify-between gap-2">
                  <p class="text-sm font-semibold text-slate-900">{{ section.label || '(untitled section)' }}</p>
                  <span v-if="section.required" class="text-xs font-semibold text-red-500">Always shown</span>
                  <label v-else class="flex items-center gap-1 text-xs text-slate-400">
                    <input type="checkbox" disabled />
                    Optional checkbox
                  </label>
                </div>
                <p v-if="section.prompt" class="mt-1 text-xs text-slate-400">{{ section.prompt }}</p>
              </div>

              <div v-if="sippEnabled" class="rounded-md border border-slate-300 bg-white p-3">
                <p class="text-xs font-bold uppercase tracking-wide text-slate-500">SIPP Report (Annex C)</p>
                <div class="mt-2 space-y-2">
                  <div v-for="key in (Object.keys(SIPP_LABELS) as SippKey[])" :key="key" class="rounded-md bg-slate-50 p-2 ring-1 ring-slate-200">
                    <p class="text-sm font-semibold text-slate-900">{{ SIPP_LABELS[key] }}</p>
                    <p class="mt-0.5 text-xs text-slate-400">{{ sippPrompts[key] }}</p>
                  </div>
                </div>
              </div>
            </div>

            <p class="mt-4 text-xs font-semibold text-slate-500">Character limit: {{ form.char_limit }}</p>
          </aside>
        </div>

        <div v-if="pendingRemovedKeys.length > 0" class="shrink-0 border-t border-amber-200 bg-amber-50 px-6 py-4 text-sm text-amber-900">
          <p>
            You're removing field(s) <strong>{{ pendingRemovedKeys.join(', ') }}</strong>. If students already filled these in,
            that data will be preserved but will stop appearing in the editor. Continue?
          </p>
          <div class="mt-3 flex justify-end gap-3">
            <button type="button" class="rounded-md border border-amber-400 px-3 py-1.5 text-xs font-semibold text-amber-900" @click="cancelRemovalConfirm">
              Go Back
            </button>
            <button type="button" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white" @click="performSave">
              Confirm Removal &amp; Save
            </button>
          </div>
        </div>

        <div v-else class="flex shrink-0 justify-end gap-3 border-t border-slate-100 px-6 py-4">
          <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700" @click="closeModal">
            Cancel
          </button>
          <button
            type="button"
            class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:bg-slate-400"
            :disabled="isSaving || !canSave"
            @click="save"
          >
            {{ isSaving ? 'Saving...' : 'Save' }}
          </button>
        </div>
      </section>
    </div>
  </section>
</template>
