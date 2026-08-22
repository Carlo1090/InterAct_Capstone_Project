<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\CompanySupervisor;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A clean, obviously-named Company Supervisor world under mdcbalbero (CABM-B),
 * for testing the supervisor role end to end.
 *
 * The pre-existing CABM-B supervisors from CabmbUsersDemoSeeder are realistic
 * but their logins (cabmb.sup.bsa, cabmb.sup.om2, ...) are awkward to type and
 * impossible to remember mid-demo. This seeder adds ONE supervisor whose
 * username follows the same mdc* convention as every other demo login, with a
 * roster of three interns named to match:
 *
 *   mdcbalsup      — the company login supervisor
 *   mdcbalintern1  \
 *   mdcbalintern2   > his interns, all at the same company
 *   mdcbalintern3  /
 *
 * All under mdcbalbero's BSBA-FM batch, so the coordinator, the supervisor and
 * the students all see each other.
 *
 * It uses its OWN company rather than joining an existing one on purpose: a
 * company may have at most one login-bearing supervisor
 * (CoordinatorCompanyController::guardSingleLogin), so attaching mdcbalsup to
 * a company that already has one would be rejected by the app's own rule.
 *
 * Re-runnable: every write is a firstOrCreate/updateOrCreate keyed on a stable
 * identifier, and enrollments go through the same unique (batch, student) pair
 * the rest of the system relies on.
 */
class CabmbSupervisorDemoSeeder extends Seeder
{
    /** Every demo account in this project shares this password. */
    private const DEMO_PASSWORD = 'password';

    private const COMPANY = 'Tagbilaran Cooperative Bank';

    public function run(): void
    {
        $coordinator = User::where('username', 'mdcbalbero')->first();

        if (! $coordinator) {
            return;
        }

        // BSBA-FM is mdcbalbero's finance cohort — a bank placement fits it.
        $program = Program::where('code', 'BSBA-FM')->first();
        $batch = $program
            ? Batch::where('program_id', $program->id)
                ->where('coordinator_id', $coordinator->id)
                ->first()
            : null;

        if (! $batch) {
            return;
        }

        $company = Company::firstOrCreate(
            ['name' => self::COMPANY],
            [
                'address' => 'CPG Avenue, Tagbilaran City, Bohol',
                'location' => 'Tagbilaran City, Bohol',
                'industry' => 'Banking & Finance',
                'contact_number' => '038-411-3120',
                'head_name' => 'Mr. Alfonso Bernaldez',
                'head_contact_number' => '038-411-3121',
                'head_email' => 'info@tagbilarancoopbank.example',
                'department_head' => 'Branch Operations',
                'description' => 'Cooperative bank hosting BSBA-FM interns in branch operations.',
                'is_active' => true,
            ]
        );

        // The company's ONE login-bearing supervisor. batch_students.supervisor_id
        // is derived from this row by EnrollmentService, so it must exist before
        // any student can be placed here.
        $supervisor = User::updateOrCreate(
            ['username' => 'mdcbalsup'],
            [
                'name' => 'Ms. Rowena Salazar',
                'email' => 'mdcbalsup@gmail.com',
                'password' => Hash::make(self::DEMO_PASSWORD),
                'role' => 'supervisor',
                'is_active' => true,
                // Never seed a verified address: email_verified_at is the only
                // thing stopping a demo run from mailing a real stranger.
                'must_change_password' => false,
            ]
        );

        CompanySupervisor::firstOrCreate(
            ['company_id' => $company->id, 'user_id' => $supervisor->id],
            ['position' => 'Branch Operations Supervisor']
        );

        // A named-only contact alongside the login, so the "login-bearing vs
        // named-only" split is visible on the company page without any setup.
        CompanySupervisor::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Mr. Elmer Bautista'],
            ['user_id' => null, 'position' => 'Assistant Branch Manager']
        );

        $companySupervisorId = CompanySupervisor::where('company_id', $company->id)
            ->where('user_id', $supervisor->id)
            ->value('id');

        $interns = [
            ['username' => 'mdcbalintern1', 'name' => 'Jomar Bactol', 'sid' => '2022-FM-101', 'sex' => 'male'],
            ['username' => 'mdcbalintern2', 'name' => 'Rhea Lumapas', 'sid' => '2022-FM-102', 'sex' => 'female'],
            ['username' => 'mdcbalintern3', 'name' => 'Kenneth Auza', 'sid' => '2022-FM-103', 'sex' => 'female'],
        ];

        foreach ($interns as $intern) {
            $student = User::updateOrCreate(
                ['username' => $intern['username']],
                [
                    'name' => $intern['name'],
                    'email' => $intern['username'].'@gmail.com',
                    'password' => Hash::make(self::DEMO_PASSWORD),
                    'role' => 'student',
                    'student_id_number' => $intern['sid'],
                    'program_id' => $batch->program_id,
                    'is_active' => true,
                    'must_change_password' => false,
                ]
            );

            // DatabaseSeeder runs WithoutModelEvents, which mutes the
            // UserObserver that would normally create this row.
            StudentProfile::updateOrCreate(
                ['user_id' => $student->id],
                [
                    'student_id_number' => $intern['sid'],
                    'sex' => $intern['sex'],
                    'total_hours_required' => 486,
                ]
            );

            // An active enrollment clears User::isInfoSheetGated() on its own
            // (the legacy directly-enrolled path), so these three can log in
            // and reach every student page without an approved sheet.
            BatchStudent::updateOrCreate(
                ['batch_id' => $batch->id, 'student_id' => $student->id],
                [
                    'company_id' => $company->id,
                    'supervisor_id' => $supervisor->id,
                    'company_supervisor_id' => $companySupervisorId,
                    'assigned_division' => 'Branch Operations',
                    'status' => 'active',
                ]
            );
        }
    }
}
