<?php

namespace App\Http\Requests\Student;

use Illuminate\Foundation\Http\FormRequest;

class StoreInfoSheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:draft,submitted'],

            'personal_info' => ['required', 'array'],
            'personal_info.last_name' => ['required', 'string', 'max:100'],
            'personal_info.first_name' => ['required', 'string', 'max:100'],
            'personal_info.middle_name' => ['nullable', 'string', 'max:100'],
            // Required only on SUBMIT, so a half-finished draft still saves.
            // The official GROUP information sheet has a dedicated
            // Parent's/Guardian's Name column, and a blank there is a
            // compliance gap the coordinator cannot fill in for them. The
            // contact number stays optional — a student may genuinely have
            // none to give.
            'personal_info.parent_guardian_name' => ['required_if:status,submitted', 'nullable', 'string', 'max:150'],
            'personal_info.parent_guardian_contact' => ['nullable', 'string', 'max:30'],
            'personal_info.date_of_birth' => ['nullable', 'date'],
            'personal_info.sex' => ['nullable', 'in:male,female'],
            'personal_info.home_address' => ['nullable', 'string', 'max:255'],
            'personal_info.contact_number' => ['nullable', 'string', 'max:30'],
            'personal_info.email' => ['nullable', 'email', 'max:255'],
            'personal_info.student_id_number' => ['nullable', 'string', 'max:30'],

            'academic_info' => ['present', 'array'],
            'academic_info.program_course' => ['nullable', 'string', 'max:255'],
            'academic_info.year_level' => ['nullable', 'in:1st-year,2nd-year,3rd-year,4th-year'],
            'academic_info.department' => ['nullable', 'string', 'max:150'],
            'academic_info.internship_coordinator' => ['nullable', 'string', 'max:150'],
            'academic_info.coordinator_contact_no' => ['nullable', 'string', 'max:30'],

            'ojt_info' => ['present', 'array'],
            // The one constrained field: the chosen company is picked from the
            // coordinator-curated dropdown. company_id drives the Accept step;
            // host_company keeps the name for display/PDF.
            'ojt_info.company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'ojt_info.host_company' => ['nullable', 'string', 'max:200'],
            'ojt_info.company_address' => ['nullable', 'string', 'max:255'],
            'ojt_info.company_signatory_moa' => ['nullable', 'string', 'max:150'],
            'ojt_info.office_designation' => ['nullable', 'string', 'max:150'],
            'ojt_info.supervisor_name' => ['nullable', 'string', 'max:150'],
            'ojt_info.supervisor_contact' => ['nullable', 'string', 'max:30'],
            'ojt_info.area_assigned' => ['nullable', 'string', 'max:150'],
            'ojt_info.division_assigned' => ['nullable', 'string', 'max:150'],
            'ojt_info.intern_duty_schedule' => ['nullable', 'string', 'max:150'],
            'ojt_info.ojt_start_date' => ['nullable', 'date'],
            'ojt_info.ojt_end_date' => ['nullable', 'date', 'after_or_equal:ojt_info.ojt_start_date'],

            'emergency_contact' => ['nullable', 'array'],
        ];
    }

    /**
     * Friendly field names so a validation message reads "The Family Name field
     * is required." instead of the raw dotted key "The personal info.last name
     * field is required." (The frontend also shows a generic banner + inline
     * field highlighting, but these keep any server-surfaced message readable.)
     */
    public function attributes(): array
    {
        return [
            'personal_info.last_name' => 'Family Name',
            'personal_info.first_name' => 'First Name',
            'personal_info.middle_name' => 'Middle Name',
            'personal_info.parent_guardian_name' => "Parent's / Guardian's Name",
            'personal_info.parent_guardian_contact' => "Parent's / Guardian's Contact No.",
            'personal_info.contact_number' => 'Contact No.',
            'personal_info.email' => 'Email',
            'personal_info.student_id_number' => 'Student ID Number',
            'academic_info.year_level' => 'Year',
            'ojt_info.company_id' => 'Name of Company',
            'ojt_info.host_company' => 'Name of Company',
            'ojt_info.company_address' => 'Company Address',
            'ojt_info.company_signatory_moa' => 'Company Signatory (MOA)',
            'ojt_info.office_designation' => 'Office Designation / Position',
            'ojt_info.supervisor_name' => 'Name of Supervisor / Office Head',
            'ojt_info.supervisor_contact' => 'Supervisor Contact Number',
            'ojt_info.area_assigned' => 'Area Assigned',
            'ojt_info.intern_duty_schedule' => "Intern's Duty Schedule",
            'ojt_info.ojt_start_date' => 'Start of Internship Duty',
            'ojt_info.ojt_end_date' => 'Estimated Date to Finish Internship',
        ];
    }
}
