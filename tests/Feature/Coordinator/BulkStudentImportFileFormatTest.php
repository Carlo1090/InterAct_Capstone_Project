<?php

namespace Tests\Feature\Coordinator;

use App\Exceptions\BulkImportFileException;
use App\Models\Batch;
use App\Models\Department;
use App\Models\Program;
use App\Models\StudentInformationSheet;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\StudentBulkImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * How bulk import behaves on the FILE as a whole — which worksheet it reads,
 * what happens to an unreadable or mis-headed upload, and that a row failing
 * halfway leaves nothing behind.
 *
 * Split out from BulkStudentImportTest, which covers row-level validation and
 * scoping and uploads only CSV. That CSV-only habit is precisely what hid the
 * worst bug this file pins: a CSV has no worksheets, so the multi-sheet
 * overwrite could never happen there. Real coordinators upload .xlsx.
 */
class BulkStudentImportFileFormatTest extends TestCase
{
    use RefreshDatabase;

    private function programFor(string $code, string $deptCode = 'CAST'): Program
    {
        $department = Department::firstOrCreate(
            ['code' => $deptCode],
            ['name' => $deptCode.' Department', 'is_active' => true]
        );

        return Program::firstOrCreate(
            ['department_id' => $department->id, 'code' => $code],
            ['name' => $code.' Program', 'is_active' => true]
        );
    }

    private function coordinatorFor(Program $program): User
    {
        $coordinator = User::factory()->create(['role' => 'coordinator']);
        $coordinator->departmentsCoordinated()->attach($program->department_id);

        return $coordinator;
    }

    private function batchFor(Program $program, User $coordinator): Batch
    {
        return Batch::create([
            'program_id' => $program->id,
            'coordinator_id' => $coordinator->id,
            'name' => $program->code.' 2026 Internship',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(3)->toDateString(),
            'required_hours' => 486,
            'working_days_per_week' => 5,
            'academic_year' => now()->format('Y'),
            'semester' => 'Internship',
            'is_active' => true,
        ]);
    }

    /**
     * A REAL .xlsx, written by PhpSpreadsheet, so the worksheet handling under
     * test is genuinely exercised. Passing an UploadedFile with a null mime
     * lets Symfony guess it from the file itself, which is what the `mimes`
     * rule then checks.
     */
    private function xlsxUpload(callable $build, string $filename = 'roster.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $build($spreadsheet);

        $path = tempnam(sys_get_temp_dir(), 'roster').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $filename, null, null, true);
    }

    /**
     * @param  list<string>  $lines
     */
    private function rawCsvUpload(array $lines, string $filename = 'roster.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'roster').'.csv';
        file_put_contents($path, implode("\r\n", $lines));

        return new UploadedFile($path, $filename, 'text/csv', null, true);
    }

    private function preview(User $coordinator, Program $program, Batch $batch, UploadedFile $file)
    {
        Sanctum::actingAs($coordinator, ['*']);

        return $this->post('/api/coordinator/accounts/bulk-import/preview', [
            'file' => $file,
            'program_id' => $program->id,
            'batch_id' => $batch->id,
        ]);
    }

    /**
     * THE BUG THIS FILE EXISTS FOR. Reader::loadSpreadsheet() hands every
     * worksheet to the same import object when it is not WithMultipleSheets,
     * so an unguarded `$this->rows = $rows` let the LAST sheet overwrite the
     * roster: the file imported as zero rows and reported it as a perfectly
     * successful preview of an empty file, with no error anywhere. An empty
     * trailing "Sheet2" is enough, and Excel adds one by default.
     */
    public function test_an_xlsx_with_a_second_sheet_still_imports_the_roster_from_the_first(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $file = $this->xlsxUpload(function (Spreadsheet $spreadsheet) {
            $roster = $spreadsheet->getActiveSheet();
            $roster->setTitle('Roster');
            $roster->fromArray([
                ['First Name', 'Middle Name', 'Family Name', 'Sex', 'Student ID Number', 'Email'],
                ['Ana', 'Reyes', 'Cruz', 'Female', '2026-100', 'ana@example.com'],
                ['Ben', '', 'Lim', 'Male', '2026-101', 'ben@example.com'],
            ], null, 'A1');

            // Deliberately blank, the way a stock Excel workbook ships.
            $spreadsheet->createSheet()->setTitle('Sheet2');
        });

        $response = $this->preview($coordinator, $bsit, $batch, $file);

        $response->assertOk();
        $this->assertSame(2, $response->json('valid_count'));
        $this->assertSame(
            ['2026-100', '2026-101'],
            collect($response->json('rows'))->pluck('student_id_number')->all()
        );
    }

    /**
     * The other half of the same rule: a later sheet that actually carries the
     * roster's marker column still wins over an instructions tab in front of
     * it, so "first sheet only" never becomes its own trap.
     */
    public function test_a_roster_on_the_second_sheet_wins_over_an_instructions_tab_on_the_first(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $file = $this->xlsxUpload(function (Spreadsheet $spreadsheet) {
            $notes = $spreadsheet->getActiveSheet();
            $notes->setTitle('Instructions');
            $notes->setCellValue('A1', 'Fill in the Roster tab, then upload this file.');

            $roster = $spreadsheet->createSheet();
            $roster->setTitle('Roster');
            $roster->fromArray([
                ['First Name', 'Middle Name', 'Family Name', 'Sex', 'Student ID Number', 'Email'],
                ['Cara', '', 'Diaz', 'Female', '2026-102', 'cara@example.com'],
            ], null, 'A1');
        });

        $response = $this->preview($coordinator, $bsit, $batch, $file);

        $response->assertOk();
        $this->assertSame(1, $response->json('valid_count'));
        $this->assertSame('2026-102', $response->json('rows')[0]['student_id_number']);
    }

    /**
     * A file PhpSpreadsheet cannot open used to escape the service as a raw
     * reader exception, i.e. a 500, which reads as the app being broken rather
     * than the file.
     *
     * Driven against the service rather than the endpoint on purpose. The
     * `mimes` rule catches the EASY shapes of this first — a garbled upload
     * usually sniffs as application/zip or text/plain and is refused before
     * the reader runs — but it cannot catch the ones that matter: a
     * password-protected workbook, or one whose inner parts are damaged, both
     * of which sniff as a perfectly good .xlsx and then throw on read. Going
     * through HTTP here would only re-test the mimes rule.
     */
    public function test_an_unreadable_file_raises_a_handled_import_error_not_a_raw_reader_exception(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'broken').'.xlsx';
        file_put_contents($path, "PK\x03\x04 this is not really a workbook");

        $this->expectException(BulkImportFileException::class);
        $this->expectExceptionMessageMatches('/could not be read/');

        app(StudentBulkImportService::class)
            ->parseAndValidate(new UploadedFile($path, 'roster.xlsx', null, null, true));
    }

    /** The endpoint answers 422 with a readable message, never a 500. */
    public function test_the_endpoint_answers_an_unusable_file_with_422(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $path = tempnam(sys_get_temp_dir(), 'broken').'.xlsx';
        file_put_contents($path, "PK\x03\x04 this is not really a workbook");

        $response = $this->preview(
            $coordinator,
            $bsit,
            $batch,
            new UploadedFile($path, 'roster.xlsx', null, null, true)
        );

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('message'));
    }

    /**
     * A renamed heading used to surface as the SAME row-level error on every
     * single row, with nothing anywhere pointing at row 1, which is where the
     * actual mistake is.
     */
    public function test_a_missing_column_is_reported_once_and_named_rather_than_failing_every_row(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $file = $this->rawCsvUpload([
            'First Name,Middle Name,Family Name,Sex,Student ID Number,E-mail',
            'Ana,Reyes,Cruz,Female,2026-110,ana@example.com',
        ]);

        $response = $this->preview($coordinator, $bsit, $batch, $file);

        $response->assertStatus(422);
        $this->assertStringContainsString('missing the Email column', $response->json('message'));
    }

    public function test_a_file_with_only_headings_is_rejected_with_a_message(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $file = $this->rawCsvUpload(['First Name,Middle Name,Family Name,Sex,Student ID Number,Email']);

        $response = $this->preview($coordinator, $bsit, $batch, $file);

        $response->assertStatus(422);
        $this->assertStringContainsString('No student rows', $response->json('message'));
    }

    /** A registrar export routinely abbreviates Sex, and rejecting it meant hand-editing every row. */
    public function test_sex_accepts_the_m_and_f_abbreviations(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        $file = $this->rawCsvUpload([
            'First Name,Middle Name,Family Name,Sex,Student ID Number,Email',
            'Ana,,Cruz,F,2026-120,ana120@example.com',
            'Ben,,Lim,m,2026-121,ben121@example.com',
        ]);

        $response = $this->preview($coordinator, $bsit, $batch, $file);

        $response->assertOk();
        $this->assertSame(2, $response->json('valid_count'));
        $this->assertSame(['female', 'male'], collect($response->json('rows'))->pluck('sex')->all());
    }

    /**
     * An address differing only in case is caught by validation and NAMED,
     * rather than slipping through to die on the unique index and surface as
     * the generic "Could not be created". SQLite's `=` on text is
     * case-sensitive, so the plain where() this replaced missed it entirely.
     */
    public function test_an_email_differing_only_in_case_is_flagged_as_already_in_use(): void
    {
        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        User::factory()->create(['role' => 'student', 'email' => 'taken@example.com', 'username' => 'taken-user']);

        $file = $this->rawCsvUpload([
            'First Name,Middle Name,Family Name,Sex,Student ID Number,Email',
            'Ana,,Cruz,Female,2026-130,TAKEN@example.com',
        ]);

        $response = $this->preview($coordinator, $bsit, $batch, $file);

        $response->assertOk();
        $this->assertSame(0, $response->json('valid_count'));
        $this->assertContains('This Email is already in use.', $response->json('rows')[0]['errors']);
    }

    /**
     * One row is one transaction. A failure after the users row was written
     * used to leave an account behind carrying no draft info sheet, while
     * telling the coordinator to "retry this row in a new upload" — which then
     * failed validation with "already in use", so the advice could not be
     * followed and the student was stranded with no intended batch to be
     * Accepted into.
     */
    public function test_a_row_that_fails_midway_leaves_no_half_built_account_behind(): void
    {
        Notification::fake();

        $bsit = $this->programFor('BSIT');
        $coordinator = $this->coordinatorFor($bsit);
        $batch = $this->batchFor($bsit, $coordinator);

        // Blow up AFTER User::create has already run inside createOne().
        $this->mock(EnrollmentService::class, function ($mock) {
            $mock->shouldReceive('scaffoldIntendedSheet')->andThrow(new RuntimeException('sheet write failed'));
        });

        $file = $this->rawCsvUpload([
            'First Name,Middle Name,Family Name,Sex,Student ID Number,Email',
            'Ana,,Cruz,Female,2026-140,ana140@example.com',
        ]);

        Sanctum::actingAs($coordinator, ['*']);

        $response = $this->post('/api/coordinator/accounts/bulk-import/confirm', [
            'file' => $file,
            'program_id' => $bsit->id,
            'batch_id' => $batch->id,
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('created_count'));

        // The whole point: nothing survives, so the advised retry can work.
        $this->assertNull(User::where('student_id_number', '2026-140')->first());
        $this->assertSame(0, StudentInformationSheet::count());
    }
}
