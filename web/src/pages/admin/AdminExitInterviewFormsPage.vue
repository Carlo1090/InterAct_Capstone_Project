<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import api from '@/lib/axios'
import LoadStatus from '@/components/LoadStatus.vue'
import type { AdminExitInterviewFormsResponse, ExitInterviewQuestion } from '@/types/api'

/**
 * Admin → Exit Interview: the catalogue of hardcoded exit interview forms.
 *
 * Read-only. Each form is defined in code (app/Support/ExitInterview/Forms/)
 * and a department is pointed at one of them on the Departments page, when it
 * is created or edited. This page exists so the admin can see what each form
 * actually asks, which departments currently use it, and how long it prints
 * — without opening a student's copy.
 */
const isLoading = ref(true)
const loadError = ref('')
const forms = ref<AdminExitInterviewFormsResponse['forms']>([])
const defaultKey = ref('')

/** Which forms are expanded to show their questions. Collapsed by default:
 *  the page is a catalogue first, and a 31-question form is a wall of text. */
const open = reactive(new Set<string>())

const toggle = (key: string) => {
  if (open.has(key)) open.delete(key)
  else open.add(key)
}

const load = async () => {
  isLoading.value = true
  loadError.value = ''

  try {
    const { data } = await api.get<AdminExitInterviewFormsResponse>('/api/admin/exit-interview-forms')
    forms.value = data.forms
    defaultKey.value = data.default
  } catch {
    loadError.value = 'Unable to load the exit interview forms.'
  } finally {
    isLoading.value = false
  }
}

const answerKind = (question: ExitInterviewQuestion): string => {
  if (question.type === 'scale') return 'Rating'
  if (question.type === 'yes_no_text') return 'Yes / No + explanation'
  return 'Written answer'
}

onMounted(load)
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-blue-100 bg-blue-50 px-4 py-3 text-sm text-blue-800">
      These are the exit interview forms built into InternTrack, one per department's official paper form. They
      are fixed — the questions are the department's own — and each department is assigned one of them on the
      <RouterLink to="/admin/departments" class="font-semibold underline">Departments</RouterLink> page. A
      student fills in whichever form their batch's department is assigned; an interview keeps the form it was
      started on even if the assignment later changes.
    </div>

    <LoadStatus :loading="isLoading" :error="loadError" :retry="load">
      <div class="space-y-4">
        <div
          v-for="form in forms"
          :key="form.key"
          class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200"
        >
          <!-- Catalogue row: identity, size, and who uses it -->
          <div class="flex flex-wrap items-start justify-between gap-4 px-5 py-4">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-semibold text-slate-900">{{ form.label }}</h3>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-600">{{ form.key }}</span>
                <span
                  v-if="form.key === defaultKey"
                  class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700"
                >
                  Default
                </span>
              </div>
              <p class="mt-1 text-xs text-slate-500">
                {{ form.college_line }} · {{ form.title }}<template v-if="form.subtitle"> · {{ form.subtitle }}</template>
              </p>
              <dl class="mt-3 grid gap-x-6 gap-y-2 text-xs sm:grid-cols-3">
                <div>
                  <dt class="font-medium uppercase tracking-wide text-slate-400">Questions</dt>
                  <dd class="mt-0.5 text-sm text-slate-800">
                    {{ form.question_count }} in {{ form.sections.length }} sections
                  </dd>
                </div>
                <div>
                  <dt class="font-medium uppercase tracking-wide text-slate-400">Printed length</dt>
                  <dd class="mt-0.5 text-sm text-slate-800">{{ form.pages }} pages, long bond</dd>
                </div>
                <div>
                  <dt class="font-medium uppercase tracking-wide text-slate-400">Reference</dt>
                  <dd class="mt-0.5 truncate text-sm text-slate-800" :title="form.reference">{{ form.reference }}</dd>
                </div>
              </dl>
              <div class="mt-3">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Used by</p>
                <div class="mt-1 flex flex-wrap gap-1.5">
                  <span
                    v-for="department in form.departments"
                    :key="department.id"
                    class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700"
                    :title="department.name"
                  >
                    {{ department.code }}
                  </span>
                  <span v-if="form.departments.length === 0" class="text-xs text-slate-400">
                    No department is assigned this form.
                  </span>
                </div>
              </div>
            </div>

            <button
              type="button"
              class="shrink-0 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
              :aria-expanded="open.has(form.key)"
              @click="toggle(form.key)"
            >
              {{ open.has(form.key) ? 'Hide questions' : 'View questions' }}
            </button>
          </div>

          <!-- The questions, exactly as the student sees and the PDF prints them -->
          <div v-if="open.has(form.key)" class="border-t border-slate-100 px-5 py-4">
            <p class="text-xs text-slate-500">
              <span class="font-semibold text-slate-700">{{ form.student_info_heading }}</span> — name, program,
              company, training period, coordinator, plus the student's typed department/position, total hours
              and date of interview. Identical on every form.
            </p>

            <div v-for="section in form.sections" :key="section.heading" class="mt-4">
              <h4 class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ section.heading }}</h4>
              <ol class="mt-2 divide-y divide-slate-100">
                <li
                  v-for="question in section.questions"
                  :key="question.key"
                  class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2"
                >
                  <p class="min-w-0 flex-1 text-sm text-slate-800">
                    <span class="font-semibold">{{ question.n }}.</span> {{ question.text }}
                    <span v-if="question.label" class="text-slate-500"> — {{ question.label }}</span>
                  </p>
                  <span class="shrink-0 text-xs text-slate-400">
                    {{ answerKind(question) }}
                    <template v-if="question.options">
                      · {{ Object.values(question.options).join(' / ') }}
                    </template>
                  </span>
                </li>
              </ol>
            </div>

            <p class="mt-4 text-xs text-slate-500">
              Then, on the last page: the student's signature line and the
              <span class="font-semibold text-slate-700">Section for OJT/Internship Coordinator</span> — compliance
              verification, pending requirements, remarks, and the coordinator's and dean's signatories. Identical
              on every form.
            </p>
          </div>
        </div>
      </div>
    </LoadStatus>
  </section>
</template>
