<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;


/**
 * Demo data for the STUDENT-driven intake → coordinator Accept flow (CABM-B,
 * under coordinator Balbero). Two NOT-yet-enrolled students, each with a
 * scaffolded info sheet pointing at an in-scope batch but NO batch_students
 * row, so the whole gateway can be walked end to end:
 *
 *   - mdcintake  — DRAFT sheet: log in as the student (gated to only the info
 *     sheet), fill it, choose a company, submit.
 *   - mdcintake2 — SUBMITTED sheet with a chosen company that already has a
 *     supervisor, so it sits in coordinator Balbero's "Submitted" queue ready
 *     to Accept (which enrolls the student and lifts their gate).
 *
 * Both log in by USERNAME (password `password`); neither has an email. Fully
 * re-runnable and self re-arming — each run resets them to not-enrolled.
 */
class CabmbIntakeDemoSeeder extends Seeder
{
    public function run(): void
    {
        $program = Program::where('code', 'BSA')->first();
        $batch = Batch::where('name', 'BSA 2026 Internship')->first();

        if (! $program || ! $batch) {
            return;
        }

        $batch->loadMissing(['program.department', 'coordinator']);

        // A curated company that already has a supervisor attached, so Accept
        // can assign the placement's supervisor.
        $company = Company::where('name', 'Bohol Quality Corporation')->first();

        $this->intakeStudent(
            username: 'mdcintake',
            name: 'Josefa Ramirez',
            sid: '2022-BSA-INTK1',
            program: $program,
            batch: $batch,
            status: 'draft',
            company: null,
        );

        $this->intakeStudent(
            username: 'mdcintake2',
            name: 'Marlon Pabalan',
            sid: '2022-BSA-INTK2',
            program: $program,
            batch: $batch,
            status: 'submitted',
            company: $company,
        );
    }

    private function intakeStudent(
        string $username,
        string $name,
        string $sid,
        Program $program,
        Batch $batch,
        string $status,
        ?Company $company,
    ): void {
        $student = User::updateOrCreate(
            ['username' => $username],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => 'student',
                'student_id_number' => $sid,
                'program_id' => $program->id,
                'is_active' => true,
            ]
        );

        StudentProfile::updateOrCreate(
            ['user_id' => $student->id],
            ['student_id_number' => $sid, 'sex' => 'female', 'year_level' => '4th Year', 'total_hours_required' => 486]
        );

        // Re-arm: ensure this demo student is NOT enrolled on every run.
        BatchStudent::where('student_id', $student->id)->delete();

        [$firstName, $lastName] = array_pad(explode(' ', trim($name), 2), 2, '');

        StudentInformationSheet::updateOrCreate(
            ['student_id' => $student->id, 'batch_id' => $batch->id],
            [
                'submission_status' => $status,
                'submitted_at' => $status === 'submitted' ? now() : null,
                'rejection_reason' => null,
                'personal_info' => [
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'contact_number' => '0917-000-0000',
                    'student_id_number' => $sid,
                ],
                'academic_info' => [
                    'program_course' => $batch->program?->name,
                    'year_level' => '4th Year',
                    'department' => $batch->program?->department?->name,
                    'internship_coordinator' => $batch->coordinator?->name,
                ],
                'ojt_info' => $company ? [
                    'company_id' => $company->id,
                    'host_company' => $company->name,
                    'company_address' => $company->address,
                    'area_assigned' => 'Accounting',
                    'intern_duty_schedule' => 'Mon-Fri, 8:00 AM - 5:00 PM',
                ] : [],
                'emergency_contact' => null,
            ]
        );
    }
}
