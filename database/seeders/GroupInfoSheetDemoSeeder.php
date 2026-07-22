<?php

namespace Database\Seeders;

use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\StudentInformationSheet;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data for the GROUP Student Information Sheet (one document per company).
 *
 * The other seeders already place three same-program interns at Bohol Quality
 * Corporation (BSA) and three at Metrobank - Tagbilaran Branch (BSBA-FM), but
 * none of them has an information sheet — so the group roster would fall back
 * to splitting users.name with empty Parent's/Guardian's columns. This fills in
 * approved sheets for them, which is where the roster actually reads from.
 *
 * One intern (Carlos Diaz) is deliberately left WITHOUT a sheet, so the page
 * also demonstrates the incomplete-source-data case the coordinator's inline
 * cell editing and "Add Intern" button exist for.
 *
 * Additive and re-runnable: it only writes information sheets for enrollments
 * the earlier seeders already created, and never touches batch_students.
 */
class GroupInfoSheetDemoSeeder extends Seeder
{
    /**
     * Personal details keyed by the seeded intern's users.name.
     *
     * @var array<string, array{last: string, first: string, middle: string, contact: string, parent: string, parent_contact: string, sex: string}>
     */
    private const INTERNS = [
        'Andrea Villanueva' => [
            'last' => 'Villanueva', 'first' => 'Andrea', 'middle' => 'Bautista',
            'contact' => '0917-201-3345', 'parent' => 'Rodolfo Villanueva', 'parent_contact' => '0918-441-2210',
            'sex' => 'female',
        ],
        'Miguel Torres' => [
            'last' => 'Torres', 'first' => 'Miguel', 'middle' => 'Sarmiento',
            'contact' => '0926-118-7742', 'parent' => 'Elena Torres', 'parent_contact' => '0917-556-8890',
            'sex' => 'male',
        ],
        'Karlo Mendoza' => [
            'last' => 'Mendoza', 'first' => 'Karlo', 'middle' => 'Aguilar',
            'contact' => '0935-772-1108', 'parent' => 'Teresita Mendoza', 'parent_contact' => '0920-330-9917',
            'sex' => 'male',
        ],
        'Liza Aquino' => [
            'last' => 'Aquino', 'first' => 'Liza', 'middle' => 'Fuentes',
            'contact' => '0917-664-2251', 'parent' => 'Benjamin Aquino', 'parent_contact' => '0999-812-4407',
            'sex' => 'female',
        ],
        'Renz Adrian Corvera' => [
            'last' => 'Corvera', 'first' => 'Renz Adrian', 'middle' => 'Lumapas',
            'contact' => '0908-231-5567', 'parent' => 'Marilou Corvera', 'parent_contact' => '0927-145-3320',
            'sex' => 'male',
        ],
        // Carlos Diaz is intentionally absent — see the class docblock.
    ];

    public function run(): void
    {
        $companyIds = Company::whereIn('name', [
            'Bohol Quality Corporation',
            'Metrobank - Tagbilaran Branch',
        ])->pluck('id');

        if ($companyIds->isEmpty()) {
            return;
        }

        $enrollments = BatchStudent::whereIn('company_id', $companyIds)
            ->with(['student', 'batch.program.department', 'batch.coordinator', 'company'])
            ->get();

        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;
            $details = self::INTERNS[$student?->name ?? ''] ?? null;

            if (! $student || ! $details) {
                continue;
            }

            $this->fillProfile($student, $details);
            $this->approveSheet($enrollment, $details);
        }
    }

    /**
     * @param  array<string, string>  $details
     */
    private function fillProfile(User $student, array $details): void
    {
        StudentProfile::updateOrCreate(
            ['user_id' => $student->id],
            [
                'middle_name' => $details['middle'],
                'contact_number' => $details['contact'],
                'sex' => $details['sex'],
                'year_level' => '4th Year',
            ]
        );
    }

    /**
     * @param  array<string, string>  $details
     */
    private function approveSheet(BatchStudent $enrollment, array $details): void
    {
        $batch = $enrollment->batch;
        $company = $enrollment->company;

        StudentInformationSheet::updateOrCreate(
            ['student_id' => $enrollment->student_id, 'batch_id' => $enrollment->batch_id],
            [
                'submission_status' => 'approved',
                'submitted_at' => now(),
                'rejection_reason' => null,
                'personal_info' => [
                    'last_name' => $details['last'],
                    'first_name' => $details['first'],
                    'middle_name' => $details['middle'],
                    'contact_number' => $details['contact'],
                    'parent_guardian_name' => $details['parent'],
                    'parent_guardian_contact' => $details['parent_contact'],
                    'sex' => $details['sex'],
                ],
                'academic_info' => [
                    'program_course' => $batch?->program?->name,
                    'year_level' => '4th-year',
                    'department' => $batch?->program?->department?->name,
                    'internship_coordinator' => $batch?->coordinator?->name,
                ],
                'ojt_info' => [
                    'company_id' => $company?->id,
                    'host_company' => $company?->name,
                    'company_address' => $company?->address,
                    'area_assigned' => $enrollment->assigned_division,
                ],
                'emergency_contact' => null,
            ]
        );
    }
}
