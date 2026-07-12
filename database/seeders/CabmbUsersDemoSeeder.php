<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Department;
use App\Models\JournalTemplate;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Broad, re-runnable CABM-B demo data so the coordinator "Users" page (interns
 * + supervisors) and batch roster management are clickable for the defense.
 *
 * CABM-B and its four programs (BSA, BSBA-FM, BSBA-MM, BSBA-OM) already exist
 * via DepartmentProgramSeeder — this seeder only adds students, supervisors,
 * companies, batches and enrollments INTO them.
 *
 * CABM-B has a DEDICATED coordinator, "Maria Antonnette Balbero" (CABM-B only),
 * so her world is pure CABM-B with no BSIT bleed-through. The CAST/BSIT demo
 * coordinator is explicitly detached from CABM-B here.
 */
class CabmbUsersDemoSeeder extends Seeder
{
    public function run(): void
    {
        $cabmb = Department::where('code', 'CABM-B')->first();

        if (! $cabmb) {
            return;
        }

        // De-pollute: the CAST/BSIT demo coordinator must NOT run CABM-B anymore.
        User::where('email', 'mdccore@gmail.com')->first()
            ?->departmentsCoordinated()->detach($cabmb->id);

        // Dedicated CABM-B coordinator (the CABM-B demo login), CABM-B only.
        $coordinator = User::updateOrCreate(
            ['email' => 'mdcbalbero@gmail.com'],
            [
                'name' => 'Maria Antonnette Balbero',
                'password' => Hash::make('password'),
                'role' => 'coordinator',
                'is_active' => true,
            ]
        );
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
            "Dunkin' Bohol" => [
                'address' => 'K of C Drive, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Food & Marketing',
                'head_name' => 'Ms. Trisha Yap',
                'description' => 'Marketing and store operations placements.',
            ],
            'BayView Resort Panglao' => [
                'address' => 'Alona Beach, Panglao, Bohol',
                'location' => 'Panglao, Bohol',
                'industry' => 'Hospitality & Operations',
                'head_name' => 'Mr. Leo Amper',
                'description' => 'Resort operations and front-office placements.',
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
            'cabmb.sup.mm2@gmail.com' => ['name' => 'Ms. Trisha Yap', 'company' => "Dunkin' Bohol", 'position' => 'Marketing Officer'],
            'cabmb.sup.om2@gmail.com' => ['name' => 'Mr. Leo Amper', 'company' => 'BayView Resort Panglao', 'position' => 'Operations Officer'],
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

        // --- Journal template shared by all four CABM-B programs -------------
        // Without a template on the batch, the student write page has no
        // sections to offer (new daily entries can't be written) and the
        // coordinator's entry-detail view has no labels to key content by.
        // Seeding bypasses the FormRequest-level enforcement of the fixed
        // Daily Accomplishment section, so its canonical definition
        // (ValidatesJournalTemplate::FIXED_SECTION) is mirrored explicitly.
        $template = JournalTemplate::firstOrCreate(
            ['name' => 'CABM-B Daily Journal Template'],
            [
                'sections' => [
                    ['key' => 'daily_accomplishment', 'label' => 'Daily Accomplishment', 'prompt' => 'Summarize what you accomplished today.', 'required' => true, 'sipp' => false],
                    ['key' => 'issues_concerns', 'label' => 'Issues and Concerns Encountered', 'prompt' => 'Describe any issues or concerns encountered today.', 'required' => false, 'sipp' => true],
                    ['key' => 'solutions', 'label' => 'Solutions', 'prompt' => 'What solutions were applied or proposed?', 'required' => false, 'sipp' => true],
                    ['key' => 'recommendations', 'label' => 'Recommendations', 'prompt' => 'Any recommendations going forward?', 'required' => false, 'sipp' => true],
                ],
                'char_limit' => 1500,
                'is_active' => true,
            ]
        );

        // The pivot has UNIQUE(program_id) — cover every CABM-B program not
        // already claimed by a DIFFERENT template (idempotent re-runs keep
        // this template's own rows via syncWithoutDetaching).
        $claimedElsewhere = DB::table('journal_template_program')
            ->whereIn('program_id', $programs->values())
            ->where('journal_template_id', '!=', $template->id)
            ->pluck('program_id');
        $template->programs()->syncWithoutDetaching(
            $programs->values()->diff($claimedElsewhere)->all()
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
                    'journal_template_id' => $template->id,
                    'academic_year' => now()->format('Y'),
                    'semester' => 'Internship',
                    'is_active' => true,
                ]
            );
        }

        // Batches that already existed from an earlier run were created with
        // no template — point them at the CABM-B template without clobbering
        // a template a coordinator may have assigned since.
        Batch::whereIn('id', collect($batches)->pluck('id'))
            ->whereNull('journal_template_id')
            ->update(['journal_template_id' => $template->id]);

        // --- Students (a mix of enrolled and NOT-enrolled per program) -------
        $studentDefs = [
            // BSA
            ['email' => 'cabmb.bsa1@gmail.com', 'name' => 'Andrea Villanueva', 'sid' => '2022-BSA-001', 'sex' => 'female', 'program' => 'BSA', 'enroll' => ['company' => 'Bohol Quality Corporation', 'supervisor' => 'cabmb.sup.bsa@gmail.com']],
            ['email' => 'cabmb.bsa2@gmail.com', 'name' => 'Miguel Torres', 'sid' => '2022-BSA-002', 'sex' => 'male', 'program' => 'BSA', 'enroll' => ['company' => 'Bohol Quality Corporation', 'supervisor' => 'cabmb.sup.bsa@gmail.com']],
            ['email' => 'cabmb.bsa3@gmail.com', 'name' => 'Bea Salcedo', 'sid' => '2022-BSA-003', 'sex' => 'female', 'program' => 'BSA', 'enroll' => null],
            ['email' => 'cabmb.bsa4@gmail.com', 'name' => 'Carlos Diaz', 'sid' => '2022-BSA-004', 'sex' => 'male', 'program' => 'BSA', 'enroll' => ['company' => 'Bohol Quality Corporation', 'supervisor' => 'cabmb.sup.bsa@gmail.com']],
            // BSBA-FM
            ['email' => 'cabmb.fm1@gmail.com', 'name' => 'Karlo Mendoza', 'sid' => '2022-FM-001', 'sex' => 'male', 'program' => 'BSBA-FM', 'enroll' => ['company' => 'Metrobank - Tagbilaran Branch', 'supervisor' => 'cabmb.sup.fm@gmail.com']],
            ['email' => 'cabmb.fm2@gmail.com', 'name' => 'Liza Aquino', 'sid' => '2022-FM-002', 'sex' => 'female', 'program' => 'BSBA-FM', 'enroll' => ['company' => 'Metrobank - Tagbilaran Branch', 'supervisor' => 'cabmb.sup.fm@gmail.com']],
            ['email' => 'cabmb.fm3@gmail.com', 'name' => 'Noel Fabros', 'sid' => '2022-FM-003', 'sex' => 'male', 'program' => 'BSBA-FM', 'enroll' => null],
            // BSBA-MM
            ['email' => 'cabmb.mm1@gmail.com', 'name' => 'Patricia Cruz', 'sid' => '2022-MM-001', 'sex' => 'female', 'program' => 'BSBA-MM', 'enroll' => ['company' => 'Alturas Group of Companies', 'supervisor' => 'cabmb.sup.mm@gmail.com']],
            ['email' => 'cabmb.mm2@gmail.com', 'name' => 'Rafael Ong', 'sid' => '2022-MM-002', 'sex' => 'male', 'program' => 'BSBA-MM', 'enroll' => null],
            ['email' => 'cabmb.mm3@gmail.com', 'name' => 'Elena Reyes', 'sid' => '2022-MM-003', 'sex' => 'female', 'program' => 'BSBA-MM', 'enroll' => ['company' => "Dunkin' Bohol", 'supervisor' => 'cabmb.sup.mm2@gmail.com']],
            // BSBA-OM
            ['email' => 'cabmb.om1@gmail.com', 'name' => 'Sophia Reyes', 'sid' => '2022-OM-001', 'sex' => 'female', 'program' => 'BSBA-OM', 'enroll' => ['company' => 'Island City Mall Management', 'supervisor' => 'cabmb.sup.om@gmail.com']],
            ['email' => 'cabmb.om2@gmail.com', 'name' => 'Ted Villamor', 'sid' => '2022-OM-002', 'sex' => 'male', 'program' => 'BSBA-OM', 'enroll' => null],
            ['email' => 'cabmb.om3@gmail.com', 'name' => 'Fritz Gonzales', 'sid' => '2022-OM-003', 'sex' => 'male', 'program' => 'BSBA-OM', 'enroll' => ['company' => 'BayView Resort Panglao', 'supervisor' => 'cabmb.sup.om2@gmail.com']],
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
