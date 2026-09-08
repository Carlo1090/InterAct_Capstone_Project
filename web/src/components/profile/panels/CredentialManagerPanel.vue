<script setup lang="ts">
/**
 * Credential Manager — the coordinator's answer to "I can't sign in".
 *
 * This REPLACES the per-row "Resend" button that used to sit on
 * Users → Interns, between "View" and "Delete". Reissuing a password is
 * critical and irreversible (the account's current password stops working the
 * instant it fires), so it does not belong on a row being casually browsed —
 * and it is never something a coordinator does while scanning a roster, but
 * something they do for one named person who has asked for help. A searched-for
 * destination matches that, and it can cover SUPERVISORS too, which a button on
 * the Interns tab structurally could not.
 *
 * SECURITY: the temporary password is held in a plain ref and shown once. It is
 * deliberately NOT run through useFormDraft/sessionStorage — same rule the bulk
 * import's one-time credentials table follows (lib/formDraft.ts: never persist
 * a credential). Closing the panel loses it, which is correct.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import api from '@/lib/axios'
import { confirmAction } from '@/lib/toast'
import { categorizeError } from '@/lib/apiError'
import type { CredentialAccount, CredentialIssueResult } from '@/types/api'

type RoleFilter = '' | 'student' | 'supervisor'

const accounts = ref<CredentialAccount[]>([])
const total = ref(0)
const limit = ref(0)
const isLoading = ref(true)
const errorMessage = ref('')

const search = ref('')
const roleFilter = ref<RoleFilter>('')

// The issued credential, shown once and never persisted.
const issued = ref<CredentialIssueResult | null>(null)
const issuingId = ref<number | null>(null)
const copied = ref(false)
let copiedTimer: ReturnType<typeof setTimeout> | null = null

const hiddenCount = computed(() => Math.max(0, total.value - accounts.value.length))

const load = async () => {
  errorMessage.value = ''

  try {
    const { data } = await api.get<{ accounts: CredentialAccount[]; total: number; limit: number }>(
      '/api/coordinator/credentials',
      { params: { search: search.value || undefined, role: roleFilter.value || undefined } },
    )
    accounts.value = data.accounts
    total.value = data.total
    limit.value = data.limit
  } catch (error) {
    const { message } = categorizeError(error, 'Unable to load your accounts.')
    errorMessage.value = message
  } finally {
    isLoading.value = false
  }
}

// Typing re-queries the server, so it is debounced; the role chips are a single
// deliberate click and fire immediately.
let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(search, () => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 300)
})

watch(roleFilter, () => {
  load()
})

const roleLabel = (role: CredentialAccount['role']) => (role === 'student' ? 'Intern' : 'Supervisor')

const issue = async (account: CredentialAccount) => {
  const destination = account.email
    ? `The new password is emailed to ${account.email} and also shown here for you to read out.`
    : 'This account has no email address on file, so the new password is shown here for you to hand over yourself.'

  const proceed = await confirmAction({
    title: `Issue a temporary password for ${account.name}?`,
    message: `Their current password stops working immediately and they must set a new one at their next sign-in.\n\n${destination}`,
    confirmLabel: 'Issue Password',
    tone: 'danger',
  })
  if (!proceed) return

  issuingId.value = account.id
  errorMessage.value = ''

  try {
    const { data } = await api.post<CredentialIssueResult>(`/api/coordinator/credentials/${account.id}/issue`)
    issued.value = data
    copied.value = false
  } catch (error) {
    const { message } = categorizeError(error, 'Unable to issue a temporary password.')
    errorMessage.value = message
  } finally {
    issuingId.value = null
  }
}

const copyPassword = async () => {
  if (!issued.value) return

  try {
    await navigator.clipboard.writeText(issued.value.temporary_password)
    copied.value = true
    if (copiedTimer !== null) clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => {
      copied.value = false
    }, 1500)
  } catch {
    // Clipboard is unavailable (insecure origin or denied permission) — the
    // password is on screen and selectable, so this needs no error banner.
  }
}

const dismissIssued = () => {
  issued.value = null
  copied.value = false
}

onMounted(load)

onBeforeUnmount(() => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  if (copiedTimer !== null) clearTimeout(copiedTimer)
})
</script>

<template>
  <div>
    <!--
      The issued credential takes over the panel: it is the one thing the
      coordinator came here for, and a row that scrolls out of view takes the
      password with it. It is shown until dismissed, and cannot be recovered.
    -->
    <div v-if="issued" class="px-4 py-4">
      <div class="rounded-lg bg-amber-50/60 p-4 ring-1 ring-amber-200">
        <p class="text-xs font-medium uppercase tracking-wide text-amber-700">Temporary password issued</p>
        <p class="mt-1 text-sm font-semibold text-slate-900">{{ issued.name }}</p>
        <p class="font-mono text-xs text-slate-500">{{ issued.username }}</p>

        <div class="mt-3 rounded-md bg-white px-3 py-2 ring-1 ring-slate-200">
          <p class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Password</p>
          <p class="mt-0.5 font-mono text-base break-all text-slate-900 select-all">{{ issued.temporary_password }}</p>
        </div>

        <p
          class="mt-3 text-xs"
          :class="issued.emailed === false ? 'font-semibold text-rose-700' : 'text-slate-600'"
        >
          <template v-if="issued.emailed === true">
            Emailed to {{ issued.email }}. If it never arrives, read the password above out instead.
          </template>
          <template v-else-if="issued.emailed === false">
            Email delivery failed — this password is the only copy, so hand it over now.
          </template>
          <template v-else>
            No email address on file for this account, so nothing was sent. Hand this password over directly.
          </template>
        </p>

        <p class="mt-2 text-xs text-slate-500">
          They will be asked to set their own password the moment they sign in. This is shown once — it cannot be
          retrieved after you close this.
        </p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-white"
            @click="copyPassword"
          >
            {{ copied ? 'Copied' : 'Copy password' }}
          </button>
          <button
            type="button"
            class="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-700"
            @click="dismissIssued"
          >
            Done
          </button>
        </div>
      </div>
    </div>

    <template v-else>
      <div class="space-y-3 border-b border-slate-100 px-4 py-3">
        <p class="text-xs text-slate-500">
          Issue a new temporary password to one of your interns or company supervisors when they cannot sign in.
        </p>

        <label class="block">
          <span class="sr-only">Search accounts</span>
          <input
            v-model="search"
            type="search"
            placeholder="Search by name, username, ID or email"
            class="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
          />
        </label>

        <div class="flex flex-wrap gap-2">
          <button
            v-for="option in [
              { value: '' as RoleFilter, label: 'All' },
              { value: 'student' as RoleFilter, label: 'Interns' },
              { value: 'supervisor' as RoleFilter, label: 'Supervisors' },
            ]"
            :key="option.label"
            type="button"
            class="rounded-full px-3 py-1 text-xs font-semibold transition"
            :class="
              roleFilter === option.value
                ? 'bg-blue-600 text-white'
                : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
            "
            @click="roleFilter = option.value"
          >
            {{ option.label }}
          </button>
        </div>
      </div>

      <p v-if="isLoading" class="px-4 py-6 text-center text-sm text-slate-500">Loading...</p>
      <p v-else-if="errorMessage" class="px-4 py-6 text-center text-sm text-red-700">{{ errorMessage }}</p>

      <ul v-else class="divide-y divide-slate-100">
        <li v-if="accounts.length === 0" class="px-4 py-6 text-center text-sm text-slate-500">
          {{ search ? 'No accounts match that search.' : 'No intern or supervisor accounts in your scope yet.' }}
        </li>

        <li v-for="account in accounts" :key="`${account.role}-${account.id}`" class="px-4 py-3">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-slate-900">{{ account.name }}</p>
              <p class="truncate font-mono text-xs text-slate-400">{{ account.username }}</p>
            </div>
            <span
              class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide"
              :class="account.role === 'student' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700'"
            >
              {{ roleLabel(account.role) }}
            </span>
          </div>

          <p v-if="account.context" class="mt-1 truncate text-xs text-slate-500">{{ account.context }}</p>
          <p class="mt-0.5 truncate text-xs" :class="account.email ? 'text-slate-500' : 'text-amber-700'">
            {{ account.email ?? 'No email on file — password shown on screen only' }}
          </p>

          <button
            type="button"
            class="mt-2 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="issuingId === account.id"
            @click="issue(account)"
          >
            {{ issuingId === account.id ? 'Issuing...' : 'Issue temporary password' }}
          </button>
        </li>

        <li v-if="hiddenCount > 0" class="px-4 py-3 text-center text-xs text-slate-500">
          Showing the first {{ limit }} of {{ total }} accounts — search to narrow this down.
        </li>
      </ul>
    </template>
  </div>
</template>
