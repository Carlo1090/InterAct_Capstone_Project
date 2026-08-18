<?php

namespace Tests\Feature\Student;

use App\Models\WeeklyActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Student\Concerns\EnrollsStudentInBatch;
use Tests\TestCase;

class WeeklyActivityLogTest extends TestCase
{
    use EnrollsStudentInBatch;
    use RefreshDatabase;

    public function test_student_can_build_a_weekly_activity_log_and_download_its_pdf(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->startOfWeek()->addDays(6)->toDateString();

        $logResponse = $this->postJson('/api/student/weekly-activity-logs', [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'area_assigned' => 'Software Development Team',
            'no_of_hours' => 40,
        ]);
        $logResponse->assertCreated();
        $logId = $logResponse->json('id');

        $entryResponse = $this->postJson("/api/student/weekly-activity-logs/{$logId}/entries", [
            'inclusive_date_start' => $weekStart,
            'inclusive_date_end' => $weekEnd,
            'activities' => 'Built the weekly activity log feature.',
            'documents_records' => 'Pull request #42',
            'objectives' => 'Ship the SIPP weekly activity report.',
            'supervisor_name' => 'Engr. Ramon Villanueva',
            'supervisor_position' => 'Senior Software Engineer',
        ]);
        $entryResponse->assertCreated();

        $pdfResponse = $this->get("/api/student/weekly-activity-logs/{$logId}/pdf");

        $pdfResponse->assertOk();
        $this->assertStringContainsString('application/pdf', $pdfResponse->headers->get('Content-Type'));

        $pdfContent = $pdfResponse->getContent();
        $this->assertNotEmpty($pdfContent);

        Storage::disk('local')->put('tmp/weekly-activity-log-test.pdf', $pdfContent);
        $bytes = Storage::disk('local')->size('tmp/weekly-activity-log-test.pdf');
        fwrite(STDERR, "\nWeekly activity log PDF size: {$bytes} bytes\n");
        $this->assertGreaterThan(0, $bytes);
    }

    /**
     * The paper form is US Letter. dompdf defaults to A4 (595x842), which
     * silently narrows every measured column, so the explicit setPaper() call
     * in the controller is load-bearing — pin it.
     */
    public function test_the_pdf_is_us_letter_and_fits_on_one_page(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $weekStart = now()->startOfWeek()->toDateString();
        $logId = $this->postJson('/api/student/weekly-activity-logs', [
            'week_start' => $weekStart,
            'week_end' => now()->startOfWeek()->addDays(6)->toDateString(),
            'area_assigned' => 'Accounting Office',
            'no_of_hours' => 40,
        ])->json('id');

        // Fill the form to its pre-printed row count; it must still be one page.
        foreach (range(1, 5) as $i) {
            $this->postJson("/api/student/weekly-activity-logs/{$logId}/entries", [
                'inclusive_date_start' => $weekStart,
                'inclusive_date_end' => $weekStart,
                'activities' => "Row {$i} activities.",
                'documents_records' => 'Official receipts',
                'objectives' => 'Apply bookkeeping procedures.',
                'supervisor_name' => 'Ms. Carmela Uy',
                'supervisor_position' => 'Accounting Head',
            ])->assertCreated();
        }

        $pdf = $this->get("/api/student/weekly-activity-logs/{$logId}/pdf")->assertOk()->getContent();

        $this->assertStringContainsString(
            'MediaBox [0.000 0.000 612.000 792.000]',
            $pdf,
            'The PDF is not US Letter — dompdf most likely fell back to A4.'
        );

        preg_match_all('~/Count (\d+)~', $pdf, $matches);
        $this->assertSame(
            1,
            max(array_map('intval', $matches[1])),
            'A full form must fit on a single page, like the printed original.'
        );
    }

    /**
     * Guards the facsimile: every label printed on the paper form must appear.
     */
    public function test_the_pdf_renders_every_label_from_the_paper_form(): void
    {
        $student = $this->enrolledStudent();
        Sanctum::actingAs($student, ['*']);

        $logId = $this->postJson('/api/student/weekly-activity-logs', [
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->startOfWeek()->addDays(6)->toDateString(),
        ])->json('id');

        $html = view('pdf.weekly-activity-log', [
            'log' => WeeklyActivityLog::with('entries')->find($logId),
            'header' => [
                'student_name' => 'Juan Dela Cruz',
                'program_and_year' => 'BSIT 4th-year',
                'faculty_adviser' => 'Coordinator Name',
                'company_name' => 'Bohol Quality Corporation',
                'supervisor_name' => 'Ms. Carmela Uy',
                'department_line' => 'College of Accountancy, Business and Management',
                'unit_line' => 'Business Department',
            ],
            'periodCovered' => 'Jan 1 - 5, 2026',
            'hours' => '40',
            'rows' => [],
        ])->render();

        foreach ([
            'Mater Dei College',
            'College of Accountancy, Business and Management',
            'Business Department',
            'Weekly Activity Log and Time Log Summary Guide',
            'Name of Student Intern', 'Program and Year', 'Faculty Adviser',
            'Name of Company', 'Name of Supervisor', 'Area Assigned',
            'Period Covered', 'No. of hours',
            'Activities', 'Document/Records', 'Objective/s',
        ] as $label) {
            $this->assertStringContainsString($label, $html, "The form label '{$label}' is missing from the PDF.");
        }
    }

    public function test_student_cannot_access_another_students_weekly_activity_log(): void
    {
        $studentA = $this->enrolledStudent();
        $studentB = $this->enrolledStudent();

        Sanctum::actingAs($studentA, ['*']);
        $logResponse = $this->postJson('/api/student/weekly-activity-logs', [
            'week_start' => now()->startOfWeek()->toDateString(),
            'week_end' => now()->startOfWeek()->addDays(6)->toDateString(),
        ]);
        $logId = $logResponse->json('id');

        Sanctum::actingAs($studentB, ['*']);
        $this->getJson("/api/student/weekly-activity-logs/{$logId}")->assertStatus(403);
        $this->getJson("/api/student/weekly-activity-logs/{$logId}/pdf")->assertStatus(403);
    }
}
