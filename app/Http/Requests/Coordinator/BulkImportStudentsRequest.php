<?php

namespace App\Http\Requests\Coordinator;

use App\Services\StudentBulkImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The "envelope" for a bulk-import request — the file itself plus the single
 * Program + Batch every row in it will be created into. Program and Batch
 * are picked ONCE here rather than being columns in the spreadsheet, mirroring
 * CreateAccountRequest's scoping: it keeps foreign keys out of free-typed
 * cells, where a typo'd program/batch name would silently misfile a student.
 * Row-level data validation (names, ID number, email, sex) happens separately
 * in StudentBulkImportService, since it needs to run per-row against the
 * parsed spreadsheet content.
 */
class BulkImportStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'coordinator';
    }

    public function rules(): array
    {
        // Any batch in the coordinator's department — the same set Create
        // Account and Enroll accept (see User::placeableBatchIds()).
        $batchIds = $this->user()->placeableBatchIds()->all();

        return [
            // 'txt' is deliberate, not a typo: PHP's fileinfo sniffs a SMALL
            // CSV (a handful of rows) as text/plain rather than text/csv —
            // there isn't enough content for libmagic's CSV heuristic to
            // trigger confidently. Without it, a coordinator adding just 1-2
            // stragglers would get rejected while a full-roster upload works
            // fine, which is a confusing, size-dependent failure to debug.
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:2048'],
            'program_id' => ['required', 'integer', 'exists:programs,id'],
            'batch_id' => ['required', 'integer', 'exists:batches,id', Rule::in($batchIds)],
            // confirm() only: which rows of the file this request creates. The
            // SPA walks the file in slices so no single request runs long
            // enough to be cut off by the proxy in front of the API (see
            // BulkStudentImportController::confirm). Omitted = the whole file.
            'offset' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.StudentBulkImportService::MAX_ROWS_PER_REQUEST],
        ];
    }

    public function messages(): array
    {
        return [
            'batch_id.in' => 'The selected batch is outside your department.',
            'file.mimes' => 'The file must be an Excel (.xlsx, .xls) or CSV file.',
            'file.max' => 'The file may not be larger than 2MB.',
        ];
    }
}
