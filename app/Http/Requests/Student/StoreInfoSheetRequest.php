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
            'personal_info.parent_guardian_name' => ['nullable', 'string', 'max:150'],
            'personal_info.date_of_birth' => ['nullable', 'date'],
            'personal_info.sex' => ['nullable', 'in:male,female'],
            'personal_info.home_address' => ['nullable', 'string', 'max:255'],
            'personal_info.contact_number' => ['nullable', 'string', 'max:30'],
            'personal_info.email' => ['nullable', 'email', 'max:255'],
            'personal_info.student_id_number' => ['nullable', 'string', 'max:30'],

            'academic_info' => ['required', 'array'],
            'academic_info.program_course' => ['nullable', 'string', 'max:255'],
            'academic_info.year_level' => ['nullable', 'string', 'max:20'],
            'academic_info.department' => ['nullable', 'string', 'max:150'],
            'academic_info.internship_coordinator' => ['nullable', 'string', 'max:150'],
            'academic_info.coordinator_contact_no' => ['nullable', 'string', 'max:30'],

            'ojt_info' => ['required', 'array'],
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
}
