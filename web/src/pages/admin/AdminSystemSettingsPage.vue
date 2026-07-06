<script setup lang="ts">
// SCAFFOLD ONLY - static mock data, no backend wired up yet (see Phase 3 roadmap)
const systemInfo = [
  ['System Version', 'v1.0.0 (Phase 2)'],
  ['Backend', 'Laravel 13'],
  ['Frontend', 'Vue.js 3'],
  ['Database', 'MySQL'],
  ['Institution', 'Mater Dei College'],
]
</script>

<template>
  <section class="space-y-5">
    <div class="grid gap-5 xl:grid-cols-2">
      <div class="space-y-5">
        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">General Settings</h2>
          <div class="mt-5 space-y-4">
            <label class="block">
              <span class="text-xs font-bold text-slate-600">System Name</span>
              <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="InternTrack" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Institution Name</span>
              <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="Mater Dei College" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Institution Address</span>
              <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" value="Cabulijan, Tubigon, Bohol" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">System Email</span>
              <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" type="email" value="system@interntrack.local" />
            </label>
          </div>
        </div>

        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Notification Settings</h2>
          <div class="mt-5 space-y-4">
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Daily Email Reminder Time</span>
              <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" type="time" value="21:00" />
              <p class="mt-1 text-xs text-slate-500">Interns receive a reminder email if no journal entry has been submitted by this time.</p>
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Reminder Email Subject Template</span>
              <input
                class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                value="Reminder: Submit Your Daily Internship Journal"
              />
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked />
              Enable automated email reminders
            </label>
          </div>
        </div>
      </div>

      <div class="space-y-5">
        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">Journal Settings</h2>
          <div class="mt-5 space-y-4">
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Weekly Compilation Day</span>
              <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                <option>Sunday</option>
                <option>Saturday</option>
                <option>Monday</option>
              </select>
              <p class="mt-1 text-xs text-slate-500">
                Weekly journals are automatically compiled and forwarded to company supervisors on this day.
              </p>
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Minimum Word Count</span>
              <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" type="number" value="250" />
            </label>
            <label class="block">
              <span class="text-xs font-bold text-slate-600">Submission Deadline</span>
              <select class="mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm">
                <option>11:59 PM (Same Day)</option>
                <option>9:00 AM (Next Day)</option>
              </select>
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" />
              Exclude late/overdue entries
            </label>
          </div>
        </div>

        <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 class="text-sm font-bold text-slate-900">System Information</h2>
          <div class="mt-4 divide-y divide-slate-100">
            <div v-for="[label, value] in systemInfo" :key="label" class="flex items-center justify-between py-2">
              <span class="text-sm text-slate-500">{{ label }}</span>
              <span class="text-sm font-semibold text-slate-800">{{ value }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="flex justify-end gap-3">
      <button type="button" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Cancel</button>
      <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700">
        Save Changes
      </button>
    </div>
  </section>
</template>
