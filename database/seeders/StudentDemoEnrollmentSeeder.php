<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\JournalTemplate;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use Illuminate\Database\Seeder;

class StudentDemoEnrollmentSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::where('email', 'student@interntrack.local')->first();
        $coordinator = User::where('email', 'coordinator@interntrack.local')->first();
        $supervisor = User::where('email', 'supervisor@interntrack.local')->first();
        $company = Company::where('name', 'TechPH Inc.')->first();
        $program = Program::where('code', 'BSIT')->first();

        if (! $student || ! $coordinator || ! $supervisor || ! $company || ! $program) {
            return;
        }

        $startDate = now()->subMonths(2)->startOfDay();
        $endDate = now()->addMonths(2)->startOfDay();

        $template = JournalTemplate::firstOrCreate(
            ['program_id' => $program->id, 'name' => 'BSIT Daily Journal Template'],
            [
                'sections' => [
                    ['key' => 'task_performed', 'label' => 'Task Performed', 'prompt' => 'Describe the specific tasks you completed today.', 'required' => true, 'sipp' => false],
                    ['key' => 'skills_applied', 'label' => 'Skills Applied', 'prompt' => 'What skills or tools did you use or learn?', 'required' => false, 'sipp' => false],
                    ['key' => 'challenges_encountered', 'label' => 'Challenges Encountered', 'prompt' => 'Note any challenges and how you addressed them.', 'required' => false, 'sipp' => false],
                    ['key' => 'issues_concerns', 'label' => 'Problem / Concern', 'prompt' => 'Describe any problem or concern encountered today.', 'required' => false, 'sipp' => true],
                    ['key' => 'solutions', 'label' => 'Solutions', 'prompt' => 'What solutions were applied or proposed?', 'required' => false, 'sipp' => true],
                    ['key' => 'recommendations', 'label' => 'Recommendations', 'prompt' => 'Any recommendations going forward?', 'required' => false, 'sipp' => true],
                ],
                'word_limit' => 500,
                'is_active' => true,
            ]
        );

        $batch = Batch::firstOrCreate(
            ['program_id' => $program->id, 'name' => 'Program 2025-A'],
            [
                'coordinator_id' => $coordinator->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'required_hours' => 486,
                'working_days_per_week' => 5,
                'daily_reminder_time' => '21:00:00',
                'journal_template_id' => $template->id,
                'academic_year' => now()->format('Y'),
                'semester' => 'Internship',
                'is_active' => true,
            ]
        );

        BatchStudent::firstOrCreate(
            ['batch_id' => $batch->id, 'student_id' => $student->id],
            [
                'company_id' => $company->id,
                'supervisor_id' => $supervisor->id,
                'assigned_division' => 'Software Development Team',
                'status' => 'active',
            ]
        );

        $profile = $student->studentProfile;
        [$firstName, $lastName] = array_pad(explode(' ', $student->name, 2), 2, '');

        StudentInformationSheet::firstOrCreate(
            ['student_id' => $student->id, 'batch_id' => $batch->id],
            [
                'personal_info' => [
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'middle_name' => $profile?->middle_name,
                    'date_of_birth' => $profile?->date_of_birth?->toDateString(),
                    'sex' => $profile?->sex,
                    'home_address' => $profile?->home_address,
                    'contact_number' => $profile?->contact_number,
                    'email' => $student->email,
                    'student_id_number' => $student->student_id_number,
                ],
                'academic_info' => [
                    'program_course' => $program->name,
                    'year_level' => $profile?->year_level,
                    'department' => $program->department?->name,
                    'internship_coordinator' => $coordinator->name,
                    'coordinator_contact_no' => null,
                ],
                'ojt_info' => [
                    'host_company' => $company->name,
                    'company_address' => $company->address,
                    'supervisor_name' => $supervisor->name,
                    'supervisor_contact' => null,
                    'area_assigned' => 'Software Development Team',
                    'division_assigned' => 'Software Development Team',
                    'ojt_start_date' => $startDate->toDateString(),
                    'ojt_end_date' => $endDate->toDateString(),
                ],
                'submission_status' => 'submitted',
                'submitted_at' => $startDate,
            ]
        );
    }
}
