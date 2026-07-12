<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\BatchStudent;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Dedicated CABM-B demo student (BSBA-FM, under coordinator Balbero) with a
 * full Mon-Fri week of SUBMITTED daily journal entries carrying real
 * daily_accomplishment text — a clean target for the Weekly Bundling demo
 * trigger, so it and the student's own Weekly Journals page have real,
 * non-empty content to show for the CABM-B walkthrough. Mirrors
 * SupervisorReviewDemoSeeder's shape (dated two weeks back, five submitted
 * entries) but scoped entirely to CABM-B, not CAST. Additive: existing CAST
 * demo data and CabmbUsersDemoSeeder's broader roster are untouched.
 * Fully re-runnable.
 */
class CabmbWeeklyDemoSeeder extends Seeder
{
    public function run(): void
    {
        $program = Program::where('code', 'BSBA-FM')->first();
        $batch = Batch::where('name', 'BSBA-FM 2026 Internship')->first();
        $company = Company::where('name', 'Metrobank - Tagbilaran Branch')->first();
        // Reuse the CABM-B-scoped supervisor CabmbUsersDemoSeeder already
        // attached to this company, rather than creating a second one.
        $supervisor = User::where('email', 'cabmb.sup.fm@gmail.com')->first();

        if (! $program || ! $batch || ! $company || ! $supervisor) {
            return;
        }

        $student = User::updateOrCreate(
            ['email' => 'mdcbalberostudent@gmail.com'],
            [
                'name' => 'Renz Adrian Corvera',
                'password' => Hash::make('password'),
                'role' => 'student',
                'student_id_number' => '2022-FM-100',
                'program_id' => $program->id,
                'is_active' => true,
            ]
        );

        StudentProfile::updateOrCreate(
            ['user_id' => $student->id],
            [
                'student_id_number' => '2022-FM-100',
                'sex' => 'male',
                'year_level' => '4th Year',
                'total_hours_required' => 486,
            ]
        );

        $enrollment = BatchStudent::firstOrCreate(
            ['batch_id' => $batch->id, 'student_id' => $student->id],
            [
                'company_id' => $company->id,
                'supervisor_id' => $supervisor->id,
                'assigned_division' => 'Finance & Operations',
                'status' => 'active',
            ]
        );

        $enrollment->forceFill([
            'company_id' => $company->id,
            'supervisor_id' => $supervisor->id,
            'status' => 'active',
        ])->save();

        $weekStart = now()->subWeeks(2)->startOfWeek(Carbon::MONDAY);

        $dailyAccomplishments = [
            "Reviewed the branch's daily transaction reconciliation report and assisted the operations officer in verifying deposit slips against the teller's log.",
            'Assisted in encoding loan application documents into the branch system and organized client folders for the credit evaluation team.',
            "Sat in on a client onboarding session and helped prepare account-opening forms, cross-checking ID requirements against the bank's compliance checklist.",
            'Helped compile the weekly cash flow summary for the branch manager and learned how the branch reports figures to the regional office.',
            "Assisted with month-end filing of cleared checks and observed the branch's process for balancing the vault at close of business.",
        ];

        foreach ($dailyAccomplishments as $offset => $text) {
            $entryDate = $weekStart->copy()->addDays($offset);

            // Fetch-then-update rather than JournalEntry::updateOrCreate(): its
            // plain-equality match array can miss this row under SQLite, where
            // a date-cast column still stores a time component, and re-running
            // this seeder would then hit the unique(student_id, entry_date)
            // constraint instead of updating in place.
            $entry = JournalEntry::where('student_id', $student->id)
                ->whereDate('entry_date', $entryDate)
                ->first();

            $attributes = [
                'batch_id' => $batch->id,
                'content' => [
                    'task_performed' => $text,
                    'daily_accomplishment' => $text,
                ],
                'status' => 'submitted',
                'submitted_at' => $entryDate->copy()->setTime(21, 0),
            ];

            if ($entry) {
                $entry->update($attributes);
            } else {
                JournalEntry::create([
                    'student_id' => $student->id,
                    'entry_date' => $entryDate->toDateString(),
                    ...$attributes,
                ]);
            }
        }
    }
}
