<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Broad, re-runnable CABM-B demo data so the coordinator "Users" page (interns
 * + supervisors) and batch roster management are clickable for the defense.
 *
 * CABM-B and its four programs (BSA, BSBA-FM, BSBA-MM, BSBA-OM) already exist
 * via DepartmentProgramSeeder — this seeder only adds students, supervisors,
 * companies, batches and enrollments INTO them, and additively assigns the demo
 * coordinator to CABM-B (kept alongside her existing CAST/BSIT scope).
 */
class CabmbUsersDemoSeeder extends Seeder
{
    public function run(): void
    {
        $cabmb = Department::where('code', 'CABM-B')->first();
        $coordinator = User::where('email', 'mdccore@gmail.com')->first();

        if (! $cabmb || ! $coordinator) {
            return;
        }

        // Additive: the demo coordinator now also runs CABM-B (keeps CAST).
        $coordinator->departmentsCoordinated()->syncWithoutDetaching([$cabmb->id]);

        $programs = Program::where('department_id', $cabmb->id)
            ->pluck('id', 'code'); // ['BSA' => id, 'BSBA-FM' => id, ...]

        // --- Companies used by CABM-B students -------------------------------
        $companyDefs = [
            'Bohol Quality Corporation' => [
                'address' => 'CPG North Avenue, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Retail & Accounting',
                'head_name' => 'Mr. Vicente Uy',
                'description' => 'Accounting and store administration placements.',
            ],
            'Metrobank - Tagbilaran Branch' => [
                'address' => 'CPG Avenue, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Banking & Finance',
                'head_name' => 'Ms. Aileen Bautista',
                'description' => 'Branch banking and financial management placements.',
            ],
            'Alturas Group of Companies' => [
                'address' => 'Carlos P. Garcia Avenue, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Retail & Marketing',
                'head_name' => 'Mr. Rico Uy',
                'description' => 'Marketing and merchandising placements.',
            ],
            'Island City Mall Management' => [
                'address' => 'Dampas District, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Retail Operations',
                'head_name' => 'Ms. Divina Lim',
                'description' => 'Mall and retail operations placements.',
            ],
        ];

        $companies = [];
        foreach ($companyDefs as $name => $attrs) {
            $companies[$name] = Company::firstOrCreate(
                ['name' => $name],
                [...$attrs, 'is_active' => true]
            );
        }

        // A company NOT linked to any enrollment: its supervisor represents the
        // "created by the coordinator" set — visible only through the scope of
        // unlinked companies, not through any student's placement.
        $unlinkedCompany = Company::firstOrCreate(
            ['name' => 'Bohol Chamber of Commerce & Industry'],
            [
                'address' => 'M. Torralba Street, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Business Association',
                'head_name' => 'Atty. Marisol Fuentes',
                'description' => 'Freshly added partner — no interns placed yet.',
                'is_active' => true,
            ]
        );

        // --- Supervisors -----------------------------------------------------
        $supervisorDefs = [
            'cabmb.sup.bsa@gmail.com' => ['name' => 'Ms. Carmela Uy', 'company' => 'Bohol Quality Corporation', 'position' => 'Accounting Supervisor'],
            'cabmb.sup.fm@gmail.com' => ['name' => 'Mr. Dennis Chua', 'company' => 'Metrobank - Tagbilaran Branch', 'position' => 'Branch Operations Officer'],
            'cabmb.sup.mm@gmail.com' => ['name' => 'Ms. Grace Lim', 'company' => 'Alturas Group of Companies', 'position' => 'Marketing Supervisor'],
            'cabmb.sup.om@gmail.com' => ['name' => 'Mr. Paolo Reyes', 'company' => 'Island City Mall Management', 'position' => 'Operations Supervisor'],
        ];

        $supervisors = [];
        foreach ($supervisorDefs as $email => $def) {
            $supervisor = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $def['name'],
                    'password' => Hash::make('password'),
                    'role' => 'supervisor',
                    'is_active' => true,
                ]
            );

            CompanySupervisor::firstOrCreate(
                ['company_id' => $companies[$def['company']]->id, 'user_id' => $supervisor->id],
                ['position' => $def['position']]
            );

            $supervisors[$email] = $supervisor;
        }

        // Created-by-coordinator supervisor on the still-unlinked company.
        $createdSupervisor = User::updateOrCreate(
            ['email' => 'cabmb.sup.created@gmail.com'],
            [
                'name' => 'Atty. Marisol Fuentes',
                'password' => Hash::make('password'),
                'role' => 'supervisor',
                'is_active' => true,
            ]
        );
        CompanySupervisor::firstOrCreate(
            ['company_id' => $unlinkedCompany->id, 'user_id' => $createdSupervisor->id],
            ['position' => 'Executive Director']
        );

        // --- Batches: one active cohort per CABM-B program -------------------
        $batchNames = [
            'BSA' => 'BSA 2026 Internship',
            'BSBA-FM' => 'BSBA-FM 2026 Internship',
            'BSBA-MM' => 'BSBA-MM 2026 Internship',
            'BSBA-OM' => 'BSBA-OM 2026 Internship',
        ];

        $batches = [];
        foreach ($batchNames as $code => $batchName) {
            if (! isset($programs[$code])) {
                continue;
            }

            $batches[$code] = Batch::firstOrCreate(
                ['program_id' => $programs[$code], 'name' => $batchName],
                [
                    'coordinator_id' => $coordinator->id,
                    'start_date' => now()->subMonths(2)->startOfDay(),
                    'end_date' => now()->addMonths(2)->startOfDay(),
                    'required_hours' => 486,
                    'working_days_per_week' => 5,
                    'daily_reminder_time' => '21:00:00',
                    'journal_template_id' => null,
                    'academic_year' => now()->format('Y'),
                    'semester' => 'Internship',
                    'is_active' => true,
                ]
            );
        }

        // --- Students (a mix of enrolled and NOT-enrolled per program) -------
        $studentDefs = [
            // BSA
            ['email' => 'cabmb.bsa1@gmail.com', 'name' => 'Andrea Villanueva', 'sid' => '2022-BSA-001', 'sex' => 'female', 'program' => 'BSA', 'enroll' => ['company' => 'Bohol Quality Corporation', 'supervisor' => 'cabmb.sup.bsa@gmail.com']],
            ['email' => 'cabmb.bsa2@gmail.com', 'name' => 'Miguel Torres', 'sid' => '2022-BSA-002', 'sex' => 'male', 'program' => 'BSA', 'enroll' => ['company' => 'Bohol Quality Corporation', 'supervisor' => 'cabmb.sup.bsa@gmail.com']],
            ['email' => 'cabmb.bsa3@gmail.com', 'name' => 'Bea Salcedo', 'sid' => '2022-BSA-003', 'sex' => 'female', 'program' => 'BSA', 'enroll' => null],
            // BSBA-FM
            ['email' => 'cabmb.fm1@gmail.com', 'name' => 'Karlo Mendoza', 'sid' => '2022-FM-001', 'sex' => 'male', 'program' => 'BSBA-FM', 'enroll' => ['company' => 'Metrobank - Tagbilaran Branch', 'supervisor' => 'cabmb.sup.fm@gmail.com']],
            ['email' => 'cabmb.fm2@gmail.com', 'name' => 'Liza Aquino', 'sid' => '2022-FM-002', 'sex' => 'female', 'program' => 'BSBA-FM', 'enroll' => ['company' => 'Metrobank - Tagbilaran Branch', 'supervisor' => 'cabmb.sup.fm@gmail.com']],
            ['email' => 'cabmb.fm3@gmail.com', 'name' => 'Noel Fabros', 'sid' => '2022-FM-003', 'sex' => 'male', 'program' => 'BSBA-FM', 'enroll' => null],
            // BSBA-MM
            ['email' => 'cabmb.mm1@gmail.com', 'name' => 'Patricia Cruz', 'sid' => '2022-MM-001', 'sex' => 'female', 'program' => 'BSBA-MM', 'enroll' => ['company' => 'Alturas Group of Companies', 'supervisor' => 'cabmb.sup.mm@gmail.com']],
            ['email' => 'cabmb.mm2@gmail.com', 'name' => 'Rafael Ong', 'sid' => '2022-MM-002', 'sex' => 'male', 'program' => 'BSBA-MM', 'enroll' => null],
            // BSBA-OM
            ['email' => 'cabmb.om1@gmail.com', 'name' => 'Sophia Reyes', 'sid' => '2022-OM-001', 'sex' => 'female', 'program' => 'BSBA-OM', 'enroll' => ['company' => 'Island City Mall Management', 'supervisor' => 'cabmb.sup.om@gmail.com']],
            ['email' => 'cabmb.om2@gmail.com', 'name' => 'Ted Villamor', 'sid' => '2022-OM-002', 'sex' => 'male', 'program' => 'BSBA-OM', 'enroll' => null],
        ];

        foreach ($studentDefs as $def) {
            if (! isset($programs[$def['program']])) {
                continue;
            }

            $student = User::updateOrCreate(
                ['email' => $def['email']],
                [
                    'name' => $def['name'],
                    'password' => Hash::make('password'),
                    'role' => 'student',
                    'student_id_number' => $def['sid'],
                    'program_id' => $programs[$def['program']],
                    'is_active' => true,
                ]
            );

            StudentProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'student_id_number' => $def['sid'],
                    'sex' => $def['sex'],
                    'year_level' => '4th Year',
                    'total_hours_required' => 486,
                ]
            );

            if ($def['enroll'] && isset($batches[$def['program']])) {
                BatchStudent::firstOrCreate(
                    ['batch_id' => $batches[$def['program']]->id, 'student_id' => $student->id],
                    [
                        'company_id' => $companies[$def['enroll']['company']]->id,
                        'supervisor_id' => $supervisors[$def['enroll']['supervisor']]->id,
                        'assigned_division' => 'Operations',
                        'status' => 'active',
                    ]
                );
            }
        }
    }
}
