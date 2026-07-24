<?php

namespace Database\Seeders;

use App\Models\BatchStudent;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

class SippBsaDemoSeeder extends Seeder
{
    /**
     * Seed submitted journal entries carrying SIPP content (issues_concerns /
     * solutions / recommendations) for the enrolled BSA demo students
     * (CabmbUsersDemoSeeder), so the per-program Annual SIPP Report has
     * real BSA candidate rows to demonstrate — mirrors SippDemoEntriesSeeder's
     * CAST/BSIT pattern, scoped to CABM-B/BSA instead.
     */
    public function run(): void
    {
        $studentEntries = [
            'cabmb.bsa1@gmail.com' => [
                [
                    'days_ago' => 11,
                    'content' => [
                        'daily_accomplishment' => 'Performed physical inventory count reconciliation for the warehousing division.',
                        'issues_concerns' => 'Several inventory tags were missing barcodes, slowing down the count.',
                        'solutions' => 'Cross-referenced missing items against the prior month\'s stock ledger to identify them manually.',
                        'recommendations' => 'Recommend re-printing barcode tags for fast-moving items every quarter.',
                    ],
                ],
                [
                    'days_ago' => 7,
                    'content' => [
                        'daily_accomplishment' => 'Assisted in preparing bank reconciliation statements for the current month.',
                        'issues_concerns' => 'Two deposits in transit were not yet reflected in the bank statement, causing a variance.',
                        'solutions' => 'Verified the deposit slips against the collection report and noted them as reconciling items.',
                        'recommendations' => 'Suggest requesting same-day bank confirmation for large deposits going forward.',
                    ],
                ],
                [
                    'days_ago' => 3,
                    'content' => [
                        'daily_accomplishment' => 'Encoded accounts payable vouchers into the accounting system.',
                        'issues_concerns' => 'Some supplier invoices lacked approval signatures before encoding.',
                        'solutions' => 'Routed the unsigned invoices back to the purchasing officer for approval first.',
                        'recommendations' => 'Recommend a standard checklist attached to every invoice before it reaches accounting.',
                    ],
                ],
            ],
            'cabmb.bsa2@gmail.com' => [
                [
                    'days_ago' => 10,
                    'content' => [
                        'daily_accomplishment' => 'Helped compute depreciation schedules for office equipment under the straight-line method.',
                        'issues_concerns' => 'The fixed asset register had two items with no recorded acquisition date.',
                        'solutions' => 'Located the original purchase orders in the archive to fill in the missing dates.',
                        'recommendations' => 'Recommend digitizing the fixed asset register to prevent missing fields in the future.',
                    ],
                ],
                [
                    'days_ago' => 6,
                    'content' => [
                        'daily_accomplishment' => 'Assisted the finance team in preparing the trial balance for month-end closing.',
                        'issues_concerns' => 'A suspense account balance could not be traced to a specific transaction.',
                        'solutions' => 'Worked with the bookkeeper to trace the entry back to a misclassified journal voucher.',
                        'recommendations' => 'Recommend a monthly suspense-account review to catch misclassifications earlier.',
                    ],
                ],
            ],
            'cabmb.bsa4@gmail.com' => [
                [
                    'days_ago' => 9,
                    'content' => [
                        'daily_accomplishment' => 'Assisted with the quarterly internal audit of petty cash funds.',
                        'issues_concerns' => 'The petty cash custodian\'s log did not match the physical cash count.',
                        'solutions' => 'Recounted the fund with the custodian present and documented the discrepancy for review.',
                        'recommendations' => 'Recommend surprise petty cash counts at least once a month.',
                    ],
                ],
                [
                    'days_ago' => 4,
                    'content' => [
                        'daily_accomplishment' => 'Prepared supporting schedules for the accounts receivable aging report.',
                        'issues_concerns' => 'Several long-outstanding receivables had no follow-up notes on file.',
                        'solutions' => 'Flagged the accounts for the collections officer and logged their last contact dates.',
                        'recommendations' => 'Recommend a standard follow-up log for receivables past 60 days.',
                    ],
                ],
            ],
        ];

        foreach ($studentEntries as $email => $entries) {
            $student = User::where('email', $email)->first();

            if (! $student) {
                continue;
            }

            $enrollment = BatchStudent::where('student_id', $student->id)
                ->where('status', 'active')
                ->first();

            if (! $enrollment) {
                continue;
            }

            foreach ($entries as $entry) {
                $date = now()->subDays($entry['days_ago'])->toDateString();

                JournalEntry::updateOrCreate(
                    ['student_id' => $student->id, 'entry_date' => $date],
                    [
                        'batch_id' => $enrollment->batch_id,
                        'content' => $entry['content'],
                        'status' => 'submitted',
                        'submitted_at' => now()->subDays($entry['days_ago']),
                    ]
                );
            }
        }
    }
}
