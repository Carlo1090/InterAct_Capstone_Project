<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import api from '@/lib/axios'
import { showToast, confirmAction } from '@/lib/toast'
import ToastHost from '@/components/ToastHost.vue'
import type { JournalTemplateRecord, JournalTemplateSection, Program } from '@/types/api'

type TemplateForm = {
  program_id: number | null
  name: string
  char_limit: number
  is_active: boolean
  sections: JournalTemplateSection[]
}

const templates = ref<JournalTemplateRecord[]>([])
const programs = ref<Program[]>([])
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
  program_id: null,
  name: '',
  char_limit: 1500,
  is_active: true,
  sections: [],
})

const autoKeyEnabled = reactive<boolean[]>([])

const slugify = (label: string): string =>
  label
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .replace(/^(\d)/, '_$1')

const load = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const { data } = await api.get<{ templates: JournalTemplateRecord[]; programs: Program[] }>(
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
  form.program_id = programs.value[0]?.id ?? null
  form.name = ''
  form.char_limit = 1500
  form.is_active = true
  form.sections = [{ key: 'task_performed', label: 'Task Performed', prompt: '', required: true, sipp: false }]
  autoKeyEnabled.splice(0, autoKeyEnabled.length, false)
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
  form.program_id = template.program_id
  form.name = template.name
  form.char_limit = template.char_limit
  form.is_active = template.is_active
  form.sections = template.sections.map((section) => ({ ...section }))
  autoKeyEnabled.splice(0, autoKeyEnabled.length, ...form.sections.map(() => false))
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
  form.sections.push({ key: '', label: '', prompt: '', required: false, sipp: false })
  autoKeyEnabled.push(true)
}

const removeSection = (index: number) => {
  form.sections.splice(index, 1)
  autoKeyEnabled.splice(index, 1)
}

const moveSection = (index: number, direction: -1 | 1) => {
  const target = index + direction
  if (target < 0 || target >= form.sections.length) return

  const [section] = form.sections.splice(index, 1)
  form.sections.splice(target, 0, section)

  const [flag] = autoKeyEnabled.splice(index, 1)
  autoKeyEnabled.splice(target, 0, flag)
}

const onLabelInput = (index: number) => {
  if (autoKeyEnabled[index]) {
    form.sections[index].key = slugify(form.sections[index].label)
  }
}

const onKeyInput = (index: number) => {
  autoKeyEnabled[index] = false
}

const hasRequiredSection = computed(() => form.sections.some((section) => section.required))
const duplicateKeys = computed(() => {
  const keys = form.sections.map((section) => section.key).filter(Boolean)
  return keys.filter((key, index) => keys.indexOf(key) !== index)
})
const hasEmptyFields = computed(() => form.sections.some((section) => !section.key || !section.label))
const canSave = computed(
  () => form.sections.length > 0 && hasRequiredSection.value && duplicateKeys.value.length === 0 && !hasEmptyFields.value,
)

const removedKeysIfSaved = computed(() => {
  if (!editingTemplateId.value) return []
  const currentKeys = form.sections.map((section) => section.key)
  return originalKeys.value.filter((key) => !currentKeys.includes(key))
})

const performSave = async () => {
  isSaving.value = true
  modalErrors.value = {}
  modalMessage.value = ''

  const payload = {
    program_id: form.program_id,
    name: form.name,
    char_limit: form.char_limit,
    is_active: form.is_active,
    sections: form.sections,
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
      modalMessage.value = 'Please fix the errors below.'
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
  if (template.is_active && !confirmAction(`Deactivate the "${template.name}" template?`)) return

  try {
    await api.patch(`/api/coordinator/journal-templates/${template.id}/toggle-active`)
    await load()
    showToast(template.is_active ? 'Template deactivated.' : 'Template activated.')
  } catch {
    errorMessage.value = 'Unable to update template status.'
  }
}

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
        class="rounded-md bg-slate-950 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
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
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Program</th>
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
            <td class="px-4 py-3 text-sm text-slate-700">{{ template.program?.name ?? '—' }}</td>
            <td class="px-4 py-3 text-sm text-slate-700">{{ template.sections.length }}</td>
            <td class="px-4 py-3 font-mono text-sm text-slate-700">{{ template.char_limit }}</td>
            <td class="px-4 py-3 text-sm">
              <span
                class="rounded-full px-3 py-1 text-xs font-bold"
                :class="template.is_active ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'"
              >
                {{ template.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="px-4 py-3 text-sm">
              <div class="flex gap-2">
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="openEditModal(template)">
                  Edit
                </button>
                <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700" @click="toggleActive(template)">
                  {{ template.is_active ? 'Deactivate' : 'Activate' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-950/50 px-4 py-8">
      <section class="grid w-full max-w-5xl gap-6 rounded-lg bg-white p-6 shadow-xl lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <div>
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-slate-950">{{ editingTemplateId ? 'Edit Template' : 'Create Template' }}</h3>
            <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-900" @click="closeModal">Cancel</button>
          </div>

          <div class="mt-5 grid gap-4 md:grid-cols-2">
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="template-name">Name</label>
              <input id="template-name" v-model="form.name" type="text" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="template-program">Program</label>
              <select id="template-program" v-model.number="form.program_id" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <option v-for="program in programs" :key="program.id" :value="program.id">{{ program.name }}</option>
              </select>
            </div>
            <div>
              <label class="mb-2 block text-sm font-medium text-slate-700" for="template-char-limit">Character Limit</label>
              <input id="template-char-limit" v-model.number="form.char_limit" type="number" min="100" max="10000" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </div>
            <label class="mt-7 flex items-center gap-2 text-sm font-medium text-slate-700">
              <input v-model="form.is_active" type="checkbox" />
              Active
            </label>
          </div>

          <div class="mt-6">
            <div class="flex items-center justify-between">
              <h4 class="text-sm font-bold text-slate-900">Sections</h4>
              <button type="button" class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700" @click="addSection">
                + Add Section
              </button>
            </div>

            <div class="mt-3 space-y-3">
              <div v-for="(section, index) in form.sections" :key="index" class="rounded-md border border-slate-200 p-3">
                <div class="flex items-start gap-2">
                  <div class="flex flex-col gap-1 pt-1">
                    <button type="button" class="text-xs text-slate-400 hover:text-slate-700" :disabled="index === 0" @click="moveSection(index, -1)">▲</button>
                    <button type="button" class="text-xs text-slate-400 hover:text-slate-700" :disabled="index === form.sections.length - 1" @click="moveSection(index, 1)">▼</button>
                  </div>

                  <div class="flex-1 space-y-2">
                    <div class="grid gap-2 md:grid-cols-2">
                      <input
                        v-model="section.label"
                        type="text"
                        placeholder="Label (e.g. Skills Applied)"
                        class="rounded-md border border-slate-300 px-3 py-2 text-sm"
                        @input="onLabelInput(index)"
                      />
                      <input
                        v-model="section.key"
                        type="text"
                        placeholder="key_in_snake_case"
                        class="rounded-md border border-slate-300 px-3 py-2 font-mono text-sm"
                        @input="onKeyInput(index)"
                      />
                    </div>
                    <input v-model="section.prompt" type="text" placeholder="Prompt shown to the student (optional)" class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                    <div class="flex flex-wrap gap-4 text-xs font-semibold text-slate-600">
                      <label class="flex items-center gap-1.5">
                        <input v-model="section.required" type="checkbox" />
                        Always-on required field
                      </label>
                      <label class="flex items-center gap-1.5">
                        <input v-model="section.sipp" type="checkbox" />
                        SIPP-flagged (ANNEX C)
                      </label>
                    </div>
                  </div>

                  <button type="button" class="text-xs font-semibold text-red-600 hover:text-red-800" @click="removeSection(index)">
                    Remove
                  </button>
                </div>
              </div>

              <p v-if="form.sections.length === 0" class="text-sm text-slate-400">No sections yet — add at least one required section.</p>
            </div>

            <ul class="mt-3 space-y-1 text-xs text-red-600">
              <li v-if="!hasRequiredSection">At least one section must be marked as an always-on required field.</li>
              <li v-if="duplicateKeys.length > 0">Section keys must be unique (duplicate: {{ [...new Set(duplicateKeys)].join(', ') }}).</li>
              <li v-if="hasEmptyFields">Every section needs both a label and a key.</li>
            </ul>
          </div>

          <div v-if="Object.keys(modalErrors).length > 0" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
            <p v-for="(messages, field) in modalErrors" :key="field">{{ field }}: {{ messages.join(' ') }}</p>
          </div>
          <p v-if="modalMessage" class="mt-4 rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{{ modalMessage }}</p>

          <div v-if="pendingRemovedKeys.length > 0" class="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
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

          <div v-else class="mt-6 flex justify-end gap-3">
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
        </div>

        <aside class="rounded-lg border border-slate-200 bg-slate-50 p-4">
          <h4 class="text-xs font-bold uppercase tracking-wide text-slate-500">Student Preview</h4>
          <p class="mt-1 text-xs text-slate-400">How this template will render on the daily journal.</p>

          <div class="mt-4 space-y-3">
            <div v-for="(section, index) in form.sections" :key="index" class="rounded-md bg-white p-3 ring-1 ring-slate-200">
              <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-semibold text-slate-900">
                  {{ section.label || '(untitled section)' }}
                  <span v-if="section.sipp" class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">SIPP</span>
                </p>
                <span v-if="section.required" class="text-xs font-semibold text-red-500">Always shown</span>
                <label v-else class="flex items-center gap-1 text-xs text-slate-400">
                  <input type="checkbox" disabled />
                  Optional checkbox
                </label>
              </div>
              <p v-if="section.prompt" class="mt-1 text-xs text-slate-400">{{ section.prompt }}</p>
            </div>
          </div>

          <p class="mt-4 text-xs font-semibold text-slate-500">Character limit: {{ form.char_limit }}</p>
        </aside>
      </section>
    </div>
  </section>
</template>
