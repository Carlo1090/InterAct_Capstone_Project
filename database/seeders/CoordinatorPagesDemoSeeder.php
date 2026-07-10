<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Extra demo data so the newly-wired coordinator pages (dashboard, journal
 * activities, partner companies, info sheets) are non-empty in the demo
 * coordinator's (BSIT / CAST) scope. Fully re-runnable.
 */
class CoordinatorPagesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $coordinator = User::where('email', 'mdccore@gmail.com')->first();
        $program = Program::where('code', 'BSIT')->first();
        $batch = Batch::where('name', 'Program 2025-A')->first();
        $primarySupervisor = User::where('email', 'mdcsupervisor@gmail.com')->first();

        if (! $coordinator || ! $program || ! $batch || ! $primarySupervisor) {
            return;
        }

        // A second partner company + its supervisor, so the companies page and
        // supervisor panel show more than one placement.
        $princeRetail = Company::firstOrCreate(
            ['name' => 'Prince Retail Group'],
            [
                'address' => 'CPG Avenue, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Retail',
                'head_name' => 'Ms. Hazel Empleo',
                'department_head' => 'Ms. Rowena Lim',
                'description' => 'Merchandising and store administration placements.',
                'is_active' => true,
            ]
        );

        $secondarySupervisor = User::updateOrCreate(
            ['email' => 'mdcsupervisor2@gmail.com'],
            [
                'name' => 'Ms. Hazel Empleo',
                'password' => Hash::make('password'),
                'role' => 'supervisor',
                'is_active' => true,
            ]
        );

        CompanySupervisor::firstOrCreate(
            ['company_id' => $princeRetail->id, 'user_id' => $secondarySupervisor->id],
            ['position' => 'Store Operations Supervisor']
        );

        $techph = Company::where('name', 'TechPH Inc.')->first();

        $extraStudents = [
            [
                'email' => 'mdcstudent2@gmail.com',
                'name' => 'Maria Santos',
                'student_id_number' => '2021-IT-002',
                'sex' => 'female',
                'company' => $techph,
                'supervisor' => $primarySupervisor,
                // A missing daily journal this week => "student behind".
                'week_status' => 'missing',
            ],
            [
                'email' => 'mdcstudent3@gmail.com',
                'name' => 'Jose Ramirez',
                'student_id_number' => '2021-IT-003',
                'sex' => 'male',
                'company' => $princeRetail,
                'supervisor' => $secondarySupervisor,
                'week_status' => 'overdue',
            ],
        ];

        foreach ($extraStudents as $data) {
            $student = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'role' => 'student',
                    'student_id_number' => $data['student_id_number'],
                    'program_id' => $program->id,
                    'is_active' => true,
                ]
            );

            StudentProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'student_id_number' => $data['student_id_number'],
                    'sex' => $data['sex'],
                    'year_level' => '4th Year',
                    'total_hours_required' => 486,
                ]
            );

            $company = $data['company'] ?? $techph;

            BatchStudent::firstOrCreate(
                ['batch_id' => $batch->id, 'student_id' => $student->id],
                [
                    'company_id' => $company?->id,
                    'supervisor_id' => $data['supervisor']->id,
                    'assigned_division' => 'Operations',
                    'status' => 'active',
                ]
            );

            // A missing/overdue daily entry dated this week (Mon–today).
            JournalEntry::updateOrCreate(
                ['student_id' => $student->id, 'entry_date' => now()->startOfWeek()->addDay()->toDateString()],
                [
                    'batch_id' => $batch->id,
                    'content' => ['task_performed' => ''],
                    'status' => $data['week_status'],
                ]
            );

            StudentInformationSheet::firstOrCreate(
                ['student_id' => $student->id, 'batch_id' => $batch->id],
                [
                    'personal_info' => [
                        'last_name' => 'Santos',
                        'first_name' => $data['name'],
                        'sex' => $data['sex'],
                        'email' => $student->email,
                        'student_id_number' => $data['student_id_number'],
                    ],
                    'academic_info' => [
                        'program_course' => $program->name,
                        'year_level' => '4th Year',
                        'department' => $program->department?->name,
                        'internship_coordinator' => $coordinator->name,
                    ],
                    'ojt_info' => [
                        'host_company' => $company?->name,
                        'company_address' => $company?->address,
                        'supervisor_name' => $data['supervisor']->name,
                    ],
                    'submission_status' => 'submitted',
                    'submitted_at' => now(),
                ]
            );
        }

        // A submitted daily entry this week for the original demo student, so
        // the "submitted this week" stat is non-zero.
        $primaryStudent = User::where('email', 'mdcstudent@gmail.com')->first();
        if ($primaryStudent) {
            JournalEntry::updateOrCreate(
                ['student_id' => $primaryStudent->id, 'entry_date' => now()->toDateString()],
                [
                    'batch_id' => $batch->id,
                    'content' => ['task_performed' => 'Completed sprint tasks and stand-up notes.'],
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]
            );
        }
    }
}
