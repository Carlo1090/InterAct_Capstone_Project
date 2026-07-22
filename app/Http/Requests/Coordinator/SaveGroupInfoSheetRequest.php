<?php

namespace App\Http\Requests\Coordinator;

use Illuminate\Foundation\Http\FormRequest;

class SaveGroupInfoSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:draft,finalized'],

            // The document's editable header line. Defaults server-side to the
            // coordinator's own department name when left blank — the reference
            // form hardcodes "College of Accountancy, Business and Management",
            // which is wrong for any non-CABM coordinator.
            'department_line' => ['nullable', 'string', 'max:150'],

            // The Internship Company Information block. Typed by the
            // COORDINATOR, never sourced from an individual student's info
            // sheet — several interns at one company each type their own
            // company details, and those disagree.
            'company' => ['present', 'array'],
            'company.host_company' => ['nullable', 'string', 'max:200'],
            'company.company_address' => ['nullable', 'string', 'max:255'],
            'company.company_signatory_moa' => ['nullable', 'string', 'max:150'],
            'company.office_designation' => ['nullable', 'string', 'max:150'],
            'company.supervisor_name' => ['nullable', 'string', 'max:150'],
            'company.supervisor_contact' => ['nullable', 'string', 'max:30'],
            'company.intern_duty_schedule' => ['nullable', 'string', 'max:150'],
            'company.area_assigned' => ['nullable', 'string', 'max:150'],
            'company.ojt_start_date' => ['nullable', 'date'],
            'company.ojt_end_date' => ['nullable', 'date', 'after_or_equal:company.ojt_start_date'],

            // Overrides for auto-populated roster rows (keyed by batch_student id).
            'rows' => ['present', 'array'],
            'rows.*.id' => ['required', 'integer'],
            'rows.*.last_name' => ['nullable', 'string', 'max:100'],
            'rows.*.first_name' => ['nullable', 'string', 'max:100'],
            'rows.*.middle_initial' => ['nullable', 'string', 'max:10'],
            'rows.*.program_year' => ['nullable', 'string', 'max:100'],
            'rows.*.contact_number' => ['nullable', 'string', 'max:30'],
            'rows.*.parent_guardian_name' => ['nullable', 'string', 'max:150'],
            'rows.*.parent_guardian_contact' => ['nullable', 'string', 'max:30'],
            'rows.*.included' => ['required', 'boolean'],

            // Coordinator-added rows for interns with incomplete source data.
            'manual_rows' => ['sometimes', 'array'],
            'manual_rows.*.id' => ['required', 'string', 'max:50'],
            'manual_rows.*.last_name' => ['nullable', 'string', 'max:100'],
            'manual_rows.*.first_name' => ['nullable', 'string', 'max:100'],
            'manual_rows.*.middle_initial' => ['nullable', 'string', 'max:10'],
            'manual_rows.*.program_year' => ['nullable', 'string', 'max:100'],
            'manual_rows.*.contact_number' => ['nullable', 'string', 'max:30'],
            'manual_rows.*.parent_guardian_name' => ['nullable', 'string', 'max:150'],
            'manual_rows.*.parent_guardian_contact' => ['nullable', 'string', 'max:30'],
            'manual_rows.*.included' => ['required', 'boolean'],

            'deleted_ids' => ['sometimes', 'array'],
            'deleted_ids.*' => ['integer'],
        ];
    }

    public function attributes(): array
    {
        return [
            'department_line' => 'Department Name',
            'company.host_company' => 'Name of Company',
            'company.company_address' => 'Company Address',
            'company.company_signatory_moa' => 'Complete Name of Official Company Signatory (for MOA)',
            'company.office_designation' => 'Office Designation / Position',
            'company.supervisor_name' => 'Name of Supervisor / Office Head',
            'company.supervisor_contact' => 'Contact Number',
            'company.intern_duty_schedule' => "Intern's Duty Schedule",
            'company.area_assigned' => 'Area Assigned',
            'company.ojt_start_date' => 'Start of Internship Duty',
            'company.ojt_end_date' => 'Estimated Date to Finish Internship',
        ];
    }
}
