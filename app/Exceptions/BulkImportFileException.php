<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The uploaded roster cannot be processed AS A FILE — it is unreadable, has
 * no data rows, or is missing required columns. Distinct from a row being
 * invalid: those are reported per-row in the preview table so the coordinator
 * can fix three cells and re-upload, whereas these mean the preview table
 * would be empty or uniformly wrong, and rendering it as "0 rows" tells them
 * nothing about what to change.
 *
 * Caught by BulkStudentImportController and returned as a 422 with this
 * message, which is written to be read by a coordinator, not a developer.
 */
class BulkImportFileException extends RuntimeException {}
