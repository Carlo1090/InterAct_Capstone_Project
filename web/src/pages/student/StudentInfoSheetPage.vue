<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const nameParts = computed(() => (auth.user?.name ?? 'Juan Dela Cruz').split(' '))
const firstName = computed(() => nameParts.value[0] ?? 'Juan')
const lastName = computed(() => nameParts.value.slice(1).join(' ') || 'Dela Cruz')
</script>

<template>
  <section class="space-y-5">
    <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
      Please complete all required fields. This form is part of your SIPP compliance documentation.
    </div>

    <form class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
      <div class="rounded-lg bg-slate-50 p-5">
        <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700">I. Personal Information</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">
            Last Name
            <input :value="lastName" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            First Name
            <input :value="firstName" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Middle Name
            <input value="Reyes" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Student ID Number
            <input value="2021-IT-001" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Date of Birth
            <input type="date" value="2002-05-12" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Sex
            <select class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option>Male</option>
              <option>Female</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-slate-700 md:col-span-2">
            Home Address
            <input value="Purok 4, Barangay Mabini, Tubigon, Bohol" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Contact Number
            <input value="09171234567" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Email Address
            <input :value="auth.user?.email ?? 'juan.delacruz@student.mdc.edu.ph'" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
        </div>
      </div>

      <div class="mt-5 rounded-lg bg-slate-50 p-5">
        <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700">II. Academic Information</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">
            Program / Course
            <input value="Bachelor of Science in Information Technology" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Year Level
            <select class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
              <option>4th Year</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Department
            <input value="Information Technology" readonly class="mt-2 w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            OJT Coordinator
            <input value="Ma. Teresa Reyes" readonly class="mt-2 w-full rounded-md border border-slate-300 bg-slate-100 px-3 py-2 text-sm" />
          </label>
        </div>
      </div>

      <div class="mt-5 rounded-lg bg-slate-50 p-5">
        <h2 class="text-xs font-bold uppercase tracking-wide text-blue-700">III. Internship Assignment</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">
            Host Company
            <input value="TechPH Inc." class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Company Supervisor
            <input value="Engr. Ramon Villanueva" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            Start Date
            <input type="date" value="2025-04-07" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label class="block text-sm font-medium text-slate-700">
            End Date
            <input type="date" value="2025-06-27" class="mt-2 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
          </label>
        </div>
      </div>

      <div class="mt-6 flex justify-end gap-3">
        <button type="button" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Save Draft</button>
        <button type="button" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Submit</button>
      </div>
    </form>
  </section>
</template>
