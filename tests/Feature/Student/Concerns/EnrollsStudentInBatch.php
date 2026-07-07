<?php

namespace Tests\Feature\Student\Concerns;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Department;
use App\Models\JournalTemplate;
use App\Models\Program;
use App\Models\User;

trait EnrollsStudentInBatch
{
    protected function enrolledStudent(array $batchOverrides = []): User
    {
        $department = Department::firstOrCreate(
            ['code' => 'CAST'],
            ['name' => 'College of Arts, Sciences and Technology', 'is_active' => true]
        );
        $program = Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => 'BSIT'],
            ['name' => 'BS Information Technology', 'is_active' => true]
        );
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $company = Company::create(['name' => 'TechPH Inc. '.uniqid(), 'address' => 'Cebu City', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student', 'program_id' => $program->id]);

        $template = JournalTemplate::create([
            'program_id' => $program->id,
            'name' => 'BSIT Daily Journal Template '.uniqid(),
            'sections' => [
                ['label' => 'Tasks Performed', 'prompt' => 'Describe the tasks.'],
            ],
            'is_active' => true,
        ]);

        $batch = Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => 'Program 2025-A '.uniqid(),
            'start_date' => now()->subMonth(),
            'end_date' => now()->addMonth(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'daily_reminder_time' => '21:00:00',
            'journal_template_id' => $template->id,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
            ...$batchOverrides,
        ]);

        BatchStudent::create([
            'batch_id' => $batch->id,
            'student_id' => $student->id,
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ]);

        return $student;
    }
}
